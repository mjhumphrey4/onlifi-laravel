<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('support_tickets')) {
            return;
        }

        Schema::connection('central')->create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->foreignId('tenant_user_id')->nullable()->constrained('tenant_users')->onDelete('set null');
            $table->string('subject');
            $table->string('category', 80)->default('general');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['open', 'pending_admin', 'pending_customer', 'resolved', 'closed'])->default('open');
            $table->enum('last_reply_by', ['tenant', 'admin', 'system'])->default('tenant');
            $table->boolean('unread_for_admin')->default(true)->index();
            $table->boolean('unread_for_tenant')->default(false)->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('support_tickets');
    }
};
