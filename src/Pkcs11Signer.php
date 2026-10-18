<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11;

use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Certificate;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\DigestAlgorithm;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\KeyType;
use Dskripchenko\PhpPdf\Pdf\Signature\Signer\Signer;

/**
 * Signs PDFs with a key held by a PKCS#11 token — an HSM, a smart card, a
 * USB token, SoftHSM — through the php-pkcs11 extension. The key never
 * leaves the device: php-pdf computes the digest and the token signs it.
 *
 * ```php
 * $signer = Pkcs11Signer::open('/usr/lib/softhsm/libsofthsm2.so', pin: $pin, token: 'signing', keyLabel: 'pdf');
 * $pdf = PdfSigner::fromBytes($pdf)->sign(new SignatureConfig(signer: $signer));
 * ```
 */
final class Pkcs11Signer implements Signer
{
    private readonly DigestAlgorithm $digest;

    /**
     * @param  object  $key  The private key as {@see Token} found it.
     * @param  list<Certificate>  $chain  Intermediate certificates to embed.
     */
    public function __construct(
        private readonly Token $token,
        private readonly object $key,
        private readonly Certificate $certificate,
        private readonly array $chain = [],
        ?DigestAlgorithm $digest = null,
    ) {
        if (in_array($certificate->keyType(), [KeyType::Gost256, KeyType::Gost512], true)) {
            throw new Pkcs11Exception('PKCS#11: GOST keys are not supported — sign GOST through CryptoPro CSP with dskripchenko/php-pdf-cryptopro');
        }
        $this->digest = $digest ?? DigestAlgorithm::defaultFor($certificate);
    }

    /**
     * Opens the token through the php-pkcs11 extension and finds the key.
     *
     * @param  string  $module  Path to the vendor's PKCS#11 library (`.so`, `.dylib`, `.dll`).
     * @param  ?string  $pin  The user PIN; private keys are visible only after login.
     * @param  ?string  $token  The token label, when the module has several.
     * @param  ?int  $slot  The slot id — an alternative to `token`.
     * @param  ?string  $keyLabel  The private key's CKA_LABEL.
     * @param  ?string  $keyId  The private key's CKA_ID, hex (`01`, `0x01`, `a1:b2`) — as `pkcs11-tool -O` shows it.
     * @param  ?string  $certificatePem  The signing certificate, when the token does not hold it next to the key.
     * @param  list<string>|string  $chainPem  Intermediate certificates — a PEM bundle or a list of them.
     */
    public static function open(
        string $module,
        #[\SensitiveParameter] ?string $pin = null,
        ?string $token = null,
        ?int $slot = null,
        ?string $keyLabel = null,
        ?string $keyId = null,
        ?string $certificatePem = null,
        array|string $chainPem = [],
        ?DigestAlgorithm $digest = null,
    ): self {
        return self::fromToken(new PhpPkcs11Token($module, $pin, $token, $slot), $keyLabel, $keyId, $certificatePem, $chainPem, $digest);
    }

    /**
     * Finds the key on an already opened {@see Token}, and its certificate:
     * the given one, or the token's certificate with the key's CKA_ID.
     *
     * @param  list<string>|string  $chainPem
     */
    public static function fromToken(
        Token $token,
        ?string $keyLabel = null,
        ?string $keyId = null,
        ?string $certificatePem = null,
        array|string $chainPem = [],
        ?DigestAlgorithm $digest = null,
    ): self {
        $id = $keyId !== null ? self::idBytes($keyId) : null;
        $key = $token->findPrivateKey($keyLabel, $id);

        if ($certificatePem !== null) {
            $certificate = Certificate::fromPem($certificatePem);
        } else {
            $linked = $token->keyId($key);
            $der = ($linked !== null && $linked !== '' ? $token->findCertificate(null, $linked) : null)
                ?? ($keyLabel !== null ? $token->findCertificate($keyLabel, null) : null)
                ?? throw new Pkcs11Exception('PKCS#11: the token has no certificate for the key — pass certificatePem:');
            $certificate = Certificate::fromDer($der);
        }

        $chain = [];
        foreach (is_string($chainPem) ? [$chainPem] : $chainPem as $bundle) {
            array_push($chain, ...Certificate::allFromPem($bundle));
        }

        return new self($token, $key, $certificate, $chain, $digest);
    }

    public function certificate(): Certificate
    {
        return $this->certificate;
    }

    public function chain(): array
    {
        return $this->chain;
    }

    public function digestAlgorithm(): DigestAlgorithm
    {
        return $this->digest;
    }

    public function sign(string $data): string
    {
        $digest = $this->digest->digest($data);
        $signature = match ($this->certificate->keyType()) {
            KeyType::Rsa => $this->token->sign($this->key, Mechanism::RSA_PKCS, SignatureFormat::digestInfo($this->digest, $digest)),
            KeyType::Ec => SignatureFormat::ecdsaToDer($this->token->sign($this->key, Mechanism::ECDSA, $digest)),
            default => throw new \LogicException('Unreachable: GOST is refused in the constructor'),
        };

        // A key that does not belong to the certificate would make a PDF
        // that no reader accepts; say so here instead.
        if (openssl_verify($data, $signature, $this->certificate->publicKey(), $this->digest->opensslName()) !== 1) {
            throw new Pkcs11Exception('PKCS#11: the signature does not verify against the certificate — the key on the token does not belong to it');
        }

        return $signature;
    }

    /** `01`, `0x01`, `a1:b2`, `A1 B2` → raw bytes. */
    private static function idBytes(string $hex): string
    {
        $clean = (string) preg_replace('/^0x|[\s:]/i', '', $hex);
        if ($clean === '' || strlen($clean) % 2 !== 0 || ! ctype_xdigit($clean)) {
            throw new \InvalidArgumentException('keyId must be hex, like "01" or "a1:b2"; got "'.$hex.'"');
        }

        return (string) hex2bin($clean);
    }
}
