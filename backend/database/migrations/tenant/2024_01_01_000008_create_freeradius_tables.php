<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('radcheck', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->index();
            $table->string('attribute', 64);
            $table->char('op', 2)->default('==');
            $table->string('value', 253);
            $table->index(['username', 'attribute']);
        });

        Schema::connection('tenant')->create('radreply', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64)->index();
            $table->string('attribute', 64);
            $table->char('op', 2)->default('=');
            $table->string('value', 253);
            $table->index(['username', 'attribute']);
        });

        Schema::connection('tenant')->create('radgroupcheck', function (Blueprint $table) {
            $table->id();
            $table->string('groupname', 64)->index();
            $table->string('attribute', 64);
            $table->char('op', 2)->default('==');
            $table->string('value', 253);
        });

        Schema::connection('tenant')->create('radgroupreply', function (Blueprint $table) {
            $table->id();
            $table->string('groupname', 64)->index();
            $table->string('attribute', 64);
            $table->char('op', 2)->default('=');
            $table->string('value', 253);
        });

        Schema::connection('tenant')->create('radusergroup', function (Blueprint $table) {
            $table->string('username', 64)->index();
            $table->string('groupname', 64);
            $table->integer('priority')->default(1);
        });

        Schema::connection('tenant')->create('radacct', function (Blueprint $table) {
            $table->bigIncrements('radacctid');
            $table->string('acctsessionid', 64)->index();
            $table->string('acctuniqueid', 32)->unique();
            $table->string('username', 64)->index();
            $table->string('groupname', 64)->nullable();
            $table->string('realm', 64)->nullable();
            $table->string('nasipaddress', 15)->index();
            $table->string('nasportid', 15)->nullable();
            $table->string('nasporttype', 32)->nullable();
            $table->dateTime('acctstarttime')->nullable()->index();
            $table->dateTime('acctupdatetime')->nullable();
            $table->dateTime('acctstoptime')->nullable()->index();
            $table->integer('acctinterval')->nullable()->index();
            $table->integer('acctsessiontime')->unsigned()->nullable()->index();
            $table->string('acctauthentic', 32)->nullable();
            $table->string('connectinfo_start', 50)->nullable();
            $table->string('connectinfo_stop', 50)->nullable();
            $table->bigInteger('acctinputoctets')->nullable();
            $table->bigInteger('acctoutputoctets')->nullable();
            $table->string('calledstationid', 50)->index();
            $table->string('callingstationid', 50)->index();
            $table->string('acctterminatecause', 32);
            $table->string('servicetype', 32)->nullable();
            $table->string('framedprotocol', 32)->nullable();
            $table->string('framedipaddress', 15)->index();
        });

        Schema::connection('tenant')->create('radpostauth', function (Blueprint $table) {
            $table->id();
            $table->string('username', 64);
            $table->string('pass', 64);
            $table->string('reply', 32);
            $table->timestamp('authdate')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('radpostauth');
        Schema::connection('tenant')->dropIfExists('radacct');
        Schema::connection('tenant')->dropIfExists('radusergroup');
        Schema::connection('tenant')->dropIfExists('radgroupreply');
        Schema::connection('tenant')->dropIfExists('radgroupcheck');
        Schema::connection('tenant')->dropIfExists('radreply');
        Schema::connection('tenant')->dropIfExists('radcheck');
    }
};
