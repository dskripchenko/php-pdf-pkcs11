# Security policy

Report vulnerabilities privately through
[GitHub security advisories](https://github.com/dskripchenko/php-pdf-pkcs11/security/advisories/new)
or by e-mail to denskrp90@gmail.com — not in public issues.

Supported: the latest minor release.

Handling the token PIN is up to the application: keep it out of logs and
process arguments. The package passes it only to the PKCS#11 module and
marks it `#[\SensitiveParameter]`. A PKCS#11 login holds for the whole token
within the process, so decide who may sign before the signer is built.
