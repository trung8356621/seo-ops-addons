<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\Media\Jobs\GenerateMediaJob;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\ImageOutputModePromptInjector;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Support\PromptPostProcessing;
use Omnichannel\Addons\Media\Support\ProductGallery\ProductGalleryReadyState;
use Omnichannel\Addons\Content\Support\Utf8Sanitizer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class Utf8SanitizerVariablesForAiTest extends TestCase
{
    public function test_generate_media_job_prompt_runner_path_uses_variables_for_ai(): void
    {
        $job = (string) file_get_contents(
            (new ReflectionClass(GenerateMediaJob::class))->getFileName() ?: '',
        );
        $runner = (string) file_get_contents(
            (new ReflectionClass(PromptRunnerService::class))->getFileName() ?: '',
        );

        self::assertStringContainsString('promptRunner->run(', $job);
        self::assertStringContainsString('ensureQuickSplitSnapshot(', $job);
        self::assertStringContainsString('Utf8Sanitizer::variablesForAi($variables)', $runner);
        self::assertStringContainsString(
            'PromptPostProcessing::resolveFromVariablesOrPrompt($variables, $prompt)',
            $runner,
        );
    }

    public function test_job_to_runner_bag_survives_variables_for_ai_without_array_to_string(): void
    {
        $before = [
            'title' => 'scalar',
            'input' => "  tote   bag  \n\n\n  navy  ",
            'count' => 3,
            'ratio' => 1.5,
            'enabled' => true,
            'missing' => null,
            'product_gallery' => [
                'gallery_ready' => true,
                'gallery_source' => 'pending',
                'fallback_snapshot' => [
                    'media_ids' => [1, 2],
                    'urls' => ['https://example.test/a.jpg', 'https://example.test/b.jpg'],
                    'origin' => 'album_before_generate',
                    'label' => "  bad\x00  utf8   label  ",
                ],
            ],
            'quick_split' => [
                'enabled' => true,
                'grid_size' => 3,
                'rows' => 3,
                'columns' => 3,
                'expected_panels' => 9,
                'resize_enabled' => false,
                'resize_width' => null,
                'resize_height' => null,
                'label' => "  nested   spaces\x00  ",
            ],
        ];

        self::assertIsArray($before['product_gallery']);
        self::assertIsArray($before['quick_split']);

        set_error_handler(static function (int $severity, string $message): bool {
            if (str_contains($message, 'Array to string conversion')) {
                self::fail('Array to string conversion: '.$message);
            }

            return false;
        });

        try {
            $after = Utf8Sanitizer::variablesForAi($before);
        } finally {
            restore_error_handler();
        }

        self::assertIsArray($after['product_gallery']);
        self::assertIsArray($after['quick_split']);
        self::assertSame('array', gettype($after['product_gallery']));
        self::assertSame('array', gettype($after['quick_split']));

        self::assertSame('scalar', $after['title']);
        self::assertSame('tote bag'."\n\n".'navy', $after['input']);
        self::assertSame(3, $after['count']);
        self::assertSame(1.5, $after['ratio']);
        self::assertTrue($after['enabled']);
        self::assertNull($after['missing']);
        self::assertTrue($after['product_gallery']['gallery_ready']);
        self::assertSame([1, 2], $after['product_gallery']['fallback_snapshot']['media_ids']);
        self::assertSame('bad utf8 label', $after['product_gallery']['fallback_snapshot']['label']);
        self::assertSame('nested spaces', $after['quick_split']['label']);
        self::assertSame(3, $after['quick_split']['grid_size']);
        self::assertSame(3, $after['quick_split']['rows']);
        self::assertSame(3, $after['quick_split']['columns']);
        self::assertSame(9, $after['quick_split']['expected_panels']);

        $prompt = $this->createStub(SeoPrompt::class);
        $resolved = PromptPostProcessing::resolveFromVariablesOrPrompt($after, $prompt);
        self::assertTrue($resolved['split_enabled']);
        self::assertSame(3, $resolved['split_grid_size']);
        self::assertSame(3, $resolved['split_rows']);
        self::assertSame(3, $resolved['split_columns']);
        self::assertSame(9, $resolved['expected_panels']);

        $injected = (new ImageOutputModePromptInjector)->inject('Product views here.', $resolved);
        self::assertStringContainsString('MODE=SQUARE_SPRITE_SHEET', $injected);
        self::assertStringContainsString('GRID_ROWS=3', $injected);
        self::assertStringContainsString('GRID_COLUMNS=3', $injected);
        self::assertStringContainsString('TOTAL_CELLS=9', $injected);
        self::assertStringContainsString('CELL_ASPECT_RATIO=1:1', $injected);

        $galleryState = ProductGalleryReadyState::readFromVariables($after);
        self::assertSame([1, 2], $galleryState['fallback_snapshot']['media_ids']);
        self::assertSame(
            ['https://example.test/a.jpg', 'https://example.test/b.jpg'],
            $galleryState['fallback_snapshot']['urls'],
        );
    }

    public function test_variables_aligns_structure_semantics_without_ai_compaction(): void
    {
        $payload = [
            'title' => "  hello   world  ",
            'product_gallery' => ['gallery_ready' => true],
            'quick_split' => [
                'enabled' => true,
                'label' => "  keep   spaces\x00x  ",
            ],
            'missing' => null,
        ];

        $plain = Utf8Sanitizer::variables($payload);
        $forAi = Utf8Sanitizer::variablesForAi($payload);

        self::assertIsArray($plain['product_gallery']);
        self::assertIsArray($plain['quick_split']);
        self::assertIsArray($forAi['product_gallery']);
        self::assertIsArray($forAi['quick_split']);
        self::assertTrue($plain['product_gallery']['gallery_ready']);
        self::assertNull($plain['missing']);

        // variables(): UTF-8 only — does not collapse whitespace like AI compact.
        self::assertSame('  hello   world  ', $plain['title']);
        self::assertSame('  keep   spacesx  ', $plain['quick_split']['label']);

        // variablesForAi(): nested strings also AI-compact.
        self::assertSame('hello world', $forAi['title']);
        self::assertSame('keep spacesx', $forAi['quick_split']['label']);
    }

    public function test_array_deep_reuses_utf8_value_sanitizer_not_ai_compact(): void
    {
        $deep = Utf8Sanitizer::arrayDeep([
            'nested' => ['label' => "  a   b\x00  "],
            'flag' => true,
        ]);

        self::assertTrue($deep['flag']);
        self::assertSame('  a   b  ', $deep['nested']['label']);
    }
}
