<?php

declare(strict_types=1);

// Signs a PDF through Pkcs11Signer with each SoftHSM key and validates the
// result with php-pdf. Run by docker/smoke/run.sh.

require __DIR__.'/../../vendor/autoload.php';

use Dskripchenko\PhpPdf\Pdf\Document;
use Dskripchenko\PhpPdf\Pdf\Signature\Appearance\SignatureAppearance;
use Dskripchenko\PhpPdf\Pdf\Signature\PdfSigner;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\SignatureValidator;
use Dskripchenko\PhpPdf\Pdf\Signature\Validation\TrustStore;
use Dskripchenko\PhpPdf\Pdf\SignatureConfig;
use Dskripchenko\PhpPdfPkcs11\PhpPkcs11Token;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Exception;
use Dskripchenko\PhpPdfPkcs11\Pkcs11Signer;

$module = (string) getenv('MODULE');
$pin = (string) getenv('PIN');
$trust = TrustStore::fromPem((string) file_get_contents((string) getenv('ROOT')));
$out = (string) getenv('OUT');

$doc = Document::new();
$doc->addPage();
$pdf = $doc->toBytes();
$failed = false;

// Errors a user meets first, with the explanation they get. The wrong PIN
// goes first: a PKCS#11 login holds for the whole token in the process.
foreach ([
    'wrong PIN' => static fn () => new PhpPkcs11Token($module, '000000', tokenLabel: 'signing'),
    'unknown token' => static fn () => new PhpPkcs11Token($module, $pin, tokenLabel: 'nope'),
] as $name => $make) {
    try {
        $make();
        printf("%-26s FAILED: no error\n", $name);
        $failed = true;
    } catch (Pkcs11Exception $e) {
        printf("%-26s ok: %s\n", $name, $e->getMessage());
    }
}

// One session for all the signatures, as a worker would keep it.
$token = new PhpPkcs11Token($module, $pin, tokenLabel: 'signing');
$cases = [
    'rsa-certificate-on-token' => static fn () => Pkcs11Signer::fromToken($token, keyLabel: 'rsa'),
    'ec-by-id' => static fn () => Pkcs11Signer::fromToken($token, keyId: '02', certificatePem: (string) file_get_contents((string) getenv('EC_CERT'))),
    'open-by-module-path' => static fn () => Pkcs11Signer::open($module, pin: $pin, token: 'signing', keyLabel: 'rsa'),
];

foreach ($cases as $name => $make) {
    try {
        $signer = $make();
        $signed = PdfSigner::fromBytes($pdf)
            ->addField('signature', page: 0, x: 60, y: 60, width: 250, height: 75, appearance: SignatureAppearance::simple())
            ->sign(new SignatureConfig(signer: $signer, reason: 'smoke'));
        file_put_contents("$out/$name.pdf", $signed);

        $s = SignatureValidator::fromBytes($signed)->validate($trust)->signatures[0];
        printf("%-26s %s | %s %s | valid=%s errors=%s\n", $name, $signer->certificate()->subjectCommonName(),
            $signer->certificate()->keyType()->name, $signer->digestAlgorithm()->value,
            var_export($s->isValid(), true), json_encode($s->errors));
        $failed = $failed || ! $s->isValid();
    } catch (Throwable $e) {
        printf("%-26s FAILED %s: %s\n", $name, $e::class, $e->getMessage());
        $failed = true;
    }
}

try {
    Pkcs11Signer::fromToken($token);
    echo "ambiguous key              FAILED: no error\n";
    $failed = true;
} catch (Pkcs11Exception $e) {
    printf("%-26s ok: %s\n", 'ambiguous key', $e->getMessage());
}

exit($failed ? 1 : 0);
