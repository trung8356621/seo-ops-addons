<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsEditor;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsGeneral;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsOverview;
use PHPUnit\Framework\TestCase;

final class SettingsListUiContractTest extends TestCase
{
    public function test_seo_settings_editor_uses_tags_input_and_no_textarea_for_lists(): void
    {
        $code = (string) file_get_contents((new \ReflectionClass(SeoSettingsEditor::class))->getFileName());

        // Uses TagsInput for trusted domains and FAQ keywords
        self::assertStringContainsString("Forms\Components\TagsInput::make('wiki_trust_domains')", $code);
        self::assertStringContainsString('Forms\Components\TagsInput::make(SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS)', $code);

        // Does NOT use Textarea
        self::assertStringNotContainsString('Forms\Components\Textarea', $code);
        self::assertStringNotContainsString('wiki_trust_domains_text', $code);
    }

    public function test_seo_settings_general_uses_tags_input_and_no_textarea_for_lists(): void
    {
        $code = (string) file_get_contents((new \ReflectionClass(SeoSettingsGeneral::class))->getFileName());

        // Uses TagsInput for team chat extensions and social supported domains
        self::assertStringContainsString('Forms\Components\TagsInput::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS)', $code);
        self::assertStringContainsString('Forms\Components\TagsInput::make(SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS)', $code);

        // Does NOT use Textarea for those list fields
        self::assertStringNotContainsString('Forms\Components\Textarea::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS)', $code);
        self::assertStringNotContainsString('Forms\Components\Textarea::make(SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS)', $code);
        self::assertStringNotContainsString('Forms\Components\Textarea', $code);
    }

    public function test_seo_settings_keywords_cta_blacklist_remains_tags_input(): void
    {
        $code = (string) file_get_contents((new \ReflectionClass(SeoSettingsKeywords::class))->getFileName());

        self::assertStringContainsString('Forms\Components\TagsInput::make(SeoKeywordSettingsService::KEY_CTA_BLACKLIST)', $code);
        self::assertStringNotContainsString('Forms\Components\Textarea', $code);
    }

    public function test_legacy_seo_settings_overview_kept_compatible_without_textarea_drift(): void
    {
        $code = (string) file_get_contents((new \ReflectionClass(SeoSettingsOverview::class))->getFileName());

        self::assertStringContainsString('Forms\Components\TagsInput::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS)', $code);
        self::assertStringNotContainsString('Forms\Components\Textarea', $code);
    }
}
