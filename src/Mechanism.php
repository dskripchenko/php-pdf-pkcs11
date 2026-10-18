<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11;

/**
 * The PKCS#11 mechanisms the signer uses. Both take a digest computed
 * outside the token, so any token that signs at all can do it — no need
 * for its own SHA-2 support — and the document never has to be streamed
 * through the device.
 */
final class Mechanism
{
    /** RSA PKCS#1 v1.5 over a DER `DigestInfo`. */
    public const RSA_PKCS = 0x00000001;

    /** ECDSA over a digest; the result is `r ‖ s`. */
    public const ECDSA = 0x00001041;

    private function __construct() {}
}
