<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Tests\Unit;

use Omnichannel\Addons\Publishing\Services\Publishing\PublishingLocalMediaSlugPreparer;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyAddonPath;
use Tests\Support\ProjectRoot;

final class PublishingLocalMediaSlugPreparerContractTest extends TestCase
{
    public function test_preparer_reuses_canonical_fix_all_service(): void
    {
        $path = ProjectRoot::addonsPath().'/publishing/src/Services/Publishing/PublishingLocalMediaSlugPreparer.php';
        self::assertFileExists($path);
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('SeoMediaArticleSlugFixAllService', $src);
        self::assertStringContainsString('fixPendingMediaForPublish', $src);
        self::assertStringContainsString('pendingLocalSlugFixIds', $src);
        self::assertStringContainsString('OUTCOME_HARD_BLOCKED', $src);
        self::assertStringContainsString('OUTCOME_PREPARED', $src);
    }

    public function test_fix_all_service_exposes_pending_publish_entry(): void
    {
        $path = ProjectRoot::addonsPath()
            .'/content-projects/src/Services/ContentProject/SeoMediaArticleSlugFixAllService.php';
        $src = (string) file_get_contents($path);

        self::assertStringContainsString('function fixPendingMediaForPublish', $src);
        self::assertStringContainsString('SeoMediaArticleSlugFixService', $src);
        self::assertStringContainsString('not_auto_fixable_ids', $src);
    }

    public function test_handler_wires_preparer_before_claim(): void
    {
        $handler = (string) file_get_contents(
            ProjectRoot::addonsPath()
            .'/content-projects/src/Services/ContentProject/Application/Handlers/ProcessScheduledProjectItemPublishHandler.php',
        );

        self::assertStringContainsString('PublishingLocalMediaSlugPreparer', $handler);
        self::assertLessThan(
            (int) strpos($handler, 'claimForDispatch'),
            (int) strpos($handler, 'prepareForPublish'),
        );
    }

    public function test_failure_classifier_treats_media_preflight_as_permanent(): void
    {
        $src = (string) file_get_contents(
            ProjectRoot::addonsPath().'/publishing/src/Application/Publishing/PublishFailureClassifier.php',
        );

        self::assertStringContainsString('media_preflight', $src);
        self::assertStringContainsString('cần xử lý trước khi xuất bản', $src);
    }
}
