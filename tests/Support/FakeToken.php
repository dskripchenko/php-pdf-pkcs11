<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11\Tests\Support;

use Dskripchenko\PhpPdfPkcs11\Mechanism;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Exception;
use Dskripchenko\PhpPdfPkcs11\Token;

/**
 * A token in memory: private keys and certificates with labels and ids.
 * Signs RSA PKCS#1 v1.5 with OpenSSL; records every call.
 */
final class FakeToken implements Token
{
    /** @var list<array{0: string, 1: mixed, 2: mixed}> */
    public array $calls = [];

    /**
     * @param  list<array{label: string, id: string, keyPem: string}>  $keys
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
            throw new Pkcs11Exception('The fake signs RSA only', 0x70);
        }
        openssl_private_encrypt($data, $signature, $key->keyPem, OPENSSL_PKCS1_PADDING);

        return $signature;
    }
}
