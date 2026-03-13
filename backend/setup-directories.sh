#!/bin/bash

# OnLiFi Laravel Directory Setup Script
# Run this on the server after deployment

echo "Setting up Laravel directories and permissions..."

# Create required directories
mkdir -p bootstrap/cache
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/testing
mkdir -p storage/logs
mkdir -p storage/app/public

# Set permissions (775 for directories, 664 for files)
chmod -R 775 bootstrap/cache
chmod -R 775 storage

# Set ownership to web server user (www-data)
chown -R www-data:www-data bootstrap/cache
chown -R www-data:www-data storage

# Also set current user to www-data group for development
if [ -n "$SUDO_USER" ]; then
    usermod -a -G www-data $SUDO_USER
fi

echo "✓ Directories created"
echo "✓ Permissions set to 775"
echo "✓ Ownership set to www-data:www-data"
echo ""
echo "If you're still getting permission errors, run:"
echo "  sudo chmod -R 777 storage bootstrap/cache"
echo ""
echo "Now run: composer install"
