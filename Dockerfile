FROM php:8.4-apache@sha256:8207906b8ebcd6c969dca3ee9e6d4527dc176a0f7b5713e827429be073796d05 AS base

RUN apt-get update \
 && apt-get upgrade -y \
 && apt-get install -y libapache2-mod-auth-openidc libldap-dev \
 && apt-get autoremove -y \
 && apt-get clean \
 && (apt-get distclean || rm -rf /var/cache/apt/archives /var/lib/apt/lists/*) \
 && mkdir -p /var/cache/apache2/mod_auth_openidc/oidc-sessions /var/www/empty  /var/www/config \
 && chown www-data:www-data /var/cache/apache2/mod_auth_openidc/oidc-sessions \
 && a2enmod rewrite \
 && docker-php-ext-install ldap

COPY apache/auth_openidc.conf /etc/apache2/conf-available/auth_openidc.conf
COPY apache/ports.conf /etc/apache2/ports.conf
COPY apache/001-server-status.conf /etc/apache2/sites-enabled/001-server-status.conf

COPY src/html /var/www/html
COPY src/lib /var/www/lib
