<?php

// Signatures of the php-pkcs11 extension that this package calls, for static
// analysis without the extension installed.

namespace Pkcs11;

const CKU_USER = 1;
const CKO_CERTIFICATE = 1;
const CKO_PRIVATE_KEY = 3;
const CKA_CLASS = 0;
const CKA_LABEL = 3;
const CKA_VALUE = 17;
const CKA_ID = 258;

class Exception extends \Exception {}

class Module
{
    public function __construct(string $modulePath) {}

    /** @return list<int> */
    public function getSlotList(): array {}

    /** @return array{label: string, flags: int} */
    public function getTokenInfo(int $slotId): array {}

    public function openSession(int $slotid, int $flags = 0, ?string $application = null, ?callable $notify = null): Session {}
}

class Session
{
    public function login(int $loginType, string $pin): void {}

    /**
     * @param  array<int, mixed>  $template
     * @return array<int, Key|P11Object>
     */
    public function findObjects(array $template): array {}
}

class P11Object
{
    /**
     * @param  list<int>  $attributeIds
     * @return array<int, mixed>
     */
    public function getAttributeValue(array $attributeIds): array {}
}

class Key extends P11Object
{
    public function sign(Mechanism $mechanism, string $data): string {}
}

class Mechanism
{
    public function __construct(int $mechanismId, mixed $mechanismArgument = null) {}
}
