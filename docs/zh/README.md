# dskripchenko/php-pdf-pkcs11

使用 [`dskripchenko/php-pdf`](https://github.com/dskripchenko/php-pdf)
签署 PDF，密钥保存在 **PKCS#11 令牌**中：网络或 PCIe HSM（Thales Luna、
Entrust nShield、Utimaco、AWS CloudHSM）、智能卡或国家电子身份证、USB 令牌
（YubiKey、SafeNet eToken、Rutoken）或 SoftHSM。密钥从不离开设备：php-pdf
计算哈希，令牌对其签名，得到使用 RSA、ECDSA 或 GOST R 34.10-2012 的 PAdES 签名。

> 🌐 [English](../../README.md) · [Deutsch](../de/README.md) · [Русский](../ru/README.md) · **中文**

## 用法

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

凡是 php-pdf 接受签名者的地方都可以使用它：已有 PDF 的
`PdfSigner::sign()`、`Pdf\Document::sign()` 以及排版文档的 `signature:`。

除非传入 `certificatePem:`，证书从令牌读取——即与密钥 `CKA_ID` 相同的那张，
令牌通常就是这样存放的。签名者在把签名交给 php-pdf 之前会用证书验证它，
因此不属于该证书的密钥会得到明确的错误，而不是生成一个任何阅读器都不接受
的 PDF。

## 选项

| 参数 | 默认值 | 含义 |
|---|---|---|
| `module` | — | 厂商 PKCS#11 库的路径：`libsofthsm2.so`、`libeTPkcs11.so`、`opensc-pkcs11.so`、`libCryptoki2_64.so`… |
| `pin` | `null` | 用户 PIN。私钥只有登录后才可见 |
| `token` | 唯一的令牌 | 模块中有多个令牌时的令牌标签 |
| `slot` | — | 槽位 id，可替代 `token` |
| `keyLabel` | — | 私钥的 `CKA_LABEL` |
| `keyId` | — | 私钥的 `CKA_ID`，十六进制：`01`、`0x01`、`a1:b2`（与 `pkcs11-tool -O` 的显示一致）。既无标签也无 id 时，使用唯一的私钥 |
| `certificatePem` | 来自令牌 | 令牌中没有签名证书时提供 |
| `chainPem` | `[]` | 要嵌入的中间证书——PEM 证书包或列表 |
| `digest` | 随密钥 | RSA 和 P-256 用 SHA-256，P-384 用 SHA-384，P-521 用 SHA-512；GOST 用 Streebog-256/512（且只能如此） |
| `hashOnToken` | `false` | 仅限 GOST：由令牌自行计算数据的哈希（见下文） |

哈希在 PHP 中计算，令牌用 `CKM_RSA_PKCS`（基于 `DigestInfo`）或
`CKM_ECDSA` 对其签名。凡是能签名的令牌都支持这两种机制，文档从不经由设备
传输，令牌自身是否支持 SHA-2 也无关紧要。

## GOST R 34.10-2012

符合 TC26 规范的令牌——Rutoken ECP 2.0/3.0、JaCarta GOST 以及其他实现了
TC26 PKCS#11 扩展的模块——上的 GOST 密钥与其他密钥一样签名；哈希与证书由
密钥决定：

| 密钥 | 哈希 | 机制 | 令牌输入 |
|---|---|---|---|
| GOST R 34.10-2012，256 位 | Streebog-256 | `CKM_GOSTR3410`（`0x1201`） | 32 字节哈希 |
| GOST R 34.10-2012，512 位 | Streebog-512 | `CKM_GOSTR3410_512`（`0xD4321006`） | 64 字节哈希 |

设置 `hashOnToken: true` 时，令牌用 `CKM_GOSTR3410_WITH_GOSTR3411_12_256`
（`0xD4321008`）或 `…_512`（`0xD4321009`）自行对签名属性计算哈希——适用于
不提供无哈希机制的令牌。

PKCS#11（v2.40，2.45.7）以大端序 `s ‖ r` 返回签名——这正是 CMS 所承载的编码
（RFC 4491，2.2.2），也是 OpenSSL gost 引擎的输出——因此该值原样写入 PDF。
由于各令牌在这一点上曾有差异，签名器会用证书验证该值；若验证不通过，则依次
尝试交换两半、整体字节反转、各半分别反转，取能通过验证的那个；若都不通过，
说明密钥与证书不匹配，抛出 `Pkcs11Exception`。

GOST 需要**在 PHP 中加载 OpenSSL 的 gost 引擎**（`openssl.cnf` 的 `engines`
部分）：无论令牌是否计算哈希，php-pdf 都用它计算 Streebog 并验证签名。
`docker/gost/` 构建了这样的 PHP；其中的 `enable-gost-engine.sh` 在默认配置中
启用该引擎。

**验证情况。** GOST 流程已端到端测试——签名、PDF 以及 php-pdf 的验证器——
使用一个模拟令牌：它通过 OpenSSL gost 引擎以真实的 GOST R 34.10-2012 密钥
（256 和 512）签名，并以上述每种编码返回结果；CI 在 `docker/gost` 镜像中运行。
尚未在实体 Rutoken 或 JaCarta 上运行过，也没有可用于实测的软件令牌：Ubuntu
24.04 中的 SoftHSM2 编译时未启用 GOST，而 SoftHSM2 最多只支持 GOST R
34.10-2001。欢迎提供真实设备上的测试报告。

## 长时间运行的 worker

加载模块和登录的开销比一次签名更大。在队列 worker、Octane 或 RoadRunner
中，只打开一次令牌并重复使用：

```php
$token = new PhpPkcs11Token($module, $pin, tokenLabel: 'signing');

// per document
$signer = Pkcs11Signer::fromToken($token, keyLabel: 'pdf');
```

PKCS#11 每个进程只允许初始化一次模块，因此所有 `PhpPkcs11Token` 实例共享
这一初始化。登录在进程内同样对整个令牌生效：一旦某个实例已登录，同一令牌
的另一个实例用任何 PIN 都能工作。请在决定谁可以签名的地方检查 PIN，而不是
依赖令牌。

## 错误

错误以 `Pkcs11Exception` 抛出；模块报告了代码时，`$e->returnValue` 为
PKCS#11 的 `CKR_*` 代码，消息会解释常见情况：PIN 错误、过期或被锁定、槽位中
没有令牌、设备已移除、密钥不允许签名、令牌不支持该机制。有歧义的查找会列出
找到的内容——"2 private keys match, pass keyLabel: or keyId: — label "rsa",
id 01; label "ec", id 02"。

## 要求

- **PHP 8.2+**，以及 `dskripchenko/php-pdf` 1.19 或更高版本（由 Composer 安装）。
- **php-pkcs11 扩展**——
  [gamringer/php-pkcs11](https://github.com/gamringer/php-pkcs11)，针对你的
  PHP 版本编译（`phpize && ./configure && make install`）。本包的
  `docker/Dockerfile` 在 Ubuntu 24.04 上完成这些步骤，并固定了本包经过验证
  的提交。
- 设备对应的 **厂商 PKCS#11 库**，且运行 PHP 的用户有权访问它。
- **RSA、EC 或 GOST R 34.10-2012 密钥。** GOST 还需要 PHP 中的 OpenSSL gost
  引擎（见上文）。密钥位于 CryptoPro CSP 而非 PKCS#11 令牌中时，请使用
  [dskripchenko/php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro)。

## 在 SoftHSM 上试用

`docker/` 会构建一个包含 PHP 8.3、php-pkcs11 和 SoftHSM2 的容器，创建一个
带有 RSA 和 EC 密钥及一次性根证书所签发证书的令牌，签署 PDF 并用 php-pdf
和 poppler 的 `pdfsig` 验证——见 [docker/README.md](../../docker/README.md)。
CI 在每次变更时运行它，并在 `docker/gost/`（带 gost 引擎的 PHP）中运行 GOST 测试。

## 无令牌时的测试

`Pkcs11Signer` 通过小接口 `Token` 与设备交互；`PhpPkcs11Token` 是基于该扩展
的实现。把自己的 `Token` 传给 `Pkcs11Signer::fromToken()`，即可在没有设备的
情况下运行代码——本包的测试就是用基于 OpenSSL 的内存令牌这样做的。

## php-pdf 系列

| 包 | 提供的功能 |
|---|---|
| [php-pdf](https://github.com/dskripchenko/php-pdf) | 核心：用 HTML 加 CSS 或构建器生成 PDF，读取、合并、填写表单、签名和验证。 |
| [laravel-php-pdf](https://github.com/dskripchenko/laravel-php-pdf) | Laravel 桥接：Blade 视图直接生成 PDF、`response()->pdf()`、按配置签名。 |
| [php-pdf-fonts-liberation](https://github.com/dskripchenko/php-pdf-fonts-liberation) | Liberation Sans、Serif 和 Mono，与 Arial、Times New Roman 和 Courier New 度量兼容（OFL 许可，独立于 MIT 核心之外）。 |
| [php-pdf-cryptopro](https://github.com/dskripchenko/php-pdf-cryptopro) | 使用服务器上 CryptoPro CSP 中的密钥签名：GOST R 34.10-2012，俄罗斯合格电子签名。 |
| **php-pdf-pkcs11**（本包） | 通过 PKCS#11 使用 HSM、智能卡或 USB 令牌上的密钥签名。 |
| [php-pdf-cloud-kms](https://github.com/dskripchenko/php-pdf-cloud-kms) | 使用 AWS KMS、Google Cloud KMS 或 Azure Key Vault 中的密钥签名。 |

## 许可证

MIT——见 [LICENSE](../../LICENSE)。
