<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Content\Services\ArticleCompletedArchiveQueryService;
use Tests\TestCase;

final class ArticleCompletedArchiveMonthOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_archive_items');
        Schema::connection('omi_seo_ai')->create('seo_content_archive_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function test_month_options_bucket_by_year_month(): void
    {
        DB::connection('omi_seo_ai')->table('seo_content_archive_items')->insert([
            ['site_id' => 5, 'archived_at' => '2026-08-02 10:00:00'],
            ['site_id' => 5, 'archived_at' => '2026-08-20 10:00:00'],
            ['site_id' => 5, 'archived_at' => '2026-09-01 10:00:00'],
            ['site_id' => 9, 'archived_at' => '2026-07-01 10:00:00'],
        ]);

        $options = app(ArticleCompletedArchiveQueryService::class)->monthOptionsForSites([5]);

        self::assertSame(['2026-09' => '09/2026', '2026-08' => '08/2026'], $options);
    }
}
