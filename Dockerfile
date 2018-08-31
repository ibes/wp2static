FROM wordpress:latest

RUN touch /var/log/apache2/php_err.log && chown www-data:www-data /var/log/apache2/php_err.log
COPY provisioning/php_error.ini /usr/local/etc/php/conf.d/php_error.ini
COPY provisioning/newrelicconfig/* /usr/local/etc/php/conf.d/

RUN apt-get update \
&& apt-get install -y inotify-tools rsync mysql-client iproute zlib1g-dev unzip vim mlocate wget gnupg iputils-ping \
&& rm -rf /var/lib/apt/lists/* \
&& docker-php-ext-install zip

# start newrelix
ARG NR_INSTALL_SILENT=1

# Install newrelic for php
RUN \
    # Add newrelic as apt-get source
    echo 'deb http://apt.newrelic.com/debian/ newrelic non-free' > /etc/apt/sources.list.d/newrelic.list \
    && curl -L https://download.newrelic.com/548C16BF.gpg | apt-key add - \

    # Install package from newrelic
    && apt-get update \
    && apt-get install newrelic-php5 -y \

    # Cleanup
    && apt-get clean \
    && apt-get autoremove \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* /var/log/apt/*

# end newrelix

# trigger rebuild

# install wp cli
RUN curl -L https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp
RUN chmod +x /usr/local/bin/wp

# install phpunit to path (version 5 dev will need older version)
RUN curl -L https://phar.phpunit.de/phpunit.phar -o /usr/local/bin/phpunit
RUN chmod +x /usr/local/bin/phpunit

COPY provisioning/*.sh /
COPY provisioning/.env-vars /

COPY provisioning/test_data/ /test_data

COPY provisioning/install/plugins/* /plugins/
