<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\KeywordIntelligence\ViewKeywordWorkspace;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeywordIntelligenceUiTest extends TestCase
{
    public function test_view_workspace_allows_phase2_tabs(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(ViewKeywordWorkspace::class))->getFileName(),
        );
        self::assertStringContainsString('existing_content', $source);
        self::assertStringContainsString('analysis', $source);
        self::assertStringContainsString('AnalyzeKeywordWorkspaceCommand', $source);
        self::assertStringNotContainsString('SeoKiKeyword::query()->update', $source);
    }
}
