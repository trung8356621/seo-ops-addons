<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoArticleQualityAssessmentService;
use Omnichannel\Addons\Seo\Services\SeoAuditRuleMatcher;
use Omnichannel\Addons\Seo\Services\SeoScoringSettingsService;
use Omnichannel\Addons\Seo\Support\SeoScoringRuleMessageResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoAuditScoringIntegrationTest extends TestCase
{
    public function test_disabled_rule_is_ignored_even_with_legacy_violation_in_db(): void
    {
        $this->app->instance(SeoScoringSettingsService::class, SeoScoringSettingsService::withOverrides([
            SeoScoringRulesRegistry::KEY_H2_MISSING => [
                'enabled' => false,
                'deduction' => 20,
            ],
        ]));

        $assessment = app(SeoArticleQualityAssessmentService::class)->assessFromAnalysis([
            'violations' => ['seo.heading', 'h2_missing', 'faq_missing'],
            'seo_score' => 90,
        ]);

        $this->assertNotContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $assessment['matched_rule_keys']);
        $this->assertContains(SeoScoringRulesRegistry::KEY_FAQ_MISSING, $assessment['matched_rule_keys']);
    }

    public function test_legacy_violation_key_normalizes_to_canonical_rule(): void
    {
        $this->assertSame(
            SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW,
            SeoScoringRulesRegistry::canonicalRuleKeyForViolation('seo.length'),
        );
        $this->assertSame(
            SeoScoringRulesRegistry::KEY_H2_MISSING,
            SeoScoringRuleMessageResolver::normalizeViolationKey('seo.heading'),
        );
    }

    public function test_audit_filter_definitions_only_include_enabled_filterable_rules(): void
    {
        $this->app->instance(SeoScoringSettingsService::class, SeoScoringSettingsService::withOverrides([
            SeoScoringRulesRegistry::KEY_H2_MISSING => [
                'enabled' => false,
                'deduction' => 20,
            ],
        ]));

        $filters = SeoScoringRulesRegistry::auditFilterDefinitions(2000);
        $keys = array_column($filters, 'key');

        $this->assertNotContains(SeoScoringRulesRegistry::KEY_H2_MISSING, $keys);
        $this->assertContains(SeoScoringRulesRegistry::KEY_FAQ_MISSING, $keys);
        $this->assertContains(SeoScoringRulesRegistry::KEY_MISSING_FOCUS_KEYWORD, $keys);
    }

    public function test_filter_label_uses_article_length_target_not_hardcoded_600(): void
    {
        $target = 2000;
        $filters = SeoScoringRulesRegistry::auditFilterDefinitions($target);
        $contentFilter = collect($filters)->firstWhere('key', SeoScoringRulesRegistry::KEY_CONTENT_LENGTH_LOW);

        $this->assertNotNull($contentFilter);
        $this->assertStringContainsString((string) $target, (string) ($contentFilter['label'] ?? ''));
        $this->assertStringNotContainsString('600', (string) ($contentFilter['label'] ?? ''));
    }

    public function test_aggregate_filter_labels_use_registry_threshold(): void
    {
        $aggregates = SeoScoringRulesRegistry::aggregateFilterDefinitions();

        $this->assertCount(1, $aggregates);
        $this->assertSame(SeoScoringRulesRegistry::AGGREGATE_FILTER_LOW_SEO_SCORE, $aggregates[0]['key']);
        $this->assertStringContainsString(
            (string) SeoScoringRulesRegistry::AUDIT_LOW_SCORE_THRESHOLD,
            (string) ($aggregates[0]['label'] ?? ''),
        );
    }

    public function test_audit_matcher_uses_any_for_scoring_rules_and_and_with_scope(): void
    {
        $matcher = app(SeoAuditRuleMatcher::class);

        $assessment = [
            'score' => 80,
            'technical_score' => 80,
            'matched_rule_keys' => [SeoScoringRulesRegistry::KEY_FAQ_MISSING],
        ];

        $this->assertTrue($matcher->matchesSelectedFilters(
            $assessment,
            [SeoScoringRulesRegistry::KEY_H2_MISSING, SeoScoringRulesRegistry::KEY_FAQ_MISSING],
            false,
            false,
        ));

        $this->assertFalse($matcher->matchesSelectedFilters(
            $assessment,
            [SeoScoringRulesRegistry::KEY_H2_MISSING],
            false,
            false,
        ));
    }

    public function test_aggregate_score_filter_does_not_require_rule_violation(): void
    {
        $matcher = app(SeoAuditRuleMatcher::class);

        $assessment = [
            'score' => 55,
            'technical_score' => 55,
            'matched_rule_keys' => [],
        ];

        $this->assertTrue($matcher->matchesSelectedFilters($assessment, [], true, false));
        $this->assertTrue($matcher->matchesSelectedFilters($assessment, [], false, true));
    }

    public function test_no_scoring_selection_matches_all_articles(): void
    {
        $matcher = app(SeoAuditRuleMatcher::class);

        $this->assertTrue($matcher->matchesSelectedFilters([
            'score' => 95,
            'technical_score' => 95,
            'matched_rule_keys' => [],
        ], [], false, false));
    }
}
