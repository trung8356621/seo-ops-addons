<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\AiPrompt\Services\SeoPromptSettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Settings UI cho phép keyword_density tới 2000; default non-product dài >100.
 * Hook schema phải khớp — nếu không, post_type≠product fail INVALID_INPUT ngẫu nhiên.
 */
final class KeywordDensityHookInputLimitTest extends TestCase
{
    public function test_default_keyword_density_exceeds_legacy_100_for_non_product(): void
    {
        $vars = SeoPromptSettingsService::withDefaults()->promptVariables('post');
        $density = (string) ($vars['keyword_density'] ?? '');

        self::assertGreaterThan(100, mb_strlen($density));
        self::assertLessThanOrEqual(2000, mb_strlen($density));
    }

    public function test_content_hooks_allow_keyword_density_up_to_settings_limit(): void
    {
        $dir = ProjectRoot::addonsPath().'/ai-prompt'.'/resources/prompt-hooks/v01';
        foreach ([
            'article.content.generate@0.1.0.json',
            'article.content.rewrite@0.1.0.json',
        ] as $file) {
            $path = $dir.'/'.$file;
            self::assertFileExists($path);
            $json = json_decode((string) file_get_contents($path), true);
            self::assertIsArray($json);
            $max = (int) ($json['input_schema']['keyword_density']['max_length'] ?? 0);
            self::assertSame(2000, $max, $file.' keyword_density.max_length');
        }
    }
}
