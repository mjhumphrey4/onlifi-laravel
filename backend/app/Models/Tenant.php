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
        // Each tenant gets their own database for complete data isolation
        // Use the central database credentials (root/admin) to create tenant databases
        
        $centralUsername = config('database.connections.central.username');
        $centralPassword = config('database.connections.central.password');
        $centralHost = config('database.connections.central.host');
        
        // Tenant will use the same credentials as central (simpler, no user creation needed)
        $this->database_username = $centralUsername;
        $this->database_password = $centralPassword;
        $this->database_host = $centralHost;
        $this->database_port = config('database.connections.central.port', 3306);
        
        $connection = DB::connection('central');
        
        try {
            // Create the tenant's dedicated database
            $connection->statement("CREATE DATABASE IF NOT EXISTS `{$this->database_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            \Log::info("Created database {$this->database_name} for tenant {$this->name}");
            
            // Save the updated credentials
            $this->save();
            
            return true;
        } catch (\Exception $e) {
            \Log::error("Database provisioning failed for tenant {$this->name}: " . $e->getMessage());
            throw $e;
        }
    }

    public function provisionDatabase()
    {
        return $this->createDatabase();
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
