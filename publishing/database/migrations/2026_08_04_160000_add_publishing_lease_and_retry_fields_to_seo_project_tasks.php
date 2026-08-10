<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishing Queue lease + retry persistence.
 * Stuck detection must NOT rely on updated_at.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $schema = Schema::connection($this->connection);

            if (! $schema->hasColumn('seo_project_tasks', 'publishing_started_at')) {
                $table->timestamp('publishing_started_at')->nullable()->after('last_publish_attempt_at');
            }
            if (! $schema->hasColumn('seo_project_tasks', 'publish_lease_expires_at')) {
                $table->timestamp('publish_lease_expires_at')->nullable()->after('publishing_started_at')->index();
            }
            if (! $schema->hasColumn('seo_project_tasks', 'publish_attempt_count')) {
                $table->unsignedSmallInteger('publish_attempt_count')->default(0)->after('publish_retry_count');
            }
            if (! $schema->hasColumn('seo_project_tasks', 'next_publish_retry_at')) {
                $table->timestamp('next_publish_retry_at')->nullable()->after('publish_lease_expires_at')->index();
            }
            if (! $schema->hasColumn('seo_project_tasks', 'last_publish_error_code')) {
                $table->string('last_publish_error_code', 64)->nullable()->after('last_publish_error');
            }
            if (! $schema->hasColumn('seo_project_tasks', 'last_publish_error_message')) {
                $table->text('last_publish_error_message')->nullable()->after('last_publish_error_code');
            }
            if (! $schema->hasColumn('seo_project_tasks', 'last_publish_http_status')) {
                $table->unsignedSmallInteger('last_publish_http_status')->nullable()->after('last_publish_error_message');
            }
            if (! $schema->hasColumn('seo_project_tasks', 'last_publish_failed_at')) {
                $table->timestamp('last_publish_failed_at')->nullable()->after('last_publish_http_status');
            }
            if (! $schema->hasColumn('seo_project_tasks', 'publish_operation_key')) {
                $table->string('publish_operation_key', 191)->nullable()->after('last_publish_failed_at')->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_project_tasks')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_project_tasks', function (Blueprint $table): void {
            $cols = [
                'publishing_started_at',
                'publish_lease_expires_at',
                'publish_attempt_count',
                'next_publish_retry_at',
                'last_publish_error_code',
                'last_publish_error_message',
                'last_publish_http_status',
                'last_publish_failed_at',
                'publish_operation_key',
            ];
            $existing = array_values(array_filter(
                $cols,
                static fn (string $c): bool => Schema::connection('omi_seo_ai')->hasColumn('seo_project_tasks', $c),
            ));
            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
