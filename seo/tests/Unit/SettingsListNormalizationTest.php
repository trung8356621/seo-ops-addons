<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Services\ArticleEditorHistoryService;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Omnichannel\Addons\Seo\Services\SeoOverviewSettingsService;
use Omnichannel\Addons\Seo\Services\SettingsTransfer\SeoSettingsBundleService;
use Tests\TestCase;

final class SettingsListNormalizationTest extends TestCase
{
    private ArticleEditorHistoryService $editorService;

    private SeoOverviewSettingsService $overviewService;

    private SeoKeywordSettingsService $keywordService;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        WpOption::clearRequestCache();

        $this->editorService = new ArticleEditorHistoryService;
        $this->overviewService = SeoOverviewSettingsService::withDefaults();
        $this->keywordService = SeoKeywordSettingsService::withDefaults();
    }

    public function test_team_chat_extension_normalization(): void
    {
        // 17. .JPG -> jpg
        self::assertSame(['jpg'], $this->overviewService->normalizeExtensions(['.JPG']));

        // 18. .pdf -> pdf
        self::assertSame(['pdf'], $this->overviewService->normalizeExtensions(['.pdf']));

        // PDF with leading space and dotless
        self::assertSame(['pdf'], $this->overviewService->normalizeExtensions([' PDF']));

        // 19. duplicate case variants -> deduped
        self::assertSame(['jpg', 'pdf'], $this->overviewService->normalizeExtensions(['.JPG', 'jpg', 'Jpg', 'PDF', '.pdf']));

        // 20. invalid extension -> rejected
        self::assertSame([], $this->overviewService->normalizeExtensions(['bad*ext', 'toolongextensionname', '..', '']));
    }

    public function test_faq_catch_phrase_normalization(): void
    {
        // 21. duplicate case variants dedupe correctly
        $raw = [
            'Frequently Asked Questions',
            'frequently asked questions',
            'FREQUENTLY ASKED QUESTIONS',
        ];
        self::assertSame(['frequently asked questions'], $this->overviewService->normalizeKeywords($raw));

        // 22. multi-word phrase remains one item (not split by space)
        $phrase = 'Frequently Asked Questions';
        $normalized = $this->overviewService->normalizeKeywords([$phrase]);
        self::assertCount(1, $normalized);
        self::assertSame('frequently asked questions', $normalized[0]);
    }

    public function test_old_dirty_wiki_trust_domains_option_is_normalized_on_read(): void
    {
        // 23. old dirty wiki_trust_domains option is normalized on read
        WpOption::set(ArticleEditorHistoryService::OPTION_KEY, [
            'history_step' => 20,
            'autosave_interval_seconds' => 2,
            'wiki_trust_domains' => [
                'HTTPS://WWW.Example.com/path',
                ' example.com ',
                'EXAMPLE.COM',
                '*.GOV',
                'javascript:alert(1)',
                'mailto:admin@example.com',
            ],
        ], 'no');

        $settings = $this->editorService->getSettings();
        self::assertSame(['example.com', '*.gov'], $settings['wiki_trust_domains']);
        self::assertSame(['example.com', '*.gov'], $this->editorService->getWikiTrustDomains());
    }

    public function test_save_trusted_domains_stores_canonical_values(): void
    {
        // 24. save trusted domains stores canonical values
        $this->editorService->saveSettings([
            'wiki_trust_domains' => [
                'https://WWW.Sub.Domain.com/page?x=1',
                '//trusted.org/test#hash',
                '*.EDU',
                'invalid domain',
            ],
        ]);

        $saved = $this->editorService->getWikiTrustDomains();
        self::assertSame(['sub.domain.com', 'trusted.org', '*.edu'], $saved);
    }

    public function test_settings_import_canonicalizes_trusted_domain_url_input(): void
    {
        // 25. settings import canonicalizes trusted-domain URL input
        $bundleService = app(SeoSettingsBundleService::class);
        $section = $bundleService->registry()->get('article_editor');
        self::assertNotNull($section);

        $section->apply(1, [
            'wiki_trust_domains' => [
                'https://www.example.com/path',
                '*.gov',
            ],
        ], 'merge');

        self::assertSame(['example.com', '*.gov'], $this->editorService->getWikiTrustDomains());
    }

    public function test_social_domain_settings_store_canonical_host(): void
    {
        // 26. social domain settings store canonical host
        $this->overviewService->saveSocialSupportedDomainsSettings([
            SeoOverviewSettingsService::KEY_SOCIAL_SUPPORTED_DOMAINS => [
                'HTTPS://WWW.Facebook.COM/something',
                'https://mastodon.social/@user',
                'linkedin.com',
                '*.facebook.com', // wildcard rejected
            ],
        ]);

        $domains = $this->overviewService->getSocialSupportedDomains();
        self::assertSame(['facebook.com', 'mastodon.social', 'linkedin.com'], $domains);
    }

    public function test_team_chat_extensions_store_canonical_tags(): void
    {
        // 27. team-chat extensions store canonical tags
        $this->overviewService->saveTeamChatSettings([
            SeoOverviewSettingsService::KEY_TEAM_CHAT_ALLOWED_EXTENSIONS => [
                '.JPG',
                ' pdf',
                '.PNG',
                'invalid.ext',
            ],
        ]);

        $extensions = $this->overviewService->getTeamChatAllowedExtensions();
        self::assertSame(['jpg', 'pdf', 'png'], $extensions);
    }

    public function test_cta_blacklist_behavior_remains_consistent(): void
    {
        // 28. CTA blacklist behavior unchanged
        $this->keywordService->saveSettings([
            SeoKeywordSettingsService::KEY_CTA_BLACKLIST => [
                '  tại đây  ',
                'CLICK VÀO',
                'tại đây',
            ],
        ]);

        $blacklist = $this->keywordService->getCtaBlacklist();
        self::assertSame(['tại đây', 'CLICK VÀO'], $blacklist);
    }

    public function test_non_cta_global_rule_persists_and_reloads(): void
    {
        $settings = $this->keywordService->getSettings();
        $settings['question_terms'] = ['which one', 'so sánh gì'];
        $this->keywordService->saveSettings($settings);

        $reloaded = new SeoKeywordSettingsService;
        self::assertSame(['which one', 'so sánh gì'], $reloaded->getSettings()['question_terms']);
    }
}
