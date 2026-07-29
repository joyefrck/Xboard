<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_app_artifacts')) {
            return;
        }

        if (!Schema::hasColumn('v2_app_artifacts', 'mirror_status')) {
            Schema::table('v2_app_artifacts', function (Blueprint $table) {
                $table->string('mirror_status', 16)
                    ->default('local')
                    ->after('uploaded_by');
            });
        }

        if (!Schema::hasIndex('v2_app_artifacts', 'v2_app_artifacts_mirror_status_index')) {
            Schema::table('v2_app_artifacts', function (Blueprint $table) {
                $table->index('mirror_status', 'v2_app_artifacts_mirror_status_index');
            });
        }

        Schema::table('v2_app_artifacts', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_app_artifacts', 'mirror_path')) {
                $table->string('mirror_path', 1024)->nullable();
            }

            if (!Schema::hasColumn('v2_app_artifacts', 'mirror_error')) {
                $table->text('mirror_error')->nullable();
            }

            if (!Schema::hasColumn('v2_app_artifacts', 'mirrored_at')) {
                $table->timestamp('mirrored_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_app_artifacts')) {
            return;
        }

        if (Schema::hasIndex('v2_app_artifacts', 'v2_app_artifacts_mirror_status_index')) {
            Schema::table('v2_app_artifacts', function (Blueprint $table) {
                $table->dropIndex('v2_app_artifacts_mirror_status_index');
            });
        }

        foreach ([
            'mirror_status',
            'mirror_path',
            'mirror_error',
            'mirrored_at',
        ] as $column) {
            if (Schema::hasColumn('v2_app_artifacts', $column)) {
                Schema::table('v2_app_artifacts', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
