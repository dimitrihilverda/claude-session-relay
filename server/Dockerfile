# Session relay server: PHP 8.5 + Apache. Put it behind HTTPS (see README).
FROM php:8.5-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends libpq-dev \
	&& docker-php-ext-install pdo_pgsql \
	&& rm -rf /var/lib/apt/lists/*

# Pass the Authorization header to PHP, route everything to the front controller.
RUN { \
	echo '<VirtualHost *:80>'; \
	echo '  DocumentRoot /var/www/relay/public'; \
	echo '  SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1'; \
	echo '  <Directory /var/www/relay/public>'; \
	echo '    AllowOverride None'; \
	echo '    Require all granted'; \
	echo '    FallbackResource /index.php'; \
	echo '    DirectoryIndex index.php'; \
	echo '  </Directory>'; \
	echo '</VirtualHost>'; \
	} > /etc/apache2/sites-available/000-default.conf

COPY bootstrap.php /var/www/relay/
COPY public /var/www/relay/public
COPY src /var/www/relay/src
COPY migrations /var/www/relay/migrations
COPY bin /var/www/relay/bin
COPY docker/entrypoint.sh /usr/local/bin/relay-entrypoint

RUN chmod +x /usr/local/bin/relay-entrypoint && ln -s /var/www/relay/bin/relay /usr/local/bin/relay

WORKDIR /var/www/relay
ENTRYPOINT ["relay-entrypoint"]
CMD ["apache2-foreground"]
