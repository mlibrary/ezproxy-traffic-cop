FROM bertrama/php8.5.12-apache:latest@sha256:fe1df6ad1d5d9f7b8b66a1eb11d66de463fe38d4df2c13d3536b7d2fb37ac78e AS base

RUN apt-get update \
 && apt-get upgrade -y \
 && apt-get install -y libapache2-mod-auth-openidc libldap-dev gettext-base\
 && apt-get autoremove -y \
 && apt-get clean \
 && (apt-get distclean || rm -rf /var/cache/apt/archives /var/lib/apt/lists/*) \
 && mkdir -p /var/cache/apache2/mod_auth_openidc/oidc-sessions /var/www/empty  /var/www/config \
 && chown www-data:www-data /var/cache/apache2/mod_auth_openidc/oidc-sessions \
 && a2enmod rewrite \
 && docker-php-ext-install ldap

COPY apache/000-default.conf.initial /etc/apache2/sites-available/000-default.conf.initial
COPY apache/auth_openidc.conf /etc/apache2/conf-available/auth_openidc.conf
COPY apache/ports.conf /etc/apache2/ports.conf
COPY apache/001-server-status.conf /etc/apache2/sites-enabled/001-server-status.conf
COPY apache/upload_filesize.ini /usr/local/etc/php/conf.d/upload_filesize.ini

COPY src/html /var/www/html
COPY src/lib /var/www/lib
COPY src/receiver /var/www/receiver

COPY bin/docker-envsubst-entrypoint /usr/local/bin/docker-envsubst-entrypoint
COPY bin/sender /usr/local/bin/sender
COPY bin/generate-transmission-keys /usr/local/bin/generate-transmission-keys

ENTRYPOINT ["docker-envsubst-entrypoint"]
CMD ["apache2-foreground"]
