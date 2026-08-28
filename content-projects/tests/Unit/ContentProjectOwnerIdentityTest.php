<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use App\Models\User;
use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectContinuationService;
use Omnichannel\Addons\ContentProjects\Services\ContentProjectStaffAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

final class ContentProjectOwnerIdentityTest extends TestCase
{
    public function test_list_owner_column_uses_related_user_name_and_eager_loads_user(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectResource::class))->getFileName(),
        );

        self::assertStringContainsString("TextColumn::make('user.name')", $source);
        self::assertStringContainsString("->with(['user', 'site'])", $source);
        self::assertStringNotContainsString('owner_name', $source);
        self::assertStringNotContainsString('staff_name', $source);
    }

    public function test_staff_availability_lists_eligible_writers_by_user_name(): void
    {
        $source = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectStaffAvailabilityService::class))->getFileName(),
        );

        self::assertStringContainsString("pluck('user_id')", $source);
        self::assertStringContainsString('Month uniqueness retired', $source);
        self::assertStringContainsString("\$params['staff']", $source);
        self::assertStringContainsString('$user->name', $source);
        self::assertStringNotContainsString("whereNotIn('id', \$assignedIds)", $source);
        self::assertStringNotContainsString('nickname', $source);
        self::assertStringNotContainsString('display_name', $source);
    }

    public function test_monthly_clone_copies_user_id_not_owner_name(): void
    {
        $source = new SeoProject;
        $source->user_id = 123;
        $source->site_id = 9;
        $source->description = 'keep-me';

        $attrs = (new ContentProjectContinuationService)->continuationAttributes($source, '2026-08-01');

        self::assertSame(123, $attrs['user_id']);
        self::assertSame(9, $attrs['site_id']);
        self::assertSame('2026-08-01', $attrs['month']);
        self::assertArrayNotHasKey('owner_name', $attrs);
        self::assertArrayNotHasKey('staff_name', $attrs);
        self::assertSame('keep-me', $attrs['description']);
    }

    public function test_renaming_user_is_visible_on_project_without_updating_project_row(): void
    {
        $user = new User(['name' => 'Yến Huỳnh']);
        $user->id = 123;

        $project = new SeoProject;
        $project->user_id = 123;
        $project->setRelation('user', $user);

        $user->name = 'Natoli Yến';

        self::assertSame(123, (int) $project->user_id);
        self::assertSame('Natoli Yến', $project->user?->name);
        self::assertSame('Natoli Yến', $project->user?->display_name);
    }

    public function test_two_users_with_same_name_keep_distinct_project_ownership(): void
    {
        $first = new User(['name' => 'Natoli A']);
        $first->id = 11;
        $second = new User(['name' => 'Natoli A']);
        $second->id = 22;

        $july = new SeoProject;
        $july->user_id = 11;
        $july->setRelation('user', $first);

        $other = new SeoProject;
        $other->user_id = 22;
        $other->setRelation('user', $second);

        self::assertSame('Natoli A', $july->user?->name);
        self::assertSame('Natoli A', $other->user?->name);
        self::assertNotSame((int) $july->user_id, (int) $other->user_id);
    }

    public function test_staff_with_july_project_stays_assigned_after_rename_when_tables_exist(): void
    {
        if (! Schema::hasTable('users')
            || ! Schema::connection('omi_seo_ai')->hasTable('seo_projects')
        ) {
            $this->markTestSkipped('users / seo_projects tables are not available.');
        }

        $user = User::query()->create([
            'name' => 'Yến Huỳnh',
            'email' => 'yen-rename-'.uniqid('', true).'@example.com',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_STAFF,
            'seo_role' => User::SEO_ROLE_CONTENT_MANAGER,
            'status' => User::STATUS_NORMAL,
            'parent_id' => 1,
        ]);

        $project = SeoProject::query()->create([
            'name' => SeoProject::defaultNameFromMonth(Carbon::parse('2026-07-01')),
            'user_id' => (int) $user->id,
            'site_id' => 92099,
            'month' => '2026-07-01',
            'status' => SeoProject::STATUS_MANUAL,
            'kind' => SeoProject::KIND_MONTHLY,
            'total_tasks' => 0,
        ]);

        $user->update(['name' => 'Natoli Yến']);

        $assigned = app(ContentProjectStaffAvailabilityService::class)
            ->assignedStaffIdsForMonth('2026-07');

        self::assertContains((int) $user->id, $assigned);
        self::assertSame('Natoli Yến', $project->fresh()->user?->name);
        self::assertSame((int) $user->id, (int) $project->fresh()->user_id);

        $project->delete();
        $user->delete();
    }
}
