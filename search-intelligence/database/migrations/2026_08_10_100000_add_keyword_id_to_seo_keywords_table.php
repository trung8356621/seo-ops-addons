<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search Intelligence seo_keywords joins Search Foundation canonical keywords.id.
 * Duplicate phrase identity must not fork forever — keyword_id is the join key.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keywords')) {
            return;
        }

        if (! $schema->hasColumn('seo_keywords', 'keyword_id')) {
            $schema->table('seo_keywords', function (Blueprint $table): void {
                $table->unsignedBigInteger('keyword_id')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_keywords') && $schema->hasColumn('seo_keywords', 'keyword_id')) {
            $schema->table('seo_keywords', function (Blueprint $table): void {
                $table->dropColumn('keyword_id');
            });
        }
    }
};
