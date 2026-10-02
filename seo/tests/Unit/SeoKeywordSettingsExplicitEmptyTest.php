<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use App\Models\WpOption;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Tests\TestCase;

final class SeoKeywordSettingsExplicitEmptyTest extends TestCase
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
    }

    public function test_explicit_empty_global_rule_persists_without_restoring_defaults(): void
    {
        $service = SeoKeywordSettingsService::withDefaults();
        $settings = $service->getSettings();
        $settings['marketing_terms'] = [];
        $service->saveSettings($settings);

        self::assertSame([], (new SeoKeywordSettingsService)->getSettings()['marketing_terms']);
    }
}
