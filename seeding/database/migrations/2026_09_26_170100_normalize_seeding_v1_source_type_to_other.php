<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire legacy seeding_topics.source_type = seeding_v1 → other.
 * SeedingTopicSourceType::SeedingV1 is removed from the product enum.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seeding_topics')) {
            return;
        }

        if (! $schema->hasColumn('seeding_topics', 'source_type')) {
            return;
        }

        DB::connection($this->connection)
            ->table('seeding_topics')
            ->where('source_type', 'seeding_v1')
            ->update(['source_type' => 'other']);
    }

    public function down(): void
    {
        // Intentionally empty — seeding_v1 is no longer a supported source type.
    }
};
