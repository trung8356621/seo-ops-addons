<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\SeoAnalyzerService;
use ReflectionMethod;
use Tests\TestCase;

final class SeoAnalyzerAnchorNormalizeTest extends TestCase
{
    public function test_normalize_anchor_restores_focus_keyword_when_anchor_is_suffix(): void
    {
        $method = new ReflectionMethod(SeoAnalyzerService::class, 'normalizeAnchorAgainstFocusKeyword');
        $method->setAccessible(true);

        $analyzer = app(SeoAnalyzerService::class);

        $result = $method->invoke(
            $analyzer,
            'àu sắc túi canvas 2025',
            'màu sắc túi canvas 2025',
        );

        $this->assertSame('màu sắc túi canvas 2025', $result);
    }

    public function test_normalize_anchor_restores_focus_keyword_when_first_letter_outside_anchor(): void
    {
        $method = new ReflectionMethod(SeoAnalyzerService::class, 'normalizeAnchorAgainstFocusKeyword');
        $method->setAccessible(true);

        $analyzer = app(SeoAnalyzerService::class);

        $result = $method->invoke(
            $analyzer,
            'ay balo laptop',
            'May Balo Laptop',
        );

        $this->assertSame('May Balo Laptop', $result);
    }
}
