<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('layout', ['single', 'grid-2x2', 'grid-2x4', 'grid-3x3'])->default('grid-2x4');
            $table->string('paper_size')->default('A4');
            $table->json('design')->nullable(); // Stores design settings like colors, fonts, logo position
            $table->string('logo_url')->nullable();
            $table->string('background_color')->default('#ffffff');
            $table->string('text_color')->default('#000000');
            $table->string('accent_color')->default('#3b82f6');
            $table->boolean('show_voucher_code')->default(true);
            $table->boolean('show_voucher_type')->default(true);
            $table->boolean('show_sales_point')->default(true);
            $table->boolean('show_duration')->default(true);
            $table->boolean('show_price')->default(true);
            $table->boolean('show_expiry')->default(false);
            $table->boolean('show_qr_code')->default(false);
            $table->text('header_text')->nullable();
            $table->text('footer_text')->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_templates');
    }
};
