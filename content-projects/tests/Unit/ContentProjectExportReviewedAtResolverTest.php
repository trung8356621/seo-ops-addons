<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectExportReviewedAtResolver;
use PHPUnit\Framework\TestCase;

final class ContentProjectExportReviewedAtResolverTest extends TestCase
{
    public function test_priority_reviewed_at_then_last_update_wp_then_wp_created_at(): void
    {
        $resolver = new ContentProjectExportReviewedAtResolver();

        self::assertSame(
            '2026-09-01 10:00:00',
            $resolver->resolve([
                'reviewed_at' => '2026-09-01 10:00:00',
                'last_update_wp' => '2026-08-01 10:00:00',
                'wp_created_at' => '2026-07-01 10:00:00',
            ]),
        );

        self::assertSame(
            '2026-08-01 10:00:00',
            $resolver->resolve([
                'reviewed_at' => null,
                'last_update_wp' => '2026-08-01 10:00:00',
                'wp_created_at' => '2026-07-01 10:00:00',
            ]),
        );

        self::assertSame(
            '2026-07-01 10:00:00',
            $resolver->resolve([
                'reviewed_at' => '',
                'last_update_wp' => null,
                'wp_created_at' => '2026-07-01 10:00:00',
            ]),
        );

        self::assertNull($resolver->resolve([
            'reviewed_at' => null,
            'last_update_wp' => '',
            'wp_created_at' => null,
        ]));
    }
}
