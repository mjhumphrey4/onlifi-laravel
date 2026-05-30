<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('central')->hasTable('support_ticket_messages')) {
            return;
        }

        Schema::connection('central')->create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->onDelete('cascade');
            $table->enum('sender_type', ['tenant', 'admin', 'system']);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->longText('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'created_at']);
            $table->index(['sender_type', 'sender_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('support_ticket_messages');
    }
};
