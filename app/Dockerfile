FROM php:7.4-cli-alpine

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && sync
RUN install-php-extensions intl

COPY ./cron_scripts/start.sh /opt/init.sh
COPY cron_scripts/run_haproxy.sh /etc/periodic/hourly/run_haproxy.sh
RUN chmod +x /etc/periodic/hourly/run_haproxy.sh
RUN chmod +x /opt/init.sh

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /opt/checker/

WORKDIR /opt/checker
RUN composer install

CMD /opt/init.sh
