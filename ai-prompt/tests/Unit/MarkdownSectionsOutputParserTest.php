<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\DuplicateOutputSection;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\MismatchedSectionMarker;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\MissingRequiredSection;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\TextOutsideDeclaredSections;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\UnknownSectionMarker;
use Omnichannel\Addons\AiPrompt\PromptHooks\Output\MarkdownSectionsOutputParser;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use ReflectionClass;
use Tests\TestCase;

final class MarkdownSectionsOutputParserTest extends TestCase
{
    private MarkdownSectionsOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new MarkdownSectionsOutputParser;
    }

    private function outlineDefinition(): \Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookDefinition
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $loader->clearCache();

        return $loader->indexed()['article.outline.generate@0.1.0'];
    }

    private function pad(string $prefix): string
    {
        return $prefix.' '.str_repeat('y', 120);
    }

    private function validRaw(): string
    {
        $outline = $this->pad('# Outline section content');
        $vocab = $this->pad('- Vocabulary section content');

        return "[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_1_OUTLINE]\n"
            ."[START_TASK_2_VOCABULARY]\n{$vocab}\n[END_TASK_2_VOCABULARY]";
    }

    public function test_valid_two_section_output_and_ports(): void
    {
        $raw = $this->validRaw();
        $result = $this->parser->parse($this->outlineDefinition(), $raw, 'corr-1');

        self::assertArrayHasKey('outline', $result->sections);
        self::assertArrayHasKey('vocabulary', $result->sections);
        self::assertStringContainsString('Outline section', $result->ports['task_1_outline']);
        self::assertStringContainsString('Vocabulary section', $result->ports['task_2_vocabulary']);
        self::assertSame($raw, $result->ports['total']);
        self::assertStringNotContainsString('[START_TASK_1_OUTLINE]', $result->sections['outline']);
        self::assertStringNotContainsString('[END_TASK_2_VOCABULARY]', $result->sections['vocabulary']);
    }

    public function test_reversed_section_order_rejected(): void
    {
        $outline = $this->pad('outline');
        $vocab = $this->pad('vocab');
        $raw = "[START_TASK_2_VOCABULARY]\n{$vocab}\n[END_TASK_2_VOCABULARY]\n"
            ."[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_1_OUTLINE]";

        $this->expectException(MismatchedSectionMarker::class);
        $this->parser->parse($this->outlineDefinition(), $raw, 'corr-order');
    }

    public function test_missing_outline_marker(): void
    {
        $vocab = $this->pad('vocab only');
        $raw = "[START_TASK_2_VOCABULARY]\n{$vocab}\n[END_TASK_2_VOCABULARY]";

        $this->expectException(MissingRequiredSection::class);
        $this->parser->parse($this->outlineDefinition(), $raw, 'corr-missing-outline');
    }

    public function test_missing_vocabulary_marker(): void
    {
        $outline = $this->pad('outline only');
        $raw = "[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_1_OUTLINE]";

        $this->expectException(MissingRequiredSection::class);
        $this->parser->parse($this->outlineDefinition(), $raw, 'corr-missing-vocab');
    }

    public function test_duplicate_section(): void
    {
        $outline = $this->pad('outline');
        $vocab = $this->pad('vocab');
        $raw = "[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_1_OUTLINE]\n"
            ."[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_1_OUTLINE]\n"
            ."[START_TASK_2_VOCABULARY]\n{$vocab}\n[END_TASK_2_VOCABULARY]";

        $this->expectException(DuplicateOutputSection::class);
        $this->parser->parse($this->outlineDefinition(), $raw, 'corr-dup');
    }

    public function test_wrong_end_marker(): void
    {
        $outline = $this->pad('outline');
        $vocab = $this->pad('vocab');
        $raw = "[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_2_VOCABULARY]\n"
            ."[START_TASK_2_VOCABULARY]\n{$vocab}\n[END_TASK_2_VOCABULARY]";

        $this->expectException(MismatchedSectionMarker::class);
        $this->parser->parse($this->outlineDefinition(), $raw, 'corr-mismatch');
    }

    public function test_unknown_task_marker(): void
    {
        $raw = $this->validRaw()."\n[START_TASK_9_UNKNOWN]\nx\n[END_TASK_9_UNKNOWN]";

        $this->expectException(UnknownSectionMarker::class);
        $this->parser->parse($this->outlineDefinition(), $raw, 'corr-unknown');
    }

    public function test_short_preamble_outside_sections_normalized_away(): void
    {
        $raw = "Dưới đây là dàn ý:\n".$this->validRaw();
        $result = $this->parser->parse($this->outlineDefinition(), $raw, 'corr-outside-short');
        self::assertArrayHasKey('outline', $result->sections);
        self::assertStringContainsString('Outline section', $result->ports['task_1_outline']);
    }

    public function test_bom_and_outer_fence_normalized(): void
    {
        $raw = "\xEF\xBB\xBF```markdown\n".$this->validRaw()."\n```";
        $result = $this->parser->parse($this->outlineDefinition(), $raw, 'corr-bom-fence');
        self::assertArrayHasKey('vocabulary', $result->sections);
    }

    public function test_text_between_sections_still_rejected(): void
    {
        $outline = $this->pad('# Outline section content');
        $vocab = $this->pad('- Vocabulary section content');
        $raw = "[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_1_OUTLINE]\n"
            ."EXTRA MEANINGFUL BODY BETWEEN SECTIONS\n"
            ."[START_TASK_2_VOCABULARY]\n{$vocab}\n[END_TASK_2_VOCABULARY]";

        $this->expectException(TextOutsideDeclaredSections::class);
        $this->parser->parse($this->outlineDefinition(), $raw, 'corr-between');
    }

    public function test_markdown_fence_normalization(): void
    {
        $outline = "```markdown\n".$this->pad('# Fenced outline')."\n```";
        $vocab = $this->pad('vocab');
        $raw = "[START_TASK_1_OUTLINE]\n{$outline}\n[END_TASK_1_OUTLINE]\n"
            ."[START_TASK_2_VOCABULARY]\n{$vocab}\n[END_TASK_2_VOCABULARY]";

        $result = $this->parser->parse($this->outlineDefinition(), $raw, 'corr-fence');
        self::assertStringNotContainsString('```', $result->sections['outline']);
        self::assertStringContainsString('Fenced outline', $result->sections['outline']);
    }

    public function test_marker_regex_escaping_and_definition_driven(): void
    {
        $loader = new PromptHookDefinitionLoader(
            PromptHookDefinitionLoader::defaultV01Directory(),
            PromptHookDefinitionLoader::defaultPhase1Directory(),
        );
        $definition = $loader->hydrateSpecV01([
            'spec_version' => '0.1',
            'key' => 'article.demo.sections',
            'version' => '0.1.0',
            'status' => 'experimental',
            'name' => 'Demo sections',
            'enabled' => true,
            'model' => ['provider' => 'prompt_connection', 'name' => 'configured', 'settings' => []],
            'locale' => ['mode' => 'site', 'fallback' => 'en'],
            'input_schema' => [
                'keyword' => ['type' => 'string', 'required' => true, 'nullable' => false],
            ],
            'output_schema' => [
                'type' => 'markdown_sections',
                'sections' => [
                    [
                        'key' => 'alpha',
                        'label' => 'Alpha',
                        'task' => 1,
                        'start_marker' => '[START+A.(1)]',
                        'end_marker' => '[END+A.(1)]',
                        'required' => true,
                        'output_port' => 'task_1_alpha',
                        'normalize' => ['trim'],
                    ],
                    [
                        'key' => 'beta',
                        'label' => 'Beta',
                        'task' => 2,
                        'start_marker' => '[START+B.(2)]',
                        'end_marker' => '[END+B.(2)]',
                        'required' => true,
                        'output_port' => 'task_2_beta',
                        'normalize' => ['trim'],
                    ],
                ],
                'combined_output' => [
                    'enabled' => true,
                    'output_port' => 'total',
                    'preserve_markers' => true,
                ],
                'validation' => [
                    'reject_unknown_task_markers' => false,
                    'allow_text_outside_sections' => false,
                ],
            ],
            'template' => ['source' => 'legacy_prompt_content', 'system' => null, 'user' => null],
            'side_effects' => [],
        ]);

        $raw = "[START+A.(1)]\nalpha body\n[END+A.(1)]\n[START+B.(2)]\nbeta body\n[END+B.(2)]";
        $result = $this->parser->parse($definition, $raw, 'corr-escape');
        self::assertSame('alpha body', $result->ports['task_1_alpha']);
        self::assertSame('beta body', $result->ports['task_2_beta']);
    }

    public function test_parser_has_no_hardcoded_outline_hook_condition(): void
    {
        $ref = new ReflectionClass(MarkdownSectionsOutputParser::class);
        $source = file_get_contents($ref->getFileName() ?: '');
        self::assertIsString($source);
        self::assertStringNotContainsString('article.outline.generate', $source);
        self::assertStringNotContainsString("hookKey ===", $source);
    }
}
