<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v2_app_download_logs', function (Blueprint $table) {
            $table->dropForeign(['app_artifact_id']);
        });

        Schema::table('v2_app_download_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('app_artifact_id')->nullable()->change();
            $table->foreign('app_artifact_id')
                ->references('id')
                ->on('v2_app_artifacts')
                ->cascadeOnDelete();
        });

        DB::table('v2_app_versions')
            ->whereNotNull('app_id')
            ->where(function ($query) {
                $query->whereNull('download_url')
                    ->orWhereRaw("TRIM(download_url) = ''");
            })
            ->update(['is_enabled' => false]);
    }

    public function down(): void
    {
        if (DB::table('v2_app_download_logs')->whereNull('app_artifact_id')->exists()) {
            throw new \RuntimeException('Cannot restore required artifact links while external download logs exist.');
        }

        Schema::table('v2_app_download_logs', function (Blueprint $table) {
            $table->dropForeign(['app_artifact_id']);
        });

        Schema::table('v2_app_download_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('app_artifact_id')->nullable(false)->change();
            $table->foreign('app_artifact_id')
                ->references('id')
                ->on('v2_app_artifacts')
                ->cascadeOnDelete();
        });
    }
};
