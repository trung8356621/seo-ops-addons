<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit\Topic;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditResultParser;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicalMapAuditService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TopicalMapAuditContractTest extends TestCase
{
    public function test_hook_key_and_version_match_installer(): void
    {
        self::assertSame('seo_keywords.topical_map_audit', TopicalMapAuditService::HOOK_KEY);
        self::assertSame('0.1.0', TopicalMapAuditService::HOOK_VERSION);
        self::assertSame(TopicalMapAuditService::HOOK_KEY, DefaultTopicalMapAuditPromptInstaller::HOOK_KEY);
        self::assertSame(TopicalMapAuditService::HOOK_VERSION, DefaultTopicalMapAuditPromptInstaller::HOOK_VERSION);
    }

    public function test_canonical_hook_json_exists_and_forbids_topic_creation(): void
    {
        $spec = DefaultTopicalMapAuditPromptInstaller::loadCanonicalSpec();
        self::assertSame('seo_keywords.topical_map_audit', $spec['key'] ?? null);
        self::assertSame('0.1.0', $spec['version'] ?? null);
        self::assertArrayHasKey('mcp_markdown', $spec['input_schema'] ?? []);
        self::assertArrayHasKey('topical_map_json', $spec['input_schema'] ?? []);

        $markdown = DefaultTopicalMapAuditPromptInstaller::canonicalDefaultMarkdown();
        self::assertStringContainsString('do NOT create Topics', $markdown);
        self::assertStringContainsString('do NOT invent an SEO score', $markdown);
        self::assertStringContainsString('recommended_actions', $markdown);
        self::assertStringContainsString('{{mcp_markdown}}', $markdown);
        self::assertStringContainsString('{{topical_map_json}}', $markdown);
    }

    public function test_audit_service_uses_mcp_context_and_prompt_hooks_not_direct_provider(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(TopicalMapAuditService::class))->getFileName());
        self::assertStringContainsString('McpAiContextBuilder', $src);
        self::assertStringContainsString('PromptHookCallerBridge', $src);
        self::assertStringContainsString('PromptRunnerService', $src);
        self::assertStringContainsString('topicalMap->overview', $src);
        self::assertStringNotContainsString('OpenAI', $src);
        self::assertStringNotContainsString('Anthropic', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertStringNotContainsString('model:', $src);
    }

    public function test_parser_normalizes_structured_payload(): void
    {
        $parser = new TopicalMapAuditResultParser;
        $result = $parser->parse(json_encode([
            'summary' => 'Coverage uneven',
            'findings' => [[
                'type' => 'coverage_gap',
                'severity' => 'HIGH',
                'topic_ref' => 'topic:12',
                'title' => 'Weak bags',
                'reason' => 'Low MCP',
            ]],
            'opportunities' => [[
                'topic' => 'School bags',
                'reason' => 'Demand signals',
                'suggested_action' => 'Expand DNA',
            ]],
            'recommended_actions' => ['Review weak Topics', ['action' => 'Open New Topics']],
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($result['ok']);
        self::assertSame('Coverage uneven', $result['payload']['summary']);
        self::assertSame('high', $result['payload']['findings'][0]['severity']);
        self::assertSame('topic:12', $result['payload']['findings'][0]['topic_ref']);
        self::assertSame('Expand DNA', $result['payload']['opportunities'][0]['suggested_action']);
        self::assertSame(['Review weak Topics', 'Open New Topics'], $result['payload']['recommended_actions']);
    }

    public function test_parser_rejects_empty_and_accepts_fenced_json(): void
    {
        $parser = new TopicalMapAuditResultParser;
        self::assertFalse($parser->parse('{}')['ok']);
        self::assertFalse($parser->parse('not-json')['ok']);

        $fenced = "```json\n{\"summary\":\"ok\",\"findings\":[],\"opportunities\":[],\"recommended_actions\":[\"a\"]}\n```";
        $ok = $parser->parse($fenced);
        self::assertTrue($ok['ok']);
        self::assertSame('ok', $ok['payload']['summary']);
    }
}
