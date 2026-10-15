<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        foreach (['seo_keyword_quality_tags', 'seo_keyword_quality_policies', 'seo_keyword_quality_suppressions'] as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }
            DB::connection($this->connection)->table($table)->where('code', 'SUSPECT_PROPER_NAME')->delete();
        }
    }

    public function down(): void
    {
    }
};
