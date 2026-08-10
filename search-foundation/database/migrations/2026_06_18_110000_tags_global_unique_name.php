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
        Schema::connection($this->connection)->table('tags', function (Blueprint $table): void {
            $table->dropUnique('tags_site_id_name_unique');
        });

        $duplicateGroups = DB::connection($this->connection)
            ->table('tags')
            ->selectRaw('LOWER(TRIM(name)) AS normalized_name')
            ->groupBy('normalized_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $normalizedName = (string) $group->normalized_name;

            $tagIds = DB::connection($this->connection)
                ->table('tags')
                ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
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

        Schema::connection($this->connection)->table('tags', function (Blueprint $table): void {
            $table->unique('name', 'tags_name_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('tags', function (Blueprint $table): void {
            $table->dropUnique('tags_name_unique');
            $table->unique(['site_id', 'name'], 'tags_site_id_name_unique');
        });
    }
};
