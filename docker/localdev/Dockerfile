# Local development image for the HonestHosting Site Migrator PHP support matrix.
#
# Inherits from the official wordpress image PURELY for its configured Apache +
# PHP extension set (mysqli, gd, zip, imagick, sqlite3/pdo_sqlite, ...).
#
# The core bundled in that image is deliberately IGNORED: the php7.4 tag is frozen at
# WordPress 6.1.1 (Nov 2022) and the php8.0 tag at ~6.4 (Nov 2023), both far too stale to
# test against. WordPress is instead provisioned at container start by WP-CLI, which makes
# the WP version a runtime env var and keeps every cell in the matrix on identical core.
#
# Build:  docker build --build-arg PHP_VERSION=7.4 -f docker/localdev/Dockerfile .
ARG PHP_VERSION=7.4
FROM wordpress:php${PHP_VERSION}-apache

# wp-cli.phar runs on 7.4 through 8.5. The official wordpress:cli-* images only go down to
# php8.1, so the phar is installed directly rather than using a cli sidecar.
RUN set -eux; \
    curl -fsSL -o /usr/local/bin/wp-cli.phar \
        https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar; \
    chmod +x /usr/local/bin/wp-cli.phar

COPY docker/localdev/provision.sh /usr/local/bin/provision.sh
RUN chmod +x /usr/local/bin/provision.sh

ENTRYPOINT ["/usr/local/bin/provision.sh"]
CMD ["apache2-foreground"]
