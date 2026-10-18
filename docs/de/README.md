# dskripchenko/php-pdf-pkcs11

PDFs mit [`dskripchenko/php-pdf`](https://github.com/dskripchenko/php-pdf)
signieren — mit einem Schlüssel auf einem **PKCS#11-Token**: einem Netzwerk-
oder PCIe-HSM (Thales Luna, Entrust nShield, Utimaco, AWS CloudHSM), einer
Smartcard oder einem nationalen eID-Ausweis, einem USB-Token (YubiKey,
SafeNet eToken, Rutoken) oder SoftHSM. Der Schlüssel verlässt das Gerät nie:
php-pdf berechnet den Hash, der Token signiert ihn, und das Ergebnis ist eine
PAdES-Signatur mit RSA oder ECDSA.

> 🌐 [English](../../README.md) · **Deutsch** · [Русский](../ru/README.md) · [中文](../zh/README.md)

## Verwendung

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

Der Signierer funktioniert überall, wo php-pdf einen annimmt:
`PdfSigner::sign()` für eine bestehende PDF, `Pdf\Document::sign()` und
`signature:` eines gesetzten Dokuments.

Das Zertifikat wird vom Token gelesen — dasjenige mit der `CKA_ID` des
Schlüssels, wie Tokens sie ablegen —, sofern Sie nicht `certificatePem:`
übergeben. Bevor der Signierer die Signatur an php-pdf weitergibt, prüft er
sie gegen das Zertifikat; ein Schlüssel, der nicht zum Zertifikat gehört,
führt so zu einem klaren Fehler statt zu einer PDF, die kein Reader
akzeptiert.

## Optionen

| Argument | Standard | Bedeutung |
|---|---|---|
| `module` | — | Pfad zur PKCS#11-Bibliothek des Herstellers: `libsofthsm2.so`, `libeTPkcs11.so`, `opensc-pkcs11.so`, `libCryptoki2_64.so`… |
| `pin` | `null` | Die Benutzer-PIN. Private Schlüssel sind erst nach der Anmeldung sichtbar |
| `token` | der einzige Token | Das Token-Label, wenn das Modul mehrere hat |
| `slot` | — | Die Slot-ID, als Alternative zu `token` |
| `keyLabel` | — | `CKA_LABEL` des privaten Schlüssels |
| `keyId` | — | `CKA_ID` des privaten Schlüssels, hex: `01`, `0x01`, `a1:b2` (wie `pkcs11-tool -O` sie anzeigt). Ohne Label und ID der einzige private Schlüssel |
| `certificatePem` | vom Token | Das Signaturzertifikat, wenn der Token es nicht enthält |
| `chainPem` | `[]` | Einzubettende Zwischenzertifikate — ein PEM-Bundle oder eine Liste |
| `digest` | nach Schlüssel | SHA-256 für RSA und P-256, SHA-384 für P-384, SHA-512 für P-521 |

Der Hash wird in PHP berechnet, und der Token signiert ihn mit
`CKM_RSA_PKCS` (über einer `DigestInfo`) oder `CKM_ECDSA`. Jeder Token, der
überhaupt signiert, unterstützt diese beiden, das Dokument wird nie durch
das Gerät gestreamt, und die eigene SHA-2-Unterstützung des Tokens spielt
keine Rolle.

## Langlebige Worker

Das Laden des Moduls und die Anmeldung kosten mehr als eine Signatur. In
einem Queue-Worker, unter Octane oder RoadRunner öffnen Sie den Token
einmal und verwenden ihn wieder:

```php
$token = new PhpPkcs11Token($module, $pin, tokenLabel: 'signing');

// per document
$signer = Pkcs11Signer::fromToken($token, keyLabel: 'pdf');
```

PKCS#11 erlaubt eine Initialisierung des Moduls pro Prozess, daher teilen
sich alle `PhpPkcs11Token`-Instanzen diese. Auch eine Anmeldung gilt
innerhalb des Prozesses für den gesamten Token: Sobald eine Instanz
angemeldet ist, funktioniert eine andere für denselben Token mit jeder PIN.
Prüfen Sie die PIN dort, wo Sie entscheiden, wer signieren darf, nicht im
Token.

## Fehler

Fehler sind `Pkcs11Exception`; `$e->returnValue` ist der PKCS#11-Code
`CKR_*`, sofern das Modul einen gemeldet hat, und die Meldung erklärt die
häufigen Fälle: falsche, abgelaufene oder gesperrte PIN, kein Token im
Slot, entferntes Gerät, Schlüssel ohne Signierberechtigung, vom Token nicht
unterstützter Mechanismus. Mehrdeutige Suchen listen auf, was sie gefunden
haben — "2 private keys match, pass keyLabel: or keyId: — label "rsa", id
01; label "ec", id 02".

## Voraussetzungen

- **PHP 8.2+** mit `dskripchenko/php-pdf` 1.19 oder neuer (Composer installiert es).
- **Die Erweiterung php-pkcs11** —
  [gamringer/php-pkcs11](https://github.com/gamringer/php-pkcs11), gebaut
  für Ihre PHP-Version (`phpize && ./configure && make install`).
  `docker/Dockerfile` dieses Pakets erledigt das unter Ubuntu 24.04 und
  pinnt den Commit, mit dem das Paket geprüft ist.
- **Die PKCS#11-Bibliothek des Herstellers** für das Gerät und Zugriff
  darauf für den Benutzer, unter dem PHP läuft.
- **RSA- oder EC-Schlüssel.** GOST-Schlüssel werden abgelehnt: GOST
  signieren Sie über CryptoPro CSP mit
  [dskripchenko/php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro).

## Ausprobieren mit SoftHSM

`docker/` baut einen Container mit PHP 8.3, php-pkcs11 und SoftHSM2, legt
einen Token mit einem RSA- und einem EC-Schlüssel sowie Zertifikaten einer
Wegwerf-Wurzel an, signiert PDFs und validiert sie mit php-pdf und
`pdfsig` aus poppler — siehe [docker/README.md](../../docker/README.md).
Die CI führt das bei jeder Änderung aus.

## Tests ohne Token

`Pkcs11Signer` spricht über die kleine Schnittstelle `Token` mit dem Gerät;
`PhpPkcs11Token` ist ihre Implementierung auf Basis der Erweiterung.
Übergeben Sie ein eigenes `Token` an `Pkcs11Signer::fromToken()`, um den
Code ohne Gerät auszuführen — so machen es die Tests dieses Pakets mit
einem OpenSSL-basierten In-Memory-Token.

## Die php-pdf-Familie

| Paket | Was es hinzufügt |
|---|---|
| [php-pdf](https://github.com/dskripchenko/php-pdf) | Der Kern: HTML mit CSS oder ein Builder zu PDF, lesen, zusammenführen, Formulare ausfüllen, signieren und validieren. |
| [laravel-php-pdf](https://github.com/dskripchenko/laravel-php-pdf) | Laravel-Brücke: eine Blade-View direkt als PDF, `response()->pdf()`, Signieren aus der Konfiguration. |
| [php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation) | Liberation Sans, Serif und Mono, metrisch kompatibel zu Arial, Times New Roman und Courier New (OFL, aus dem MIT-Kern herausgehalten). |
| [php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro) | Signiert mit einem Schlüssel in CryptoPro CSP auf dem Server: GOST R 34.10-2012, russische qualifizierte Signaturen. |
| **php-pdf-pkcs11** (dieses Paket) | Signiert mit einem Schlüssel auf einem HSM, einer Smartcard oder einem USB-Token über PKCS#11. |
| [php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) | Signiert mit einem Schlüssel in AWS KMS, Google Cloud KMS oder Azure Key Vault. |

## Lizenz

MIT — siehe [LICENSE](../../LICENSE).
