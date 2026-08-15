<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_classifications')) {
            return;
        }

        $schema->table('seo_keyword_classifications', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_keyword_classifications', 'source_kind')) {
                $table->string('source_kind', 32)->nullable()->index();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'is_seo_keyword')) {
                $table->boolean('is_seo_keyword')->nullable()->index();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'is_ambiguous')) {
                $table->boolean('is_ambiguous')->nullable()->index();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'keyword_score')) {
                $table->decimal('keyword_score', 5, 2)->nullable();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'classification_hash')) {
                $table->string('classification_hash', 64)->nullable();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'input_hash')) {
                $table->string('input_hash', 64)->nullable()->index();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'is_dirty')) {
                $table->boolean('is_dirty')->default(false)->index();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'duplicate_of')) {
                $table->unsignedBigInteger('duplicate_of')->nullable()->index();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'segments')) {
                $table->json('segments')->nullable();
            }
            if (! $schema->hasColumn('seo_keyword_classifications', 'occurrence_count')) {
                $table->unsignedInteger('occurrence_count')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Additive only — keep columns.
    }
};
