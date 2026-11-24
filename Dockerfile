FROM phpswoole/swoole:php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis curl coreutils && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /

# Add build arguments
ARG CACHEBUST
ARG REPO_URL
ARG BRANCH_NAME=master
# CI passes the exact tested commit. Branch heads are only a fallback for manual builds.
ARG COMMIT_SHA

RUN echo "Fetching ${COMMIT_SHA:-$BRANCH_NAME} from ${REPO_URL} with CACHEBUST: ${CACHEBUST}" && \
    rm -rf ./* && \
    rm -rf .git && \
    git config --global --add safe.directory /www && \
    git init -q . && \
    git remote add origin "${REPO_URL}" && \
    git fetch -q --depth 1 origin "${COMMIT_SHA:-$BRANCH_NAME}" && \
    git checkout -q -B "${BRANCH_NAME}" FETCH_HEAD && \
    { [ -z "${COMMIT_SHA}" ] || [ "$(git rev-parse HEAD)" = "${COMMIT_SHA}" ]; }

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

RUN sh scripts/install-node-automation-probe.sh && sh scripts/install-node-automation-mihomo.sh

RUN test -f composer.lock \
    && composer install --no-cache --no-dev --no-interaction \
    && php scripts/check-horizon-provisioning.php --environment=local \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data
    
ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=false 

# The upstream image tag is mutable and may provide an /entrypoint.sh that
# recursively chowns /www and /data. Both paths are bind mounts in production;
# use the stable PHP entrypoint so application startup cannot rewrite the host
# repository or the standalone Redis data directory.
ENTRYPOINT ["docker-php-entrypoint"]

EXPOSE 7001
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
