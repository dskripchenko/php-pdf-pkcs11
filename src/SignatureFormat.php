<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;

/**
 * Converts between what a token takes and returns and what CMS carries.
 *
 * @internal
 */
final class SignatureFormat
{
    /** DER `DigestInfo` prefixes (RFC 8017, 9.2) — the digest follows. */
    private const DIGEST_INFO_PREFIX = [
        'sha256' => '3031300d060960864801650304020105000420',
        'sha384' => '3041300d060960864801650304020205000430',
        'sha512' => '3051300d060960864801650304020305000440',
    ];

    private function __construct() {}

    /** The DER `DigestInfo` RSA PKCS#1 v1.5 signs: the algorithm and the digest. */
    public static function digestInfo(DigestAlgorithm $algorithm, string $digest): string
    {
        $prefix = self::DIGEST_INFO_PREFIX[$algorithm->value]
            ?? throw new Pkcs11Exception('PKCS#11: RSA signing does not support the '.$algorithm->value.' digest');

        return (string) hex2bin($prefix).$digest;
    }

    /** PKCS#11's ECDSA result, `r ‖ s`, as the DER `ECDSA-Sig-Value` CMS carries. */
    public static function ecdsaToDer(string $raw): string
    {
        $length = strlen($raw);
        if ($length === 0 || $length % 2 !== 0) {
            throw new Pkcs11Exception('PKCS#11: the token returned an ECDSA signature of odd length '.$length);
        }
        $half = intdiv($length, 2);
        $body = self::integer(substr($raw, 0, $half)).self::integer(substr($raw, $half));

        return "\x30".self::length(strlen($body)).$body;
    }

    private static function integer(string $magnitude): string
    {
        $magnitude = ltrim($magnitude, "\x00");
        if ($magnitude === '' || (ord($magnitude[0]) & 0x80) !== 0) {
            $magnitude = "\x00".$magnitude;
        }

        return "\x02".self::length(strlen($magnitude)).$magnitude;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
