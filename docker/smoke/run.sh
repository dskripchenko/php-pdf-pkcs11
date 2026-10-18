#!/usr/bin/env bash
# Creates a SoftHSM2 token with an RSA and an EC key, certificates for both
# from a throwaway root, signs PDFs through Pkcs11Signer and validates them.
#
#   docker run --rm -v "$PWD":/app php-pdf-pkcs11 bash docker/smoke/run.sh
set -euo pipefail

MODULE="${MODULE:-/usr/lib/softhsm/libsofthsm2.so}"
PIN=123456
WORK="$(mktemp -d)"
OUT="${OUT:-/app/docker/dist/out}"
mkdir -p "$OUT"

export SOFTHSM2_CONF="$WORK/softhsm2.conf"
mkdir -p "$WORK/tokens"
echo "directories.tokendir = $WORK/tokens" > "$SOFTHSM2_CONF"

softhsm2-util --init-token --free --label signing --so-pin 87654321 --pin "$PIN" >/dev/null
p11() { pkcs11-tool --module "$MODULE" --token-label signing --login --pin "$PIN" "$@" >/dev/null; }
p11 --keypairgen --key-type rsa:2048 --id 01 --label rsa
p11 --keypairgen --key-type EC:prime256v1 --id 02 --label ec

# A root, and a certificate for each token key: the CSR comes from a
# throwaway key, the certificate takes the token's public key instead.
cd "$WORK"
openssl req -x509 -newkey rsa:2048 -nodes -keyout root.key -out root.pem -days 30 -subj "/CN=Smoke Root" 2>/dev/null
openssl req -new -newkey rsa:2048 -nodes -keyout throwaway.key -out req.csr -subj "/CN=Smoke" 2>/dev/null
for pair in 01:rsa 02:ec; do
    id="${pair%%:*}"; label="${pair##*:}"
    pkcs11-tool --module "$MODULE" --token-label signing --read-object --type pubkey --id "$id" -o "$label.pub.der" >/dev/null
    openssl pkey -pubin -inform DER -in "$label.pub.der" -out "$label.pub.pem"
    openssl x509 -req -in req.csr -CA root.pem -CAkey root.key -CAcreateserial -days 30 \
        -force_pubkey "$label.pub.pem" -subj "/CN=Smoke ${label^^}/O=Example Ltd" -out "$label.pem" 2>/dev/null
    openssl x509 -in "$label.pem" -outform DER -out "$label.der"
done
# The RSA certificate goes onto the token next to its key; the EC one stays
# outside, to be passed as certificatePem.
p11 --write-object rsa.der --type cert --id 01 --label rsa

cd /app
composer update --no-interaction --no-progress -q
MODULE="$MODULE" PIN="$PIN" ROOT="$WORK/root.pem" EC_CERT="$WORK/ec.pem" OUT="$OUT" php docker/smoke/smoke.php
for f in "$OUT"/*.pdf; do
    printf '%s: ' "$(basename "$f")"
    pdfsig "$f" 2>/dev/null | grep -E 'Signature Validation' | sed 's/^ *- //'
done
