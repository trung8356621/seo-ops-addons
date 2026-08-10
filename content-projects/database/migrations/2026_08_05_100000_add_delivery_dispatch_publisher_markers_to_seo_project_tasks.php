<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separate scanner dispatch from active publisher lease.
 * - delivery_dispatched_at / publish_attempt_token / dispatch_count: claim+emit
 * - publisher_started_at: real WP worker start (owns lease + attempt increment)
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_project_tasks', 'delivery_dispatched_at')) {
                $table->timestamp('delivery_dispatched_at')->nullable()->after('publishing_started_at')->index();
            }
            if (! $schema->hasColumn('seo_project_tasks', 'publisher_started_at')) {
                $table->timestamp('publisher_started_at')->nullable()->after('delivery_dispatched_at')->index();
            }
            if (! $schema->hasColumn('seo_project_tasks', 'publish_attempt_token')) {
                $table->string('publish_attempt_token', 64)->nullable()->after('publish_operation_key')->index();
            }
            if (! $schema->hasColumn('seo_project_tasks', 'dispatch_count')) {
                $table->unsignedInteger('dispatch_count')->default(0)->after('publish_attempt_count');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_project_tasks')) {
            return;
        }

        $schema->table('seo_project_tasks', function (Blueprint $table) use ($schema): void {
            foreach (['delivery_dispatched_at', 'publisher_started_at', 'publish_attempt_token', 'dispatch_count'] as $column) {
                if ($schema->hasColumn('seo_project_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
