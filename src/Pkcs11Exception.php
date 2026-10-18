<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11;

/**
 * A failure talking to the token. `$returnValue` is the PKCS#11 `CKR_*`
 * code when the module reported one.
 */
final class Pkcs11Exception extends \RuntimeException
{
    private const EXPLAINED = [
        0x00000005 => 'CKR_GENERAL_ERROR: the module failed — often a key that does not allow this mechanism',
        0x00000030 => 'CKR_DEVICE_ERROR: the device reported an error',
        0x00000032 => 'CKR_DEVICE_REMOVED: the token was removed',
        0x00000063 => 'CKR_KEY_TYPE_INCONSISTENT: the key cannot be used with this mechanism',
        0x00000068 => 'CKR_KEY_FUNCTION_NOT_PERMITTED: the key is not allowed to sign (CKA_SIGN is false)',
        0x00000070 => 'CKR_MECHANISM_INVALID: the token does not support this mechanism',
        0x000000A0 => 'CKR_PIN_INCORRECT: wrong PIN — repeated attempts will lock the token',
        0x000000A3 => 'CKR_PIN_EXPIRED: the PIN has expired and must be changed',
        0x000000A4 => 'CKR_PIN_LOCKED: the PIN is locked after too many wrong attempts',
        0x000000B3 => 'CKR_SESSION_HANDLE_INVALID: the session was closed — the token was reset or reinserted',
        0x000000E0 => 'CKR_TOKEN_NOT_PRESENT: no token in the slot',
        0x00000101 => 'CKR_USER_NOT_LOGGED_IN: the key needs a login — pass the PIN',
    ];

    public function __construct(string $message, public readonly ?int $returnValue = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $returnValue ?? 0, $previous);
    }

    /** Wraps an error of the module with a plain explanation of its code. */
    public static function fromModule(string $action, \Throwable $e): self
    {
        $code = $e->getCode();
        $explained = self::EXPLAINED[$code] ?? null;

        return new self(
            'PKCS#11: '.$action.' failed — '.($explained ?? $e->getMessage()),
            $code !== 0 ? $code : null,
            $e,
        );
    }
}
