<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11\Tests;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\SignatureValidator;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdfPkcs11\Mechanism;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Exception;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Signer;
use Dskripchenko\PhpPdfPkcs11\Tests\Support\FakeToken;
use Dskripchenko\PhpPdfPkcs11\Tests\Support\TestPki;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Pkcs11SignerTest extends TestCase
{
    private static function pdf(): string
    {
        $doc = Document::new();
        $doc->addPage();

        return $doc->toBytes();
    }

    private static function token(bool $withCertificate = true): FakeToken
    {
        $pki = TestPki::get();

        return new FakeToken(
            [['label' => 'pdf', 'id' => "\x01", 'keyPem' => $pki['keyPem']]],
            $withCertificate ? [['label' => 'pdf', 'id' => "\x01", 'der' => TestPki::der($pki['certPem'])]] : [],
        );
    }

    private static function assertValid(string $pdf): void
    {
        $report = SignatureValidator::fromBytes($pdf)->validate(TrustStore::fromPem(TestPki::get()['rootPem']));
        self::assertTrue($report->allValid(), implode('; ', $report->signatures[0]->errors ?? []));
    }

    #[Test]
    public function signs_a_pdf_with_the_key_and_the_certificate_on_the_token(): void
    {
        $token = self::token();
        $signer = Pkcs11Signer::fromToken($token, keyLabel: 'pdf');
        $pdf = PdfSigner::fromBytes(self::pdf())->sign(new SignatureConfig(signer: $signer));

        self::assertValid($pdf);
        self::assertSame('Jane Doe', $signer->certificate()->subjectCommonName());
        // The certificate is matched by the key's CKA_ID, not by the label.
        self::assertContains(['findCertificate', null, "\x01"], $token->calls);
        $sign = array_values(array_filter($token->calls, static fn (array $c): bool => $c[0] === 'sign'));
        self::assertCount(1, $sign);
        self::assertSame(Mechanism::RSA_PKCS, $sign[0][1]);
        // A SHA-256 DigestInfo: 19 bytes of prefix and the 32-byte digest.
        self::assertSame(51, strlen((string) $sign[0][2]));
        self::assertStringStartsWith(hex2bin('3031300d060960864801650304020105000420'), (string) $sign[0][2]);
    }

    #[Test]
    public function finds_the_key_by_a_hex_id_in_any_spelling(): void
    {
        foreach (['01', '0x01', ' 01 '] as $id) {
            $token = self::token();
            Pkcs11Signer::fromToken($token, keyId: $id);
            self::assertSame(['findPrivateKey', null, "\x01"], $token->calls[0], $id);
        }
    }

    #[Test]
    public function a_malformed_id_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pkcs11Signer::fromToken(self::token(), keyId: 'zz');
    }

    #[Test]
    public function takes_the_certificate_from_the_caller_when_the_token_has_none(): void
    {
        $signer = Pkcs11Signer::fromToken(self::token(false), certificatePem: TestPki::get()['certPem'], chainPem: TestPki::get()['rootPem']);

        self::assertValid(PdfSigner::fromBytes(self::pdf())->sign(new SignatureConfig(signer: $signer)));
        self::assertCount(1, $signer->chain());
    }

    #[Test]
    public function says_so_when_there_is_no_certificate(): void
    {
        $this->expectException(Pkcs11Exception::class);
        $this->expectExceptionMessage('no certificate for the key');
        Pkcs11Signer::fromToken(self::token(false));
    }

    #[Test]
    public function a_key_that_does_not_match_the_certificate_is_caught_before_the_pdf_is_written(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]) ?: throw new \RuntimeException('key');
        openssl_pkey_export($other, $otherPem);
        $token = new FakeToken([['label' => 'pdf', 'id' => "\x01", 'keyPem' => $otherPem]]);
        $signer = Pkcs11Signer::fromToken($token, certificatePem: TestPki::get()['certPem']);

        $this->expectException(Pkcs11Exception::class);
        $this->expectExceptionMessage('does not belong to it');
        PdfSigner::fromBytes(self::pdf())->sign(new SignatureConfig(signer: $signer));
    }

    #[Test]
    public function the_digest_follows_the_argument(): void
    {
        $token = self::token();
        $signer = Pkcs11Signer::fromToken($token, digest: DigestAlgorithm::Sha512);
        self::assertValid(PdfSigner::fromBytes(self::pdf())->sign(new SignatureConfig(signer: $signer)));

        $sign = array_values(array_filter($token->calls, static fn (array $c): bool => $c[0] === 'sign'));
        self::assertStringStartsWith(hex2bin('3051300d060960864801650304020305000440'), (string) $sign[0][2]);
    }
}
