<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Topic candidacy flag on Group membership.
 *
 * Does not create Topics. Does not mutate existing Topic/DNA rows.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_group_keywords')) {
            return;
        }
        if ($schema->hasColumn('seo_keyword_group_keywords', 'is_topic_candidate')) {
            return;
        }

        $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
            $table->boolean('is_topic_candidate')->default(true);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_group_keywords')) {
            return;
        }
        if (! $schema->hasColumn('seo_keyword_group_keywords', 'is_topic_candidate')) {
            return;
        }

        $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
            $table->dropColumn('is_topic_candidate');
        });
    }
};
