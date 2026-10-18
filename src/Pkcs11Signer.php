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
 * RSA, ECDSA and GOST R 34.10-2012 (256 and 512, on TC26-compliant tokens
 * such as Rutoken and JaCarta; GOST needs OpenSSL's gost engine in PHP).
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
     * @param  bool  $hashOnToken  GOST only: let the token hash the data
     *                             (`CKM_GOSTR3410_WITH_GOSTR3411_12_*`) instead of
     *                             signing a digest computed in PHP.
     */
    public function __construct(
        private readonly Token $token,
        private readonly object $key,
        private readonly Certificate $certificate,
        private readonly array $chain = [],
        ?DigestAlgorithm $digest = null,
        private readonly bool $hashOnToken = false,
    ) {
        $this->digest = $digest ?? DigestAlgorithm::defaultFor($certificate);
        $gost = self::isGost($certificate->keyType());
        if ($gost && $this->digest !== DigestAlgorithm::defaultFor($certificate)) {
            throw new \InvalidArgumentException('A GOST R 34.10-2012 '.$certificate->keyBits().'-bit key signs with the '.DigestAlgorithm::defaultFor($certificate)->value.' digest only; got '.$this->digest->value);
        }
        if (! $gost && $this->digest->isGost()) {
            throw new \InvalidArgumentException('The '.$this->digest->value.' digest needs a GOST key');
        }
        if ($hashOnToken && ! $gost) {
            throw new \InvalidArgumentException('hashOnToken applies to GOST keys only');
        }
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
     * @param  bool  $hashOnToken  GOST only: the token hashes the data itself.
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
        bool $hashOnToken = false,
    ): self {
        return self::fromToken(new PhpPkcs11Token($module, $pin, $token, $slot), $keyLabel, $keyId, $certificatePem, $chainPem, $digest, $hashOnToken);
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
        bool $hashOnToken = false,
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

        return new self($token, $key, $certificate, $chain, $digest, $hashOnToken);
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
        $type = $this->certificate->keyType();
        if (self::isGost($type)) {
            return $this->signGost($data);
        }
        $digest = $this->digest->digest($data);
        $signature = $type === KeyType::Rsa
            ? $this->token->sign($this->key, Mechanism::RSA_PKCS, SignatureFormat::digestInfo($this->digest, $digest))
            : SignatureFormat::ecdsaToDer($this->token->sign($this->key, Mechanism::ECDSA, $digest));

        // A key that does not belong to the certificate would make a PDF
        // that no reader accepts; say so here instead.
        if (! $this->verifies($data, $signature)) {
            throw new Pkcs11Exception('PKCS#11: the signature does not verify against the certificate — the key on the token does not belong to it');
        }

        return $signature;
    }

    /**
     * Tokens disagree on the byte order of GOST values, so the encoding is
     * chosen by verification: the standard one first, then the variants.
     */
    private function signGost(string $data): string
    {
        if (! in_array($this->digest->opensslName(), openssl_get_md_methods(), true)) {
            throw new Pkcs11Exception('PKCS#11: GOST signing needs the OpenSSL gost engine loaded into PHP (openssl.cnf engines section) — php-pdf hashes and verifies with it');
        }
        $bits = $this->certificate->keyBits();
        $raw = $this->hashOnToken
            ? $this->token->sign($this->key, $bits === 512 ? Mechanism::GOSTR3410_WITH_GOSTR3411_12_512 : Mechanism::GOSTR3410_WITH_GOSTR3411_12_256, $data)
            : $this->token->sign($this->key, $bits === 512 ? Mechanism::GOSTR3410_512 : Mechanism::GOSTR3410, $this->digest->digest($data));

        foreach (SignatureFormat::gostCandidates($raw, $bits) as $signature) {
            if ($this->verifies($data, $signature)) {
                return $signature;
            }
        }

        throw new Pkcs11Exception('PKCS#11: the GOST signature does not verify against the certificate in any known byte order — the key on the token does not belong to it');
    }

    private function verifies(string $data, string $signature): bool
    {
        return openssl_verify($data, $signature, $this->certificate->publicKey(), $this->digest->opensslName()) === 1;
    }

    private static function isGost(KeyType $type): bool
    {
        return $type === KeyType::Gost256 || $type === KeyType::Gost512;
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
