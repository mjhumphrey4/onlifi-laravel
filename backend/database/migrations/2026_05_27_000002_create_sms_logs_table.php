<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('sms_logs')) {
            return;
        }

        Schema::connection('central')->create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('msisdn', 32);
            $table->text('message');
            $table->string('provider')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('sms_logs');
    }
};
