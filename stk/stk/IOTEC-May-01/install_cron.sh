#!/bin/bash
# IOTEC Polling Cron Job Installation Script
# Run this as root: sudo bash install_cron.sh

echo "=== IOTEC Background Polling Installation ==="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "ERROR: Please run as root (use sudo)"
    exit 1
fi

SCRIPT_DIR="/var/www/html/BiteTechsystems/yo/IOTEC"
SCRIPT_PATH="$SCRIPT_DIR/poll_pending_transactions.php"
LOG_DIR="$SCRIPT_DIR/logs"

echo "Step 1: Fixing permissions..."
chown -R www-data:www-data "$LOG_DIR"
chmod 755 "$LOG_DIR"
chmod +x "$SCRIPT_PATH"

if [ -f "$SCRIPT_DIR/token_cache.json" ]; then
    chown www-data:www-data "$SCRIPT_DIR/token_cache.json"
    chmod 644 "$SCRIPT_DIR/token_cache.json"
fi

echo "✓ Permissions fixed"
echo ""

echo "Step 2: Testing script..."
sudo -u www-data /usr/bin/php "$SCRIPT_PATH" 2>&1 | head -5
if [ $? -eq 0 ]; then
    echo "✓ Script executed successfully"
else
    echo "✗ Script execution failed - check errors above"
    exit 1
fi
echo ""

echo "Step 3: Installing cron job..."
# Create cron job for www-data user
CRON_CMD="* * * * * /usr/bin/php $SCRIPT_PATH >> $LOG_DIR/polling.log 2>&1"

# Check if cron job already exists
if crontab -u www-data -l 2>/dev/null | grep -q "poll_pending_transactions.php"; then
    echo "⚠ Cron job already exists, skipping..."
else
    # Add cron job
    (crontab -u www-data -l 2>/dev/null; echo "$CRON_CMD") | crontab -u www-data -
    echo "✓ Cron job installed (runs every minute)"
fi
echo ""

echo "Step 4: Verifying installation..."
echo "Current cron jobs for www-data:"
crontab -u www-data -l 2>/dev/null | grep -v "^#" | grep -v "^$"
echo ""

echo "=== Installation Complete ==="
echo ""
echo "The polling script will now run every minute to check pending transactions."
echo ""
echo "Monitor logs with:"
echo "  tail -f $LOG_DIR/polling.log"
echo ""
echo "Check detailed logs:"
echo "  tail -f $LOG_DIR/iotec_\$(date +%Y-%m-%d).txt"
echo ""
echo "To remove the cron job later:"
echo "  sudo crontab -u www-data -e"
echo ""
