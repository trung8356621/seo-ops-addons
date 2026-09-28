<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords;
use Omnichannel\Addons\Seo\Filament\Forms\Components\SettingsTagsInput;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsEditor;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsGeneral;
use Omnichannel\Addons\Seo\Filament\Pages\SeoSettingsOverview;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Support\SettingsTagNormalizer;
use Tests\TestCase;

final class SettingsListUiContractTest extends TestCase
{
    public function test_seo_settings_editor_uses_settings_tags_input_and_no_textarea_for_lists(): void
    {
        $code = (string) file_get_contents((new \ReflectionClass(SeoSettingsEditor::class))->getFileName());

        // Uses SettingsTagsInput for trusted domains and FAQ keywords
        self::assertStringContainsString("SettingsTagsInput::make('wiki_trust_domains')", $code);
        self::assertStringContainsString('SettingsTagsInput::make(SeoOverviewSettingsService::KEY_FAQ_CATCH_KEYWORDS)', $code);

        // Does NOT use Textarea
        self::assertStringNotContainsString('Forms\Components\Textarea', $code);
        self::assertStringNotContainsString('wiki_trust_domains_text', $code);
    }

    public function test_seo_settings_general_uses_settings_tags_input_and_no_textarea_for_lists(): void
    {
        $code = (string) file_get_contents((new \ReflectionClass(SeoSettingsGeneral::class))->getFileName());

        // Uses SettingsTagsInput for team chat extensions and social supported domains
        self::assertStringContainsString('SettingsTagsInput::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS)', $code);
        self::assertStringContainsString('SettingsTagsInput::make(SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS)', $code);

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

        self::assertStringContainsString('SettingsTagsInput::make(SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS)', $code);
        self::assertStringNotContainsString('Forms\Components\Textarea', $code);
    }

    public function test_trusted_domain_tag_normalization_and_rejection(): void
    {
        // Immediate normalization cases
        self::assertSame('seo-ops.test', SettingsTagNormalizer::normalizeTrustedDomainTag('http://seo-ops.test/'));
        self::assertSame('example.com', SettingsTagNormalizer::normalizeTrustedDomainTag('HTTPS://WWW.Example.COM/a'));
        self::assertSame('*.gov', SettingsTagNormalizer::normalizeTrustedDomainTag('*.GOV'));
        self::assertSame('*.edu', SettingsTagNormalizer::normalizeTrustedDomainTag('*.EDU'));
        self::assertSame('trusted.org', SettingsTagNormalizer::normalizeTrustedDomainTag('//trusted.org/path#hash'));

        // Invalid cases rejected
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('foo bar.com'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('javascript:alert(1)'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('mailto:test@example.com'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('tel:123'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('@'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('://bad'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('*'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('*.'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('*.*'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('*gov'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag('foo.*'));
        self::assertNull(SettingsTagNormalizer::normalizeTrustedDomainTag(null));

        // Canonical deduplication
        $rawInputs = ['example.com', 'https://www.example.com/path', 'EXAMPLE.COM'];
        self::assertSame(['example.com'], SettingsTagNormalizer::normalizeTrustedDomainTags($rawInputs));
    }

    public function test_social_domain_tag_normalization_and_rejection(): void
    {
        // Immediate normalization cases (strict, no wildcards)
        self::assertSame('facebook.com', SettingsTagNormalizer::normalizeStrictDomainTag('HTTPS://WWW.Facebook.COM/x'));
        self::assertSame('mastodon.social', SettingsTagNormalizer::normalizeStrictDomainTag('https://mastodon.social/@user'));
        self::assertSame('linkedin.com', SettingsTagNormalizer::normalizeStrictDomainTag('linkedin.com'));

        // Wildcards and invalid rejected in strict mode
        self::assertNull(SettingsTagNormalizer::normalizeStrictDomainTag('*.facebook.com'));
        self::assertNull(SettingsTagNormalizer::normalizeStrictDomainTag('*.com'));
        self::assertNull(SettingsTagNormalizer::normalizeStrictDomainTag('foo bar.com'));
        self::assertNull(SettingsTagNormalizer::normalizeStrictDomainTag(null));

        // Canonical deduplication
        $rawInputs = ['https://facebook.com', 'facebook.com', 'HTTPS://WWW.FACEBOOK.COM'];
        self::assertSame(['facebook.com'], SettingsTagNormalizer::normalizeStrictDomainTags($rawInputs));
    }

    public function test_extension_tag_normalization_and_rejection(): void
    {
        // Immediate normalization cases
        self::assertSame('jpg', SettingsTagNormalizer::normalizeExtensionTag('.JPG'));
        self::assertSame('pdf', SettingsTagNormalizer::normalizeExtensionTag(' PDF'));
        self::assertSame('png', SettingsTagNormalizer::normalizeExtensionTag('.png'));

        // Invalid cases rejected
        self::assertNull(SettingsTagNormalizer::normalizeExtensionTag('invalid.ext'));
        self::assertNull(SettingsTagNormalizer::normalizeExtensionTag('bad*ext'));
        self::assertNull(SettingsTagNormalizer::normalizeExtensionTag('toolongextensionname'));
        self::assertNull(SettingsTagNormalizer::normalizeExtensionTag('..'));
        self::assertNull(SettingsTagNormalizer::normalizeExtensionTag(''));
        self::assertNull(SettingsTagNormalizer::normalizeExtensionTag(null));

        // Canonical deduplication
        $rawInputs = ['.JPG', 'jpg', 'JPG'];
        self::assertSame(['jpg'], SettingsTagNormalizer::normalizeExtensionTags($rawInputs));
    }

    public function test_faq_phrase_tag_normalization(): void
    {
        // Preserves internal spaces, lowercases, trims
        self::assertSame('faq questions', SettingsTagNormalizer::normalizePhraseTag(' FAQ Questions '));
        self::assertSame('frequently asked questions', SettingsTagNormalizer::normalizePhraseTag('Frequently Asked Questions'));

        // Empty/null returns null
        self::assertNull(SettingsTagNormalizer::normalizePhraseTag('   '));
        self::assertNull(SettingsTagNormalizer::normalizePhraseTag(null));

        // Case-insensitive deduplication
        $rawInputs = ['FAQ Questions', 'faq questions', 'FAQ QUESTIONS'];
        self::assertSame(['faq questions'], SettingsTagNormalizer::normalizePhraseTags($rawInputs));
    }

    public function test_settings_tags_input_component_configuration_and_view(): void
    {
        $input = SettingsTagsInput::make('test_trusted')
            ->normalizer(SettingsTagsInput::TRUSTED_DOMAIN);

        self::assertSame(SettingsTagsInput::TRUSTED_DOMAIN, $input->getNormalizerType());

        // Check view is custom settings tags input
        $refProp = (new \ReflectionClass(SettingsTagsInput::class))->getProperty('view');
        $refProp->setAccessible(true);
        self::assertSame('seo::filament.forms.components.settings-tags-input', $refProp->getValue($input));

        // Check Blade view file exists and references custom Alpine component
        $bladePath = dirname(__DIR__, 2).'/resources/views/filament/forms/components/settings-tags-input.blade.php';
        self::assertFileExists($bladePath);
        $bladeContent = (string) file_get_contents($bladePath);
        self::assertStringContainsString('settingsTagsInputFormComponent', $bladeContent);
        self::assertStringContainsString('normalizeTag', $bladeContent);

        // Check JS module file exists
        $jsPath = dirname(__DIR__, 2).'/resources/js/components/settingsTagsInput.js';
        self::assertFileExists($jsPath);
        $jsContent = (string) file_get_contents($jsPath);
        self::assertStringContainsString('export function normalizeTag', $jsContent);
        self::assertStringContainsString('export default function settingsTagsInputFormComponent', $jsContent);
    }
}
