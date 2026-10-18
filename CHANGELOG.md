# Changelog

All notable changes to `dskripchenko/php-pdf-pkcs11` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `Pkcs11Signer` — a php-pdf `Signer` for an RSA or EC key held by a PKCS#11
  token (HSM, smart card, USB token, SoftHSM) through the php-pkcs11
  extension: token by label or slot, key by label or `CKA_ID`, the
  certificate from the token by the key's id or passed in, the chain,
  `CKM_RSA_PKCS` over a `DigestInfo` and `CKM_ECDSA` over the digest, the
  signature verified against the certificate before it is embedded.
- `PhpPkcs11Token` — the extension behind the `Token` interface; one module
  instance per process.
- `Pkcs11Exception` with the `CKR_*` code and plain explanations of the
  common failures.
- `docker/` — PHP 8.3, php-pkcs11 and SoftHSM2, and a smoke test that signs
  and validates PDFs with RSA and ECDSA keys; run in CI.
