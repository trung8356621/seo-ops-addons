<?php

declare(strict_types=1);

use Omnichannel\Addons\Content\Enums\ArticleReviewStatus;
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

        if (! $schema->hasTable('seo_article_reviews')) {
            $schema->create('seo_article_reviews', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('article_id')->index();
                $table->string('action_type', 32)->index();
                $table->string('from_status', 32)->nullable();
                $table->string('to_status', 32)->index();
                $table->unsignedBigInteger('reviewer_id')->index();
                $table->string('reviewer_role', 64)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index('created_at');
            });
        }

        if ($schema->hasTable('articles') && ! $schema->hasColumn('articles', 'review_status')) {
            $schema->table('articles', function (Blueprint $table) use ($schema): void {
                $after = $schema->hasColumn('articles', 'reviewed_at') ? 'reviewed_at' : 'is_reviewed';
                $table->string('review_status', 32)->default(ArticleReviewStatus::Draft->value)->after($after)->index();
            });
        }

        $this->backfillReviewStatus();
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasColumn('articles', 'review_status')) {
            $schema->table('articles', function (Blueprint $table): void {
                $table->dropColumn('review_status');
            });
        }

        $schema->dropIfExists('seo_article_reviews');
    }

    private function backfillReviewStatus(): void
    {
        $connection = DB::connection($this->connection);

        $connection->table('articles')
            ->whereNotNull('content_archived_at')
            ->update(['review_status' => ArticleReviewStatus::Archived->value]);

        $connection->table('articles')
            ->whereNull('content_archived_at')
            ->where('is_reviewed', true)
            ->update(['review_status' => ArticleReviewStatus::Approved->value]);

        $connection->table('articles')
            ->whereNull('content_archived_at')
            ->where(function ($query): void {
                $query->where('is_reviewed', false)->orWhereNull('is_reviewed');
            })
            ->update(['review_status' => ArticleReviewStatus::Draft->value]);
    }
};
