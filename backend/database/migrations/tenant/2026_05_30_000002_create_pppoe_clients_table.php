<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('pppoe_clients')) {
            return;
        }

        Schema::connection('tenant')->create('pppoe_clients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->nullable()->index();
            $table->string('name', 100);
            $table->string('username', 100);
            $table->string('password')->nullable();
            $table->string('profile', 100)->nullable();
            $table->string('service', 100)->nullable();
            $table->string('remote_address', 64)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'username']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('pppoe_clients');
    }
};
