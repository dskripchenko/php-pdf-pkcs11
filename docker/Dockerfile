# PHP 8.3 with the php-pkcs11 extension (built from source) and SoftHSM2 —
# a software token to sign against, as a stand-in for an HSM or a card.
FROM ubuntu:24.04

# The php-pkcs11 commit this package is verified with.
ARG PHP_PKCS11_REF=b6fa373098db20854545ba33db24a361d75771e1

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
        php8.3-cli php8.3-dev php8.3-mbstring php8.3-xml php8.3-zip php8.3-curl \
        build-essential git ca-certificates unzip curl \
        softhsm2 opensc openssl poppler-utils \
    && rm -rf /var/lib/apt/lists/*

RUN git clone https://github.com/gamringer/php-pkcs11.git /usr/src/php-pkcs11 \
    && cd /usr/src/php-pkcs11 && git checkout "$PHP_PKCS11_REF" && git log -1 --format='php-pkcs11 %H' \
    && phpize && ./configure && make -j"$(nproc)" && make install \
    && echo 'extension=pkcs11.so' > /etc/php/8.3/cli/conf.d/30-pkcs11.ini \
    && php -m | grep -i pkcs11

RUN curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
