FROM php:8.2-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-venv \
    && docker-php-ext-install mysqli pdo_mysql \
    && python3 -m venv /opt/venv \
    && rm -rf /var/lib/apt/lists/*
ENV PATH="/opt/venv/bin:${PATH}" \
    MPLBACKEND=Agg \
    MPLCONFIGDIR=/tmp/matplotlib \
    PYTHONDONTWRITEBYTECODE=1
COPY docker/python-requirements.txt /tmp/python-requirements.txt
RUN pip install --no-cache-dir -r /tmp/python-requirements.txt \
    && mkdir -p /var/lib/php/sessions \
    && chown www-data:www-data /var/lib/php/sessions \
    && chmod 700 /var/lib/php/sessions
COPY docker/php.ini /usr/local/etc/php/conf.d/fish.ini
COPY docker/apache.conf /etc/apache2/conf-available/fish.conf
RUN a2enconf fish
WORKDIR /var/www/html
