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
        // Use the central/admin connection which should have CREATE DATABASE privileges
        // This connection should be configured with root or admin MySQL credentials
        $connection = DB::connection('central');
        
        try {
            // Create the database
            $connection->statement("CREATE DATABASE IF NOT EXISTS `{$this->database_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            // Create user and grant privileges (skip if using same credentials as central)
            if ($this->database_username !== config('database.connections.central.username')) {
                $host = $this->database_host === 'localhost' ? 'localhost' : '%';
                $connection->statement("CREATE USER IF NOT EXISTS '{$this->database_username}'@'{$host}' IDENTIFIED BY '{$this->database_password}'");
                $connection->statement("GRANT ALL PRIVILEGES ON `{$this->database_name}`.* TO '{$this->database_username}'@'{$host}'");
                $connection->statement("FLUSH PRIVILEGES");
            }
        } catch (\Exception $e) {
            // If central connection doesn't have privileges, log the error with helpful message
            \Log::error("Database provisioning failed: " . $e->getMessage());
            \Log::info("Ensure the central database connection has CREATE DATABASE privileges, or create the database manually: {$this->database_name}");
            throw new \Exception("Database provisioning failed. Please ensure the database user has CREATE DATABASE privileges or create the database '{$this->database_name}' manually.");
        }
    }

    public function provisionDatabase()
    {
        return $this->createDatabase();
    }

    public function useCentralDatabase()
    {
        // Alternative: Use the central database with table prefixes instead of separate databases
        // This avoids the need for CREATE DATABASE privileges
        $this->database_name = config('database.connections.central.database');
        $this->database_host = config('database.connections.central.host');
        $this->database_port = config('database.connections.central.port');
        $this->database_username = config('database.connections.central.username');
        $this->database_password = config('database.connections.central.password');
        $this->save();
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
