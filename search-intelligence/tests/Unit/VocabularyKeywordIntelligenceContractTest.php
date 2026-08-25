<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\Agent\Automation\Actions\Keyword\SaveKeywordVocabularyAction;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\KeywordClassificationService;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIngestionPolicy;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence\VocabularyKeywordIntelligenceIngestionService;
use Omnichannel\Addons\Seo\Services\WorkflowKeywordResearchService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\ProjectRoot;

/**
 * Phase 7 — Vocabulary → KI feedback contracts.
 */
final class VocabularyKeywordIntelligenceContractTest extends TestCase
{
    public function test_policy_enables_related_topics_only(): void
    {
        $policy = new VocabularyKeywordIngestionPolicy;
        self::assertTrue($policy->isEnabled(VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS));
        self::assertFalse($policy->isEnabled(VocabularyKeywordIngestionPolicy::GROUP_LONG_TAIL));
        self::assertFalse($policy->isEnabled(VocabularyKeywordIngestionPolicy::GROUP_SEMANTIC));
        self::assertSame(
            VocabularyKeywordIngestionPolicy::GROUP_RELATED_TOPICS,
            $policy->resolveCanonicalGroup('### Related topics'),
        );
        self::assertNull($policy->resolveCanonicalGroup('N-grams'));
        self::assertNull($policy->resolveCanonicalGroup('Antonyms'));
        self::assertNull($policy->resolveCanonicalGroup('Holonymy'));
    }

    public function test_ingestion_service_is_deterministic_and_coverage_safe(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(VocabularyKeywordIntelligenceIngestionService::class))->getFileName(),
        );
        self::assertStringContainsString('KeywordPersistenceService', $src);
        self::assertStringContainsString('classifyOne', $src);
        self::assertStringContainsString('AI_GENERATED', $src);
        self::assertStringContainsString('article_vocabulary', $src);
        self::assertStringNotContainsString('PromptRunner', $src);
        self::assertStringNotContainsString('PromptHook', $src);
        self::assertStringContainsString('Does NOT create seo_link_maps', $src);
        self::assertStringNotContainsString('->table(\'seo_link_maps\')', $src);
        self::assertStringNotContainsString('SeoLinkMap::', $src);
        self::assertStringNotContainsString('setMainArticleId', $src);
        self::assertStringNotContainsString('SeoProjectTask', $src);
        self::assertStringNotContainsString('SeoMcpSourceSnapshot', $src);
        self::assertStringNotContainsString('backfill', $src);
    }

    public function test_classification_service_exposes_classify_one(): void
    {
        self::assertTrue(method_exists(KeywordClassificationService::class, 'classifyOne'));
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(KeywordClassificationService::class))->getFileName(),
        );
        self::assertStringContainsString('Does not refresh landscape', $src);
    }

    public function test_save_vocabulary_action_ingests_after_commit(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SaveKeywordVocabularyAction::class))->getFileName(),
        );
        self::assertStringContainsString('ingestRelatedTopics: false', $src);
        self::assertStringContainsString('ingestRelatedTopicsSafe', $src);
        self::assertStringContainsString('ki_feedback', $src);
        self::assertStringContainsString('vocabulary.ki_feedback_failed', $src);
        self::assertStringNotContainsString('backfill', $src);
        self::assertStringNotContainsString('PromptResult', $src);
    }

    public function test_workflow_service_delegates_related_topics(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(WorkflowKeywordResearchService::class))->getFileName(),
        );
        self::assertStringContainsString('VocabularyKeywordIntelligenceIngestionService', $src);
        self::assertStringContainsString('ingestRelatedTopicsSafe', $src);
        self::assertStringContainsString('ingestRelatedTopics: false', (string) file_get_contents(
            (string) (new ReflectionClass(SaveKeywordVocabularyAction::class))->getFileName(),
        ));
    }

    public function test_no_historical_backfill_command_added(): void
    {
        $addons = ProjectRoot::addonsPath();
        $hits = [];
        foreach ([
            $addons.'/search-intelligence/src',
            $addons.'/seo/src',
            $addons.'/agent/src/Automation/Actions/Keyword',
        ] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $name = $file->getFilename();
                if (str_contains(mb_strtolower($name), 'backfill') && str_contains(mb_strtolower($name), 'vocab')) {
                    $hits[] = $file->getPathname();
                }
                $contents = (string) file_get_contents($file->getPathname());
                if (preg_match('/historical\s+PromptResult|scan old PromptResults|VocabularyBackfill/i', $contents) === 1) {
                    $hits[] = $file->getPathname();
                }
            }
        }
        self::assertSame([], $hits);
    }
}
