<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchIntelligence\Services\AiKeywordDiscoveryService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiKeywordDiscoveryOrderingTest extends TestCase
{
    public function test_gemini_model_order_uses_portable_case_not_mysql_field(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AiKeywordDiscoveryService::class))->getFileName() ?: '',
        );

        $this->assertStringContainsString(
            'CASE category WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END',
            $source,
        );
        $this->assertStringNotContainsString('FIELD(category', $source);
    }
}
