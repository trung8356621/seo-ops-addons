<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Tests\Unit;

use Omnichannel\Addons\Media\Services\TypographyComplexityParser;
use Omnichannel\Addons\Content\Support\TypographyScoringConfig;
use PHPUnit\Framework\TestCase;

final class TypographyComplexityParserTest extends TestCase
{
    public function test_parses_visible_text_json_blocks(): void
    {
        $compiled = <<<'TXT'
Generate infographic.

```json
{
  "visible_text_blocks": [
    {"id": "title", "text": "Ưu đãi 50%", "type": "title", "required": true},
    {"id": "cta", "text": "Mua ngay", "type": "cta", "required": true}
  ]
}
```
TXT;

        $complexity = (new TypographyComplexityParser)->parse($compiled);

        self::assertCount(2, $complexity->visibleTextBlocks);
        self::assertTrue($complexity->exactTextRequired);
        self::assertGreaterThan(0.0, $complexity->complexityScore);
        self::assertSame('vi', $complexity->language);
    }

    public function test_scoring_penalizes_missing_required_blocks(): void
    {
        $config = new TypographyScoringConfig;
        $expected = [
            ['id' => 'title', 'text' => 'ABC', 'required' => true, 'weight' => 1.0, 'type' => 'title'],
        ];

        $high = $config->computeScore($expected, [['id' => 'title', 'text' => 'ABC']], [], [], []);
        $low = $config->computeScore($expected, [], ['title'], [], ['Extra']);

        self::assertGreaterThan($low, $high);
    }
}
