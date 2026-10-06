<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Media\Models\SeoMedia;
use Tests\TestCase;

final class SeoMediaBuilderQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('omi_seo_ai')->dropIfExists('seo_media_meta');
        Schema::connection('omi_seo_ai')->dropIfExists('seo_media');
        Schema::connection('omi_seo_ai')->create('seo_media', function (Blueprint $table): void {
            $table->id();
            $table->string('filename')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::connection('omi_seo_ai')->create('seo_media_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('media_id')->index();
            $table->string('meta_key');
            $table->text('meta_value')->nullable();
        });
    }

    public function test_article_id_matches_json_array_and_scalar_meta(): void
    {
        $jsonId = (int) DB::connection('omi_seo_ai')->table('seo_media')->insertGetId([
            'filename' => 'json.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $scalarId = (int) DB::connection('omi_seo_ai')->table('seo_media')->insertGetId([
            'filename' => 'scalar.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherId = (int) DB::connection('omi_seo_ai')->table('seo_media')->insertGetId([
            'filename' => 'other.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('omi_seo_ai')->table('seo_media_meta')->insert([
            ['media_id' => $jsonId, 'meta_key' => 'article_id', 'meta_value' => '[1013, 7]'],
            ['media_id' => $scalarId, 'meta_key' => 'article_id', 'meta_value' => '1013'],
            ['media_id' => $otherId, 'meta_key' => 'article_id', 'meta_value' => '9'],
        ]);

        $ids = SeoMedia::query()->where('article_id', 1013)->orderBy('id')->pluck('id')->all();

        self::assertSame([$jsonId, $scalarId], array_map('intval', $ids));
        $sql = strtolower(SeoMedia::query()->where('article_id', 1013)->toSql());
        self::assertTrue(
            str_contains($sql, 'json_contains') || str_contains($sql, 'json_each'),
            $sql,
        );
    }

    public function test_column_after_meta_uses_driver_datetime_cast(): void
    {
        $staleId = (int) DB::connection('omi_seo_ai')->table('seo_media')->insertGetId([
            'filename' => 'stale.jpg',
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-10-01 12:00:00',
        ]);
        $freshId = (int) DB::connection('omi_seo_ai')->table('seo_media')->insertGetId([
            'filename' => 'fresh.jpg',
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ]);

        DB::connection('omi_seo_ai')->table('seo_media_meta')->insert([
            ['media_id' => $staleId, 'meta_key' => 'wp_synced_at', 'meta_value' => '2026-09-15 00:00:00'],
            ['media_id' => $freshId, 'meta_key' => 'wp_synced_at', 'meta_value' => '2026-09-15 00:00:00'],
        ]);

        $sql = SeoMedia::query()->whereColumnAfterMeta('updated_at', '>', 'wp_synced_at')->toSql();
        self::assertStringContainsString('datetime(', strtolower($sql));

        $ids = SeoMedia::query()
            ->whereColumnAfterMeta('updated_at', '>', 'wp_synced_at')
            ->pluck('id')
            ->all();

        self::assertSame([$staleId], array_map('intval', $ids));
    }
}
