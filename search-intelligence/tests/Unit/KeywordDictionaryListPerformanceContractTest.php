<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\Concerns\InteractsWithKeywordItemActions;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\ListKeywords;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordDictionaryQuery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class KeywordDictionaryListPerformanceContractTest extends TestCase
{
    public function test_dictionary_query_projects_complete_lightweight_row_state(): void
    {
        $source = $this->source(KeywordDictionaryQuery::class);

        self::assertStringContainsString('withListState', $source);
        self::assertStringContainsString("'metas as seo_hidden'", $source);
        self::assertStringContainsString("'metas as mcp_excluded'", $source);
        self::assertStringContainsString("'linkMaps as has_site_links'", $source);
        self::assertStringNotContainsString('locked_by_active_job', $source);
        self::assertStringNotContainsString('LOWER(TRIM(list_tasks.source_content))', $source);
        self::assertStringContainsString('as focus_article_count', $source);
        self::assertStringContainsString('as linked_article_count', $source);
        self::assertStringContainsString('COUNT(DISTINCT list_maps.source_article_id)', $source);
        self::assertStringContainsString('list_sources.site_id = ?', $source);
        self::assertStringContainsString('leftJoin(\'keyword_meta as list_site_focus_meta\'', $source);
        self::assertSame(1, substr_count($source, 'COALESCE(list_site_focus_meta.meta_value, list_legacy_focus_meta.meta_value)'));
    }

    public function test_normal_dictionary_query_does_not_eager_load_rich_tag_graph(): void
    {
        $source = $this->source(KeywordResource::class);
        $method = $this->methodBody($source, 'getEloquentQuery');

        self::assertStringNotContainsString('tableEagerLoad', $method);
        self::assertStringNotContainsString('->with(', $method);
    }

    public function test_list_page_uses_dedicated_dictionary_query_not_generic_resource_query(): void
    {
        $source = $this->source(ListKeywords::class);
        $method = $this->methodBody($source, 'buildDictionaryFilteredQuery');

        self::assertStringContainsString('->filtered(', $method);
        self::assertStringNotContainsString('parent::getTableQuery()', $method);
        self::assertStringNotContainsString('getEloquentQuery()', $method);
    }

    public function test_dictionary_presenter_uses_projected_state_without_fallback_services(): void
    {
        $source = $this->source(KeywordItemPresenter::class);

        self::assertStringContainsString("\$attributes['seo_hidden']", $source);
        self::assertStringContainsString("\$attributes['mcp_excluded']", $source);
        self::assertStringContainsString('$listOnly || $panel === null ? 0', $source);
        self::assertStringContainsString('$this->tags->resolve([', $source);
        self::assertStringContainsString('$isDictionary ? null : app(KeywordLinkDetailPanelPresenter::class)', $source);
    }

    public function test_list_action_visibility_uses_projected_state_and_mutations_revalidate(): void
    {
        $list = $this->source(ListKeywords::class);
        $actions = $this->source(InteractsWithKeywordItemActions::class);
        $resource = $this->source(KeywordResource::class);

        self::assertStringNotContainsString('HideKeywordFromSeoService', $list);
        self::assertStringNotContainsString('SkipKeywordFromMcpService', $list);
        self::assertStringContainsString('canMutateKeywordVisibilityFromListState', $list);
        self::assertStringContainsString('canDelete($record)', $list);
        self::assertStringContainsString('canMutateKeywordVisibility($keyword)', $actions);
        self::assertStringContainsString('canEdit($record)', $this->methodBody($resource, 'saveKeywordFromFormData'));
    }

    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }

    private function methodBody(string $source, string $method): string
    {
        $pattern = '/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{/';
        self::assertSame(1, preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE));
        $start = (int) $match[0][1] + strlen($match[0][0]);
        $depth = 1;

        for ($offset = $start, $length = strlen($source); $offset < $length; $offset++) {
            if ($source[$offset] === '{') {
                $depth++;
            } elseif ($source[$offset] === '}' && --$depth === 0) {
                return substr($source, $start, $offset - $start);
            }
        }

        self::fail('Method body not closed: '.$method);
    }
}
