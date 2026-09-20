<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use Tests\TestCase;

final class AiCenterUsageRedirectToDashboardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('role')->default('owner');
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('api_connections')) {
            Schema::create('api_connections', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('name');
                $table->string('provider');
                $table->text('api_key')->nullable();
                $table->string('base_url')->nullable();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('seo_ai_models')) {
            Schema::create('seo_ai_models', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('api_connection_id')->index();
                $table->string('raw_model_name', 128);
                $table->string('status', 20)->default('active');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_routing_targets')) {
            Schema::create('ai_routing_targets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('profile_key', 64)->index();
                $table->unsignedBigInteger('api_connection_id')->index();
                $table->string('model_key', 128);
                $table->unsignedInteger('priority')->default(1);
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_routing_profiles')) {
            Schema::create('ai_routing_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->default(0)->index();
                $table->string('key', 64);
                $table->string('name');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wp_options')) {
            Schema::create('wp_options', function (Blueprint $table): void {
                $table->id();
                $table->string('option_name')->unique();
                $table->longText('option_value')->nullable();
                $table->string('autoload', 20)->default('yes');
            });
        }

        User::query()->delete();
        $user = User::create([
            'name' => 'Owner Admin',
            'email' => 'owner-redirect@example.com',
            'password' => bcrypt('secret'),
            'role' => 'owner',
        ]);
        $this->actingAs($user);
    }

    public function test_legacy_usage_tab_redirects_to_admin_dashboard(): void
    {
        Livewire::test(SeoSettingsAiCenter::class, ['tab' => 'usage'])
            ->assertRedirect();
    }

    public function test_ai_usage_page_class_removed(): void
    {
        self::assertFalse(class_exists(\Omnichannel\Addons\AiPrompt\Filament\Pages\AiUsagePage::class));
    }
}
