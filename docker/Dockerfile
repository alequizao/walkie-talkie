FROM php:7.4-fpm-alpine

# Dependências do sistema
RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    mysql-client \
    autoconf \
    g++ \
    make \
    linux-headers \
    && docker-php-ext-install pdo pdo_mysql sockets opcache \
    && pecl install -o -f redis \
    && docker-php-ext-enable redis \
    && apk del autoconf g++ make linux-headers

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Diretório de trabalho
WORKDIR /var/www/html

# Copiar código
COPY . /var/www/html/

# Permissões e instalação de deps
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && composer install --no-dev --optimize-autoloader --no-interaction || true

# Configuração PHP otimizada
RUN { \
    echo "memory_limit=256M"; \
    echo "upload_max_filesize=10M"; \
    echo "post_max_size=10M"; \
    echo "max_execution_time=60"; \
    echo "opcache.enable=1"; \
    echo "opcache.validate_timestamps=0"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.max_accelerated_files=10000"; \
} > /usr/local/etc/php/conf.d/zz-walkie.ini

EXPOSE 9000 8080

CMD ["php-fpm"]
