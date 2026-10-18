# Live check on SoftHSM2

A container with PHP 8.3, the php-pkcs11 extension (built from source at the
pinned commit) and SoftHSM2. The smoke test:

1. creates a SoftHSM2 token `signing` with an RSA-2048 key (`id 01`) and an
   EC P-256 key (`id 02`);
2. issues certificates for both public keys from a throwaway root and puts
   the RSA certificate on the token next to its key;
3. signs a PDF three ways — certificate from the token, EC key by id with
   the certificate passed in, `Pkcs11Signer::open()` by module path;
4. validates each PDF with php-pdf's `SignatureValidator` against the root
   and with poppler's `pdfsig`;
5. checks the errors for a wrong PIN, an unknown token and an ambiguous key.

```bash
docker build -t php-pdf-pkcs11 docker
docker run --rm -v "$PWD":/app php-pdf-pkcs11 bash docker/smoke/run.sh
```

The signed PDFs land in `docker/dist/out/` (ignored by git).

Expected output:

```
wrong PIN                  ok: PKCS#11: login failed — CKR_PIN_INCORRECT: wrong PIN — repeated attempts will lock the token
unknown token              ok: PKCS#11: no token "nope"; the module has: "signing" (slot …)
rsa-certificate-on-token   Smoke RSA | Rsa sha256 | valid=true errors=[]
ec-by-id                   Smoke EC | Ec sha256 | valid=true errors=[]
open-by-module-path        Smoke RSA | Rsa sha256 | valid=true errors=[]
ambiguous key              ok: PKCS#11: 2 private keys match, pass keyLabel: or keyId: — label "rsa", id 01; label "ec", id 02
ec-by-id.pdf: Signature Validation: Signature is Valid.
open-by-module-path.pdf: Signature Validation: Signature is Valid.
rsa-certificate-on-token.pdf: Signature Validation: Signature is Valid.
```

For a real device, install its vendor library in the image and point
`MODULE` at it; the token, labels and PIN of the smoke test then need to
match the device.
