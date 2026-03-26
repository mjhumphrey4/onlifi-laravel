<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check if sites table exists and update its structure
        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table) {
                // Add columns if they don't exist
                if (!Schema::hasColumn('sites', 'slug')) {
                    $table->string('slug', 100)->unique()->after('name');
                }
                if (!Schema::hasColumn('sites', 'description')) {
                    $table->string('description', 255)->nullable()->after('slug');
                }
                if (!Schema::hasColumn('sites', 'is_active')) {
                    $table->boolean('is_active')->default(true)->after('description');
                }
                if (!Schema::hasColumn('sites', 'api_token')) {
                    $table->string('api_token', 64)->unique()->after('is_active');
                }
            });

            // Generate API tokens for existing sites that don't have one
            DB::table('sites')->whereNull('api_token')->orWhere('api_token', '')->update([
                'api_token' => DB::raw("CONCAT(MD5(RAND()), MD5(RAND()))")
            ]);

            // Generate slugs for existing sites that don't have one
            $sites = DB::table('sites')->whereNull('slug')->orWhere('slug', '')->get();
            foreach ($sites as $site) {
                $slug = \Illuminate\Support\Str::slug($site->name);
                // Ensure unique slug
                $counter = 1;
                $originalSlug = $slug;
                while (DB::table('sites')->where('slug', $slug)->where('id', '!=', $site->id)->exists()) {
                    $slug = $originalSlug . '-' . $counter;
                    $counter++;
                }
                DB::table('sites')->where('id', $site->id)->update(['slug' => $slug]);
            }
        }

        // Add site_id to mikrotik_routers if not exists
        if (Schema::hasTable('mikrotik_routers') && !Schema::hasColumn('mikrotik_routers', 'site_id')) {
            Schema::table('mikrotik_routers', function (Blueprint $table) {
                $table->foreignId('site_id')->nullable()->after('id')->constrained('sites')->nullOnDelete();
            });
        }

        // Add site_id to voucher_groups if not exists
        if (Schema::hasTable('voucher_groups') && !Schema::hasColumn('voucher_groups', 'site_id')) {
            Schema::table('voucher_groups', function (Blueprint $table) {
                $table->foreignId('site_id')->nullable()->after('id')->constrained('sites')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('mikrotik_routers') && Schema::hasColumn('mikrotik_routers', 'site_id')) {
            Schema::table('mikrotik_routers', function (Blueprint $table) {
                $table->dropForeign(['site_id']);
                $table->dropColumn('site_id');
            });
        }

        if (Schema::hasTable('voucher_groups') && Schema::hasColumn('voucher_groups', 'site_id')) {
            Schema::table('voucher_groups', function (Blueprint $table) {
                $table->dropForeign(['site_id']);
                $table->dropColumn('site_id');
            });
        }

        if (Schema::hasTable('sites')) {
            Schema::table('sites', function (Blueprint $table) {
                if (Schema::hasColumn('sites', 'slug')) {
                    $table->dropColumn('slug');
                }
                if (Schema::hasColumn('sites', 'description')) {
                    $table->dropColumn('description');
                }
                if (Schema::hasColumn('sites', 'is_active')) {
                    $table->dropColumn('is_active');
                }
                if (Schema::hasColumn('sites', 'api_token')) {
                    $table->dropColumn('api_token');
                }
            });
        }
    }
};
