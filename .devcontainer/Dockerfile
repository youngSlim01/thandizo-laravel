FROM mcr.microsoft.com/devcontainers/php:1-8.3-bookworm

# Extensão PHP para o Laravel falar com o MySQL. Compila a partir do código
# que já vem na imagem: não usa apt (os repositórios extra da imagem, como o
# do Yarn, falham às vezes com chaves expiradas).
RUN docker-php-ext-install pdo_mysql
