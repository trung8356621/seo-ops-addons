<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordIntelligence\KeywordSearchIntent;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpIntentEvidenceService;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpPageTypeClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpResultClassifier;
use Omnichannel\Addons\SearchIntelligence\Services\SerpIntelligence\SerpUrlNormalizationService;
use PHPUnit\Framework\TestCase;

final class SerpIntentEvidenceTest extends TestCase
{
    private SerpIntentEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SerpIntentEvidenceService(
            new SerpPageTypeClassifier(new SerpUrlNormalizationService),
            new SerpResultClassifier,
        );
    }

    public function test_service_pages_signal_commercial_or_local(): void
    {
        $results = [
            ['url' => 'https://agency.test/dich-vu-seo', 'title' => 'Dich vu SEO pricing packages', 'snippet' => 'service packages near me', 'type' => 'organic'],
            ['url' => 'https://agency2.test/services', 'title' => 'SEO service packages', 'snippet' => 'local SEO service', 'type' => 'organic'],
            ['url' => 'https://agency3.test/pricing', 'title' => 'Pricing SEO dich vu', 'snippet' => 'packages and pricing', 'type' => 'organic'],
        ];

        $analysis = $this->service->analyze($results);

        self::assertContains($analysis['observed_primary_intent'], [
            KeywordSearchIntent::Commercial->value,
            KeywordSearchIntent::Local->value,
            KeywordSearchIntent::Mixed->value,
        ]);
        self::assertGreaterThanOrEqual(0.35, $analysis['confidence']);
        self::assertNotSame(KeywordSearchIntent::Unknown->value, $analysis['observed_primary_intent']);
    }

    public function test_article_serp_signals_informational(): void
    {
        $results = [];
        for ($i = 1; $i <= 4; $i++) {
            $results[] = [
                'url' => "https://blog.test/how-to-seo-guide-{$i}",
                'title' => "How to SEO guide article {$i}",
                'snippet' => 'blog post guide article how to',
                'type' => 'organic',
            ];
        }

        $analysis = $this->service->analyze($results, [['type' => 'people_also_ask']]);

        self::assertSame(KeywordSearchIntent::Informational->value, $analysis['observed_primary_intent']);
        self::assertContains('results.article_pages_dominant', $analysis['reason_codes']);
    }

    public function test_few_results_yields_low_confidence(): void
    {
        $analysis = $this->service->analyze([], []);

        self::assertSame(KeywordSearchIntent::Unknown->value, $analysis['observed_primary_intent']);
        self::assertLessThanOrEqual(0.25, $analysis['confidence']);
        self::assertContains('insufficient_evidence', $analysis['reason_codes']);
    }
}
