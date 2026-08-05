<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('v2_distribution_apps')
            ->where('distribution_scope', 'official_update')
            ->where('app_key', 'elephant-route-android')
            ->update(['name' => '大象网络官方App安卓版']);

        DB::table('v2_distribution_apps')
            ->where('distribution_scope', 'official_update')
            ->where('app_key', 'elephant-route-desktop')
            ->update(['name' => '大象网络官方App桌面版']);
    }

    public function down(): void
    {
        // Previous free-form names cannot be reconstructed safely.
    }
};
