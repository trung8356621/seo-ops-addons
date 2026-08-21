<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;

final class ContentProjectKeywordsColumnContractTest extends TestCase
{
    public function test_ops_list_renders_keyword_text_not_count(): void
    {
        $blade = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-items-list.blade.php'),
        );

        self::assertStringContainsString("\$row['keyword']", $blade);
        self::assertStringContainsString('cp-ops-kw-text', $blade);
        self::assertStringNotContainsString("\$row['keywords_count']", $blade);
        self::assertStringNotContainsString('cp-ops-kw-count', $blade);
    }

    public function test_read_model_exposes_keyword_text_with_empty_dash(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Services/ContentProject/ContentProjectItemOperationsReadModel.php',
        );

        self::assertStringContainsString("'keyword' => \$keyword !== '' ? \$keyword : '—'", $src);
    }
}
