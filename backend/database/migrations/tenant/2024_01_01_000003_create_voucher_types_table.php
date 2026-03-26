<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';
    
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('voucher_types')) {
            return;
        }
        
        Schema::create('voucher_types', function (Blueprint $table) {
            $table->id();
            $table->string('type_name', 100);
            $table->integer('duration_hours');
            $table->decimal('base_amount', 10, 2)->default(0);
            $table->text('description')->nullable();
            $table->integer('data_limit_mb')->nullable();
            $table->integer('speed_limit_kbps')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_types');
    }
};
