<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11\Tests;

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\KeyType;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\SignatureValidator;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdfPkcs11\Mechanism;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Exception;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Signer;
use Dskripchenko\PhpPdfPkcs11\Tests\Support\FakeToken;
use Dskripchenko\PhpPdfPkcs11\Tests\Support\TestPki;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GOST R 34.10-2012 through PKCS#11, against an emulated token that signs
 * with a real GOST key. Runs where OpenSSL's gost engine is loaded into PHP
 * and the CLI — in CI, the docker/gost image; skipped elsewhere.
 */
final class GostPkcs11SignerTest extends TestCase
{
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        if (! in_array('md_gost12_256', openssl_get_md_methods(), true)) {
            return;
        }
        self::$dir = sys_get_temp_dir().'/pkcs11-gost-'.bin2hex(random_bytes(6));
        mkdir(self::$dir, 0700, true);
        foreach (['256', '512', 'other256'] as $name) {
            $bits = substr($name, -3);
            exec(sprintf(
                'openssl req -x509 -newkey gost2012_%1$s -pkeyopt paramset:A -nodes -keyout %2$s/key%3$s.pem -out %2$s/cert%3$s.pem '
                .'-subj "/C=RU/O=php-pdf-pkcs11 test/CN=GOST %3$s" -days 30 -md_gost12_%1$s 2>&1',
                $bits,
                escapeshellarg(self::$dir),
                $name,
            ), $out, $code);
            if ($code !== 0) {
                throw new \RuntimeException("Cannot make a GOST $name certificate:\n".implode("\n", $out));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dir !== '') {
            array_map('unlink', (array) glob(self::$dir.'/*'));
            rmdir(self::$dir);
        }
    }

    protected function setUp(): void
    {
        if (self::$dir === '') {
            self::markTestSkipped('OpenSSL gost engine not loaded (see docker/gost)');
        }
    }

    private static function file(string $name): string
    {
        return (string) file_get_contents(self::$dir.'/'.$name);
    }

    private static function token(string $bits, string $order = 's-r', ?string $keyOf = null): FakeToken
    {
        return new FakeToken(
            [['label' => 'gost', 'id' => "\x07", 'keyPem' => self::file('key'.($keyOf ?? $bits).'.pem'), 'gostOrder' => $order]],
            [['label' => 'gost', 'id' => "\x07", 'der' => TestPki::der(self::file("cert$bits.pem"))]],
        );
    }

    private static function signPdf(Pkcs11Signer $signer): string
    {
        $doc = Document::new();
        $doc->addPage();

        return PdfSigner::fromBytes($doc->toBytes())->sign(new SignatureConfig(signer: $signer));
    }

    private static function assertValid(string $pdf, string $bits): void
    {
        $report = SignatureValidator::fromBytes($pdf)->validate(TrustStore::fromPem(self::file("cert$bits.pem")));
        self::assertTrue($report->allValid(), implode('; ', $report->signatures[0]->errors ?? []));
    }

    /** @return list<array{0: string, 1: mixed, 2: mixed}> */
    private static function signCalls(FakeToken $token): array
    {
        return array_values(array_filter($token->calls, static fn (array $c): bool => $c[0] === 'sign'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function orders(): iterable
    {
        foreach (['256', '512'] as $bits) {
            foreach (['s-r', 'r-s', 'reversed', 'halves-reversed'] as $order) {
                yield "$bits $order" => [$bits, $order];
            }
        }
    }

    #[Test]
    #[DataProvider('orders')]
    public function signs_a_pdf_with_a_gost_key_whatever_order_the_token_returns(string $bits, string $order): void
    {
        $token = self::token($bits, $order);
        $signer = Pkcs11Signer::fromToken($token, keyLabel: 'gost');
        self::assertSame($bits === '512' ? KeyType::Gost512 : KeyType::Gost256, $signer->certificate()->keyType());
        self::assertSame($bits === '512' ? DigestAlgorithm::Gost512 : DigestAlgorithm::Gost256, $signer->digestAlgorithm());

        self::assertValid(self::signPdf($signer), $bits);

        // One token operation: a Streebog digest computed in PHP.
        $sign = self::signCalls($token);
        self::assertCount(1, $sign);
        self::assertSame($bits === '512' ? Mechanism::GOSTR3410_512 : Mechanism::GOSTR3410, $sign[0][1]);
        self::assertSame($bits === '512' ? 64 : 32, strlen((string) $sign[0][2]));
    }

    #[Test]
    public function the_pkcs11_value_is_what_cms_carries_unchanged(): void
    {
        // PKCS#11 v2.40 and RFC 4491 agree on `s ‖ r`, big-endian, and so
        // does OpenSSL's gost engine: the standard case needs no conversion.
        foreach (['256', '512'] as $bits) {
            $token = self::token($bits);
            $signature = Pkcs11Signer::fromToken($token)->sign('signed attributes');
            self::assertSame(bin2hex((string) $token->lastGost), bin2hex($signature));

            $token = self::token($bits, 'reversed');
            $signature = Pkcs11Signer::fromToken($token)->sign('signed attributes');
            self::assertSame(bin2hex(strrev((string) $token->lastGost)), bin2hex($signature));
        }
    }

    #[Test]
    public function the_token_can_hash_the_data_itself(): void
    {
        foreach (['256' => Mechanism::GOSTR3410_WITH_GOSTR3411_12_256, '512' => Mechanism::GOSTR3410_WITH_GOSTR3411_12_512] as $bits => $mechanism) {
            $token = self::token((string) $bits);
            $signer = Pkcs11Signer::fromToken($token, hashOnToken: true);

            self::assertValid(self::signPdf($signer), (string) $bits);
            $sign = self::signCalls($token);
            self::assertCount(1, $sign);
            self::assertSame($mechanism, $sign[0][1]);
            // The signed attributes, DER: a SET.
            self::assertSame("\x31", ((string) $sign[0][2])[0]);
        }
    }

    #[Test]
    public function a_gost_key_that_does_not_match_the_certificate_is_caught(): void
    {
        $signer = Pkcs11Signer::fromToken(self::token('256', keyOf: 'other256'));

        $this->expectException(Pkcs11Exception::class);
        $this->expectExceptionMessage('in any known byte order');
        self::signPdf($signer);
    }

    #[Test]
    public function a_gost_key_takes_its_own_streebog_only(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('md_gost12_512 digest only');
        Pkcs11Signer::fromToken(self::token('512'), digest: DigestAlgorithm::Gost256);
    }
}
