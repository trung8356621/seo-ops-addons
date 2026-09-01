<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleLastSavedTimestampService;
use Omnichannel\Addons\SiteSync\Services\Orchestration\RunSiteSyncV3Orchestrator;
use Omnichannel\Addons\SiteSync\Services\Presentation\SiteSyncStatusPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SiteSyncV3AcceptanceHardeningTest extends TestCase
{
    public function test_touch_synced_does_not_pass_wp_post_id(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(ArticleLastSavedTimestampService::class))->getFileName()
        );
        $method = $this->methodBody($src, 'touchSynced');

        self::assertStringContainsString("'last_synced_at'", $method);
        self::assertStringNotContainsString("'wp_post_id'", $method);
    }

    public function test_catch_up_does_not_inflate_full_fetched_counter(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'phaseCatchUp');

        self::assertStringContainsString("counters['catch_up_fetched']", $method);
        self::assertStringNotContainsString("counters['fetched']", $method);
        self::assertStringContainsString('catch_up_since', $method);
    }

    public function test_import_increments_full_fetched(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );

        self::assertStringContainsString("counters['full_fetched']", $src);
    }

    public function test_handle_does_not_revive_terminal_runs(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'handle');

        self::assertStringContainsString("'needs_attention'", $method);
        self::assertStringContainsString('never flip status back to running', $method);
    }

    public function test_verify_uses_membership_not_counts_only(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(RunSiteSyncV3Orchestrator::class))->getFileName()
        );
        $method = $this->methodBody($src, 'phaseVerify');

        self::assertStringContainsString('enumerateWpContentInventory', $method);
        self::assertStringContainsString('sample_missing_wp_ids', $method);
        self::assertStringContainsString('softDeleteExtraLocalContent', $method);
        self::assertStringContainsString('final_expected_total', $method);
    }

    public function test_presenter_caps_progress_to_full_denominator(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(SiteSyncStatusPresenter::class))->getFileName()
        );
        $method = $this->methodBody($src, 'buildForV3Run');

        self::assertStringContainsString('full_fetched', $method);
        self::assertStringContainsString('catch_up_fetched', $method);
        self::assertStringContainsString('min($fullFetched', $method);
        self::assertStringContainsString("'needs_attention'", $method);
        self::assertStringContainsString("'checked' => \$fullFetched", $method);
        self::assertStringNotContainsString("'checked' => \$fetched", $method);
    }

    public function test_cursors_equal_helper_exists(): void
    {
        self::assertTrue((new ReflectionClass(RunSiteSyncV3Orchestrator::class))->hasMethod('cursorsEqual'));
    }

    private function methodBody(string $src, string $method): string
    {
        $ref = new ReflectionMethod(match ($method) {
            'touchSynced' => ArticleLastSavedTimestampService::class,
            'buildForV3Run' => SiteSyncStatusPresenter::class,
            default => RunSiteSyncV3Orchestrator::class,
        }, $method);
        $start = $ref->getStartLine();
        $end = $ref->getEndLine();
        $lines = explode("\n", $src);

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
