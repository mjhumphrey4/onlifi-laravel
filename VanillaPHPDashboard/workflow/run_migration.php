<?php
/**
 * Database Migration Script
 * Adds fallback_ref column to transactions table
 * Run this once to update your database schema
 */

require_once 'config.php';

echo "Starting database migration...\n\n";

try {
    $pdo = getDB();
    
    // Add fallback_ref column to main transactions table
    echo "Adding fallback_ref column to transactions table...\n";
    $pdo->exec("
        ALTER TABLE transactions 
        ADD COLUMN IF NOT EXISTS fallback_ref VARCHAR(255) DEFAULT NULL AFTER status_message
    ");
    echo "✓ Column added successfully\n\n";
    
    // Add index for faster lookups
    echo "Creating index on fallback_ref...\n";
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_fallback_ref ON transactions(fallback_ref)
    ");
    echo "✓ Index created successfully\n\n";
    
    echo "Migration completed successfully!\n";
    echo "\nNOTE: You should also run the following on your IOTEC database:\n";
    echo "ALTER TABLE transactions ADD COLUMN IF NOT EXISTS fallback_from VARCHAR(255) DEFAULT NULL AFTER status_message;\n";
    echo "CREATE INDEX IF NOT EXISTS idx_fallback_from ON transactions(fallback_from);\n";
    
} catch (PDOException $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
