FROM php:8.2-apache

# Ativar rewrite (boa prática)
RUN a2enmod rewrite

# Copiar arquivos do projeto
COPY . /var/www/html/

# Permissões
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80
