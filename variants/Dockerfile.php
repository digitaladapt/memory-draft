# Variant: PHP-tooling MemoryDraft
#
# The base image already runs on PHP; this variant adds the toolchain (composer
# plus dev dependencies) so an operator can run migrations or a REPL inside the
# running container. It is NOT the production image.
#
# Built and published by the shared docker workflow as `:latest-php`.

FROM digitaladapt/memory-draft:latest

USER root

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
RUN composer install --no-interaction --prefer-dist --no-progress

USER www-data
