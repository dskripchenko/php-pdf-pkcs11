<?php

declare(strict_types=1);

namespace Dskripchenko\PhpPdfPkcs11;

/**
 * {@see Token} on top of the php-pkcs11 extension
 * (https://github.com/gamringer/php-pkcs11): loads the vendor's module,
 * picks the token, opens a session and logs in.
 *
 * Keep one instance for the life of a worker: loading a module and logging
 * in costs more than a signature.
 */
final class PhpPkcs11Token implements Token
{
    private const CKF_TOKEN_INITIALIZED = 0x00000400;

    private const CKR_USER_ALREADY_LOGGED_IN = 0x00000100;

    /**
     * Loaded modules by path. A module may be initialized once per process,
     * and dropping a second instance finalizes it for every session, so
     * all tokens of one module share the instance.
     *
     * @var array<string, \Pkcs11\Module>
     */
    private static array $modules = [];

    private readonly \Pkcs11\Module $module;

    private readonly \Pkcs11\Session $session;

    /**
     * @param  string  $modulePath  The vendor's PKCS#11 library.
     * @param  ?string  $pin  The user PIN; null for a token without login.
     * @param  ?string  $tokenLabel  Which token, when the module has several.
     * @param  ?int  $slot  Which slot — an alternative to the label.
     */
    public function __construct(
        string $modulePath,
        #[\SensitiveParameter] ?string $pin = null,
        ?string $tokenLabel = null,
        ?int $slot = null,
    ) {
        if (! extension_loaded('pkcs11')) {
            throw new Pkcs11Exception('PKCS#11: the php-pkcs11 extension is not loaded — build it from https://github.com/gamringer/php-pkcs11 (docker/Dockerfile here shows how)');
        }
        $this->module = self::module($modulePath);
        $slot ??= $this->pickSlot($tokenLabel);
        try {
            $this->session = $this->module->openSession($slot);
        } catch (\Throwable $e) {
            throw Pkcs11Exception::fromModule('opening a session on slot '.$slot, $e);
        }
        if ($pin !== null) {
            try {
                $this->session->login(\Pkcs11\CKU_USER, $pin);
            } catch (\Throwable $e) {
                if ($e->getCode() !== self::CKR_USER_ALREADY_LOGGED_IN) {
                    throw Pkcs11Exception::fromModule('login', $e);
                }
            }
        }
    }

    private static function module(string $path): \Pkcs11\Module
    {
        $key = realpath($path) ?: $path;
        if (! isset(self::$modules[$key])) {
            try {
                self::$modules[$key] = new \Pkcs11\Module($path);
            } catch (\Throwable $e) {
                throw Pkcs11Exception::fromModule('loading the module '.$path, $e);
            }
        }

        return self::$modules[$key];
    }

    public function findPrivateKey(?string $label, ?string $id): object
    {
        $keys = $this->find(\Pkcs11\CKO_PRIVATE_KEY, $label, $id);
        if (count($keys) === 1) {
            return $keys[0];
        }
        $wanted = self::describe($label, $id);
        if ($keys === []) {
            throw new Pkcs11Exception('PKCS#11: no private key '.($wanted !== '' ? $wanted.' ' : '').'on the token — private keys are visible only after login: check the PIN');
        }
        $found = array_map(fn (object $k): string => self::describe(...$this->labelAndId($k)), $keys);

        throw new Pkcs11Exception('PKCS#11: '.count($keys).' private keys match, pass keyLabel: or keyId: — '.implode('; ', $found));
    }

    public function keyId(object $key): ?string
    {
        return $this->labelAndId($key)[1];
    }

    public function findCertificate(?string $label, ?string $id): ?string
    {
        foreach ($this->find(\Pkcs11\CKO_CERTIFICATE, $label, $id) as $certificate) {
            $value = $certificate->getAttributeValue([\Pkcs11\CKA_VALUE])[\Pkcs11\CKA_VALUE] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function sign(object $key, int $mechanism, string $data): string
    {
        if (! $key instanceof \Pkcs11\Key) {
            throw new \InvalidArgumentException('Not a key of this token');
        }
        try {
            return $key->sign(new \Pkcs11\Mechanism($mechanism), $data);
        } catch (\Throwable $e) {
            throw Pkcs11Exception::fromModule('signing', $e);
        }
    }

    private function pickSlot(?string $label): int
    {
        try {
            $tokens = [];
            foreach ($this->module->getSlotList() as $slot) {
                $info = $this->module->getTokenInfo($slot);
                if (((int) $info['flags'] & self::CKF_TOKEN_INITIALIZED) !== 0) {
                    $tokens[$slot] = rtrim((string) $info['label']);
                }
            }
        } catch (\Throwable $e) {
            throw Pkcs11Exception::fromModule('listing the tokens', $e);
        }

        if ($label !== null) {
            $slot = array_search($label, $tokens, true);
            if ($slot === false) {
                throw new Pkcs11Exception('PKCS#11: no token "'.$label.'"; the module has: '.self::list($tokens));
            }

            return (int) $slot;
        }
        if (count($tokens) === 1) {
            return (int) array_key_first($tokens);
        }

        throw new Pkcs11Exception($tokens === []
            ? 'PKCS#11: the module has no initialized token'
            : 'PKCS#11: the module has several tokens, pass token: or slot: — '.self::list($tokens));
    }

    /**
     * @return list<\Pkcs11\Key|\Pkcs11\P11Object>
     */
    private function find(int $class, ?string $label, ?string $id): array
    {
        $template = [\Pkcs11\CKA_CLASS => $class];
        if ($label !== null) {
            $template[\Pkcs11\CKA_LABEL] = $label;
        }
        if ($id !== null) {
            $template[\Pkcs11\CKA_ID] = $id;
        }
        try {
            return array_values($this->session->findObjects($template));
        } catch (\Throwable $e) {
            throw Pkcs11Exception::fromModule('searching the token', $e);
        }
    }

    /** @return array{0: ?string, 1: ?string} */
    private function labelAndId(object $object): array
    {
        if (! $object instanceof \Pkcs11\P11Object) {
            return [null, null];
        }
        $attributes = $object->getAttributeValue([\Pkcs11\CKA_LABEL, \Pkcs11\CKA_ID]);
        $label = $attributes[\Pkcs11\CKA_LABEL] ?? null;
        $id = $attributes[\Pkcs11\CKA_ID] ?? null;

        return [is_string($label) ? $label : null, is_string($id) ? $id : null];
    }

    private static function describe(?string $label, ?string $id): string
    {
        $parts = [];
        if ($label !== null) {
            $parts[] = 'label "'.$label.'"';
        }
        if ($id !== null && $id !== '') {
            $parts[] = 'id '.bin2hex($id);
        }

        return implode(', ', $parts);
    }

    /** @param  array<int, string>  $tokens */
    private static function list(array $tokens): string
    {
        $items = [];
        foreach ($tokens as $slot => $label) {
            $items[] = sprintf('"%s" (slot %d)', $label, $slot);
        }

        return implode(', ', $items);
    }
}
