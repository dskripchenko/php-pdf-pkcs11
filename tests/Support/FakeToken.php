<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11\Tests\Support;

use Dskripchenko\PhpPdfPkcs11\Mechanism;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Exception;
use Dskripchenko\PhpPdfPkcs11\Token;

/**
 * A token in memory: private keys and certificates with labels and ids.
 * Signs RSA PKCS#1 v1.5 with OpenSSL, and GOST R 34.10-2012 with the openssl
 * CLI and its gost engine; records every call.
 *
 * A GOST key's `gostOrder` makes the fake return its value the way a given
 * vendor might: `s-r` (PKCS#11 v2.40, the default), `r-s`, `reversed` (the
 * whole value little-endian) or `halves-reversed`.
 */
final class FakeToken implements Token
{
    /** @var list<array{0: string, 1: mixed, 2: mixed}> */
    public array $calls = [];

    /** What the last GOST signing returned, as the token encoded it. */
    public ?string $lastGost = null;

    /**
     * @param  list<array{label: string, id: string, keyPem: string, gostOrder?: string}>  $keys
     * @param  list<array{label: string, id: string, der: string}>  $certificates
     */
    public function __construct(
        private readonly array $keys,
        private readonly array $certificates = [],
    ) {}

    public function findPrivateKey(?string $label, ?string $id): object
    {
        $this->calls[] = ['findPrivateKey', $label, $id];
        $found = array_values(array_filter($this->keys, static fn (array $k): bool => ($label === null || $k['label'] === $label) && ($id === null || $k['id'] === $id)));
        if (count($found) !== 1) {
            throw new Pkcs11Exception(count($found).' private keys match');
        }

        return (object) $found[0];
    }

    public function keyId(object $key): ?string
    {
        return $key->id;
    }

    public function findCertificate(?string $label, ?string $id): ?string
    {
        $this->calls[] = ['findCertificate', $label, $id];
        foreach ($this->certificates as $c) {
            if (($label === null || $c['label'] === $label) && ($id === null || $c['id'] === $id)) {
                return $c['der'];
            }
        }

        return null;
    }

    public function sign(object $key, int $mechanism, string $data): string
    {
        $this->calls[] = ['sign', $mechanism, $data];
        if ($mechanism !== Mechanism::RSA_PKCS) {
            return $this->signGost($key, $mechanism, $data);
        }
        openssl_private_encrypt($data, $signature, $key->keyPem, OPENSSL_PKCS1_PADDING);

        return $signature;
    }

    private function signGost(object $key, int $mechanism, string $data): string
    {
        [$length, $hash] = match ($mechanism) {
            Mechanism::GOSTR3410 => [32, null],
            Mechanism::GOSTR3410_512 => [64, null],
            Mechanism::GOSTR3410_WITH_GOSTR3411_12_256 => [32, 'md_gost12_256'],
            Mechanism::GOSTR3410_WITH_GOSTR3411_12_512 => [64, 'md_gost12_512'],
            default => throw new Pkcs11Exception('The fake does not support mechanism '.dechex($mechanism), 0x70),
        };
        $digest = $hash !== null ? (string) openssl_digest($data, $hash, true) : $data;
        if (strlen($digest) !== $length) {
            throw new Pkcs11Exception('Data length '.strlen($digest).' is invalid for the mechanism', 0x21);
        }

        $dir = sys_get_temp_dir().'/pkcs11-fake-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            file_put_contents("$dir/key.pem", $key->keyPem);
            file_put_contents("$dir/digest", $digest);
            // pkeyutl signs the input as a prehashed digest: the gost engine's
            // raw `s ‖ r`, big-endian.
            exec(sprintf(
                'openssl pkeyutl -sign -inkey %s -in %s -out %s 2>&1',
                escapeshellarg("$dir/key.pem"),
                escapeshellarg("$dir/digest"),
                escapeshellarg("$dir/sig"),
            ), $out, $code);
            if ($code !== 0) {
                throw new Pkcs11Exception('openssl pkeyutl failed: '.implode("\n", $out), 0x05);
            }
            $sr = (string) file_get_contents("$dir/sig");
        } finally {
            array_map('unlink', (array) glob("$dir/*"));
            rmdir($dir);
        }

        $half = intdiv(strlen($sr), 2);
        [$s, $r] = [substr($sr, 0, $half), substr($sr, $half)];

        return $this->lastGost = match ($key->gostOrder ?? 's-r') {
            's-r' => $sr,
            'r-s' => $r.$s,
            'reversed' => strrev($sr),
            'halves-reversed' => strrev($s).strrev($r),
            default => throw new \LogicException('gostOrder'),
        };
    }
}
