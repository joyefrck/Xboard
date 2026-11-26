<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('v2_node_automation_logs', 'actor')) return;
        Schema::table('v2_node_automation_logs', function (Blueprint $t) {
            // Service identity that started the operation (e.g. the AI assistant); null for admins and the scheduler.
            $t->string('actor', 32)->nullable()->after('admin_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('v2_node_automation_logs', 'actor')) return;
        Schema::table('v2_node_automation_logs', fn (Blueprint $t) => $t->dropColumn('actor'));
    }
};
