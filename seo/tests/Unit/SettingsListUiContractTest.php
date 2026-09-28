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

    public function test_trusted_domain_tag_normalization_and_rejection(): void
    {
        // Immediate normalization cases
        self::assertSame('seo-ops.test', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('http://seo-ops.test/'));
        self::assertSame('example.com', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('HTTPS://WWW.Example.COM/a'));
        self::assertSame('*.gov', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('*.GOV'));
        self::assertSame('*.edu', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('*.EDU'));
        self::assertSame('trusted.org', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('//trusted.org/path#hash'));

        // Invalid cases rejected
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('foo bar.com'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('javascript:alert(1)'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('mailto:test@example.com'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('*'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('*.'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('*.*'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('*gov'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag('foo.*'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTag(null));

        // Canonical deduplication
        $rawInputs = ['example.com', 'https://www.example.com/path', 'EXAMPLE.COM'];
        self::assertSame(['example.com'], \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeTrustedDomainTags($rawInputs));
    }

    public function test_social_domain_tag_normalization_and_rejection(): void
    {
        // Immediate normalization cases (strict, no wildcards)
        self::assertSame('facebook.com', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeStrictDomainTag('HTTPS://WWW.Facebook.COM/x'));
        self::assertSame('mastodon.social', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeStrictDomainTag('https://mastodon.social/@user'));
        self::assertSame('linkedin.com', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeStrictDomainTag('linkedin.com'));

        // Wildcards and invalid rejected in strict mode
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeStrictDomainTag('*.facebook.com'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeStrictDomainTag('*.com'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeStrictDomainTag('foo bar.com'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeStrictDomainTag(null));

        // Canonical deduplication
        $rawInputs = ['https://facebook.com', 'facebook.com', 'HTTPS://WWW.FACEBOOK.COM'];
        self::assertSame(['facebook.com'], \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeStrictDomainTags($rawInputs));
    }

    public function test_extension_tag_normalization_and_rejection(): void
    {
        // Immediate normalization cases
        self::assertSame('jpg', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag('.JPG'));
        self::assertSame('pdf', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag(' PDF'));
        self::assertSame('png', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag('.png'));

        // Invalid cases rejected
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag('invalid.ext'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag('bad*ext'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag('toolongextensionname'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag('..'));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag(''));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTag(null));

        // Canonical deduplication
        $rawInputs = ['.JPG', 'jpg', 'JPG'];
        self::assertSame(['jpg'], \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizeExtensionTags($rawInputs));
    }

    public function test_faq_phrase_tag_normalization(): void
    {
        // Preserves internal spaces, lowercases, trims
        self::assertSame('faq questions', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizePhraseTag(' FAQ Questions '));
        self::assertSame('frequently asked questions', \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizePhraseTag('Frequently Asked Questions'));

        // Empty/null returns null
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizePhraseTag('   '));
        self::assertNull(\Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizePhraseTag(null));

        // Case-insensitive deduplication
        $rawInputs = ['FAQ Questions', 'faq questions', 'FAQ QUESTIONS'];
        self::assertSame(['faq questions'], \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::normalizePhraseTags($rawInputs));
    }

    public function test_forms_bind_alpine_interceptor_attributes(): void
    {
        $editorCode = (string) file_get_contents((new \ReflectionClass(SeoSettingsEditor::class))->getFileName());
        self::assertStringContainsString('SettingsTagNormalizer::alpineTagInterceptor(SettingsTagNormalizer::TYPE_TRUSTED_DOMAIN)', $editorCode);
        self::assertStringContainsString('SettingsTagNormalizer::alpineTagInterceptor(SettingsTagNormalizer::TYPE_PHRASE)', $editorCode);

        $generalCode = (string) file_get_contents((new \ReflectionClass(SeoSettingsGeneral::class))->getFileName());
        self::assertStringContainsString('SettingsTagNormalizer::alpineTagInterceptor(SettingsTagNormalizer::TYPE_EXTENSION)', $generalCode);
        self::assertStringContainsString('SettingsTagNormalizer::alpineTagInterceptor(SettingsTagNormalizer::TYPE_STRICT_DOMAIN)', $generalCode);

        $trustedAttrs = \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::alpineTagInterceptor(
            \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::TYPE_TRUSTED_DOMAIN,
        );
        self::assertArrayHasKey('x-init', $trustedAttrs);
        self::assertStringContainsString('this.createTag', $trustedAttrs['x-init']);

        $extAttrs = \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::alpineTagInterceptor(
            \Omnichannel\Addons\Seo\Support\SettingsTagNormalizer::TYPE_EXTENSION,
        );
        self::assertArrayHasKey('x-init', $extAttrs);
        self::assertStringContainsString('this.createTag', $extAttrs['x-init']);
    }
}

