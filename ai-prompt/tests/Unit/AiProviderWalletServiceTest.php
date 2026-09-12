<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\ApiConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Services\Wallet\AiProviderWalletService;
use Tests\TestCase;

final class AiProviderWalletServiceTest extends TestCase
{
    private AiProviderWalletService $service;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('api_connections')) {
            Schema::create('api_connections', function (Blueprint $table): void {
                $table->id();
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

        ApiConnection::query()->delete();

        $this->service = app(AiProviderWalletService::class);
    }

    public function test_deepseek_balance_fetch_success_updates_connection(): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response([
                'is_available' => true,
                'balance_infos' => [
                    [
                        'currency' => 'USD',
                        'total_balance' => '15.5000',
                        'granted_balance' => '0.0000',
                        'topped_up_balance' => '15.5000',
                    ],
                ],
            ], 200),
        ]);

        $connection = ApiConnection::create([
            'name' => 'DeepSeek Production',
            'provider' => 'deepseek',
            'api_key' => 'sk-test-deepseek-valid',
            'balance_warning_threshold' => 5.0,
            'status' => 'active',
        ]);

        $result = $this->service->checkConnectionBalance($connection);

        self::assertTrue($result->isSuccess());
        self::assertSame(15.5, $result->balance);
        self::assertSame('USD', $result->currency);

        $connection->refresh();
        self::assertEquals(15.5, (float) $connection->balance);
        self::assertSame('USD', $connection->currency);
        self::assertSame(AiProviderWalletService::STATUS_NORMAL, $connection->balance_status);
        self::assertNotNull($connection->balance_checked_at);
        self::assertNull($connection->balance_error);
    }

    public function test_deepseek_balance_under_threshold_marks_low_balance(): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::response([
                'is_available' => true,
                'balance_infos' => [
                    [
                        'currency' => 'USD',
                        'total_balance' => '2.5000',
                    ],
                ],
            ], 200),
        ]);

        $connection = ApiConnection::create([
            'name' => 'DeepSeek Low',
            'provider' => 'deepseek',
            'api_key' => 'sk-test-deepseek-low',
            'balance_warning_threshold' => 5.0,
            'status' => 'active',
        ]);

        $this->service->checkConnectionBalance($connection);

        $connection->refresh();
        self::assertEquals(2.5, (float) $connection->balance);
        self::assertSame(AiProviderWalletService::STATUS_LOW_BALANCE, $connection->balance_status);
    }

    public function test_api_failure_does_not_zero_balance_and_preserves_last_known_balance(): void
    {
        Http::fake([
            'https://api.deepseek.com/user/balance' => Http::sequence()
                ->push([
                    'is_available' => true,
                    'balance_infos' => [
                        ['currency' => 'USD', 'total_balance' => '20.0000'],
                    ],
                ], 200)
                ->push('Internal Server Error', 500),
        ]);

        $connection = ApiConnection::create([
            'name' => 'DeepSeek Fluctuating',
            'provider' => 'deepseek',
            'api_key' => 'sk-test-deepseek-error',
            'balance_warning_threshold' => 5.0,
            'status' => 'active',
        ]);

        // Lần 1: Thành công
        $this->service->checkConnectionBalance($connection);
        $connection->refresh();
        self::assertEquals(20.0, (float) $connection->balance);
        self::assertSame(AiProviderWalletService::STATUS_NORMAL, $connection->balance_status);

        // Lần 2: API lỗi 500
        $result = $this->service->checkConnectionBalance($connection);

        self::assertFalse($result->isSuccess());
        $connection->refresh();

        // TUYỆT ĐỐI KHÔNG GHI THÀNH 0 - GIỮ NGUYÊN SỐ DƯ CŨ
        self::assertEquals(20.0, (float) $connection->balance);
        self::assertSame(AiProviderWalletService::STATUS_CHECK_FAILED, $connection->balance_status);
        self::assertNotNull($connection->balance_error);
        self::assertStringContainsString('500', $connection->balance_error);
    }

    public function test_openrouter_balance_calculation_available_credits(): void
    {
        Http::fake([
            'https://openrouter.ai/api/v1/credits' => Http::response([
                'data' => [
                    'total_credits' => 50.0,
                    'total_usage' => 18.25,
                ],
            ], 200),
        ]);

        $connection = ApiConnection::create([
            'name' => 'OpenRouter Test',
            'provider' => 'openrouter',
            'api_key' => 'sk-or-test-key',
            'balance_warning_threshold' => 10.0,
            'status' => 'active',
        ]);

        $result = $this->service->checkConnectionBalance($connection);

        self::assertTrue($result->isSuccess());
        // 50.0 - 18.25 = 31.75
        self::assertSame(31.75, $result->balance);

        $connection->refresh();
        self::assertEquals(31.75, (float) $connection->balance);
        self::assertSame(AiProviderWalletService::STATUS_NORMAL, $connection->balance_status);
    }

    public function test_unsupported_providers_marked_as_unsupported_without_api_calls(): void
    {
        Http::fake();

        $connection = ApiConnection::create([
            'name' => 'Claude Anthropic',
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'status' => 'active',
        ]);

        $result = $this->service->checkConnectionBalance($connection);

        self::assertFalse($result->isSuccess());
        self::assertTrue($result->isUnsupported());

        $connection->refresh();
        self::assertNull($connection->balance);
        self::assertSame(AiProviderWalletService::STATUS_UNSUPPORTED, $connection->balance_status);
        self::assertNull($connection->balance_error);

        // Không có HTTP request nào được gửi
        Http::assertNothingSent();
    }

    public function test_update_warning_threshold(): void
    {
        $connection = ApiConnection::create([
            'name' => 'Threshold Test',
            'provider' => 'deepseek',
            'api_key' => 'sk-test',
            'balance' => 8.0,
            'balance_warning_threshold' => 5.0,
            'balance_status' => AiProviderWalletService::STATUS_NORMAL,
            'status' => 'active',
        ]);

        // Ban đầu balance 8 > threshold 5 -> normal
        self::assertSame(AiProviderWalletService::STATUS_NORMAL, $connection->balance_status);

        // Nâng threshold lên 10 -> balance 8 <= threshold 10 -> low_balance
        $this->service->updateWarningThreshold($connection, 10.0);

        $connection->refresh();
        self::assertEquals(10.0, (float) $connection->balance_warning_threshold);
        self::assertSame(AiProviderWalletService::STATUS_LOW_BALANCE, $connection->balance_status);
    }
}
