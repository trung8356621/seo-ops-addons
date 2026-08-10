<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\Application\Commands\PreviewAddGscQueriesToKeywordWorkspaceCommand;
use Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence\GscKeywordWorkspaceQueryPreviewService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\AnalyzeSelectedKeywordsCommand;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\Application\Commands\ImportKeywordsCommand;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GscKeywordIntegrationTest extends TestCase
{
    public function test_preview_add_queries_command_capability_name(): void
    {
        self::assertSame(
            'gsc_intelligence.preview_add_queries',
            (new PreviewAddGscQueriesToKeywordWorkspaceCommand('gscp_1', 'kww_1'))->name(),
        );
    }

    public function test_preview_service_commits_via_keyword_commands(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(GscKeywordWorkspaceQueryPreviewService::class))->getFileName());
        self::assertStringContainsString(ImportKeywordsCommand::class, $source);
        self::assertStringContainsString(AnalyzeSelectedKeywordsCommand::class, $source);
        self::assertStringContainsString(PreviewAddGscQueriesToKeywordWorkspaceCommand::class, $source);
    }

    public function test_preview_builds_import_rows_from_unmapped_queries(): void
    {
        $service = new GscKeywordWorkspaceQueryPreviewService;
        $preview = $service->preview('kww_1', [
            ['display_query' => 'dịch vụ seo', 'normalized_query' => 'dịch vụ seo', 'impressions' => 200],
        ]);

        self::assertSame(1, $preview['candidate_count']);
        self::assertSame('gsc_intelligence', $preview['import_rows'][0]['source']);
        self::assertContains(ImportKeywordsCommand::class, $preview['commit_commands']);
    }
}
