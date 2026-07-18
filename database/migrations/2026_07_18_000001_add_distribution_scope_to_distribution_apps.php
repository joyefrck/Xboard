<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('v2_distribution_apps', 'distribution_scope')) {
            Schema::table('v2_distribution_apps', function (Blueprint $table) {
                $table->string('distribution_scope', 32)
                    ->default('download_only')
                    ->after('description')
                    ->index();
            });
        }

        DB::table('v2_distribution_apps')
            ->whereIn('app_key', [
                'elephant-route-android',
                'elephant-route-desktop',
                'elephant-route-mac',
            ])
            ->update(['distribution_scope' => 'official_update']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('v2_distribution_apps', 'distribution_scope')) {
            Schema::table('v2_distribution_apps', function (Blueprint $table) {
                $table->dropIndex(['distribution_scope']);
                $table->dropColumn('distribution_scope');
            });
        }
    }
};
