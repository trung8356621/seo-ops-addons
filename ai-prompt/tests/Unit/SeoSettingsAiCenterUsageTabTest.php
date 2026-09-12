<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Omnichannel\Addons\AiPrompt\Filament\Pages\SeoSettingsAiCenter;
use Tests\TestCase;

final class SeoSettingsAiCenterUsageTabTest extends TestCase
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
                $table->decimal('balance', 14, 4)->nullable();
                $table->string('currency', 10)->default('USD');
                $table->string('balance_status', 32)->default('unknown');
                $table->decimal('balance_warning_threshold', 14, 4)->default(5.0000);
                $table->timestamp('balance_checked_at')->nullable();
                $table->text('balance_error')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('seo_ai_models')) {
            Schema::create('seo_ai_models', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('api_connection_id')->index();
                $table->string('category', 50)->nullable();
                $table->string('raw_model_name', 128);
                $table->string('display_name')->nullable();
                $table->integer('priority')->default(100);
                $table->string('status', 20)->default('active');
                $table->text('last_error')->nullable();
                $table->json('capabilities')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_routing_targets')) {
            Schema::create('ai_routing_targets', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('profile_id')->nullable();
                $table->string('profile_key', 64)->index();
                $table->unsignedBigInteger('api_connection_id')->index();
                $table->unsignedBigInteger('seo_ai_model_id')->nullable();
                $table->string('model_key', 128);
                $table->unsignedInteger('priority')->default(1);
                $table->boolean('enabled')->default(true);
                $table->json('options')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ai_routing_profiles')) {
            Schema::create('ai_routing_profiles', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->default(0)->index();
                $table->string('key', 64);
                $table->string('name');
                $table->string('description')->nullable();
                $table->json('required_capabilities')->nullable();
                $table->boolean('enabled')->default(true);
                $table->json('settings')->nullable();
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

        $seoConn = (new \Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt())->getConnectionName() ?: 'omi_seo_ai';
        if (! Schema::connection($seoConn)->hasTable('prompt_result_routing_attempts')) {
            Schema::connection($seoConn)->create('prompt_result_routing_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_result_id')->nullable();
                $table->string('addon', 32)->nullable()->index();
                $table->string('module', 64)->nullable()->index();
                $table->string('action', 64)->nullable()->index();
                $table->string('provider', 64)->nullable();
                $table->string('model', 128)->nullable();
                $table->string('status', 32)->default('pending');
                $table->boolean('attempted')->default(true);
                $table->unsignedInteger('input_tokens')->nullable();
                $table->unsignedInteger('output_tokens')->nullable();
                $table->unsignedInteger('total_tokens')->nullable();
                $table->unsignedSmallInteger('attempt_sequence')->default(1);
                $table->timestamps();
            });
        }

        ApiConnection::query()->delete();
        User::query()->delete();

        $user = User::create([
            'name' => 'Owner Admin',
            'email' => 'owner@example.com',
            'password' => bcrypt('secret'),
            'role' => 'owner',
        ]);
        $this->actingAs($user);
    }

    public function test_usage_tab_hydration_and_helpers(): void
    {
        ApiConnection::create([
            'name' => 'DeepSeek Production',
            'provider' => 'deepseek',
            'api_key' => 'sk-test-deepseek',
            'balance' => 12.50,
            'balance_status' => 'normal',
            'balance_warning_threshold' => 5.0,
            'status' => 'active',
        ]);

        $component = Livewire::test(SeoSettingsAiCenter::class, ['tab' => 'usage']);

        $component->assertSet('tab', 'usage')
            ->assertSet('usageHydrated', true);

        /** @var SeoSettingsAiCenter $instance */
        $instance = $component->instance();

        // Wallet cards
        $cards = $instance->walletCards();
        self::assertCount(1, $cards);
        self::assertSame('DeepSeek Production', $cards[0]['name']);
        self::assertSame(12.5, $cards[0]['balance']);
        self::assertSame('Bình thường', $cards[0]['status_label']);
        self::assertTrue($cards[0]['supported']);

        // Token summary, trend, table structure
        $summary = $instance->tokenSummary();
        self::assertArrayHasKey('seo', $summary);
        self::assertArrayHasKey('seeding', $summary);
        self::assertArrayHasKey('total_tokens', $summary);

        $trend = $instance->tokenDailyTrend();
        self::assertArrayHasKey('seo_series', $trend);
        self::assertArrayHasKey('seeding_series', $trend);

        $table = $instance->tokenTableData();
        self::assertIsArray($table);
    }

    public function test_refresh_connection_balance_action(): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response([
                'is_available' => true,
                'balance_infos' => [
                    ['currency' => 'USD', 'total_balance' => '18.7500'],
                ],
            ], 200),
        ]);

        $conn = ApiConnection::create([
            'name' => 'DeepSeek Stale',
            'provider' => 'deepseek',
            'api_key' => 'sk-test-deepseek-refresh',
            'balance' => 4.00,
            'balance_status' => 'low_balance',
            'balance_warning_threshold' => 5.0,
            'status' => 'active',
        ]);

        $component = Livewire::test(SeoSettingsAiCenter::class, ['tab' => 'usage']);
        $component->call('refreshConnectionBalance', $conn->id);

        $conn->refresh();
        self::assertEquals(18.75, (float) $conn->balance);
        self::assertSame('normal', $conn->balance_status);
    }

    public function test_edit_warning_threshold_modal_flow(): void
    {
        $conn = ApiConnection::create([
            'name' => 'Threshold Edit Test',
            'provider' => 'deepseek',
            'api_key' => 'sk-test-edit',
            'balance' => 8.00,
            'balance_warning_threshold' => 5.0,
            'balance_status' => 'normal',
            'status' => 'active',
        ]);

        $component = Livewire::test(SeoSettingsAiCenter::class, ['tab' => 'usage']);

        // Mở modal
        $component->call('openEditThresholdModal', $conn->id);
        $component->assertSet('editingThresholdConnectionId', $conn->id);
        $component->assertSet('editingThresholdValue', 5.0);

        // Đổi giá trị ngưỡng lên 10.0
        $component->set('editingThresholdValue', 10.0);
        $component->call('saveThreshold');

        $component->assertSet('editingThresholdConnectionId', null);

        $conn->refresh();
        self::assertEquals(10.0, (float) $conn->balance_warning_threshold);
        // Balance 8.0 <= threshold 10.0 -> trạng thái tự chuyển low_balance
        self::assertSame('low_balance', $conn->balance_status);
    }
}
