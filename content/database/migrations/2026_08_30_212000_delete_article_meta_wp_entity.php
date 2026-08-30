<?php

declare(strict_types=1);

/**
 * Remove deprecated article_meta.wp_entity after wp_is_term cutover.
 */
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $db = DB::connection($this->connection);

        $missingFlag = $db->table('article_meta as entity')
            ->where('entity.meta_key', 'wp_entity')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('article_meta as flag')
                    ->whereColumn('flag.article_id', 'entity.article_id')
                    ->where('flag.meta_key', 'wp_is_term');
            })
            ->limit(1)
            ->exists();

        if ($missingFlag) {
            throw new \RuntimeException(
                'Cannot delete wp_entity: some rows lack wp_is_term. Re-run classification backfill first.',
            );
        }

        $db->table('article_meta')->where('meta_key', 'wp_entity')->delete();
    }

    public function down(): void
    {
        // Non-reversible cleanup; wp_is_term remains SoT.
    }
};
