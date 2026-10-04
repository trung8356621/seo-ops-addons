<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordWorkspaceMetric;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordWorkspace\KeywordWorkspaceMetricCache;
use Tests\TestCase;

final class KeywordWorkspaceMetricCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.omi_seo_ai' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('omi_seo_ai');
        Schema::connection('omi_seo_ai')->create('seo_keyword_workspace_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('language_code', 32)->default('*');
            $table->string('namespace', 32);
            $table->string('metric_key', 64);
            $table->bigInteger('value');
            $table->timestamp('generated_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'language_code', 'namespace', 'metric_key']);
        });
    }

    public function test_missing_metric_is_persisted_and_warm_read_does_not_recompute(): void
    {
        $calls = 0;
        $cache = app(KeywordWorkspaceMetricCache::class);
        $compute = static function () use (&$calls): array {
            $calls++;

            return ['total' => 17];
        };

        self::assertSame(['total' => 17], $cache->rememberMetrics(1, 'vi', 'dictionary', ['total'], $compute));
        self::assertSame(['total' => 17], $cache->rememberMetrics(1, 'vi', 'dictionary', ['total'], $compute));
        self::assertSame(1, $calls);
        self::assertSame(17, (int) KeywordWorkspaceMetric::query()->value('value'));
    }

    public function test_site_language_and_namespace_are_isolated(): void
    {
        $cache = app(KeywordWorkspaceMetricCache::class);
        $cache->rememberMetrics(1, 'vi', 'topics', ['total'], static fn (): array => ['total' => 11]);
        $cache->rememberMetrics(1, 'en', 'topics', ['total'], static fn (): array => ['total' => 22]);
        $cache->rememberMetrics(2, 'vi', 'topics', ['total'], static fn (): array => ['total' => 33]);
        $cache->rememberMetrics(1, null, 'tags', ['total'], static fn (): array => ['total' => 44]);
        $cache->rememberMetrics(1, null, 'external', ['total'], static fn (): array => ['total' => 55]);

        $cache->invalidateNamespace(1, KeywordWorkspaceMetricCache::EXTERNAL);

        self::assertSame(4, KeywordWorkspaceMetric::query()->count());
        self::assertSame(11, (int) KeywordWorkspaceMetric::query()->where('site_id', 1)->where('language_code', 'vi')->where('namespace', 'topics')->value('value'));
        self::assertSame(22, (int) KeywordWorkspaceMetric::query()->where('site_id', 1)->where('language_code', 'en')->value('value'));
        self::assertSame(33, (int) KeywordWorkspaceMetric::query()->where('site_id', 2)->value('value'));
        self::assertSame(44, (int) KeywordWorkspaceMetric::query()->where('namespace', 'tags')->value('value'));
    }

    public function test_stale_metric_recomputes_and_metric_invalidation_is_granular(): void
    {
        KeywordWorkspaceMetric::query()->create([
            'site_id' => 7,
            'language_code' => 'vi',
            'namespace' => 'dictionary',
            'metric_key' => 'total',
            'value' => 1,
            'generated_at' => now()->subDays(2),
            'expires_at' => now()->subMinute(),
        ]);
        KeywordWorkspaceMetric::query()->create([
            'site_id' => 7,
            'language_code' => 'vi',
            'namespace' => 'dictionary',
            'metric_key' => 'active',
            'value' => 2,
            'generated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $cache = app(KeywordWorkspaceMetricCache::class);
        self::assertSame(
            ['total' => 9],
            $cache->rememberMetrics(7, 'vi', 'dictionary', ['total'], static fn (): array => ['total' => 9]),
        );
        $cache->invalidateMetric(7, 'dictionary', 'total', 'vi');

        self::assertFalse(KeywordWorkspaceMetric::query()->where('metric_key', 'total')->exists());
        self::assertTrue(KeywordWorkspaceMetric::query()->where('metric_key', 'active')->exists());
    }
}
