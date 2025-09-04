FROM FROM arm64v8/php:7.4-cli


# Installiere php-dio, composer und php-mqtt/client
RUN apt-get update && apt-get install -y php-dio libmosquitto-dev git \
    && pecl install mosquitto-0.4.0 \
    && echo "extension=mosquitto.so" > /usr/local/etc/php/conf.d/mosquitto.ini \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer require php-mqtt/client

# Kopiere PHP-Skripte
COPY phpinc /phpinc
COPY user_device.php /user_device.php

# Starte das Skript
CMD ["php", "/user_device.php"]
