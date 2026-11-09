FROM php:8.3-cli

# Install system dependencies and PHP build tools
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libzip-dev \
    libssl-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install \
    pdo_mysql \
    bcmath \
    pcntl \
    intl \
    zip \
    && pecl install protobuf \
    && pecl install grpc \
    && docker-php-ext-enable protobuf grpc \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY docker/php/uploads.ini ${PHP_INI_DIR}/conf.d/zz-uploads.ini

# Copy Laravel application
COPY . .

# Install PHP dependencies
RUN composer install --no-interaction --prefer-dist

EXPOSE 8000

CMD ["sh", "-c", "(test -d public/storage || php artisan storage:link --relative) && test -d public/storage && exec php artisan serve --no-reload --host=0.0.0.0 --port=8000"]
