FROM php:8.5-apache@sha256:eacc0d98992683cb46e4f8f44b2418a0323855dc8b59d32dc54f7a9b90a966dd AS base

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
