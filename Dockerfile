# Imagen oficial de PHP 8.2 con Apache.
FROM php:8.2-apache

# Extensiones que necesita el sistema:
# - pdo_pgsql: conexión a PostgreSQL (Neon)
# - cURL ya viene incluida en la imagen y se usa para enviar correos con Brevo.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

# Apache sirve solo la carpeta public. config, app y sql quedan fuera del alcance del navegador.
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/!/var/www/html/public/!g' /etc/apache2/apache2.conf

# Copia del proyecto. .dockerignore excluye config/local.php (con claves) y los archivos de pruebas.
COPY . /var/www/html/

# Arranque: ajusta el puerto que asigna Render y luego inicia Apache.
COPY start.sh /usr/local/bin/start.sh
RUN sed -i 's/\r$//' /usr/local/bin/start.sh \
    && chmod +x /usr/local/bin/start.sh \
    && chown -R www-data:www-data /var/www/html

CMD ["/usr/local/bin/start.sh"]
