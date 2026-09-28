<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoAccessControlSiteScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.core_connection', 'sqlite');
        Config::set('database.connections.omi_seo_ai', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->string('status')->default('normal');
            $table->timestamps();
        });

        Schema::dropIfExists('sites');
        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('domain')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_apply_accessible_site_scope_uses_resolved_ids_not_subquery(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner-scope@test.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_OWNER,
            'status' => User::STATUS_NORMAL,
        ]);

        $site = Site::query()->create([
            'user_id' => $owner->id,
            'domain' => 'example.test',
            'status' => 'active',
        ]);

        $this->actingAs($owner);

        $query = SeoAccessControl::applyAccessibleSiteScope(SeoArticle::query());
        $sql = $query->toSql();

        $this->assertStringNotContainsString('sites', strtolower($sql));
        $this->assertSame([$site->id], SeoAccessControl::accessibleSiteIds());
    }
}
