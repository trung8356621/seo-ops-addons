<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewDomainMonthlyProject;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\GenerateProjectItemsCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Commands\SendToPublishingQueueCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectVirtualMonthCommandPartitioner;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class DomainMonthlyVirtualProjectActionRoutingTest extends TestCase
{
    public function test_partitioner_groups_by_project_and_rescopes_command(): void
    {
        $partitioner = new ContentProjectVirtualMonthCommandPartitioner;
        $command = new SendToPublishingQueueCommand(101, [11, 22, 33]);
        $scoped = $partitioner->rescopeCommand($command, 102, [22]);

        self::assertInstanceOf(SendToPublishingQueueCommand::class, $scoped);
        self::assertSame(102, $scoped->projectRef);
        self::assertSame([22], $scoped->itemRefs);
        self::assertSame(101, $command->projectRef, 'Original command must stay untouched');
    }

    public function test_generate_command_rescope_keeps_mode_and_settings(): void
    {
        $partitioner = new ContentProjectVirtualMonthCommandPartitioner;
        $command = new GenerateProjectItemsCommand(101, [1, 2], 'full', false, ['generate_post_images' => true]);
        $scoped = $partitioner->rescopeCommand($command, 202, [2]);

        self::assertInstanceOf(GenerateProjectItemsCommand::class, $scoped);
        self::assertSame(202, $scoped->projectRef);
        self::assertSame([2], $scoped->itemRefs);
        self::assertSame('full', $scoped->mode);
        self::assertTrue((bool) ($scoped->settings['generate_post_images'] ?? false));
    }

    public function test_virtual_page_overrides_dispatch_paths_and_not_final_base(): void
    {
        $base = new ReflectionClass(ViewSeoProject::class);
        self::assertFalse($base->isFinal());

        $page = new ReflectionClass(ViewDomainMonthlyProject::class);
        foreach (['dispatchBus', 'dispatchPublishingCommand', 'dispatchGenerate', 'dispatchBulkStep', 'requireProject'] as $method) {
            self::assertTrue($page->hasMethod($method), $method);
            $m = $page->getMethod($method);
            self::assertSame(ViewDomainMonthlyProject::class, $m->getDeclaringClass()->getName(), $method);
        }
    }

    public function test_claim_needs_review_uses_resolve_owned_task_on_base(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ViewSeoProject::class))->getFileName(),
        );
        $start = strpos($src, 'function claimNeedsReviewItem');
        self::assertNotFalse($start);
        $chunk = substr($src, $start, 1800);
        self::assertStringContainsString('resolveOwnedTask', $chunk);
        self::assertStringContainsString('cp_return', $chunk);
        self::assertStringContainsString('virtualDomainMonth', $chunk);
    }

    public function test_resolve_owned_task_is_overridable(): void
    {
        $base = new ReflectionMethod(ViewSeoProject::class, 'resolveOwnedTask');
        $virtual = new ReflectionMethod(ViewDomainMonthlyProject::class, 'resolveOwnedTask');
        self::assertSame(ViewSeoProject::class, $base->getDeclaringClass()->getName());
        self::assertSame(ViewDomainMonthlyProject::class, $virtual->getDeclaringClass()->getName());
    }
}
