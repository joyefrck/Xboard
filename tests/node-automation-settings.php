<?php
declare(strict_types=1);
require __DIR__ . '/node-automation.php';

use App\Models\{NodeAutomationSetting as Setting, NodeAutomationGroup as MigrationGroup};

$baseline = $checks;
$migration = require dirname(__DIR__) . '/database/migrations/2026_10_08_000003_create_node_automation_settings_table.php';
[$g, $b, $dns, $probe, $service] = fixture();
$migration->down();
try { $migration->up(); expect(false, 'enabled migration guard'); }
catch (RuntimeException $e) {
    expect(!$db->schema()->hasTable('v2_node_automation_settings'), 'upgrade refuses enabled strategies before creating a table');
}
$g->update(['enabled' => false, 'probe_users' => ['1' => 5, '2' => 5]]);
$event = operation($g, 'auto', $b->id, ['status' => 'dns_unknown']);
try { $migration->up(); expect(false, 'unfinished DNS migration guard'); }
catch (RuntimeException $e) {
    expect(!$db->schema()->hasTable('v2_node_automation_settings'), 'upgrade refuses unfinished DNS operations without touching state');
}
$event->update(['status' => 'completed']);
$b->update(['state' => 'ready', 'tested_hash' => 'old', 'tested_at' => now()]);
$migration->up();
expect(Setting::find(1)->probe_user_id === 5, 'same old user on all nodes migrates to one common account');
expect($g->refresh()->probe_users === null && $g->config_hash === null && !$g->enabled, 'legacy overrides cleared and strategy requires revalidation');
expect($b->refresh()->state === 'unchecked' && $b->tested_hash === null, 'upgrade invalidates old backup results');
$migration->down();
$g->update(['probe_users' => ['1' => 5, '2' => 6]]);
MigrationGroup::create(['domain' => 'jp.example.test', 'probe_users' => ['11' => 6]]);
$migration->up();
expect(Setting::find(1)->probe_user_id === null, 'ambiguous old accounts require explicit admin choice');
$migration->down();
$migration->up();
expect(Setting::find(1)->probe_user_id === null, 'new installation leaves common account unconfigured');
echo 'node automation settings: ' . ($checks - $baseline) . " checks passed\n";

// Upgrade from the global identity to permission-group identities.
$groupMigration = require dirname(__DIR__) . '/database/migrations/2026_10_18_000001_add_group_probe_users_to_node_automation_settings.php';
$db->schema()->create('v2_user', function (Illuminate\Database\Schema\Blueprint $t) { $t->id(); $t->integer('group_id'); });
$db->schema()->create('v2_server_group', function (Illuminate\Database\Schema\Blueprint $t) { $t->id(); $t->string('name'); });
$db->table('v2_user')->insert(['id'=>5,'group_id'=>2]);
$db->table('v2_server_group')->insert(['id'=>2,'name'=>'level3']);
Setting::find(1)->update(['probe_user_id'=>5]);
$g->update(['enabled'=>true]);
try { $groupMigration->up(); expect(false, 'group upgrade enabled guard'); }
catch (RuntimeException $e) { expect(!$db->schema()->hasColumn('v2_node_automation_settings','probe_users_by_group'), 'group upgrade refuses enabled strategies without schema changes'); }
$g->update(['enabled'=>false]);
$event->update(['status'=>'dns_unknown']);
try { $groupMigration->up(); expect(false, 'group upgrade pending guard'); }
catch (RuntimeException $e) { expect(!$db->schema()->hasColumn('v2_node_automation_settings','probe_users_by_group'), 'group upgrade refuses unresolved DNS intent'); }
$event->update(['status'=>'completed']);
$g->update(['config_hash'=>'old','failures'=>2]);
$b->update(['state'=>'ready','tested_hash'=>'old']);
$quarantine=App\Models\NodeAutomationBackup::create(['group_id'=>$g->id,'name'=>'old','ip'=>'9.9.9.9','state'=>'needs_review','enabled'=>false]);
App\Models\NodeAutomationCheck::create(['group_id'=>$g->id,'ip'=>$g->current_ip,'status'=>'pass','checked_at'=>now()]);
$groupMigration->up();
expect(Setting::find(1)->probe_users_by_group===[2=>5]&&Setting::find(1)->probe_user_id===null, 'legacy account migrates only to its actual permission group and global identity cleared');
expect($g->refresh()->config_hash===null&&$g->failures===0&&$b->refresh()->state==='unchecked'&&$b->tested_hash===null, 'group upgrade invalidates configuration and backup certifications');
expect($quarantine->refresh()->state==='needs_review'&&!$quarantine->enabled&&App\Models\NodeAutomationCheck::count()===0, 'group upgrade clears observations while preserving quarantined VPS');
$groupMigration->down();
Setting::find(1)->update(['probe_user_id'=>999]);
$groupMigration->up();
expect(Setting::find(1)->probe_users_by_group===[]&&Setting::find(1)->probe_user_id===null,'deleted legacy user is not mapped');
$groupMigration->down();
$db->table('v2_server_group')->delete();
Setting::find(1)->update(['probe_user_id'=>5]);
$groupMigration->up();
expect(Setting::find(1)->probe_users_by_group===[], 'deleted permission group is not mapped');
echo 'node automation group settings: upgrade checks passed' . PHP_EOL;
