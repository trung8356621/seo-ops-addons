<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Run-level rebuild_mode for Topic grouping (TASK 6.3). */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topic_grouping_runs')) {
            return;
        }

        $schema->table('seo_topic_grouping_runs', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_topic_grouping_runs', 'rebuild_mode')) {
                $table->string('rebuild_mode', 32)
                    ->default('preserve_existing')
                    ->after('provider');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topic_grouping_runs')) {
            return;
        }
        $schema->table('seo_topic_grouping_runs', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('seo_topic_grouping_runs', 'rebuild_mode')) {
                $table->dropColumn('rebuild_mode');
            }
        });
    }
};
