<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        if (DB::table('v2_node_automation_groups')->where('enabled', true)->exists() ||
            DB::table('v2_node_automation_logs')->whereIn('status', ['queued', 'running', 'prepared', 'verifying', 'dns_unknown'])->exists()) {
            throw new RuntimeException('请先暂停节点自动化，并等待所有检测与 DNS 操作完成后再升级权限组检测账号');
        }
        Schema::table('v2_node_automation_settings', function (Blueprint $t) {
            $t->json('probe_users_by_group')->nullable();
        });
        foreach (DB::table('v2_node_automation_settings')->get() as $setting) {
            $user = $setting->probe_user_id ? DB::table('v2_user')->find($setting->probe_user_id) : null;
            $groupExists = $user?->group_id && DB::table('v2_server_group')->where('id', $user->group_id)->exists();
            DB::table('v2_node_automation_settings')->where('id', $setting->id)->update([
                'probe_users_by_group' => json_encode($groupExists ? [(string) $user->group_id => (int) $user->id] : new stdClass()),
                'probe_user_id' => null, 'updated_at' => now(),
            ]);
        }
        DB::table('v2_node_automation_groups')->update(['config_hash' => null, 'failures' => 0,
            'reason' => '已按权限组配置检测账号，请重新验证配置和备用', 'updated_at' => now()]);
        DB::table('v2_node_automation_backups')->whereNotIn('state', ['active', 'needs_review'])
            ->update(['state' => 'unchecked', 'tested_at' => null, 'tested_hash' => null]);
        DB::table('v2_node_automation_checks')->delete();
    }

    public function down(): void
    {
        // Never collapse several group identities into an arbitrary global account.
        Schema::table('v2_node_automation_settings', fn (Blueprint $t) => $t->dropColumn('probe_users_by_group'));
    }
};
