<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewSource;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordReviewHistory;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordReviewReason;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordReviewReasonService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordReviewService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class KeywordReviewServiceTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_user_review_sets_single_status_and_history(): void
    {
        $this->requireSeoDatabaseConnection();

        $reasonService = app(KeywordReviewReasonService::class);
        $reasonService->ensureDefaultReasons(1, 1);
        $reason = KeywordReviewReason::query()->where('workspace_id', 1)->first();
        $this->assertInstanceOf(KeywordReviewReason::class, $reason);

        $keyword = Keyword::query()->create([
            'phrase' => 'manual review keyword',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        $result = app(KeywordReviewService::class)->submitReview(
            $keyword,
            (int) $reason->id,
            KeywordReviewStatus::Warning,
            'Needs rewrite',
            null,
            1,
            KeywordReviewSource::ArticleSuggestion,
        );

        $fresh = $result['keyword'];
        $this->assertSame(KeywordReviewStatus::Warning->value, (string) $fresh->review_status);
        $this->assertSame((int) $reason->id, (int) $fresh->review_reason_id);
        $this->assertDatabaseHas('keyword_review_histories', [
            'keyword_id' => (int) $keyword->id,
            'to_status' => KeywordReviewStatus::Warning->value,
            'source' => KeywordReviewSource::ArticleSuggestion->value,
        ], 'omi_seo_ai');
    }

    public function test_custom_reason_review_sets_note_without_reason_id(): void
    {
        $this->requireSeoDatabaseConnection();

        $keyword = Keyword::query()->create([
            'phrase' => 'custom reason keyword',
            'type' => Keyword::TYPE_NORMAL,
        ]);

        $result = app(KeywordReviewService::class)->submitReview(
            $keyword,
            null,
            KeywordReviewStatus::Danger,
            null,
            'Off-topic anchor text',
            1,
            KeywordReviewSource::ArticleSuggestion,
            null,
            true,
            true,
        );

        $fresh = $result['keyword'];
        $this->assertSame(KeywordReviewStatus::Danger->value, (string) $fresh->review_status);
        $this->assertNull($fresh->review_reason_id);
        $this->assertSame('Off-topic anchor text', (string) $fresh->review_note);
        $this->assertDatabaseHas('keyword_review_histories', [
            'keyword_id' => (int) $keyword->id,
            'reason_id' => null,
            'note' => 'Off-topic anchor text',
            'to_status' => KeywordReviewStatus::Danger->value,
        ], 'omi_seo_ai');
    }

    public function test_article_suggestion_review_does_not_require_article_link(): void
    {
        $this->requireSeoDatabaseConnection();

        $reasonService = app(KeywordReviewReasonService::class);
        $reasonService->ensureDefaultReasons(1, 1);
        $reason = KeywordReviewReason::query()->where('workspace_id', 1)->first();
        $this->assertInstanceOf(KeywordReviewReason::class, $reason);

        $keyword = Keyword::query()->create([
            'phrase' => 'unlinked junk keyword',
            'type' => Keyword::TYPE_SUGGEST,
        ]);

        $result = app(KeywordReviewService::class)->submitReview(
            $keyword,
            (int) $reason->id,
            KeywordReviewStatus::Danger,
            null,
            null,
            1,
            KeywordReviewSource::ArticleSuggestion,
            999_999,
            true,
            true,
        );

        $fresh = $result['keyword'];
        $this->assertSame(KeywordReviewStatus::Danger->value, (string) $fresh->review_status);
        $this->assertDatabaseHas('keyword_review_histories', [
            'keyword_id' => (int) $keyword->id,
            'article_id' => 999_999,
            'source' => KeywordReviewSource::ArticleSuggestion->value,
        ], 'omi_seo_ai');
    }

    public function test_restore_keyword_returns_active_and_keeps_history(): void
    {
        $this->requireSeoDatabaseConnection();

        $reasonService = app(KeywordReviewReasonService::class);
        $reasonService->ensureDefaultReasons(1, 1);
        $reason = KeywordReviewReason::query()->where('workspace_id', 1)->first();
        $this->assertInstanceOf(KeywordReviewReason::class, $reason);

        $keyword = Keyword::query()->create([
            'phrase' => 'restore me',
            'type' => Keyword::TYPE_NORMAL,
            'review_status' => KeywordReviewStatus::Danger->value,
            'review_reason_id' => (int) $reason->id,
            'reviewed_by' => 1,
            'reviewed_at' => now(),
        ]);

        KeywordReviewHistory::query()->create([
            'keyword_id' => (int) $keyword->id,
            'from_status' => KeywordReviewStatus::Active->value,
            'to_status' => KeywordReviewStatus::Danger->value,
            'reason_id' => (int) $reason->id,
            'severity' => KeywordReviewStatus::Danger->value,
            'source' => KeywordReviewSource::KeywordsTable->value,
            'reviewed_by' => 1,
            'created_at' => now(),
        ]);

        $restored = app(KeywordReviewService::class)->restoreKeyword(
            $keyword,
            1,
            KeywordReviewSource::Restore,
        );

        $this->assertSame(KeywordReviewStatus::Active->value, (string) $restored->review_status);
        $this->assertSame(2, KeywordReviewHistory::query()->where('keyword_id', (int) $keyword->id)->count());
    }
}
