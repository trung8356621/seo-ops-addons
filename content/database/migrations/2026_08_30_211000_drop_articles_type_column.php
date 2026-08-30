<?php

declare(strict_types=1);

/**
 * Drop legacy articles.type after content_type + wp_is_term cutover.
 *
 * Prerequisites (enforced softly): content_type meta must exist for rows that
 * previously relied on articles.type. Backfill migration 2026_08_30_210000 runs first.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasColumn('articles', 'type')) {
            return;
        }

        // Safety: do not drop if any article lacks content_type after backfill window.
        $missing = DB::connection($this->connection)
            ->table('articles as a')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('article_meta as m')
                    ->whereColumn('m.article_id', 'a.id')
                    ->where('m.meta_key', 'content_type')
                    ->whereNotNull('m.meta_value')
                    ->where('m.meta_value', '!=', '');
            })
            ->limit(1)
            ->exists();

        if ($missing) {
            throw new \RuntimeException(
                'Cannot drop articles.type: some articles still lack article_meta.content_type. Re-run backfill first.',
            );
        }

        $schema->table('articles', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasColumn('articles', 'type')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table): void {
            $table->string('type', 50)->nullable()->after('status');
        });
    }
};
