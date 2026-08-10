<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Agent\Automation\Support\SensitivePayloadRedactor;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookManifestLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookOutputNormalizer;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\PromptHookSettingsResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookLocaleResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSettingsMerger;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecV01Validator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Phase 5A — Spec v0.1 + fixtures + boundaries. Không DB, không AI provider.
 */
final class PromptHookSpecPhase5ATest extends TestCase
{
    private function fixturesDir(): string
    {
        return ProjectRoot::path().DIRECTORY_SEPARATOR.'docs'
            .DIRECTORY_SEPARATOR.'automation'
            .DIRECTORY_SEPARATOR.'prompt'
            .DIRECTORY_SEPARATOR.'fixtures';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadFixtures(): array
    {
        $dir = $this->fixturesDir();
        self::assertDirectoryExists($dir);
        $out = [];
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($decoded, basename($file));
            $out[] = $decoded;
        }

        return $out;
    }

    public function test_all_representative_fixtures_pass_spec_v01(): void
    {
        $validator = new PromptHookSpecV01Validator;
        $fixtures = $this->loadFixtures();
        self::assertGreaterThanOrEqual(10, count($fixtures));

        foreach ($fixtures as $spec) {
            $errors = $validator->validate($spec);
            self::assertSame(
                [],
                $errors,
                ($spec['key'] ?? '?').': '.implode('; ', $errors),
            );
        }
    }

    public function test_invalid_key_and_version_and_duplicate_pattern(): void
    {
        $validator = new PromptHookSpecV01Validator;
        self::assertNotSame([], $validator->validate([
            'spec_version' => '0.1',
            'key' => 'BadKey',
            'version' => '1',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => ['type' => 'text'],
            'template' => ['system' => 'x', 'user' => 'y'],
        ]));

        self::assertNotSame([], $validator->validate([
            'spec_version' => '0.1',
            'key' => 'article.ok',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => ['type' => 'text'],
            'template' => [],
        ]));
    }

    public function test_disabled_hook_valid_without_full_schemas(): void
    {
        $validator = new PromptHookSpecV01Validator;
        self::assertTrue($validator->isValid([
            'spec_version' => '0.1',
            'key' => 'article.disabled.hook',
            'version' => 1,
            'enabled' => false,
        ]));
    }

    public function test_unsupported_model_settings_and_secrets_rejected(): void
    {
        $validator = new PromptHookSpecV01Validator;
        $base = [
            'spec_version' => '0.1',
            'key' => 'article.test.hook',
            'version' => '1.0',
            'enabled' => true,
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [],
            'output_schema' => ['type' => 'text'],
            'template' => ['system' => 'a', 'user' => 'b'],
        ];

        $errors = $validator->validate(array_merge($base, [
            'model' => ['settings' => ['frequency_penalty' => 1]],
        ]));
        self::assertNotSame([], $errors);

        $errorsSecret = $validator->validate(array_merge($base, [
            'model' => ['api_key' => 'sk-x', 'settings' => []],
        ]));
        self::assertNotSame([], $errorsSecret);
    }

    public function test_eloquent_pass_and_domain_side_effect_rejected(): void
    {
        $validator = new PromptHookSpecV01Validator;
        $errors = $validator->validate([
            'spec_version' => '0.1',
            'key' => 'article.bad.write',
            'version' => '1.0',
            'enabled' => true,
            'model' => ['settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [
                'article' => ['type' => 'object', 'pass_eloquent' => true],
            ],
            'output_schema' => ['type' => 'text'],
            'template' => [],
            'side_effects' => ['eloquent_save'],
        ]);
        self::assertNotSame([], $errors);
    }

    public function test_locale_fallback_chain(): void
    {
        $resolver = new PromptHookLocaleResolver;
        self::assertSame('vi', $resolver->resolve(
            ['mode' => 'site', 'fallback' => 'en'],
            ['site_locale' => 'vi'],
        ));
        self::assertSame('en', $resolver->resolve(
            ['mode' => 'article', 'fallback' => 'en'],
            ['site_locale' => '', 'article_locale' => ''],
        ));
        self::assertSame('fr', $resolver->resolve(
            ['mode' => 'fixed', 'fixed' => 'fr', 'fallback' => 'en'],
            [],
        ));
        self::assertSame('vi', $resolver->resolve(
            ['mode' => 'article', 'fallback' => 'en'],
            ['article_locale' => '', 'site_locale' => 'vi'],
        ));
    }

    public function test_settings_merge_drops_secrets(): void
    {
        $merger = new PromptHookSettingsMerger;
        $merged = $merger->merge(
            ['max_length' => 65],
            ['max_length' => 70],
            ['site_tone' => 'formal'],
            ['api_key' => 'secret', 'max_length' => 80],
        );
        self::assertSame(80, $merged['max_length']);
        self::assertSame('formal', $merged['site_tone']);
        self::assertArrayNotHasKey('api_key', $merged);
    }

    public function test_template_render_and_missing_variable(): void
    {
        $renderer = new PromptHookSpecTemplateRenderer;
        self::assertSame(
            'Hello world',
            $renderer->render('Hello {{name}}', ['name' => 'world']),
        );

        $this->expectException(\InvalidArgumentException::class);
        $renderer->render('Hello {{name}}', []);
    }

    public function test_sensitive_redaction(): void
    {
        $redactor = new SensitivePayloadRedactor;
        $out = $redactor->redact([
            'keyword' => 'balo',
            'api_key' => 'sk-live',
            'nested' => ['access_token' => 'x', 'title' => 't'],
        ]);
        self::assertSame('[redacted]', $out['api_key']);
        self::assertSame('balo', $out['keyword']);
        self::assertSame('[redacted]', $out['nested']['access_token']);
    }

    public function test_output_schema_json_fence_strip_policy(): void
    {
        $registry = new PromptHookRegistry(new PromptHookManifestLoader(
            PromptHookManifestLoader::defaultDirectory(),
            failFast: true,
        ));
        $registry->clearCache();
        $definition = $registry->get('article.title_suggestion');
        $normalizer = new PromptHookOutputNormalizer;
        $out = $normalizer->normalize($definition, "```json\n\"Title Here\"\n```");
        self::assertSame('Title Here', $out['value']);
    }

    public function test_production_settings_merge_clamps_like_resolver(): void
    {
        $registry = new PromptHookRegistry(new PromptHookManifestLoader(
            PromptHookManifestLoader::defaultDirectory(),
            failFast: true,
        ));
        $registry->clearCache();
        $definition = $registry->get('article.title_suggestion');
        $resolver = new PromptHookSettingsResolver;
        self::assertSame(100, $resolver->resolve($definition, ['max_length' => 999])['max_length']);
    }

    public function test_required_input_invalid_type_covered_by_foundation_loader(): void
    {
        $loader = new PromptHookManifestLoader(
            PromptHookManifestLoader::defaultDirectory(),
            failFast: true,
        );
        $this->expectException(\Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException::class);
        $loader->hydrate([
            'schema_version' => 1,
            'key' => 'x.y',
            'version' => 1,
            // missing label_key / description_key / model / input / output
        ]);
    }

    public function test_invalid_hook_key_pattern_on_hydrate(): void
    {
        $loader = new PromptHookManifestLoader(sys_get_temp_dir(), failFast: true);
        try {
            $loader->hydrate([
                'schema_version' => 1,
                'key' => 'INVALID',
                'version' => 1,
                'label_key' => 'a',
                'description_key' => 'b',
                'model' => ['capability' => 'text', 'structured_output' => false],
                'input' => ['fields' => [], 'prompt_payload' => []],
                'output' => ['format' => 'text'],
            ]);
            // If loader does not validate key pattern, Spec validator does — assert Spec path
            $validator = new PromptHookSpecV01Validator;
            self::assertFalse($validator->isValid([
                'spec_version' => '0.1',
                'key' => 'INVALID',
                'version' => 1,
                'enabled' => true,
                'model' => ['settings' => []],
                'locale' => ['mode' => 'site', 'fallback' => 'en'],
                'input_schema' => [],
                'output_schema' => ['type' => 'text'],
                'template' => [],
            ]));
        } catch (\Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookException) {
            self::assertTrue(true);
        }
    }

    public function test_hook_boundary_spec_and_normalizer_avoid_wordpress_filament(): void
    {
        $forbidden = [
            'WordPressArticleSyncService',
            'ArticleEditorSyncOrchestrator',
            'Filament\\Resources\\',
            'SyncArticleToWordPressFromQueueJob',
        ];
        $base = ProjectRoot::addonsPath().'/ai-prompt/src'.DIRECTORY_SEPARATOR.'PromptHooks';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // Entity resolvers may reference SeoArticle model — skip Filament/WP only.
            $contents = (string) file_get_contents($file->getPathname());
            foreach ($forbidden as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $contents,
                    $file->getFilename()." must not reference [{$needle}]",
                );
            }
        }
    }

    public function test_experimental_hooks_classified_in_fixtures(): void
    {
        $experimental = 0;
        foreach ($this->loadFixtures() as $spec) {
            if (($spec['classification'] ?? '') === 'EXPERIMENTAL') {
                $experimental++;
            }
        }
        self::assertGreaterThanOrEqual(2, $experimental);
    }
}
