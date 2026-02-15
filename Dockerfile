# On part d'une version officielle de PHP avec Apache inclus
FROM php:8.2-apache

# On installe les extensions PHP de base (pour MySQL/MariaDB notamment)
# Si vous n'utilisez pas de base de données, vous pouvez retirer la ligne ci-dessous
RUN docker-php-ext-install mysqli pdo pdo_mysql

# On active le mod_rewrite d'Apache (utile pour les URLs propres / .htaccess)
RUN a2enmod rewrite

# On copie tout votre code dans le dossier du serveur web
COPY . /var/www/html/

# On dit à Render que le site écoute sur le port 80
EXPOSE 80