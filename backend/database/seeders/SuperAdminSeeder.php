<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\SuperAdmin;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        SuperAdmin::create([
            'name' => 'Super Administrator',
            'email' => 'admin@onlifi.com',
            'password' => Hash::make('admin123'),
            'role' => 'super_admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->command->info('Super admin created successfully!');
        $this->command->info('Email: admin@onlifi.com');
        $this->command->info('Password: admin123');
        $this->command->warn('Please change this password immediately in production!');
    }
}
