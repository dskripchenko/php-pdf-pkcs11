<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11;

/**
 * The PKCS#11 mechanisms the signer uses. The default ones take a digest
 * computed outside the token, so any token that signs at all can do it —
 * no need for its own hash support — and the document never has to be
 * streamed through the device.
 */
final class Mechanism
{
    /** RSA PKCS#1 v1.5 over a DER `DigestInfo`. */
    public const RSA_PKCS = 0x00000001;

    /** ECDSA over a digest; the result is `r ‖ s`. */
    public const ECDSA = 0x00001041;

    /**
     * GOST R 34.10 over a 32-byte digest (PKCS#11 v2.40, 2.45.7): with a
     * GOST R 34.10-2012 256-bit key it signs a Streebog-256 digest. The
     * result is `s ‖ r`, big-endian.
     */
    public const GOSTR3410 = 0x00001201;

    /**
     * TC26 extensions (vendor range `CKM_VENDOR_DEFINED | 0x54321000`), as
     * Rutoken, JaCarta and OpenSC define them.
     */
    private const TC26 = 0xD4321000;

    /** GOST R 34.10-2012 512-bit over a 64-byte digest; `s ‖ r`. */
    public const GOSTR3410_512 = self::TC26 | 0x006;

    /** GOST R 34.10-2012 256-bit; the token hashes the data with Streebog-256. */
    public const GOSTR3410_WITH_GOSTR3411_12_256 = self::TC26 | 0x008;

    /** GOST R 34.10-2012 512-bit; the token hashes the data with Streebog-512. */
    public const GOSTR3410_WITH_GOSTR3411_12_512 = self::TC26 | 0x009;

    private function __construct() {}
}
