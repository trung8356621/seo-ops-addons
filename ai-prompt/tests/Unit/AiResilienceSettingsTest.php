<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Omnichannel\Addons\AiPrompt\Services\AiResilienceSettingsService;
use Tests\TestCase;

final class AiResilienceSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('wp_options');
        Schema::create('wp_options', function (Blueprint $table): void {
            $table->id();
            $table->string('option_name')->unique();
            $table->longText('option_value')->nullable();
            $table->string('autoload')->default('no');
            $table->timestamps();
        });
        WpOption::clearRequestCache();
        WpOption::set(AiResilienceSettingsService::OPTION_KEY, []);
    }

    public function test_defaults_are_six_and_three(): void
    {
        $settings = (new AiResilienceSettingsService())->get(99);
        $this->assertSame(6, $settings['max_ai_attempts']);
        $this->assertSame(3, $settings['max_free_attempts']);
    }

    public function test_persists_per_user(): void
    {
        $service = new AiResilienceSettingsService();
        $service->save(42, ['max_ai_attempts' => 4, 'max_free_attempts' => 2]);
        $this->assertSame(4, $service->get(42)['max_ai_attempts']);
        $this->assertSame(6, $service->get(99)['max_ai_attempts']);
    }

    public function test_rejects_invalid_combination(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AiResilienceSettingsService())->save(1, ['max_ai_attempts' => 3, 'max_free_attempts' => 5]);
    }
}
