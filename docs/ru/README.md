# dskripchenko/php-pdf-pkcs11

Подпись PDF библиотекой [`dskripchenko/php-pdf`](https://github.com/dskripchenko/php-pdf)
ключом, который хранит **PKCS#11-токен**: сетевой или PCIe HSM (Thales Luna,
Entrust nShield, Utimaco, AWS CloudHSM), смарт-карта или национальная
ID-карта, USB-токен (YubiKey, SafeNet eToken, Рутокен) или SoftHSM. Ключ не
покидает устройство: php-pdf считает хэш, токен его подписывает, и
получается подпись PAdES на RSA или ECDSA.

> 🌐 [English](../../README.md) · [Deutsch](../de/README.md) · **Русский** · [中文](../zh/README.md)

## Использование

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

Подписант работает везде, где php-pdf его принимает: `PdfSigner::sign()`
для готового PDF, `Pdf\Document::sign()` и `signature:` свёрстанного
документа.

Сертификат читается с токена — тот, у которого `CKA_ID` совпадает с ключом,
как их и хранят токены, — если не передан `certificatePem:`. Прежде чем
отдать подпись в php-pdf, подписант проверяет её по сертификату, поэтому
ключ, не относящийся к сертификату, даёт понятную ошибку, а не PDF, который
не примет ни одна программа просмотра.

## Параметры

| Аргумент | По умолчанию | Смысл |
|---|---|---|
| `module` | — | Путь к PKCS#11-библиотеке производителя: `libsofthsm2.so`, `libeTPkcs11.so`, `opensc-pkcs11.so`, `libCryptoki2_64.so`… |
| `pin` | `null` | PIN пользователя. Закрытые ключи видны только после входа |
| `token` | единственный токен | Метка токена, если в модуле их несколько |
| `slot` | — | Идентификатор слота, как альтернатива `token` |
| `keyLabel` | — | `CKA_LABEL` закрытого ключа |
| `keyId` | — | `CKA_ID` закрытого ключа в hex: `01`, `0x01`, `a1:b2` (как показывает `pkcs11-tool -O`). Без метки и id — единственный закрытый ключ |
| `certificatePem` | с токена | Сертификат подписи, если на токене его нет |
| `chainPem` | `[]` | Промежуточные сертификаты для встраивания — PEM-бандл или список |
| `digest` | по ключу | SHA-256 для RSA и P-256, SHA-384 для P-384, SHA-512 для P-521 |

Хэш считается в PHP, а токен подписывает его механизмом `CKM_RSA_PKCS`
(поверх `DigestInfo`) или `CKM_ECDSA`. Эти два механизма поддерживает любой
токен, который вообще умеет подписывать, документ никогда не прогоняется
через устройство, и собственная поддержка SHA-2 в токене не важна.

## Долгоживущие воркеры

Загрузка модуля и вход обходятся дороже самой подписи. В воркере очереди,
Octane или RoadRunner открывайте токен один раз и переиспользуйте его:

```php
$token = new PhpPkcs11Token($module, $pin, tokenLabel: 'signing');

// per document
$signer = Pkcs11Signer::fromToken($token, keyLabel: 'pdf');
```

PKCS#11 допускает одну инициализацию модуля на процесс, поэтому все
экземпляры `PhpPkcs11Token` делят её между собой. Вход тоже действует на
весь токен в пределах процесса: как только один экземпляр вошёл, другой для
того же токена работает с любым PIN. Проверяйте PIN там, где решаете, кому
можно подписывать, а не в токене.

## Ошибки

Ошибки — `Pkcs11Exception`; `$e->returnValue` — код PKCS#11 `CKR_*`, если
модуль его вернул, а сообщение объясняет частые случаи: неверный, истёкший
или заблокированный PIN, нет токена в слоте, устройство извлечено, ключу
запрещено подписывать, механизм не поддерживается токеном. Неоднозначный
поиск перечисляет найденное — "2 private keys match, pass keyLabel: or
keyId: — label "rsa", id 01; label "ec", id 02".

## Требования

- **PHP 8.2+** и `dskripchenko/php-pdf` 1.19 или новее (Composer установит его сам).
- **Расширение php-pkcs11** —
  [gamringer/php-pkcs11](https://github.com/gamringer/php-pkcs11), собранное
  под вашу версию PHP (`phpize && ./configure && make install`).
  `docker/Dockerfile` этого пакета делает это на Ubuntu 24.04 и фиксирует
  коммит, с которым пакет проверен.
- **PKCS#11-библиотека производителя** для устройства и доступ к ней у
  пользователя, от которого работает PHP.
- **Ключи RSA или EC.** ГОСТ-ключи отклоняются: подписывайте ГОСТ через
  КриптоПро CSP с помощью [dskripchenko/php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro).

## Проверка на SoftHSM

`docker/` собирает контейнер с PHP 8.3, php-pkcs11 и SoftHSM2, создаёт токен
с RSA- и EC-ключом и сертификатами от одноразового корня, подписывает PDF и
проверяет их php-pdf и `pdfsig` из poppler — см.
[docker/README.md](../../docker/README.md). CI запускает это на каждое
изменение.

## Тесты без токена

`Pkcs11Signer` обращается к устройству через небольшой интерфейс `Token`;
`PhpPkcs11Token` — его реализация поверх расширения. Передайте свой `Token`
в `Pkcs11Signer::fromToken()`, чтобы запускать код без устройства — так
делают тесты этого пакета с токеном в памяти на OpenSSL.

## Семейство php-pdf

| Пакет | Что добавляет |
|---|---|
| [php-pdf](https://github.com/dskripchenko/php-pdf) | Ядро: HTML с CSS или билдер в PDF, чтение, слияние, заполнение форм, подпись и проверка. |
| [laravel-php-pdf](https://github.com/dskripchenko/laravel-php-pdf) | Мост для Laravel: Blade-шаблон сразу в PDF, `response()->pdf()`, подпись из конфига. |
| [php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation) | Liberation Sans, Serif и Mono, метрически совместимые с Arial, Times New Roman и Courier New (OFL, вынесены из MIT-ядра). |
| [php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro) | Подпись ключом в КриптоПро CSP на сервере: ГОСТ Р 34.10-2012, российская квалифицированная подпись. |
| **php-pdf-pkcs11** (этот пакет) | Подпись ключом на HSM, смарт-карте или USB-токене через PKCS#11. |
| [php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) | Подпись ключом в AWS KMS, Google Cloud KMS или Azure Key Vault. |

## Лицензия

MIT — см. [LICENSE](../../LICENSE).
