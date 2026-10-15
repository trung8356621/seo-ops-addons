<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Filament;

use Omnichannel\Addons\SearchFoundation\DTO\IndustryGroup\IndustryGroup;
use Omnichannel\Addons\SearchFoundation\Enums\IndustryGroupType;
use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords;
use Omnichannel\Addons\SearchIntelligence\Livewire\IndustryMatchingPanel;
use PHPUnit\Framework\TestCase;

final class MatchResearchLiveMatchUiTest extends TestCase
{
    public function test_match_research_settings_ui_redirects_and_does_not_mutate(): void
    {
        $page = $this->source(SeoSettingsKeywords::class);
        $view = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-keywords.blade.php');
        $panel = $this->source(IndustryMatchingPanel::class);
        $panelView = (string) file_get_contents(dirname(__DIR__, 3).'/resources/views/livewire/industry-matching-panel.blade.php');
        $routing = (string) file_get_contents(dirname(__DIR__, 4).'/agent-runtime/resources/views/semantic-routing.blade.php');

        self::assertStringContainsString("redirect('/admin/settings/semantic-routing')", $page);
        foreach (['saveKeywordSettings', 'saveCustomConcept', 'deleteCustomConcept', 'testIndustryMatch', 'importLocalizationResult', 'exportLocalizationPrompt', 'TagsInput'] as $gone) {
            self::assertStringNotContainsString($gone, $page, $gone);
            self::assertStringNotContainsString($gone, $view, $gone);
        }
        foreach (['function save', 'function create', 'function delete', 'function update', 'testIndustryMatch', 'importLocalization'] as $gone) {
            self::assertStringNotContainsString($gone, $panel, $gone);
        }
        self::assertStringNotContainsString('wire:click', $panelView);
        self::assertStringNotContainsString('wire:submit', $panelView);
        self::assertStringContainsString('IndustryMatchingPanel', $routing);
        self::assertStringContainsString('Global JEV', $routing);
        self::assertStringContainsString('Internal Module JEV', $routing);
        self::assertStringNotContainsString('Match & Research', $this->source(\Omnichannel\Addons\Seo\Settings\SeoSettingsSectionContributor::class));
    }

    public function test_industry_panel_presents_only_supplied_group_fields(): void
    {
        $group = new IndustryGroup(
            key: 'industry.products.balo',
            groupType: IndustryGroupType::Products,
            sourceLocale: 'vi',
            name: 'Balo',
            aliases: ['backpack'],
            positiveExamples: ['balo học sinh'],
            negativeExamples: ['túi xách'],
            matchMode: 'hybrid',
            provenance: ['industry_context_key' => 'bags', 'match_revision_id' => 9],
            stale: false,
            enabled: true,
            industryContextKey: 'bags',
            requestedLocale: 'vi',
            effectiveLocale: 'vi',
            localized: true,
        );

        $rows = IndustryMatchingPanel::present(
            [$group],
            ['industry.products.balo' => 'School backpacks'],
            ['industry_context_key' => 'bags', 'match_revision_id' => 9],
        );

        self::assertCount(1, $rows);
        self::assertSame('Balo', $rows[0]['name']);
        self::assertSame('School backpacks', $rows[0]['description']);
        self::assertSame(['balo học sinh'], $rows[0]['positive_examples']);
        self::assertSame(['túi xách'], $rows[0]['negative_examples']);
        self::assertSame('vi', $rows[0]['language']);
        self::assertTrue($rows[0]['enabled']);
        self::assertSame(9, $rows[0]['revision']);
        self::assertSame([], IndustryMatchingPanel::present([], [], null));
    }

    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new \ReflectionClass($class))->getFileName());
    }
}
