<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExecutionLimits;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectWriterAllocator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContentProjectExecutionPackingContractTest extends TestCase
{
    public function test_packing_service_exists_with_reuse_api(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionPackingService::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('function planPack', $src);
        self::assertStringContainsString('function planPackForBalanceMonth', $src);
        self::assertStringContainsString('function planRepack', $src);
        self::assertStringContainsString('function listReusableProjects', $src);
        self::assertStringContainsString('function listReusableWorkProjects', $src);
        self::assertStringContainsString('function listAppendableProjects', $src);
        self::assertStringContainsString('function listAppendableProjectsForMonth', $src);
        self::assertStringContainsString('function canAcceptMoreItems', $src);
        self::assertStringContainsString('projectHasGeneratorDoneItems', $src);
        self::assertStringContainsString('function isReusable', $src);
        self::assertStringContainsString('hasStartedExecution', $src);
        self::assertStringContainsString('activeItemCount($project) === 0', $src);
        self::assertStringContainsString('listAppendableProjects($userId, $month)', $src);
        self::assertStringContainsString('MAX_EXECUTION_PROJECT_ITEMS', $src);
        self::assertStringContainsString('deleteEmptyMutableProject', $src);
        self::assertStringNotContainsString('created manually', strtolower($src));
    }

    /**
     * Balance Months packing ignores writer affinity; normal planPack stays writer-scoped.
     */
    public function test_balance_month_packing_ignores_writer_affinity(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExecutionPackingService::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('Balance Months ONLY', $src);
        self::assertStringContainsString('function planPackForBalanceMonth', $src);
        self::assertStringContainsString('function listAppendableProjectsForMonth', $src);
        self::assertStringContainsString('listAppendableProjectsForMonth($month)', $src);

        // Writer-scoped path still filters by user_id (not globally weakened).
        self::assertMatchesRegularExpression(
            '/function listAppendableProjects\(int \$userId[\s\S]*?->where\(\'user_id\', \$userId\)/',
            $src,
        );

        // Month-wide Balance path must not filter by a single writer within its method body.
        if (! preg_match(
            '/function listAppendableProjectsForMonth\(Carbon\|string \$month\): Collection\s*\{([\s\S]*?)\n    public function /',
            $src,
            $m,
        )) {
            self::fail('listAppendableProjectsForMonth method body not found');
        }
        self::assertStringNotContainsString("->where('user_id'", $m[1]);
        self::assertStringContainsString('freeSlots', $m[1]);
    }

    public function test_fair_allocation_then_independent_chunk_math(): void
    {
        self::assertSame([21, 21, 20], ContentProjectWriterAllocator::fairCounts(62, 3));
        self::assertSame(
            [30, 1],
            array_map('count', ContentProjectWriterAllocator::chunkByMaxItems(range(1, 31))),
        );
        self::assertSame(30, ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS);
    }

    public function test_split_service_uses_packing_not_blind_create(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft\SplitDraftContentProjectService::class,
            ))->getFileName(),
        );

        self::assertStringContainsString('ContentProjectExecutionPackingService', $src);
        self::assertStringContainsString('planPack', $src);
        self::assertStringContainsString('created_projects', $src);
        self::assertStringContainsString('reused_projects', $src);
        self::assertStringContainsString('redirect_month', $src);
        self::assertStringContainsString("'site_id' => null", $src);
        self::assertStringNotContainsString('Draft domain is required', $src);
    }

    public function test_repair_command_registered(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 3).'/seo-content-ai-compat/SeoContentAiServiceProvider.php',
        );
        self::assertStringContainsString('RepairExecutionProjectPackingCommand::class', $src);

        $cmd = (string) file_get_contents(
            (string) (new ReflectionClass(
                \Omnichannel\Addons\ContentProjects\Console\RepairExecutionProjectPackingCommand::class,
            ))->getFileName(),
        );
        self::assertStringContainsString('seo:repair-execution-project-packing', $cmd);
        self::assertStringContainsString('dry-run', $cmd);
    }

    public function test_ui_redirect_and_domain_neutral_list(): void
    {
        $trait = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Concerns/InteractsWithDraftSplit.php',
        );
        self::assertStringContainsString('draft_split_view_month_projects', $trait);
        self::assertStringContainsString('redirect($listUrl', $trait);
        self::assertStringNotContainsString("getUrl('view', ['record' => \$executionId])", $trait);

        $list = (string) file_get_contents(
            dirname(__DIR__, 2).'/src/Filament/Resources/SeoProjectResource/Pages/ListSeoProjects.php',
        );
        self::assertStringContainsString('projectType', $list);
        self::assertStringContainsString('ContentProjectListBucket', $list);
        self::assertStringNotContainsString('applyGlobalSiteScopeToProjectQuery', $list);
        self::assertStringNotContainsString('RefreshesOnDomainContextChanged', $list);
    }
}
