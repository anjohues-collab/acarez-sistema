FROM php:8.2-apache

# Instalar extensiones de MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Copiar todo el código
COPY . /var/www/html/

# Asignar permisos a todo el directorio web
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

# Crear directorios de uploads y asignar permisos
RUN mkdir -p /var/www/html/uploads/km_inicio \
    && mkdir -p /var/www/html/uploads/km_fin \
    && mkdir -p /var/www/html/uploads/hotel \
    && mkdir -p /var/www/html/uploads/caseta \
    && mkdir -p /var/www/html/uploads/comida \
    && mkdir -p /var/www/html/uploads/estacionamiento \
    && mkdir -p /var/www/html/uploads/gasolina \
    && mkdir -p /var/www/html/uploads/otros \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 755 /var/www/html/uploads

# 👉 NUEVA SECCIÓN: .htaccess para servir imágenes
RUN echo "Options +Indexes" > /var/www/html/uploads/.htaccess \
    && echo "<FilesMatch \"\.(jpg|jpeg|png|gif)$\">" >> /var/www/html/uploads/.htaccess \
    && echo "    Order Allow,Deny" >> /var/www/html/uploads/.htaccess \
    && echo "    Allow from all" >> /var/www/html/uploads/.htaccess \
    && echo "</FilesMatch>" >> /var/www/html/uploads/.htaccess

EXPOSE 80
