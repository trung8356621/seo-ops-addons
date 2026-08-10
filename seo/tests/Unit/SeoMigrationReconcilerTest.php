<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Support\SeoMigrationReconciler;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoMigrationReconcilerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.omi_seo_ai', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_reconciles_create_migration_when_table_already_exists(): void
    {
        Schema::connection('omi_seo_ai')->create('prompts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        /** @var Migrator $migrator */
        $migrator = app(Migrator::class);
        $migrator->setConnection('omi_seo_ai');

        $migrationPath = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_05_16_100000_create_prompts_table.php');
        $files = [
            '2026_05_16_100000_create_prompts_table' => $migrationPath,
        ];

        $reconciled = app(SeoMigrationReconciler::class)->reconcileExistingCreateTables(
            $migrator,
            'omi_seo_ai',
            $files,
        );

        $this->assertSame(1, $reconciled);
        $this->assertContains(
            '2026_05_16_100000_create_prompts_table',
            $migrator->getRepository()->getRan(),
        );
    }

    public function test_skips_non_create_migrations(): void
    {
        Schema::connection('omi_seo_ai')->create('prompts', function (Blueprint $table): void {
            $table->id();
        });

        /** @var Migrator $migrator */
        $migrator = app(Migrator::class);
        $migrator->setConnection('omi_seo_ai');

        $migrationPath = \Omnichannel\Addons\Seo\Support\SeoMigrationPath::find('2026_06_01_110000_add_default_model_category_to_prompts_table.php');
        $files = [
            '2026_06_01_110000_add_default_model_category_to_prompts_table' => $migrationPath,
        ];

        $reconciled = app(SeoMigrationReconciler::class)->reconcileExistingCreateTables(
            $migrator,
            'omi_seo_ai',
            $files,
        );

        $this->assertSame(0, $reconciled);
        $this->assertNotContains(
            '2026_06_01_110000_add_default_model_category_to_prompts_table',
            $migrator->getRepository()->getRan(),
        );
    }
}
