<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Services\AiUsageTaxonomy;
use PHPUnit\Framework\TestCase;

final class AiUsageTaxonomyTest extends TestCase
{
    public function test_resolves_seo_content_project_actions(): void
    {
        $outline = AiUsageTaxonomy::resolve('seo_article_outline_generator');
        self::assertSame(AiUsageTaxonomy::ADDON_SEO, $outline['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_CONTENT_PROJECT, $outline['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_OUTLINE, $outline['action']);

        $article = AiUsageTaxonomy::resolve('seo_article_full_content_writer');
        self::assertSame(AiUsageTaxonomy::ADDON_SEO, $article['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_CONTENT_PROJECT, $article['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_ARTICLE, $article['action']);

        $vocab = AiUsageTaxonomy::resolve('seo_vocabulary_extractor');
        self::assertSame(AiUsageTaxonomy::ADDON_SEO, $vocab['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_CONTENT_PROJECT, $vocab['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_VOCABULARY, $vocab['action']);

        $meta = AiUsageTaxonomy::resolve('seo_meta_tags_optimizer');
        self::assertSame(AiUsageTaxonomy::ADDON_SEO, $meta['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_CONTENT_PROJECT, $meta['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_METADATA, $meta['action']);
    }

    public function test_resolves_seo_topic_and_audit_actions(): void
    {
        $cluster = AiUsageTaxonomy::resolve('seo_topic_cluster_builder');
        self::assertSame(AiUsageTaxonomy::ADDON_SEO, $cluster['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_TOPIC, $cluster['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_CLUSTERING, $cluster['action']);

        $kw = AiUsageTaxonomy::resolve('seo_keyword_classifier');
        self::assertSame(AiUsageTaxonomy::ADDON_SEO, $kw['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_TOPIC, $kw['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_KEYWORD_CLASSIFICATION, $kw['action']);

        $audit = AiUsageTaxonomy::resolve('seo_site_audit_reviewer');
        self::assertSame(AiUsageTaxonomy::ADDON_SEO, $audit['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_SEO_AUDIT, $audit['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_AUDIT, $audit['action']);

        $scoring = AiUsageTaxonomy::resolve('seo_content_scoring_evaluator');
        self::assertSame(AiUsageTaxonomy::ADDON_SEO, $scoring['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_SEO_SCORING, $scoring['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_SCORING, $scoring['action']);
    }

    public function test_resolves_seeding_generate_comment(): void
    {
        // Qua hook key
        $byHook = AiUsageTaxonomy::resolve('any_key', null, 'seeding_generate_comment');
        self::assertSame(AiUsageTaxonomy::ADDON_SEEDING, $byHook['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_SEEDING, $byHook['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_GENERATE_COMMENT, $byHook['action']);

        // Qua context addon
        $byContext = AiUsageTaxonomy::resolve('ai_comment_writer', null, null, ['addon' => 'seeding']);
        self::assertSame(AiUsageTaxonomy::ADDON_SEEDING, $byContext['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_SEEDING, $byContext['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_GENERATE_COMMENT, $byContext['action']);

        // Qua key naming
        $byKey = AiUsageTaxonomy::resolve('seeding_generate_reply_comment');
        self::assertSame(AiUsageTaxonomy::ADDON_SEEDING, $byKey['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_SEEDING, $byKey['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_GENERATE_COMMENT, $byKey['action']);
    }

    public function test_resolves_unknown_for_unrecognized_prompt(): void
    {
        $unknown = AiUsageTaxonomy::resolve('some_random_external_prompt_key_123');
        self::assertSame(AiUsageTaxonomy::ADDON_UNKNOWN, $unknown['addon']);
        self::assertSame(AiUsageTaxonomy::MODULE_UNKNOWN, $unknown['module']);
        self::assertSame(AiUsageTaxonomy::ACTION_UNKNOWN, $unknown['action']);
    }

    public function test_extract_tokens_from_various_providers(): void
    {
        // OpenAI / DeepSeek format
        $openai = AiUsageTaxonomy::extractTokens([
            'prompt_tokens' => 150,
            'completion_tokens' => 75,
            'total_tokens' => 225,
        ]);
        self::assertSame(150, $openai['input_tokens']);
        self::assertSame(75, $openai['output_tokens']);
        self::assertSame(225, $openai['total_tokens']);

        // Claude format
        $claude = AiUsageTaxonomy::extractTokens([
            'input_tokens' => 200,
            'output_tokens' => 80,
        ]);
        self::assertSame(200, $claude['input_tokens']);
        self::assertSame(80, $claude['output_tokens']);
        self::assertSame(280, $claude['total_tokens']);

        // Gemini format
        $gemini = AiUsageTaxonomy::extractTokens([
            'promptTokenCount' => 300,
            'candidatesTokenCount' => 120,
            'totalTokenCount' => 420,
        ]);
        self::assertSame(300, $gemini['input_tokens']);
        self::assertSame(120, $gemini['output_tokens']);
        self::assertSame(420, $gemini['total_tokens']);
    }

    public function test_extract_tokens_never_creates_fake_tokens_when_empty(): void
    {
        $nullResult = AiUsageTaxonomy::extractTokens(null);
        self::assertNull($nullResult['input_tokens']);
        self::assertNull($nullResult['output_tokens']);
        self::assertNull($nullResult['total_tokens']);

        $emptyResult = AiUsageTaxonomy::extractTokens([]);
        self::assertNull($emptyResult['input_tokens']);
        self::assertNull($emptyResult['output_tokens']);
        self::assertNull($emptyResult['total_tokens']);
    }
}
