<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Services\ArticleLastContentChangeResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ArticleLastContentChange;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class ArticleLastContentChangePhase21Test extends TestCase
{
    public function test_resolver_returns_typed_change_and_max_timestamp(): void
    {
        $resolver = new ArticleLastContentChangeResolver;
        $change = $resolver->resolve([
            'last_manual_saved_at' => '2026-07-26 10:00:00',
            'last_synced_at' => '2026-07-26 11:00:00',
            'last_ai_content_at' => '2026-07-26 12:00:00',
        ]);
        self::assertInstanceOf(ArticleLastContentChange::class, $change);
        self::assertSame('ai', $change->source);
        self::assertInstanceOf(Carbon::class, $change->occurredAt);
        self::assertNotNull($change->absolute);
        self::assertArrayHasKey('occurred_at', $change->toArray());
    }

    public function test_touch_ai_and_publish_wiring(): void
    {
        $svc = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleLastSavedTimestampService.php',
        );
        $publish = (string) file_get_contents(
            ProjectRoot::addonsPath().'/ai-prompt/src/Services/PromptTestPublishService.php',
        );
        self::assertStringContainsString('function touchAiContent', $svc);
        self::assertStringContainsString('last_ai_content_at', $svc);
        self::assertStringContainsString('touchAiContent', $publish);
        self::assertStringContainsString('$newHash !== $expectedHash', $publish);
    }

    public function test_migration_exists(): void
    {
        $path = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_07_26_160000_add_last_ai_content_at_to_articles_table.php');
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);
        self::assertStringContainsString('last_ai_content_at', $src);
    }
}
