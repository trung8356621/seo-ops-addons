<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectItemOperationsReadModel;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectTaskPostTypeRecheckService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\LegacyAddonPath;

final class ContentProjectItemPostTypeDisplayContractTest extends TestCase
{
    public function test_ops_read_model_exposes_effective_post_type_via_recheck_service(): void
    {
        $src = $this->source(ContentProjectItemOperationsReadModel::class);

        self::assertStringContainsString("'post_type'", $src);
        self::assertStringContainsString('effectivePostType', $src);
        self::assertStringContainsString('ContentProjectTaskPostTypeRecheckService', $src);
        self::assertStringContainsString('resolveEffectiveForDisplay', $src);
        self::assertStringNotContainsString('function rawTaskPostType', $src);
    }

    public function test_item_meta_renders_post_type_debug_suffix(): void
    {
        $meta = (string) file_get_contents(
            LegacyAddonPath::resolve('resources/views/components/content-project-item-meta.blade.php'),
        );

        self::assertStringContainsString("row['post_type']", $meta);
        self::assertStringContainsString('post_type:', $meta);
        self::assertStringContainsString("'—'", $meta);
        self::assertStringNotContainsString('normalizePostType', $meta);
        self::assertStringNotContainsString('wire:click', $meta);
    }

    public function test_recheck_service_display_contract_for_create_vs_rewrite(): void
    {
        $src = $this->source(ContentProjectTaskPostTypeRecheckService::class);
        self::assertStringContainsString('TYPE_CREATE', $src);
        self::assertStringContainsString('TYPE_REWRITE', $src);
        self::assertStringContainsString('TYPE_IMPROVE', $src);
        self::assertStringContainsString('resolveEffectiveForDisplay', $src);
        self::assertStringContainsString('ArticlePostTypeResolver::resolve', $src);
    }

    /**
     * @param  class-string  $class
     */
    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        self::assertNotFalse($file);

        return (string) file_get_contents($file);
    }
}
