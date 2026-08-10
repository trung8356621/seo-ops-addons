<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('tags', 'site_id')) {
            return;
        }

        Schema::connection($this->connection)->table('tags', function (Blueprint $table): void {
            $table->dropUnique(['site_id', 'slug']);
            $table->dropIndex(['site_id', 'name']);
            $table->dropIndex(['site_id']);
        });

        $this->dedupeConflictingSlugs();

        Schema::connection($this->connection)->table('tags', function (Blueprint $table): void {
            $table->dropColumn('site_id');
            $table->unique('slug', 'tags_slug_unique');
        });
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasColumn('tags', 'site_id')) {
            return;
        }

        Schema::connection($this->connection)->table('tags', function (Blueprint $table): void {
            $table->dropUnique('tags_slug_unique');
            $table->unsignedBigInteger('site_id')->default(0)->index()->after('id');
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'name']);
        });
    }

    private function dedupeConflictingSlugs(): void
    {
        $duplicateGroups = DB::connection($this->connection)
            ->table('tags')
            ->selectRaw('LOWER(TRIM(slug)) AS normalized_slug')
            ->groupBy('normalized_slug')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $normalizedSlug = (string) $group->normalized_slug;

            $tagIds = DB::connection($this->connection)
                ->table('tags')
                ->whereRaw('LOWER(TRIM(slug)) = ?', [$normalizedSlug])
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if (count($tagIds) < 2) {
                continue;
            }

            $keeperId = $tagIds[0];

            foreach (array_slice($tagIds, 1) as $duplicateId) {
                $keywordIds = DB::connection($this->connection)
                    ->table('keyword_tag')
                    ->where('tag_id', $duplicateId)
                    ->pluck('keyword_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($keywordIds as $keywordId) {
                    DB::connection($this->connection)
                        ->table('keyword_tag')
                        ->insertOrIgnore([
                            'keyword_id' => $keywordId,
                            'tag_id' => $keeperId,
                        ]);
                }

                DB::connection($this->connection)
                    ->table('keyword_tag')
                    ->where('tag_id', $duplicateId)
                    ->delete();

                DB::connection($this->connection)
                    ->table('tags')
                    ->where('id', $duplicateId)
                    ->delete();
            }
        }
    }
};
