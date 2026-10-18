# dskripchenko/php-pdf-pkcs11

PDFs mit [`dskripchenko/php-pdf`](https://github.com/dskripchenko/php-pdf)
signieren — mit einem Schlüssel auf einem **PKCS#11-Token**: einem Netzwerk-
oder PCIe-HSM (Thales Luna, Entrust nShield, Utimaco, AWS CloudHSM), einer
Smartcard oder einem nationalen eID-Ausweis, einem USB-Token (YubiKey,
SafeNet eToken, Rutoken) oder SoftHSM. Der Schlüssel verlässt das Gerät nie:
php-pdf berechnet den Hash, der Token signiert ihn, und das Ergebnis ist eine
PAdES-Signatur mit RSA, ECDSA oder GOST R 34.10-2012.

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
| `digest` | nach Schlüssel | SHA-256 für RSA und P-256, SHA-384 für P-384, SHA-512 für P-521; Streebog-256/512 für GOST (dort die einzige Wahl) |
| `hashOnToken` | `false` | Nur GOST: der Token hasht die Daten selbst (siehe unten) |

Der Hash wird in PHP berechnet, und der Token signiert ihn mit
`CKM_RSA_PKCS` (über einer `DigestInfo`) oder `CKM_ECDSA`. Jeder Token, der
überhaupt signiert, unterstützt diese beiden, das Dokument wird nie durch
das Gerät gestreamt, und die eigene SHA-2-Unterstützung des Tokens spielt
keine Rolle.

## GOST R 34.10-2012

GOST-Schlüssel auf TC26-konformen Tokens — Rutoken ECP 2.0/3.0, JaCarta GOST
und andere Module mit den TC26-Erweiterungen für PKCS#11 — signieren wie
jeder andere Schlüssel; Hash und Zertifikat folgen dem Schlüssel:

| Schlüssel | Hash | Mechanismus | Eingabe des Tokens |
|---|---|---|---|
| GOST R 34.10-2012, 256 Bit | Streebog-256 | `CKM_GOSTR3410` (`0x1201`) | der 32-Byte-Hash |
| GOST R 34.10-2012, 512 Bit | Streebog-512 | `CKM_GOSTR3410_512` (`0xD4321006`) | der 64-Byte-Hash |

Mit `hashOnToken: true` hasht der Token die signierten Attribute selbst, mit
`CKM_GOSTR3410_WITH_GOSTR3411_12_256` (`0xD4321008`) oder `…_512`
(`0xD4321009`) — für einen Token, der die einfachen Mechanismen nicht bietet.

PKCS#11 (v2.40, 2.45.7) liefert die Signatur als `s ‖ r`, Big-Endian — die
Kodierung, die CMS trägt (RFC 4491, 2.2.2) und die OpenSSLs gost-Engine
schreibt —, daher geht der Wert unverändert ins PDF. Da Tokens hier schon
abgewichen sind, prüft der Signierer den Wert gegen das Zertifikat und
versucht, wenn er nicht passt, die vertauschten Hälften, den ganzen Wert in
umgekehrter Byte-Reihenfolge und jede Hälfte umgekehrt, und nimmt den, der
sich verifizieren lässt; passt keiner, gehört der Schlüssel nicht zum
Zertifikat, und es gibt eine `Pkcs11Exception`.

GOST braucht **OpenSSLs gost-Engine, in PHP geladen** (der Abschnitt
`engines` in `openssl.cnf`): php-pdf berechnet damit Streebog und
verifiziert, ob der Token hasht oder nicht. `docker/gost/` baut ein solches
PHP; sein `enable-gost-engine.sh` schaltet die Engine in der Standardkonfiguration ein.

**Prüfstand.** Der GOST-Weg ist durchgehend getestet — Signatur, PDF und
der Validator von php-pdf — gegen einen emulierten Token, der mit einem
echten GOST-R-34.10-2012-Schlüssel (256 und 512) über OpenSSLs gost-Engine
signiert und den Wert in jeder der obigen Kodierungen zurückgibt; die CI
führt das im Image `docker/gost` aus. Auf einem physischen Rutoken oder
JaCarta lief es noch nicht, und für einen Live-Test gibt es keinen
Software-Token: SoftHSM2 in Ubuntu 24.04 ist ohne GOST gebaut, und SoftHSM2
kennt bestenfalls GOST R 34.10-2001. Berichte von echten Geräten sind
willkommen.

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
- **RSA-, EC- oder GOST-R-34.10-2012-Schlüssel.** GOST braucht zusätzlich
  OpenSSLs gost-Engine in PHP (siehe oben). Für Schlüssel in CryptoPro CSP
  statt auf einem PKCS#11-Token gibt es
  [dskripchenko/php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro).

## Ausprobieren mit SoftHSM

`docker/` baut einen Container mit PHP 8.3, php-pkcs11 und SoftHSM2, legt
einen Token mit einem RSA- und einem EC-Schlüssel sowie Zertifikaten einer
Wegwerf-Wurzel an, signiert PDFs und validiert sie mit php-pdf und
`pdfsig` aus poppler — siehe [docker/README.md](../../docker/README.md).
Die CI führt das bei jeder Änderung aus, die GOST-Tests in `docker/gost/`
(PHP mit der gost-Engine).

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
