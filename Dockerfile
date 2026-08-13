# Use official PHP image with built-in web server
FROM php:8.2-cli

# Install SQLite extensions if needed
RUN docker-php-ext-install pdo pdo_sqlite

# Set the working directory inside the container
WORKDIR /app

# Copy all project files into the container
COPY . /app

# Cloud Run dynamically assigns a port using the PORT environment variable
ENV PORT=8080

# Start the PHP built-in web server pointing to our app
CMD php -S 0.0.0.0:$PORT