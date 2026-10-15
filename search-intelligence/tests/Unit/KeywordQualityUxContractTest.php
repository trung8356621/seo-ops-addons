<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class KeywordQualityUxContractTest extends TestCase
{
    public function test_topic_page_owns_quality_source_settings_and_dictionary_only_polls_scan(): void
    {
        $list = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');
        $topic = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusters.php');
        $summary = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-quality-summary.blade.php');
        $listView = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/list-keywords.blade.php');

        self::assertStringNotContainsString("Action::make('quality_settings')", $list);
        self::assertStringContainsString('refreshQualityScan', $list);
        self::assertStringContainsString('noteScanQueued', $list);
        self::assertStringNotContainsString('resetPage()', $this->methodBody($list, 'refreshQualityScan'));
        self::assertStringContainsString("Action::make('quality_settings')", $topic);
        self::assertStringContainsString('setExcluded', $topic);
        self::assertStringNotContainsString('wire:poll.5s', $summary);
        self::assertSame(1, substr_count($listView, 'wire:poll.3s="refreshQualityScan"'));
        self::assertStringContainsString('quality_unique_excluded', (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/lang/vi/filament.php'));
        $vi = (string) file_get_contents(dirname(__DIR__, 3).'/seo-content-ai-compat/lang/vi/filament.php');
        $presenter = (string) file_get_contents(dirname(__DIR__, 2).'/src/Support/KeywordIntelligence/KeywordItemPresenter.php');
        foreach ([
            'Kêu gọi hành động',
            'Anchor chung chung',
            'Lỗi văn bản',
            'Lỗi mã hóa',
            'Dạng câu / mô tả',
            'Biến thể trùng',
            'Số thứ tự dư',
        ] as $label) {
            self::assertStringContainsString($label, $vi);
        }
        $qualityTags = $this->methodBody($presenter, 'qualityTags');
        self::assertStringContainsString('ws-badge ws-badge--compact ws-badge--gray', $qualityTags);
        self::assertStringNotContainsString('keyword-item-tag--planning', $qualityTags);
        self::assertStringContainsString('KeywordQualityCode::label', $qualityTags);
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method);
        self::assertNotFalse($start);
        $next = strpos($source, 'function ', $start + 10);

        return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
    }
}
