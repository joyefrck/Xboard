<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_node_automation_connections', function (Blueprint $t) {
            $t->id();
            $t->string('zone_name')->default('443ds443.com');
            $t->string('zone_id', 32)->nullable();
            $t->text('api_token');
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
        });
        Schema::create('v2_node_automation_groups', function (Blueprint $t) {
            $t->id();
            $t->string('domain')->unique();
            $t->unsignedBigInteger('connection_id')->nullable();
            $t->string('record_id', 32)->nullable();
            $t->string('current_ip', 45)->nullable();
            $t->boolean('enabled')->default(false)->index();
            $t->string('state')->default('unconfigured');
            $t->string('reason', 255)->nullable();
            $t->json('probe_users')->nullable();
            $t->string('config_hash', 64)->nullable();
            $t->unsignedInteger('failures')->default(0);
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('last_switched_at')->nullable();
            $t->timestamp('cooldown_until')->nullable();
            $t->timestamp('propagation_until')->nullable();
            $t->timestamps();
        });
        Schema::create('v2_node_automation_backups', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id')->index();
            $t->string('name', 80);
            $t->string('ip', 45);
            $t->unsignedInteger('priority')->default(0);
            $t->boolean('enabled')->default(true);
            $t->string('state')->default('unchecked');
            $t->string('reason', 255)->nullable();
            $t->timestamp('tested_at')->nullable();
            $t->string('tested_hash', 64)->nullable();
            $t->timestamps();
            $t->unique(['group_id', 'ip']);
        });
        Schema::create('v2_node_automation_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('group_id')->index();
            $t->string('operation_id', 36)->unique();
            $t->string('action', 24);
            $t->string('status', 24)->index();
            $t->unsignedBigInteger('backup_id')->nullable();
            $t->unsignedBigInteger('admin_id')->nullable();
            $t->string('from_ip', 45)->nullable();
            $t->string('to_ip', 45)->nullable();
            $t->json('record_before')->nullable();
            $t->json('result')->nullable();
            $t->string('message', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['logs', 'backups', 'groups', 'connections'] as $name) {
            Schema::dropIfExists('v2_node_automation_' . $name);
        }
    }
};
