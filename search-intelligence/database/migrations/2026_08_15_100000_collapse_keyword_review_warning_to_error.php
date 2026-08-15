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
        if (Schema::connection($this->connection)->hasTable('keywords')
            && Schema::connection($this->connection)->hasColumn('keywords', 'review_status')) {
            DB::connection($this->connection)
                ->table('keywords')
                ->where('review_status', 'warning')
                ->update(['review_status' => 'danger']);
        }

        if (Schema::connection($this->connection)->hasTable('keyword_review_histories')
            && Schema::connection($this->connection)->hasColumn('keyword_review_histories', 'to_status')) {
            DB::connection($this->connection)
                ->table('keyword_review_histories')
                ->where('to_status', 'warning')
                ->update(['to_status' => 'danger', 'severity' => 'danger']);
        }
    }

    public function down(): void
    {
        // Irreversible semantics collapse: warning no longer exists as a live review state.
    }
};
