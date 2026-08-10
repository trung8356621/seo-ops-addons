<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroupItem;
use Omnichannel\Addons\SearchIntelligence\Services\SeoRankKeywordGroupService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoRankKeywordGroupServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.omi_seo_ai' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        ]);

        DB::purge('omi_seo_ai');
        DB::purge('mysql');
        $this->ensureTables();

        $user = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-rank-group@test.local',
            'password' => 'secret',
            'role' => 'owner',
        ]);
        $this->actingAs($user);
    }

    public function test_group_is_not_scoped_by_site(): void
    {
        $service = app(SeoRankKeywordGroupService::class);
        $group = $service->createGroup((int) auth()->id(), [
            'name' => 'Global Group',
            'country_code' => 'vn',
            'language_code' => 'vi',
            'device' => 'desktop',
            'keywords_text' => "balo du lịch\nbalo laptop",
        ]);

        $this->assertDatabaseHas('seo_rank_keyword_groups', [
            'id' => $group->id,
            'name' => 'Global Group',
            'country_code' => 'vn',
            'language_code' => 'vi',
            'device' => 'desktop',
        ], 'omi_seo_ai');

        $this->assertSame(2, $group->items()->count());
        $this->assertFalse(Schema::connection('omi_seo_ai')->hasColumn('seo_rank_keyword_groups', 'site_id'));
    }

    public function test_add_keywords_idempotent_and_multi_group(): void
    {
        $service = app(SeoRankKeywordGroupService::class);
        $groupA = $service->createGroup((int) auth()->id(), ['name' => 'A', 'keywords_text' => 'alpha']);
        $groupB = $service->createGroup((int) auth()->id(), ['name' => 'B']);

        $keyword = Keyword::query()->create(['phrase' => 'shared keyword']);

        $first = $service->addKeywordsToGroups([(int) $keyword->id], [(int) $groupA->id, (int) $groupB->id], (int) auth()->id());
        $second = $service->addKeywordsToGroups([(int) $keyword->id], [(int) $groupA->id], (int) auth()->id());

        $this->assertSame(['added' => 2, 'skipped' => 0], $first);
        $this->assertSame(['added' => 0, 'skipped' => 1], $second);
        $this->assertSame(2, SeoRankKeywordGroupItem::query()->where('group_id', $groupA->id)->count());
        $this->assertSame(1, SeoRankKeywordGroupItem::query()->where('group_id', $groupB->id)->count());
    }

    public function test_target_domain_normalization(): void
    {
        $service = app(SeoRankKeywordGroupService::class);

        $this->assertSame('example.com', $service->normalizeTargetDomain('https://www.example.com/'));
        $this->assertNull($service->normalizeTargetDomain(''));
    }

    public function test_parse_keyword_lines_deduplicates_case_insensitively(): void
    {
        $service = app(SeoRankKeywordGroupService::class);

        $lines = $service->parseKeywordLines("Alpha\nalpha\n  beta \n");

        $this->assertSame(['Alpha', 'beta'], $lines);
    }

    public function test_accessible_scope_uses_account_owner(): void
    {
        $owner = User::query()->create([
            'name' => 'Account Owner',
            'email' => 'account-owner@test.local',
            'password' => 'secret',
            'role' => 'owner',
        ]);

        SeoRankKeywordGroup::query()->create([
            'created_by' => $owner->id,
            'name' => 'Owner Group',
            'country_code' => 'vn',
            'language_code' => 'vi',
            'device' => 'desktop',
            'is_active' => true,
        ]);

        $staff = User::query()->create([
            'name' => 'Staff',
            'email' => 'staff@test.local',
            'password' => 'secret',
            'role' => 'staff',
            'seo_role' => 'planner',
        ]);

        $this->actingAs($staff);

        $visible = SeoRankKeywordGroup::query()->accessible()->count();
        $this->assertSame(0, $visible);
    }

    private function ensureTables(): void
    {
        Schema::connection('mysql')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('owner');
            $table->string('seo_role')->nullable();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('keywords', function (Blueprint $table): void {
            $table->id();
            $table->string('phrase')->unique();
            $table->timestamps();
        });

        Schema::connection('omi_seo_ai')->create('seo_rank_keyword_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('country_code', 8)->default('vn');
            $table->string('language_code', 16)->default('vi');
            $table->string('location')->nullable();
            $table->string('device', 16)->default('desktop');
            $table->string('target_domain')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('omi_seo_ai')->create('seo_rank_keyword_group_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->timestamps();
            $table->unique(['group_id', 'keyword_id']);
        });
    }
}
