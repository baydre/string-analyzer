FROM php:8.2-cli-alpine
RUN docker-php-ext-install pdo_sqlite sqlite3
WORKDIR /app
COPY . .
CMD ["sh", "-lc", "php -S 0.0.0.0:${PORT:-8000} router.php"]
