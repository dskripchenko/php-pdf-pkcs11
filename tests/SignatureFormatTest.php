<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11\Tests;

use Dskripchenko\PhpPdfPkcs11\Pkcs11Exception;
use Dskripchenko\PhpPdfPkcs11\SignatureFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignatureFormatTest extends TestCase
{
    #[Test]
    public function r_and_s_become_the_der_openssl_verifies(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']) ?: throw new \RuntimeException('key');
        $public = openssl_pkey_get_details($key)['key'];

        // Many signatures, so r or s with the top bit set or a leading zero
        // byte turn up too.
        for ($i = 0; $i < 50; $i++) {
            openssl_sign('data '.$i, $der, $key, 'sha256');
            $raw = self::rawFromDer($der, 32);

            $back = SignatureFormat::ecdsaToDer($raw);
            self::assertSame(1, openssl_verify('data '.$i, $back, $public, 'sha256'));
            self::assertSame(bin2hex($der), bin2hex($back));
        }
    }

    #[Test]
    public function an_odd_length_is_refused(): void
    {
        $this->expectException(Pkcs11Exception::class);
        SignatureFormat::ecdsaToDer("\x01\x02\x03");
    }

    #[Test]
    public function gost_candidates_start_with_the_value_as_is(): void
    {
        $s = str_repeat("\x11", 15)."\x1f";
        $r = str_repeat("\x22", 15)."\x2f";

        self::assertSame(
            [$s.$r, $r.$s, strrev($s.$r), strrev($s).strrev($r)],
            SignatureFormat::gostCandidates($s.$r, 128),
        );
        self::assertCount(4, SignatureFormat::gostCandidates(random_bytes(64), 256));
        self::assertCount(4, SignatureFormat::gostCandidates(random_bytes(128), 512));
    }

    #[Test]
    public function a_gost_value_of_the_wrong_length_is_refused(): void
    {
        $this->expectException(Pkcs11Exception::class);
        $this->expectExceptionMessage('64 bytes, expected 128');
        SignatureFormat::gostCandidates(random_bytes(64), 512);
    }

    #[Test]
    public function the_module_error_is_explained(): void
    {
        $e = Pkcs11Exception::fromModule('login', new \Exception('(0x000000a0/CKR_PIN_INCORRECT) PKCS#11 module error: Unable to login', 0xA0));

        self::assertSame(0xA0, $e->returnValue);
        self::assertStringContainsString('wrong PIN', $e->getMessage());
        self::assertStringContainsString('login failed', $e->getMessage());
    }

    /** DER `ECDSA-Sig-Value` → fixed-width `r ‖ s`, as a token returns it. */
    private static function rawFromDer(string $der, int $width): string
    {
        $offset = 2;
        $raw = '';
        for ($n = 0; $n < 2; $n++) {
            $length = ord($der[$offset + 1]);
            $value = ltrim(substr($der, $offset + 2, $length), "\x00");
            $raw .= str_pad($value, $width, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }
}
