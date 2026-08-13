FROM php:8.2-apache

# Copy your code into the Apache public folder
COPY . /var/www/html/

# Tell Apache to listen on port 8080 (which Cloud Run requires)
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf