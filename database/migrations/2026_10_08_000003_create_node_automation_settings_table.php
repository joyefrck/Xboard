<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        if (DB::table('v2_node_automation_groups')->where('enabled', true)->exists() ||
            DB::table('v2_node_automation_logs')->whereIn('status', ['queued', 'running', 'prepared', 'verifying', 'dns_unknown'])->exists()) {
            throw new RuntimeException('请先暂停节点自动化，并等待所有检测与 DNS 操作完成后再升级公共检测账号');
        }
        Schema::create('v2_node_automation_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('probe_user_id')->nullable();
            $t->timestamps();
        });
        // Migrate only an unambiguous identity; never choose one of several old users.
        $users = DB::table('v2_node_automation_groups')->pluck('probe_users')
            ->flatMap(fn ($value) => array_values(json_decode($value ?? '[]', true) ?: []))
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique();
        DB::table('v2_node_automation_settings')->insert(['id' => 1,
            'probe_user_id' => $users->count() === 1 ? $users->first() : null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('v2_node_automation_groups')->update(['probe_users' => null, 'config_hash' => null,
            'failures' => 0, 'reason' => '已改用公共检测账号，请确认账号并重新验证', 'updated_at' => now()]);
        DB::table('v2_node_automation_backups')->whereNotIn('state', ['active', 'needs_review'])
            ->update(['state' => 'unchecked', 'tested_at' => null, 'tested_hash' => null]);
        DB::table('v2_node_automation_checks')->delete();
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_node_automation_settings');
    }
};
