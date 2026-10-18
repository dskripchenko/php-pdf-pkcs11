# dskripchenko/php-pdf-pkcs11

Sign PDFs with [`dskripchenko/php-pdf`](https://github.com/dskripchenko/php-pdf)
using a key held by a **PKCS#11 token**: a network or PCIe HSM (Thales Luna,
Entrust nShield, Utimaco, AWS CloudHSM), a smart card or a national eID card,
a USB token (YubiKey, SafeNet eToken, Rutoken), or SoftHSM. The key never
leaves the device: php-pdf computes the digest, the token signs it, and the
result is a PAdES signature with RSA or ECDSA.

> 🌐 **English** · [Deutsch](docs/de/README.md) · [Русский](docs/ru/README.md) · [中文](docs/zh/README.md)

[![Tests](https://img.shields.io/github/actions/workflow/status/dskripchenko/php-pdf-pkcs11/tests.yml?branch=main&label=tests&logo=github)](https://github.com/dskripchenko/php-pdf-pkcs11/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/dskripchenko/php-pdf-pkcs11.svg)](https://packagist.org/packages/dskripchenko/php-pdf-pkcs11)
[![License: MIT](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.2-blue.svg)](composer.json)

---

## Usage

```php
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Signer;

$signer = Pkcs11Signer::open(
    '/usr/lib/softhsm/libsofthsm2.so',   // the vendor's PKCS#11 library
    pin: $pin,
    token: 'signing',                    // token label, when there are several
    keyLabel: 'pdf',                     // or keyId: '01'
);

$signed = PdfSigner::fromBytes($pdf)
    ->addField('signature', page: 0, x: 60, y: 450, width: 250, height: 75, appearance: $stamp)
    ->sign(new SignatureConfig(signer: $signer, timestampAuthority: $tsa));
```

The signer works wherever php-pdf takes one: `PdfSigner::sign()` for an
existing PDF, `Pdf\Document::sign()` and a laid-out document's `signature:`.

The certificate is read from the token — the one with the key's `CKA_ID`,
as tokens store them — unless you pass `certificatePem:`. Before handing the
signature to php-pdf the signer verifies it against the certificate, so a key
that does not belong to the certificate fails with a clear error instead of
producing a PDF no reader accepts.

## Options

| Argument | Default | Meaning |
|---|---|---|
| `module` | — | Path to the vendor's PKCS#11 library: `libsofthsm2.so`, `libeTPkcs11.so`, `opensc-pkcs11.so`, `libCryptoki2_64.so`… |
| `pin` | `null` | The user PIN. Private keys are visible only after login |
| `token` | the only token | The token label, when the module has several |
| `slot` | — | The slot id, as an alternative to `token` |
| `keyLabel` | — | The private key's `CKA_LABEL` |
| `keyId` | — | The private key's `CKA_ID`, hex: `01`, `0x01`, `a1:b2` (as `pkcs11-tool -O` shows it). With neither label nor id, the only private key |
| `certificatePem` | from the token | The signing certificate, when the token does not hold it |
| `chainPem` | `[]` | Intermediate certificates to embed — a PEM bundle or a list |
| `digest` | by the key | SHA-256 for RSA and P-256, SHA-384 for P-384, SHA-512 for P-521 |

The digest is computed in PHP and the token signs it with `CKM_RSA_PKCS`
(over a `DigestInfo`) or `CKM_ECDSA`. Every token that signs at all supports
these two, the document is never streamed through the device, and the
token's own SHA-2 support does not matter.

## Long-running workers

Loading the module and logging in cost more than a signature. In a queue
worker, Octane or RoadRunner, open the token once and reuse it:

```php
$token = new PhpPkcs11Token($module, $pin, tokenLabel: 'signing');

// per document
$signer = Pkcs11Signer::fromToken($token, keyLabel: 'pdf');
```

PKCS#11 allows one initialization of a module per process, so all
`PhpPkcs11Token` instances share it. A login also holds for the whole token
within the process: once one instance has logged in, another one for the
same token works with any PIN. Keep the PIN check where you decide who may
sign, not in the token.

## Errors

Failures are `Pkcs11Exception`; `$e->returnValue` is the PKCS#11 `CKR_*`
code when the module reported one, and the message explains the common
ones: a wrong, expired or locked PIN, no token in the slot, a removed
device, a key not allowed to sign, a mechanism the token does not support.
Ambiguous lookups list what they found — "2 private keys match, pass
keyLabel: or keyId: — label "rsa", id 01; label "ec", id 02".

## Requirements

- **PHP 8.2+** with `dskripchenko/php-pdf` 1.19 or later (Composer installs it).
- **The php-pkcs11 extension** —
  [gamringer/php-pkcs11](https://github.com/gamringer/php-pkcs11), built
  for your PHP version (`phpize && ./configure && make install`).
  `docker/Dockerfile` here does it on Ubuntu 24.04 and pins the commit this
  package is verified with.
- **The vendor's PKCS#11 library** for the device, and access to it for the
  user PHP runs as.
- **RSA or EC keys.** GOST keys are refused: sign GOST through CryptoPro CSP
  with [dskripchenko/php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro).

## Trying it on SoftHSM

`docker/` builds a container with PHP 8.3, php-pkcs11 and SoftHSM2, creates
a token with an RSA and an EC key and certificates from a throwaway root,
signs PDFs and validates them with php-pdf and poppler's `pdfsig` — see
[docker/README.md](docker/README.md). CI runs it on every change.

## Testing without a token

`Pkcs11Signer` talks to the device through the small `Token` interface;
`PhpPkcs11Token` is its implementation on top of the extension. Pass your own
`Token` to `Pkcs11Signer::fromToken()` to run the code without a device —
this package's tests do so with an in-memory token backed by OpenSSL.

## The php-pdf family

| Package | What it adds |
|---|---|
| [php-pdf](https://github.com/dskripchenko/php-pdf) | The core: HTML with CSS or a builder to PDF, read, merge, fill forms, sign and validate. |
| [laravel-php-pdf](https://github.com/dskripchenko/laravel-php-pdf) | Laravel bridge: a Blade view straight to PDF, `response()->pdf()`, signing from config. |
| [php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation) | Liberation Sans, Serif and Mono, metric-compatible with Arial, Times New Roman and Courier New (OFL, kept out of the MIT core). |
| [php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro) | Signs with a key in CryptoPro CSP on the server: GOST R 34.10-2012, Russian qualified signatures. |
| **php-pdf-pkcs11** (this package) | Signs with a key on an HSM, a smart card or a USB token, through PKCS#11. |
| [php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) | Signs with a key in AWS KMS, Google Cloud KMS or Azure Key Vault. |

## License

MIT — see [LICENSE](LICENSE).
