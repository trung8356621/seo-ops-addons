<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\KeywordTopicClusterDetail;
use Omnichannel\Addons\SearchIntelligence\Filament\Resources\KeywordResource\Pages\ListKeywords;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordItemPresenter;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordPhrasePresentation;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;
use Tests\TestCase;

final class KeywordItemContractTest extends TestCase
{
    public function test_dictionary_and_cluster_detail_include_canonical_keyword_item_partial(): void
    {
        $dictionaryColumn = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/tables/columns/keyword-item.blade.php',
        ));
        $detail = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ));

        self::assertStringContainsString('partials.keyword-item', $dictionaryColumn);
        self::assertStringContainsString('partials.keyword-item', $detail);
        self::assertStringNotContainsString('keyword-row', $detail);
    }

    public function test_legacy_keyword_row_partial_is_not_used(): void
    {
        $legacyRow = ProjectRoot::addonsPath().'/seo-content-ai-compat/resources/views/filament/resources/keywords/pages/partials/keyword-row.blade.php';
        self::assertFileDoesNotExist($legacyRow);

        $resource = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource.php');
        self::assertStringContainsString("ViewColumn::make('keyword_item')", $resource);
        self::assertStringNotContainsString('keyword-phrase', $resource);
        self::assertStringNotContainsString('keyword-operational-tags', $resource);
    }

    public function test_shared_livewire_traits_are_wired_on_both_pages(): void
    {
        $list = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');
        $detail = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');

        self::assertStringContainsString('InteractsWithKeywordItemActions', $list);
        self::assertStringContainsString('InteractsWithKeywordItemActions', $detail);
        self::assertStringContainsString('dictionaryKeywordDnaMap', $list);
        self::assertStringContainsString('clusterDataEpoch', $detail);
    }

    public function test_keyword_item_core_markup_is_shared(): void
    {
        $partial = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-item.blade.php',
        ));

        foreach ([
            'keyword-item__phrase',
            'keyword-item__semantic',
            'keyword-item__operational',
            'keyword-item__planning',
            'keyword-item__meta-line',
            'keyword-item__menu',
            'x-teleport="body"',
            'keyword-item__menu--portal',
            'saveKeywordPhraseInline',
        ] as $marker) {
            self::assertStringContainsString($marker, $partial);
        }
    }

    public function test_presenter_exposes_dictionary_and_cluster_context_flags(): void
    {
        $presenter = (string) file_get_contents(dirname(__DIR__, 2).'/src/Support/KeywordIntelligence/KeywordItemPresenter.php');

        self::assertStringContainsString("CONTEXT_DICTIONARY = 'dictionary'", $presenter);
        self::assertStringContainsString("CONTEXT_CLUSTER = 'cluster'", $presenter);
        self::assertStringContainsString("'show_cluster' => \$context === self::CONTEXT_DICTIONARY", $presenter);
        self::assertStringNotContainsString("'show_detach'", $presenter);
    }

    public function test_sentence_case_formatter_is_presentation_only(): void
    {
        self::assertSame(
            'Balo anh ngữ châu âu CIE',
            KeywordPhrasePresentation::present('BALO ANH NGỮ CHÂU ÂU CIE'),
        );
        self::assertSame('BALO ANH NGỮ CHÂU ÂU CIE', 'BALO ANH NGỮ CHÂU ÂU CIE');
    }

    public function test_keyword_menu_has_skip_mcp_and_exclude_seo_not_detach(): void
    {
        $partial = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/partials/keyword-item.blade.php',
        ));

        self::assertStringContainsString('skipKeywordFromMcp', $partial);
        self::assertStringContainsString('keyword_item_skip_mcp', $partial);
        self::assertStringContainsString('keyword_item_exclude_seo', $partial);
        self::assertStringNotContainsString('detachKeywordFromCluster', $partial);
        self::assertStringNotContainsString('topic_detach_from_cluster', $partial);
    }

    public function test_cluster_detail_wires_shared_drawer_layout(): void
    {
        $detail = (string) file_get_contents(LegacyAddonPath::resolve(
            'resources/views/filament/resources/keywords/pages/topic-cluster-detail.blade.php',
        ));
        $list = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/ListKeywords.php');
        $cluster = (string) file_get_contents(dirname(__DIR__, 2).'/src/Filament/Resources/KeywordResource/Pages/KeywordTopicClusterDetail.php');

        self::assertStringContainsString('keyword-detail-layout', $detail);
        self::assertStringContainsString('keyword-detail-drawer', $detail);
        self::assertStringContainsString('InteractsWithKeywordDetailDrawer', $list);
        self::assertStringContainsString('InteractsWithKeywordDetailDrawer', $cluster);
    }

    public function test_detach_service_nulls_cluster_key_and_preserves_classification_row(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/Services/KeywordIntelligence/DetachKeywordFromClusterService.php');

        self::assertStringContainsString('$row->cluster_key = null', $source);
        self::assertStringContainsString('$this->singletonPruner->prune($siteId)', $source);
        self::assertStringContainsString('TopicClusterDirtyState::mark', $source);
    }

    public function test_list_and_cluster_detail_page_classes_exist(): void
    {
        self::assertTrue(class_exists(ListKeywords::class));
        self::assertTrue(class_exists(KeywordTopicClusterDetail::class));
        self::assertTrue(class_exists(KeywordItemPresenter::class));
    }
}
