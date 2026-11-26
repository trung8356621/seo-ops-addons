<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectProgressReadModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectArticleWordCountContractTest extends TestCase
{
    public function test_ops_and_progress_use_saved_body_counter_not_prompt_result(): void
    {
        $ops = $this->source(ContentProjectItemOperationsReadModel::class);
        $progress = $this->source(ContentProjectProgressReadModel::class);

        self::assertStringContainsString('ArticleBodyWordCounter', $ops);
        self::assertStringContainsString("'word_count'", $ops);
        self::assertStringContainsString('Saved article body only', $ops);
        // word_count path must not read run-item snapshots (ops may still select
        // output_snapshot elsewhere for generation diagnostics).
        $wordBlock = substr($ops, (int) strpos($ops, "'word_count'"), 280);
        self::assertStringContainsString('ArticleBodyWordCounter::countOrNull', $wordBlock);
        self::assertStringNotContainsString('output_snapshot', $wordBlock);
        self::assertStringNotContainsString('snapshot_actual_words', $wordBlock);

        self::assertStringContainsString('ArticleBodyWordCounter', $progress);
        self::assertStringContainsString('resolveWordCounts', $progress);
        self::assertStringContainsString("'word_count'", $progress);
        self::assertStringContainsString("get(['id', 'body'])", $progress);
        self::assertDoesNotMatchRegularExpression("/ITEM_COLUMNS\\s*=\\s*\\[[^\\]]*output_snapshot/s", $progress);
    }

    public function test_ops_table_renders_word_count_between_keywords_and_workflow(): void
    {
        $list = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );
        $theadPos = strpos($list, '<thead>');
        $theadEnd = strpos($list, '</thead>');
        self::assertNotFalse($theadPos);
        self::assertNotFalse($theadEnd);
        $thead = substr($list, $theadPos, $theadEnd - $theadPos);

        $kw = strpos($thead, 'ops_col_keywords');
        $words = strpos($thead, 'ops_col_word_count');
        $wf = strpos($thead, 'ops_col_workflow');
        self::assertNotFalse($kw);
        self::assertNotFalse($words);
        self::assertNotFalse($wf);
        self::assertLessThan($words, $kw);
        self::assertLessThan($wf, $words);
        self::assertStringContainsString('ArticleBodyWordCounter::format', $list);
    }

    public function test_progress_playlist_renders_word_count_column(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve(
                'resources/views/filament/resources/seo-project-resource/pages/view-seo-project-progress.blade.php',
            ),
        );

        self::assertStringContainsString('progress_col_word_count', $blade);
        self::assertStringContainsString('cp-col-words', $blade);
        self::assertStringContainsString('ArticleBodyWordCounter::format', $blade);
        self::assertStringContainsString("colspan=\"5\"", $blade);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($file);

        return (string) file_get_contents($file);
    }
}
