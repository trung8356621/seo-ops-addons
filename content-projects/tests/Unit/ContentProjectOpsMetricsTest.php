<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations\ContentProjectOpsMetrics;
use ReflectionClass;
use Tests\TestCase;

final class ContentProjectOpsMetricsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_ops_metrics');
        Schema::connection('omi_seo_ai')->create('seo_content_project_ops_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('metric_key');
            $table->date('bucket_date');
            $table->unsignedBigInteger('site_id')->default(0);
            $table->unsignedBigInteger('project_id')->default(0);
            $table->unsignedInteger('value')->default(0);
            $table->timestamps();
            $table->unique(['metric_key', 'bucket_date', 'site_id', 'project_id']);
        });
    }

    public function test_atomic_increment_stays_isolated_mysql_upsert(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ContentProjectOpsMetrics::class))->getFileName() ?: '');

        self::assertStringContainsString('private function atomicIncrement', $source);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $source);
        self::assertStringContainsString('value = value + VALUES(value)', $source);
    }

    public function test_snapshot_today_sums_existing_rows(): void
    {
        $today = now()->toDateString();
        DB::connection('omi_seo_ai')->table('seo_content_project_ops_metrics')->insert([
            [
                'metric_key' => 'runs',
                'bucket_date' => $today,
                'site_id' => 1,
                'project_id' => 0,
                'value' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'metric_key' => 'runs',
                'bucket_date' => $today,
                'site_id' => 2,
                'project_id' => 0,
                'value' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $snapshot = (new ContentProjectOpsMetrics)->snapshotToday([1, 2]);

        self::assertSame(5, $snapshot['runs'] ?? 0);
    }

    public function test_increment_does_not_throw_when_dialect_upsert_is_unavailable(): void
    {
        (new ContentProjectOpsMetrics)->increment('runs', 1, 1, 0);

        self::assertTrue(true);
    }

    public function test_missing_table_is_noop(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_ops_metrics');

        $metrics = new ContentProjectOpsMetrics;
        $metrics->increment('runs', 1);
        self::assertSame([], $metrics->snapshotToday());
    }
}
