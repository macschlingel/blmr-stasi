FROM php:8.1-cli

RUN apt-get update && apt-get install -y \
    libmariadb-dev \
    && docker-php-ext-install pdo_mysql

WORKDIR /app
CMD ["php", "EventFetcher.php"] 