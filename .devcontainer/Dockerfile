FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

# Extensões PHP que o Laravel precisa para MySQL, e o cliente mysql.
RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client \
    && docker-php-ext-install pdo_mysql \
    && rm -rf /var/lib/apt/lists/*
