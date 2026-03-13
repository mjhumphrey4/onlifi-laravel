#!/bin/bash

# OnLiFi Laravel Directory Setup Script
# Run this on the server after deployment

echo "Setting up Laravel directories and permissions..."

# Create required directories
mkdir -p bootstrap/cache
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p storage/app/public

# Set permissions (775 for directories, 664 for files)
chmod -R 775 bootstrap/cache
chmod -R 775 storage

# Set ownership to web server user
# Adjust www-data to your web server user (nginx, apache, etc.)
if [ -n "$SUDO_USER" ]; then
    chown -R www-data:www-data bootstrap/cache
    chown -R www-data:www-data storage
fi

echo "✓ Directories created"
echo "✓ Permissions set to 775"
echo "✓ Ownership set to www-data"
echo ""
echo "Now run: composer install"
