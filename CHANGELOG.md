# Changelog

All notable changes to `dskripchenko/php-pdf-pkcs11` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/);
versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- GOST R 34.10-2012 keys (256 and 512 bits) on TC26-compliant PKCS#11
  tokens — Rutoken ECP, JaCarta GOST and the like. The digest (Streebog,
  computed in PHP) is signed with `CKM_GOSTR3410` (`0x1201`) or
  `CKM_GOSTR3410_512` (`0xD4321006`); `hashOnToken: true` lets the token
  hash instead, with `CKM_GOSTR3410_WITH_GOSTR3411_12_256/512`
  (`0xD4321008`/`0xD4321009`). The value (`s ‖ r`, big-endian per PKCS#11
  v2.40 and RFC 4491) is checked against the certificate, with the halves
  swapped and byte-reversed variants tried for tokens that encode it
  differently. Needs OpenSSL's gost engine in PHP. Verified with an
  emulated token; not yet on a physical Rutoken or JaCarta (the SoftHSM2
  in the docker image has no GOST).
- `docker/gost/` — PHP with OpenSSL's gost engine; CI runs the GOST tests
  (an emulated token signing with real GOST keys) in it.

### Changed
- GOST certificates are no longer refused by `Pkcs11Signer`. A GOST key
  accepts only its own Streebog digest; a GOST digest needs a GOST key.

## [1.0.0] — 2026-10-18

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
