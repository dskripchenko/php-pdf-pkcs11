<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11;

/**
 * The few PKCS#11 operations the signer needs. {@see PhpPkcs11Token} does
 * them through the php-pkcs11 extension; tests put a fake in its place.
 *
 * Keys are handed around as opaque handles: whatever the implementation
 * needs to sign with the key later.
 */
interface Token
{
    /**
     * The private key with this label and/or CKA_ID (raw bytes). With
     * neither, the only private key on the token.
     *
     * @throws Pkcs11Exception when there is no such key, or more than one.
     */
    public function findPrivateKey(?string $label, ?string $id): object;

    /** The key's CKA_ID (raw bytes), or null when it has none. */
    public function keyId(object $key): ?string;

    /**
     * The certificate (DER) with this label and/or CKA_ID, or null when the
     * token holds none.
     */
    public function findCertificate(?string $label, ?string $id): ?string;

    /** Signs `$data` with the key by one of the {@see Mechanism} constants. */
    public function sign(object $key, int $mechanism, string $data): string;
}
