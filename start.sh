#!/bin/sh
# Arranque del contenedor en Render.
# Render entrega el puerto en la variable PORT; Apache debe escuchar en ese puerto.
set -e

PUERTO="${PORT:-80}"

sed -i "s/^Listen 80$/Listen ${PUERTO}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PUERTO}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
