<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Filament;

use Omnichannel\Addons\SearchIntelligence\Filament\Pages\SeoSettingsKeywords;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupEntityMatchResult;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupMatchEvidence;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupMatchResult;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticDisabledException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use PHPUnit\Framework\TestCase;

final class MatchResearchLiveMatchUiTest extends TestCase
{
    public function test_page_drops_old_matcher_and_cta_debug_surfaces(): void
    {
        $page = $this->source(SeoSettingsKeywords::class);
        $view = (string) file_get_contents(dirname(__DIR__, 4).'/seo-content-ai-compat/resources/views/filament/pages/seo-settings-keywords.blade.php');

        foreach (['debugPhrase', 'matcherReport', 'debugReport', 'debugMatcher', 'debugCtaBlacklist', 'industryRules', 'MatchRuleMatcher', 'CtaKeywordBlacklistDebugService', 'Debug Matcher'] as $gone) {
            self::assertStringNotContainsString($gone, $page, $gone);
            self::assertStringNotContainsString($gone, $view, $gone);
        }

        self::assertStringContainsString('IndustryGroupSemanticMatcher', $page);
        self::assertStringContainsString('function testIndustryMatch', $page);
        self::assertStringContainsString("ref: 'ui:test'", $page);
        self::assertStringNotContainsString('SemanticAnalyticsClient', $page);
        self::assertStringContainsString('Live Industry Match', $view);
        self::assertStringContainsString('Show all evidence', $view);
        self::assertStringContainsString('x-show="showAll"', $view);
        self::assertStringContainsString('Semantic similarity (positive_max)', $view);
        self::assertStringNotContainsString('confidence', strtolower($view));
        self::assertStringContainsString('match_revision_inactive', $view);
        self::assertStringContainsString('Industry Groups', $view);
        self::assertStringContainsString('Other Industry Rules', $view);
        self::assertStringContainsString('heading="Localization"', $view);
        self::assertStringContainsString('saveCustomConcept', $view);
        self::assertStringContainsString('Site-scoped custom Match & Research concepts.', $view);
        self::assertStringNotContainsString('No semantic auto-tagging', $view);
        self::assertStringNotContainsString('Legacy runtime settings', $page);
        self::assertStringNotContainsString('Still used by legacy consumers', $view);
        self::assertStringContainsString('CTA / Noise', $page);
        self::assertStringContainsString('Keyword filters', $view);
    }

    public function test_suggested_rows_are_the_default_matches(): void
    {
        $result = new IndustryGroupMatchResult(
            scopeRef: 'ui:industry-match',
            entities: [
                new IndustryGroupEntityMatchResult('ui:test', 'balo học sinh cấp 1', [
                    $this->evidence('industry.products.balo', 'products', true, 0.483, true),
                    $this->evidence('industry.products.zalo', 'products', false, 0.91, false),
                ]),
            ],
            conceptsUsed: 2,
            staleGroupsSkipped: 0,
            disabledGroupsSkipped: 0,
            calledPython: true,
            analysisId: 'an-1',
        );

        $presented = SeoSettingsKeywords::presentIndustryMatch($result);
        self::assertTrue($presented['called_python']);
        self::assertSame('an-1', $presented['analysis_id']);
        self::assertSame(2, $presented['concepts_used']);

        $visible = SeoSettingsKeywords::visibleIndustryEvidence($presented['evidence'], false);
        self::assertCount(1, $visible);
        self::assertSame('industry.products.balo', $visible[0]['industry_group_key']);
        self::assertTrue($visible[0]['suggested_match']);

        $all = SeoSettingsKeywords::visibleIndustryEvidence($presented['evidence'], true);
        self::assertCount(2, $all);
        self::assertFalse($all[1]['suggested_match']);
        self::assertArrayHasKey('positive_max', $all[1]);
        self::assertArrayNotHasKey('confidence', $all[1]);
    }

    public function test_inactive_revision_and_python_failure_do_not_invent_matches(): void
    {
        self::assertSame(
            'Match & Research revision chưa được kích hoạt.',
            SeoSettingsKeywords::industryMatchStatusLabel('match_revision_inactive'),
        );
        self::assertSame(
            'Chưa có revision Match & Research cho Industry Context của site hiện tại.',
            SeoSettingsKeywords::industryMatchStatusLabel('no_match_revision'),
        );

        $disabled = SeoSettingsKeywords::failureIndustryMatch(SemanticDisabledException::disabled());
        self::assertFalse($disabled['called_python']);
        self::assertSame('semantic_disabled', $disabled['reason']);
        self::assertSame([], $disabled['evidence']);

        $down = SeoSettingsKeywords::failureIndustryMatch(SemanticUnavailableException::connectionFailed('refused'));
        self::assertFalse($down['called_python']);
        self::assertSame('semantic_unavailable', $down['reason']);
        self::assertSame([], $down['evidence']);
        self::assertStringNotContainsString('MatchRuleMatcher', $this->source(SeoSettingsKeywords::class));
    }

    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new \ReflectionClass($class))->getFileName());
    }

    private function evidence(string $key, string $type, bool $lexical, float $positive, bool $suggested): IndustryGroupMatchEvidence
    {
        return new IndustryGroupMatchEvidence(
            entityRef: 'ui:test',
            industryGroupKey: $key,
            groupType: $type,
            lexicalMatched: $lexical,
            lexicalNegativeMatched: false,
            lexicalMatchedExamples: $lexical ? ['balo'] : [],
            positiveMax: $positive,
            positiveTopKMean: 0.4,
            negativeMax: 0.1,
            margin: 0.2,
            bestPositiveExample: 'balo',
            bestNegativeExample: null,
            suggestedMatch: $suggested,
            matchingStrategy: 'hybrid',
        );
    }
}
