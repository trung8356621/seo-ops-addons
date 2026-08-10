<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExplicitBindingExecutor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PromptHookTopicSchemaGateTest extends TestCase
{
    public function test_enrich_topic_unset_when_schema_lacks_topic(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'enrichTopicInput');
        $method->setAccessible(true);

        $out = $method->invoke($executor, [
            'topic' => 'leaked',
            'input' => 'outline body',
        ], [
            'input' => ['type' => 'string', 'required' => true],
            'focus_keyword' => ['type' => 'string', 'required' => false],
        ]);

        self::assertArrayNotHasKey('topic', $out);
        self::assertSame('outline body', $out['input'] ?? null);
    }

    public function test_enrich_topic_injects_when_schema_declares_topic(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'enrichTopicInput');
        $method->setAccessible(true);

        $out = $method->invoke($executor, [
            'post_title' => 'My Title',
            'keyword' => 'kw',
        ], [
            'topic' => ['type' => 'string', 'required' => false],
            'post_title' => ['type' => 'string', 'required' => false],
            'keyword' => ['type' => 'string', 'required' => false],
        ]);

        self::assertSame('My Title', $out['topic'] ?? null);
    }

    public function test_seed_empty_post_title_from_keyword(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'seedEmptyPostTitleFromSubject');
        $method->setAccessible(true);

        $out = $method->invoke($executor, [
            'keyword' => 'thời trang bền vững',
            'post_title' => null,
        ], [
            'post_title' => ['type' => 'string', 'required' => false, 'nullable' => true],
            'keyword' => ['type' => 'string', 'required' => false],
        ]);

        self::assertSame('thời trang bền vững', $out['post_title'] ?? null);
    }

    public function test_seed_empty_post_title_does_not_override_explicit(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'seedEmptyPostTitleFromSubject');
        $method->setAccessible(true);

        $out = $method->invoke($executor, [
            'keyword' => 'kw',
            'post_title' => 'Explicit Title',
        ], [
            'post_title' => ['type' => 'string', 'required' => false],
            'keyword' => ['type' => 'string', 'required' => false],
        ]);

        self::assertSame('Explicit Title', $out['post_title'] ?? null);
    }

    public function test_map_input_whitelists_schema_keys_only(): void
    {
        $executor = (new ReflectionClass(PromptHookExplicitBindingExecutor::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(PromptHookExplicitBindingExecutor::class, 'mapInput');
        $method->setAccessible(true);

        $out = $method->invoke($executor, [
            'input' => ['type' => 'string', 'required' => true],
            'focus_keyword' => ['type' => 'string', 'required' => false],
        ], [
            'input' => 'body',
            'focus_keyword' => 'kw',
            'topic' => 'MUST_NOT_PASS',
            'random_key' => 'x',
        ], []);

        self::assertSame(['input' => 'body', 'focus_keyword' => 'kw'], $out);
        self::assertArrayNotHasKey('topic', $out);
    }

    public function test_legacy_compile_vars_do_not_merge_full_shared_payload(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(PromptHookExplicitBindingExecutor::class))->getFileName(),
        );
        self::assertStringContainsString('Schema-whitelist only', $src);
        self::assertStringContainsString('expandCompileAliasMirrors', $src);
        self::assertStringNotContainsString('array_merge($variables, $input)', $src);
    }

    public function test_content_generate_manifest_has_no_topic_key(): void
    {
        $path = dirname((new ReflectionClass(PromptHookExplicitBindingExecutor::class))->getFileName(), 3)
            .'/resources/prompt-hooks/v01/article.content.generate@0.1.0.json';
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);
        $schema = $json['input_schema'] ?? [];
        self::assertIsArray($schema);
        self::assertArrayNotHasKey('topic', $schema);
        self::assertArrayHasKey('input', $schema);
    }

    public function test_outline_manifest_declares_topic(): void
    {
        $path = dirname((new ReflectionClass(PromptHookExplicitBindingExecutor::class))->getFileName(), 3)
            .'/resources/prompt-hooks/v01/article.outline.generate@0.1.0.json';
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        self::assertArrayHasKey('topic', $json['input_schema'] ?? []);
    }
}
