<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Filament\Resources\AiConnectionResource;
use Omnichannel\Addons\AiPrompt\Models\ApiConnectionListRow;
use Omnichannel\Addons\SearchIntelligence\Models\SeoGscMasterConnection;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;
use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class AiConnectionResourceEditUrlTest extends TestCase
{
    private const CONNECTION_HASH = 'YTzrG3WKuG3ygjB3cA8wU7CVfV9aRh7G';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.mysql' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
            'app.url' => 'https://seo.teamvijahe.com',
        ]);

        DB::purge('mysql');

        Schema::connection('mysql')->create('seo_gsc_master_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('status')->default('not_configured');
            $table->text('credentials')->nullable();
            $table->string('account_email')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_global')->default(false);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        URL::defaults(['connection_hash' => self::CONNECTION_HASH]);
        SeoConnectionContext::applyUrlDefaults(self::CONNECTION_HASH);

        $owner = new User([
            'role' => User::ROLE_OWNER,
            'seo_role' => User::SEO_ROLE_MANAGER,
            'status' => User::STATUS_NORMAL,
        ]);
        $owner->id = 77;
        $owner->exists = true;
        $this->actingAs($owner);
    }

    public function test_gsc_list_row_can_be_edited(): void
    {
        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => 77,
            'name' => 'Google search full web',
            'status' => 'not_configured',
            'is_global' => false,
        ]);

        $row = ApiConnectionListRow::fromGsc($connection);

        $this->assertTrue(AiConnectionResource::canEdit($row));
    }

    public function test_gsc_edit_url_includes_connection_hash(): void
    {
        $connection = SeoGscMasterConnection::query()->create([
            'user_id' => 77,
            'name' => 'Google search full web',
            'status' => 'not_configured',
            'is_global' => false,
        ]);

        $row = ApiConnectionListRow::fromGsc($connection);
        $url = AiConnectionResource::resolveEditUrl($row);

        $this->assertIsString($url);
        $this->assertStringContainsString('/seo/'.self::CONNECTION_HASH.'/settings/api/google-search-console/'.$connection->id.'/edit', $url);
    }

    public function test_ai_connection_edit_url_uses_record_route(): void
    {
        $record = new ApiConnection([
            'provider' => ApiConnectionProviders::GEMINI,
            'name' => 'gemini-api',
        ]);
        $record->id = 12;
        $record->exists = true;

        $url = AiConnectionResource::resolveEditUrl($record);

        $this->assertIsString($url);
        $this->assertStringContainsString('/seo/'.self::CONNECTION_HASH.'/settings/api/12/edit', $url);
    }

    public function test_deepseek_connection_edit_url_uses_numeric_record_route(): void
    {
        $record = new ApiConnection([
            'provider' => ApiConnectionProviders::DEEPSEEK,
            'name' => 'deepseek',
        ]);
        $record->id = 2;
        $record->exists = true;

        $url = AiConnectionResource::resolveEditUrl($record);

        $this->assertIsString($url);
        $this->assertStringContainsString('/seo/'.self::CONNECTION_HASH.'/settings/api/2/edit', $url);
        $this->assertStringNotContainsString('/serp/', $url);
    }

    public function test_serp_edit_url_does_not_collide_with_numeric_ai_edit(): void
    {
        $url = AiConnectionResource::externalEditUrl(ApiConnectionProviders::SERPER);

        $this->assertStringContainsString('/settings/api/serp/serper/edit', $url);
    }
}
