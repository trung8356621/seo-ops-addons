<?php

declare(strict_types=1);

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
use Omnichannel\Addons\Content\Support\ArticleReviewCutoverRules;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch C hard cutover — align review_status from legacy is_reviewed, then drop column.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('articles') || ! $schema->hasColumn('articles', 'is_reviewed')) {
            return;
        }

        $stats = ArticleReviewCutoverRules::emptyStats();

        DB::connection($this->connection)
            ->table('articles')
            ->orderBy('id')
            ->select(['id', 'review_status', 'is_reviewed', 'reviewed_at'])
            ->chunkById(500, function ($rows) use (&$stats): void {
                foreach ($rows as $row) {
                    $stats['scanned']++;
                    $isReviewed = (bool) ($row->is_reviewed ?? false);
                    $reviewStatus = is_string($row->review_status ?? null) ? (string) $row->review_status : null;
                    $decision = ArticleReviewCutoverRules::decide($reviewStatus, $isReviewed);

                    $rule = $decision['rule'];
                    if (isset($stats[$rule])) {
                        $stats[$rule]++;
                    } else {
                        $stats['preserve_other']++;
                    }

                    if ($decision['action'] === 'preserve') {
                        continue;
                    }

                    $stats['updated']++;
                    $payload = ['review_status' => $decision['target_status']];
                    if ($decision['action'] === 'set_approved' && ($row->reviewed_at ?? null) === null) {
                        $payload['reviewed_at'] = now();
                    }

                    DB::connection($this->connection)
                        ->table('articles')
                        ->where('id', (int) $row->id)
                        ->update($payload);
                }
            });

        echo 'Cutover is_reviewed stats: '.json_encode($stats, JSON_THROW_ON_ERROR).PHP_EOL;

        $schema->table('articles', function (Blueprint $table): void {
            $table->dropColumn('is_reviewed');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('articles') || $schema->hasColumn('articles', 'is_reviewed')) {
            return;
        }

        $after = $schema->hasColumn('articles', 'review_status') ? 'review_status' : 'status';

        $schema->table('articles', function (Blueprint $table) use ($after): void {
            $table->boolean('is_reviewed')->default(false)->after($after);
        });
    }
};
