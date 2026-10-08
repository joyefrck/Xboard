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
