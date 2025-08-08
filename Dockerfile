# Use the official PHP image with Apache
FROM php:8.2-apache

# Enable Apache mod_rewrite (used for URL routing)
RUN a2enmod rewrite

# Copy current directory (your PHP files) to Apache server root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Expose port 80 (web server)
EXPOSE 80
