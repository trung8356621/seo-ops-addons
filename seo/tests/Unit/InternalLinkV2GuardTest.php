<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Services\Linking\InternalLinkV2Guard;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class InternalLinkV2GuardTest extends TestCase
{
    #[Test]
    public function self_ineligible_and_invalid_targets_are_removed(): void
    {
        $result = (new InternalLinkV2Guard())->filter('article:source', [
            ['ref' => 'article:source', 'url' => 'https://example.test/source', 'eligible' => true, 'same_as_source' => true],
            ['ref' => 'article:draft', 'url' => 'https://example.test/draft', 'eligible' => false],
            ['ref' => 'article:bad', 'url' => 'notaurl', 'eligible' => true],
            ['ref' => 'article:ok', 'url' => 'https://example.test/ok', 'eligible' => true],
        ]);

        self::assertSame(['article:ok'], array_column($result['accepted'], 'ref'));
        self::assertSame(
            ['self_link', 'ineligible', 'invalid_url'],
            array_column($result['rejected'], 'reason'),
        );
    }
}
