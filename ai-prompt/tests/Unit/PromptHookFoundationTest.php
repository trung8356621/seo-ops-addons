<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookInputResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookManifestLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookOutputNormalizer;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookErrorCode;
use PHPUnit\Framework\TestCase;

final class PromptHookFoundationTest extends TestCase
{
    private function loader(): PromptHookManifestLoader
    {
        return new PromptHookManifestLoader(
            PromptHookManifestLoader::defaultDirectory(),
            failFast: true,
        );
    }

    private function registry(): PromptHookRegistry
    {
        $registry = new PromptHookRegistry($this->loader());
        $registry->clearCache();

        return $registry;
    }

    public function test_registry_loads_both_manifests(): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->has('article.title_suggestion'));
        self::assertTrue($registry->has('article.meta_description_suggestion'));
        self::assertCount(2, $registry->all());
    }

    public function test_duplicate_key_fails_fast(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'prompt_hooks_dup_'.uniqid('', true);
        mkdir($dir);

        $manifest = [
            'schema_version' => 1,
            'key' => 'dup.hook',
            'version' => 1,
            'label_key' => 'prompt_hooks.x.label',
            'description_key' => 'prompt_hooks.x.description',
            'model' => ['capability' => 'text', 'structured_output' => false],
            'input' => ['fields' => [], 'prompt_payload' => []],
            'settings' => [],
            'template' => null,
            'output' => ['format' => 'text'],
        ];
        file_put_contents($dir.'/a.json', json_encode($manifest));
        file_put_contents($dir.'/b.json', json_encode($manifest));

        $loader = new PromptHookManifestLoader($dir, failFast: true);

        try {
            $this->expectException(PromptHookException::class);
            $loader->loadAll();
        } finally {
            @unlink($dir.'/a.json');
            @unlink($dir.'/b.json');
            @rmdir($dir);
        }
    }

    public function test_manifest_missing_required_field_fails(): void
    {
        $loader = $this->loader();

        $this->expectException(PromptHookException::class);
        $loader->hydrate([
            'schema_version' => 1,
            'key' => 'broken',
            'version' => 1,
        ]);
    }

    public function test_title_hook_input_with_keyword_and_null_old_title(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $resolver = new PromptHookInputResolver;

        $resolved = $resolver->resolve(
            $definition,
            ['keyword' => '  cách giữ form balo  ', 'old_title' => ''],
            ['article' => ['title' => 'DB Title', 'focus_keyword' => 'db keyword', 'keyword' => 'db keyword']],
        );

        self::assertSame('cách giữ form balo', $resolved['keyword']);
        self::assertNull($resolved['old_title']);
    }

    public function test_title_hook_empty_keyword_fails_after_resolve(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $resolver = new PromptHookInputResolver;

        $this->expectException(PromptHookException::class);
        try {
            $resolver->resolve(
                $definition,
                ['keyword' => '   '],
                ['article' => ['title' => 'T', 'focus_keyword' => null, 'keyword' => null]],
            );
        } catch (PromptHookException $exception) {
            self::assertSame(PromptHookErrorCode::HookInputInvalid, $exception->errorCode);
            throw $exception;
        }
    }

    public function test_title_hook_falls_back_to_entity_keyword(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $resolver = new PromptHookInputResolver;

        $resolved = $resolver->resolve(
            $definition,
            [],
            ['article' => ['title' => 'DB Title', 'focus_keyword' => 'focus from db', 'keyword' => 'focus from db']],
        );

        self::assertSame('focus from db', $resolved['keyword']);
        self::assertSame('DB Title', $resolved['old_title']);
    }

    public function test_runtime_old_title_overrides_article_title(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $resolver = new PromptHookInputResolver;

        $resolved = $resolver->resolve(
            $definition,
            ['old_title' => 'UI Title'],
            ['article' => ['title' => 'DB Title', 'focus_keyword' => 'kw', 'keyword' => 'kw']],
        );

        self::assertSame('UI Title', $resolved['old_title']);
    }

    public function test_unknown_runtime_field_rejected(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $resolver = new PromptHookInputResolver;

        $this->expectException(PromptHookException::class);
        $resolver->resolve(
            $definition,
            ['keyword' => 'kw', 'hacker' => 'x'],
            ['article' => ['title' => 'T', 'focus_keyword' => 'kw', 'keyword' => 'kw']],
        );
    }

    public function test_meta_hook_title_required_and_old_description_null(): void
    {
        $definition = $this->registry()->get('article.meta_description_suggestion');
        $resolver = new PromptHookInputResolver;

        $resolved = $resolver->resolve(
            $definition,
            ['title' => '  My Title  ', 'old_description' => ''],
            ['article' => ['title' => 'DB', 'description' => 'From DB']],
        );

        self::assertSame('My Title', $resolved['title']);
        self::assertNull($resolved['old_description']);
    }

    public function test_meta_hook_falls_back_to_article_description(): void
    {
        $definition = $this->registry()->get('article.meta_description_suggestion');
        $resolver = new PromptHookInputResolver;

        $resolved = $resolver->resolve(
            $definition,
            [],
            ['article' => ['title' => 'DB Title', 'description' => 'Normalized description']],
        );

        self::assertSame('DB Title', $resolved['title']);
        self::assertSame('Normalized description', $resolved['old_description']);
    }

    public function test_meta_hook_runtime_overrides(): void
    {
        $definition = $this->registry()->get('article.meta_description_suggestion');
        $resolver = new PromptHookInputResolver;

        $resolved = $resolver->resolve(
            $definition,
            ['title' => 'UI Title', 'old_description' => 'UI Desc'],
            ['article' => ['title' => 'DB Title', 'description' => 'DB Desc']],
        );

        self::assertSame('UI Title', $resolved['title']);
        self::assertSame('UI Desc', $resolved['old_description']);
    }

    public function test_meta_hook_empty_title_fails(): void
    {
        $definition = $this->registry()->get('article.meta_description_suggestion');
        $resolver = new PromptHookInputResolver;

        $this->expectException(PromptHookException::class);
        $resolver->resolve(
            $definition,
            ['title' => ''],
            ['article' => ['title' => null, 'description' => null]],
        );
    }

    public function test_settings_defaults_and_drop_unknown_keys(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $resolver = new PromptHookSettingsResolver;

        $resolved = $resolver->normalizeForDefinition($definition, [
            'max_length' => 80,
            'garbage' => 'nope',
            'preserve_meaning' => false,
        ]);

        self::assertSame(80, $resolved['max_length']);
        self::assertFalse($resolved['preserve_meaning']);
        self::assertArrayNotHasKey('garbage', $resolved);
    }

    public function test_settings_clamp_out_of_range(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $resolver = new PromptHookSettingsResolver;

        $resolved = $resolver->resolve($definition, ['max_length' => 200]);

        self::assertSame(100, $resolved['max_length']);

        $resolvedLow = $resolver->resolve($definition, ['max_length' => 10]);
        self::assertSame(30, $resolvedLow['max_length']);
    }

    public function test_output_strips_fence_quotes_and_rejects_empty(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $normalizer = new PromptHookOutputNormalizer;

        $out = $normalizer->normalize($definition, "```\n\"Mẹo giữ form balo\"\n```");
        self::assertSame('Mẹo giữ form balo', $out['value']);

        $this->expectException(PromptHookException::class);
        $normalizer->normalize($definition, "   \n  ");
    }

    public function test_expose_to_prompt_excludes_nothing_for_title_payload(): void
    {
        $definition = $this->registry()->get('article.title_suggestion');
        $resolver = new PromptHookInputResolver;
        $resolved = [
            'keyword' => 'kw',
            'old_title' => null,
        ];
        $exposed = $resolver->exposeToPrompt($definition, $resolved);

        self::assertSame(['keyword' => 'kw', 'old_title' => null], $exposed);
        self::assertArrayNotHasKey('article_id', $exposed);
    }

    public function test_registry_cache_clear_reloads(): void
    {
        $registry = $this->registry();
        self::assertCount(2, $registry->all());
        $registry->clearCache();
        self::assertCount(2, $registry->all());
    }
}
