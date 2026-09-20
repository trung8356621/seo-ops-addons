<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Pages\ViewSeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Tests\TestCase;

/**
 * Behavioral Livewire: Create with AI remorphs Running / Emergency Stop without remount.
 *
 * Requires SEO_TEST_USE_MYSQL=true against the local app DB (same as other CP integration tests).
 */
final class ContentProjectRuntimeUiCreateWithAiLivewireTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai', 'mysql'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var(env('SEO_TEST_USE_MYSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set SEO_TEST_USE_MYSQL=true to run against local MySQL.');
        }
    }

    public function test_create_with_ai_shows_runtime_controls_then_clears_after_completion(): void
    {
        $user = User::query()->where('role', 'owner')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();
        if (! $user instanceof User) {
            $this->markTestSkipped('No user available.');
        }

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('seo'));

        $project = $this->findEligibleProject();
        if (! $project instanceof SeoProject) {
            $this->markTestSkipped('No eligible content project with pending generate items.');
        }

        SeoProjectRun::query()
            ->where('project_id', (int) $project->getKey())
            ->where('status', SeoProjectRun::STATUS_RUNNING)
            ->update([
                'status' => SeoProjectRun::STATUS_CANCELLED,
                'finished_at' => now(),
            ]);

        $component = Livewire::actingAs($user)
            ->test(ViewSeoProject::class, ['record' => (int) $project->getKey()]);

        $htmlIdle = $component->html();
        self::assertStringNotContainsString('Dừng khẩn cấp', $htmlIdle);

        $component->callAction('create_with_ai');

        $run = SeoProjectRun::query()
            ->where('project_id', (int) $project->getKey())
            ->latest('id')
            ->first();
        self::assertInstanceOf(SeoProjectRun::class, $run);
        self::assertSame(SeoProjectRun::STATUS_RUNNING, (string) $run->status);

        $htmlRunning = $component->html();
        self::assertStringContainsString('Dừng khẩn cấp', $htmlRunning);
        self::assertTrue(
            str_contains($htmlRunning, 'Đang chạy')
            || str_contains($htmlRunning, 'Running')
            || str_contains($htmlRunning, 'data-cp-runtime-live')
            || str_contains($htmlRunning, 'data-cp-active-runtime'),
            'Expected running indicator or runtime banner after Create with AI (no remount).',
        );
        self::assertGreaterThan(0, (int) $component->instance()->runtimeUiEpoch);

        $run->update([
            'status' => SeoProjectRun::STATUS_COMPLETED,
            'finished_at' => now(),
        ]);
        $component->call('manualRefreshOps');

        $htmlDone = $component->html();
        self::assertStringNotContainsString('Dừng khẩn cấp', $htmlDone);
        self::assertStringNotContainsString('data-cp-runtime-live', $htmlDone);
        self::assertStringNotContainsString('data-cp-active-runtime', $htmlDone);
    }

    private function findEligibleProject(): ?SeoProject
    {
        $ids = SeoProjectTask::query()
            ->where('status', 'pending')
            ->whereNull('article_id')
            ->whereNull('archived_at')
            ->orderByDesc('id')
            ->limit(40)
            ->pluck('project_id')
            ->unique()
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($ids as $projectId) {
            $project = SeoProject::query()->find($projectId);
            if (! $project instanceof SeoProject) {
                continue;
            }
            if ($project->isDraftPlanning() || $project->isArchive() || $project->isProjectArchived()) {
                continue;
            }
            if (! SeoProjectResource::canView($project)) {
                continue;
            }
            if (! SeoProjectResource::canGeneratePendingItems($project)) {
                continue;
            }

            return $project;
        }

        return null;
    }
}
