<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto Cost + Auto Rerun fields for Global Monthly batch items.
 * Existing batches keep sticky ai_cost_policy; new columns are nullable/default-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('seo_content_global_batch_items')) {
            return;
        }

        $schema->table('seo_content_global_batch_items', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_content_global_batch_items', 'current_strategy')) {
                $table->string('current_strategy', 32)->nullable()->after('status');
            }
            if (! $schema->hasColumn('seo_content_global_batch_items', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0)->after('attempt_count');
            }
            if (! $schema->hasColumn('seo_content_global_batch_items', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable()->index()->after('retry_count');
            }
            if (! $schema->hasColumn('seo_content_global_batch_items', 'free_attempted')) {
                $table->boolean('free_attempted')->default(false)->after('next_retry_at');
            }
            if (! $schema->hasColumn('seo_content_global_batch_items', 'paid_attempted')) {
                $table->boolean('paid_attempted')->default(false)->after('free_attempted');
            }
            if (! $schema->hasColumn('seo_content_global_batch_items', 'last_error_category')) {
                $table->string('last_error_category', 64)->nullable()->after('error_message');
            }
            if (! $schema->hasColumn('seo_content_global_batch_items', 'attempt_meta')) {
                $table->json('attempt_meta')->nullable()->after('last_error_category');
            }
            if (! $schema->hasColumn('seo_content_global_batch_items', 'succeeded_via')) {
                $table->string('succeeded_via', 32)->nullable()->after('attempt_meta');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('seo_content_global_batch_items')) {
            return;
        }

        $schema->table('seo_content_global_batch_items', function (Blueprint $table) use ($schema): void {
            foreach ([
                'current_strategy',
                'retry_count',
                'next_retry_at',
                'free_attempted',
                'paid_attempted',
                'last_error_category',
                'attempt_meta',
                'succeeded_via',
            ] as $col) {
                if ($schema->hasColumn('seo_content_global_batch_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
