FROM mcr.microsoft.com/devcontainers/php:1-8.2-bullseye

RUN apt-get update && \
    apt-get install -y libzip-dev && \
    docker-php-ext-install zip