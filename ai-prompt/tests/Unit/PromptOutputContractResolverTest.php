<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Contracts\PromptOutputContractResolver;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDeterministicTemplateRenderer;
use Omnichannel\Addons\AiPrompt\PromptHooks\Spec\PromptHookSpecV01Validator;
use PHPUnit\Framework\TestCase;

final class PromptOutputContractResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'omi_contract_'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function test_resolves_markdown_article_from_default_catalog(): void
    {
        $resolver = new PromptOutputContractResolver(
            new PromptOutputContractCatalog(PromptOutputContractCatalog::defaultDirectory()),
        );

        $resolved = $resolver->resolve('markdown.article');

        $this->assertNotSame('', $resolved['text']);
        $this->assertStringContainsString('MARKDOWN ARTICLE OUTPUT CONTRACT', $resolved['text']);
        $this->assertSame('markdown.article', $resolved['contracts'][0]['key'] ?? null);
        $this->assertSame('1.0.0', $resolved['contracts'][0]['version'] ?? null);
    }

    public function test_append_injects_contract_once(): void
    {
        $resolver = new PromptOutputContractResolver(
            new PromptOutputContractCatalog(PromptOutputContractCatalog::defaultDirectory()),
        );

        $first = $resolver->appendToPrompt('Business prompt body.', 'markdown.article');
        $second = $resolver->appendToPrompt($first['prompt'], 'markdown.article');

        $this->assertSame(1, substr_count($second['prompt'], 'MARKDOWN ARTICLE OUTPUT CONTRACT'));
        $this->assertStringContainsString('Business prompt body.', $second['prompt']);
    }

    public function test_missing_contract_throws(): void
    {
        $resolver = new PromptOutputContractResolver(
            new PromptOutputContractCatalog($this->tmpDir),
        );

        $this->expectException(PromptHookFailure::class);
        $this->expectExceptionMessage('not found');
        $resolver->resolve('missing.contract');
    }

    public function test_circular_include_throws(): void
    {
        file_put_contents($this->tmpDir.'/alpha.contract@1.0.0.md', "A\n\n{{include:beta.contract}}");
        file_put_contents($this->tmpDir.'/beta.contract@1.0.0.md', "B\n\n{{include:alpha.contract}}");

        $resolver = new PromptOutputContractResolver(
            new PromptOutputContractCatalog($this->tmpDir),
        );

        $this->expectException(PromptHookFailure::class);
        $this->expectExceptionMessage('Circular');
        $resolver->resolve('alpha.contract');
    }

    public function test_duplicate_include_expands_once(): void
    {
        file_put_contents($this->tmpDir.'/shared.piece@1.0.0.md', 'SHARED_BODY');
        file_put_contents(
            $this->tmpDir.'/wrapper.piece@1.0.0.md',
            "START\n{{include:shared.piece}}\nMID\n{{include:shared.piece}}\nEND",
        );

        $resolver = new PromptOutputContractResolver(
            new PromptOutputContractCatalog($this->tmpDir),
        );
        $resolved = $resolver->resolve('wrapper.piece');

        $this->assertSame(1, substr_count($resolved['text'], 'SHARED_BODY'));
        $this->assertCount(2, $resolved['contracts']);
    }

    public function test_generate_and_rewrite_hooks_declare_same_contract(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
            new PromptHookSpecV01Validator,
        );
        $indexed = $loader->indexed();

        $generate = $indexed['article.content.generate@0.1.0'] ?? null;
        $rewrite = $indexed['article.content.rewrite@0.1.0'] ?? null;

        $this->assertNotNull($generate);
        $this->assertNotNull($rewrite);
        $this->assertSame('markdown.article', $generate->outputContractKey());
        $this->assertSame('markdown.article', $rewrite->outputContractKey());
    }

    public function test_json_title_hook_has_no_markdown_contract(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
            new PromptHookSpecV01Validator,
        );
        $title = $loader->indexed()['article.title_suggestion@0.1.0'] ?? null;

        $this->assertNotNull($title);
        $this->assertNull($title->outputContractKey());
    }

    public function test_renderer_appends_contract_for_legacy_prompt(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
            new PromptHookSpecV01Validator,
        );
        $definition = $loader->indexed()['article.content.generate@0.1.0'];
        $renderer = new PromptHookDeterministicTemplateRenderer;

        $request = $renderer->render(
            $definition,
            ['input' => 'outline here'],
            ['locale_code' => 'vi', 'language_name' => 'Vietnamese'],
            ['temperature' => 0.6],
            ['legacy_compiled_prompt' => 'Write the article now.'],
        );

        $content = (string) ($request->messages[0]['content'] ?? '');
        $this->assertStringContainsString('Write the article now.', $content);
        $this->assertSame(1, substr_count($content, 'MARKDOWN ARTICLE OUTPUT CONTRACT'));
        $this->assertSame(
            [['key' => 'markdown.article', 'version' => '1.0.0']],
            $request->metadata['output_contracts'] ?? null,
        );
        $this->assertSame($content, $request->metadata['legacy_compiled_prompt'] ?? null);
    }

    public function test_new_hook_can_declare_contract_via_json_only(): void
    {
        $errors = (new PromptHookSpecV01Validator)->validate([
            'spec_version' => '0.1',
            'key' => 'article.content.demo',
            'version' => '0.1.0',
            'enabled' => true,
            'model' => ['provider' => 'prompt_connection', 'name' => 'configured', 'settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [
                'input' => ['type' => 'string', 'required' => true],
            ],
            'output_schema' => ['type' => 'markdown'],
            'output_contract' => 'markdown.article',
            'template' => ['source' => 'legacy_prompt_content'],
        ]);

        $this->assertSame([], $errors);
    }
}
