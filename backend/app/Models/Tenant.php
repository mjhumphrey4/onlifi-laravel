<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use SoftDeletes;

    protected $connection = 'central';

    protected $fillable = [
        'name',
        'slug',
        'domain',
        'database_name',
        'database_host',
        'database_port',
        'database_username',
        'database_password',
        'api_key',
        'api_secret',
        'is_active',
        'settings',
        'trial_ends_at',
        'subscribed_at',
        'status',
        'approved_at',
        'approved_by',
        'rejection_reason',
        'yoapi_username',
        'yoapi_password',
        'yoapi_mode',
        'radius_secret',
    ];

    protected $hidden = [
        'database_password',
        'api_secret',
        'yoapi_password',
        'radius_secret',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_active' => 'boolean',
        'trial_ends_at' => 'datetime',
        'subscribed_at' => 'datetime',
    ];

    public function users()
    {
        return $this->hasMany(TenantUser::class);
    }

    public function configure()
    {
        Config::set('database.connections.tenant', [
            'driver' => 'mysql',
            'host' => $this->database_host,
            'port' => $this->database_port,
            'database' => $this->database_name,
            'username' => $this->database_username,
            'password' => $this->database_password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
        ]);

        DB::purge('tenant');
        DB::reconnect('tenant');
    }

    public function createDatabase()
    {
        // Option 1: Try to use a separate database per tenant (requires CREATE DATABASE privileges)
        // Option 2: Fall back to using the central database (shared database approach)
        
        $useSeparateDatabase = config('tenancy.use_separate_databases', false);
        
        if (!$useSeparateDatabase) {
            // Use shared database approach - all tenants use central database
            return $this->useCentralDatabase();
        }
        
        // Try to create separate database
        $connection = DB::connection('central');
        
        try {
            // Create the database
            $connection->statement("CREATE DATABASE IF NOT EXISTS `{$this->database_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // Create user and grant privileges (skip if using same credentials as central)
            if ($this->database_username !== config('database.connections.central.username')) {
                $host = $this->database_host === 'localhost' ? 'localhost' : '%';
                
                // Try to create user - may fail if user exists
                try {
                    $connection->statement("CREATE USER IF NOT EXISTS '{$this->database_username}'@'{$host}' IDENTIFIED BY '{$this->database_password}'");
                } catch (\Exception $e) {
                    // User might already exist, try to alter password instead
                    try {
                        $connection->statement("ALTER USER '{$this->database_username}'@'{$host}' IDENTIFIED BY '{$this->database_password}'");
                    } catch (\Exception $e2) {
                        \Log::warning("Could not create/alter user: " . $e2->getMessage());
                    }
                }
                
                $connection->statement("GRANT ALL PRIVILEGES ON `{$this->database_name}`.* TO '{$this->database_username}'@'{$host}'");
                $connection->statement("FLUSH PRIVILEGES");
            }
        } catch (\Exception $e) {
            \Log::warning("Separate database provisioning failed, falling back to shared database: " . $e->getMessage());
            // Fall back to shared database approach
            return $this->useCentralDatabase();
        }
    }

    public function provisionDatabase()
    {
        return $this->createDatabase();
    }

    public function useCentralDatabase()
    {
        // Use the central database - all tenants share the same database
        // This is simpler and doesn't require CREATE DATABASE privileges
        $this->database_name = config('database.connections.central.database');
        $this->database_host = config('database.connections.central.host');
        $this->database_port = config('database.connections.central.port');
        $this->database_username = config('database.connections.central.username');
        $this->database_password = config('database.connections.central.password');
        $this->save();
        
        \Log::info("Tenant {$this->name} configured to use shared central database");
    }

    public function runMigrations()
    {
        $this->configure();
        
        \Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);
    }

    public static function generateApiKey(): string
    {
        return 'onlifi_' . Str::random(32);
    }

    public static function generateApiSecret(): string
    {
        return Str::random(64);
    }

    public function verifyApiSecret(string $secret): bool
    {
        return hash_equals($this->api_secret, $secret);
    }

    public function isTrialExpired(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isPast();
    }

    public function isSubscribed(): bool
    {
        return $this->subscribed_at !== null;
    }

    public function canAccess(): bool
    {
        return $this->is_active && ($this->isSubscribed() || !$this->isTrialExpired());
    }
}
