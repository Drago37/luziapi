# Image WordPress officielle + Composer (nécessaire pour installer Timber dans le thème).
FROM wordpress:6.7-php8.3-apache

# Composer (copié depuis l'image officielle composer).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Quelques outils utiles en dev (git pour Composer, client mysql, unzip).
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip default-mysql-client \
    && rm -rf /var/lib/apt/lists/*
