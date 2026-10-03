<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Filament\Support\Colors\Color;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptHookPresentationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PromptHookModulePresentationTest extends TestCase
{
    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function hookModules(): array
    {
        return [
            'industry' => ['industry.match.generate', 'industry'],
            'article' => ['article.comment.generate', 'article'],
            'keyword' => ['keyword.discovery.structured', 'keyword'],
            'legacy gallery generate' => ['product.gallery.generate', 'article'],
            'legacy gallery plan' => ['product.gallery.plan', 'article'],
            'null hook' => [null, null],
            'empty hook' => ['', null],
            'blank hook' => ['   ', null],
        ];
    }

    #[DataProvider('hookModules')]
    public function test_module_is_presented_as_raw_first_segment_with_gallery_compatibility(
        ?string $hookKey,
        ?string $expected,
    ): void {
        self::assertSame($expected, PromptHookPresentationService::moduleForHook($hookKey));
    }

    /**
     * @return array<string, array{0: ?string, 1: string|array<int, string>}>
     */
    public static function moduleBadgeColors(): array
    {
        return [
            'article' => ['article.comment.generate', 'info'],
            'legacy gallery as article' => ['product.gallery.generate', 'info'],
            'industry' => ['industry.match.generate', Color::Purple],
            'keyword' => ['keyword.discovery.structured', 'success'],
            'seeding' => ['seeding.comment.generate', 'warning'],
            'seo audit' => ['seo_audit.run', 'danger'],
            'seo keywords' => ['seo_keywords.extract', Color::Cyan],
            'agent' => ['agent.response.compose', Color::Yellow],
            'unknown' => ['custom.module.run', 'gray'],
            'no hook' => [null, 'gray'],
        ];
    }

    #[DataProvider('moduleBadgeColors')]
    public function test_module_badges_have_stable_colors(
        ?string $hookKey,
        string|array $expected,
    ): void {
        self::assertSame($expected, PromptResource::moduleBadgeColorForHook($hookKey));
    }
}
