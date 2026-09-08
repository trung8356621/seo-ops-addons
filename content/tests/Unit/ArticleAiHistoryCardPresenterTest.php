<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use DateTimeImmutable;
use Omnichannel\Addons\Content\Support\ArticleAiHistoryCardPresenter;
use PHPUnit\Framework\TestCase;

final class ArticleAiHistoryCardPresenterTest extends TestCase
{
    public function test_compact_model_strips_provider_and_suffix_tag(): void
    {
        self::assertSame(
            'nemotron-3-ultra-550b-a55b · free',
            ArticleAiHistoryCardPresenter::compactModel('nvidia/nemotron-3-ultra-550b-a55b:free'),
        );
        self::assertSame('gpt-5.4', ArticleAiHistoryCardPresenter::compactModel('gpt-5.4'));
        self::assertSame('gpt-5.4 · paid', ArticleAiHistoryCardPresenter::compactModel('openai/gpt-5.4:paid'));
        self::assertSame('', ArticleAiHistoryCardPresenter::compactModel('  '));
        self::assertSame('Unknown model', ArticleAiHistoryCardPresenter::compactModel('Unknown model'));
        self::assertSame('3 models used', ArticleAiHistoryCardPresenter::compactModel('3 models used'));
    }

    public function test_attempt_meta_formats_retry_and_time_without_date(): void
    {
        $ranAt = new DateTimeImmutable('2026-09-08 01:12:00');

        $retry = ArticleAiHistoryCardPresenter::attemptMeta(2, $ranAt, 'retry');
        self::assertTrue($retry['is_retry']);
        self::assertSame('Attempt #2', $retry['attempt_label']);
        self::assertSame('01:12', $retry['time_label']);

        $first = ArticleAiHistoryCardPresenter::attemptMeta(1, $ranAt, 'first');
        self::assertFalse($first['is_retry']);
        self::assertSame('Attempt #1', $first['attempt_label']);
        self::assertSame('01:12', $first['time_label']);
    }

    public function test_group_date_label_is_date_only(): void
    {
        self::assertSame(
            '08/09/2026',
            ArticleAiHistoryCardPresenter::groupDateLabel(new DateTimeImmutable('2026-09-08 01:15:00')),
        );
    }

    public function test_word_count_label(): void
    {
        self::assertSame('301 từ', ArticleAiHistoryCardPresenter::wordCountLabel(301));
        self::assertNull(ArticleAiHistoryCardPresenter::wordCountLabel(0));
        self::assertNull(ArticleAiHistoryCardPresenter::wordCountLabel(null));
    }
}
