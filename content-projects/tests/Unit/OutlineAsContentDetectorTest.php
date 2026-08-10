<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\OutlineAsContentDetector;
use Omnichannel\Addons\ContentProjects\Services\Workflow\ArtifactReusePolicy;
use PHPUnit\Framework\TestCase;

final class OutlineAsContentDetectorTest extends TestCase
{
    public function test_detects_outline_marker_in_body(): void
    {
        $detector = new OutlineAsContentDetector(new ArtifactReusePolicy);
        $body = "<p>[START_TASK_1_OUTLINE]</p><p>## Heading</p><p>[END_TASK_1_OUTLINE]</p>";

        self::assertSame('body_starts_with_or_contains_outline_marker', $detector->classify($body));
    }

    public function test_ignores_normal_article_body(): void
    {
        $detector = new OutlineAsContentDetector(new ArtifactReusePolicy);
        self::assertNull($detector->classify('<h1>Title</h1><p>Normal article paragraph.</p>'));
    }
}
