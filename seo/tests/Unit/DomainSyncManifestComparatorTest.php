<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Support\DomainSyncManifestComparator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

final class DomainSyncManifestComparatorTest extends TestCase
{
    public function test_it_fetches_new_and_updated_posts_and_skips_unchanged(): void
    {
        $local = collect([
            (object) [
                'wp_post_id' => 10,
                'type' => 'article',
                'wp_post_type' => 'post',
                'updated_at' => Carbon::parse('2026-01-01 10:00:00'),
            ],
            (object) [
                'wp_post_id' => 20,
                'type' => 'product',
                'wp_post_type' => 'product',
                'updated_at' => Carbon::parse('2026-06-01 12:00:00'),
            ],
        ]);

        $manifest = [
            ['wp_id' => 10, 'type' => 'article', 'wp_post_type' => 'post', 'wp_entity' => 'post', 'post_modified' => '2026-01-01 10:00:00'],
            ['wp_id' => 20, 'type' => 'product', 'wp_post_type' => 'product', 'wp_entity' => 'post', 'post_modified' => '2026-06-10 08:00:00'],
            ['wp_id' => 30, 'type' => 'article', 'wp_post_type' => 'post', 'wp_entity' => 'post', 'post_modified' => '2026-06-11 08:00:00'],
        ];

        $plan = (new DomainSyncManifestComparator)->resolveFetchRefs($manifest, $local);

        self::assertSame(1, $plan['skipped']);
        self::assertSame(1, $plan['new_count']);
        self::assertSame(1, $plan['update_count']);
        self::assertCount(2, $plan['refs']);
        self::assertSame(20, $plan['refs'][0]['wp_id']);
        self::assertSame(30, $plan['refs'][1]['wp_id']);
    }

    public function test_it_only_fetches_new_taxonomy_entries(): void
    {
        $local = collect([
            (object) [
                'wp_post_id' => 5,
                'type' => 'category',
                'wp_post_type' => 'category',
                'updated_at' => Carbon::parse('2026-01-01 10:00:00'),
            ],
        ]);

        $manifest = [
            ['wp_id' => 5, 'type' => 'category', 'wp_post_type' => 'category', 'wp_entity' => 'term'],
            ['wp_id' => 6, 'type' => 'category', 'wp_post_type' => 'category', 'wp_entity' => 'term'],
        ];

        $plan = (new DomainSyncManifestComparator)->resolveFetchRefs($manifest, $local);

        self::assertSame(1, $plan['skipped']);
        self::assertSame(1, $plan['new_count']);
        self::assertSame(0, $plan['update_count']);
        self::assertCount(1, $plan['refs']);
        self::assertSame(6, $plan['refs'][0]['wp_id']);
    }

    public function test_it_fetches_manifest_articles_missing_locally_even_when_marked_skipped(): void
    {
        $local = collect([
            (object) [
                'wp_post_id' => 10,
                'type' => 'article',
                'wp_post_type' => 'post',
                'updated_at' => Carbon::parse('2026-01-01 10:00:00'),
            ],
        ]);

        $manifest = [
            ['wp_id' => 10, 'type' => 'article', 'wp_post_type' => 'post', 'wp_entity' => 'post', 'post_modified' => '2026-01-01 10:00:00'],
            ['wp_id' => 99, 'type' => 'article', 'wp_post_type' => 'post', 'wp_entity' => 'post', 'post_modified' => '2026-06-11 08:00:00'],
        ];

        $plan = (new DomainSyncManifestComparator)->resolveFetchRefs($manifest, $local);

        self::assertSame(1, $plan['skipped']);
        self::assertSame(1, $plan['new_count']);
        self::assertCount(1, $plan['refs']);
        self::assertSame(99, $plan['refs'][0]['wp_id']);
    }

    public function test_it_resolves_metadata_refresh_refs_for_all_local_entries(): void
    {
        $local = collect([
            (object) ['wp_post_id' => 10, 'type' => 'article', 'updated_at' => Carbon::parse('2026-01-01 10:00:00')],
            (object) ['wp_post_id' => 20, 'type' => 'product', 'updated_at' => Carbon::parse('2026-06-01 12:00:00')],
            (object) ['wp_post_id' => 5, 'type' => 'category', 'updated_at' => Carbon::parse('2026-01-01 10:00:00')],
        ]);

        $manifest = [
            ['wp_id' => 10, 'type' => 'article', 'wp_post_type' => 'post', 'wp_entity' => 'post', 'post_modified' => '2026-01-01 10:00:00'],
            ['wp_id' => 20, 'type' => 'product', 'wp_post_type' => 'product', 'wp_entity' => 'post', 'post_modified' => '2026-01-01 10:00:00'],
            ['wp_id' => 5, 'type' => 'category', 'wp_post_type' => 'category', 'wp_entity' => 'term'],
            ['wp_id' => 30, 'type' => 'article', 'wp_post_type' => 'post', 'wp_entity' => 'post', 'post_modified' => '2026-06-11 08:00:00'],
        ];

        $plan = (new DomainSyncManifestComparator)->resolveMetadataRefreshRefs($manifest, $local);

        self::assertSame(3, $plan['total']);
        self::assertCount(3, $plan['refs']);
        self::assertSame([10, 20, 5], array_map(static fn (array $ref): int => (int) $ref['wp_id'], $plan['refs']));
    }

    public function test_it_fetches_when_wp_post_type_drifts_even_if_timestamp_unchanged(): void
    {
        $local = collect([
            (object) [
                'wp_post_id' => 10,
                'type' => 'article',
                'wp_post_type' => 'post',
                'updated_at' => Carbon::parse('2026-06-15 12:00:00'),
            ],
        ]);

        $manifest = [
            [
                'wp_id' => 10,
                'type' => 'article',
                'wp_post_type' => 'page',
                'wp_entity' => 'post',
                // Remote older than local — timestamp alone would skip.
                'post_modified' => '2026-01-01 10:00:00',
            ],
        ];

        $plan = (new DomainSyncManifestComparator)->resolveFetchRefs($manifest, $local);

        self::assertSame(0, $plan['skipped']);
        self::assertSame(1, $plan['update_count']);
        self::assertSame(10, $plan['refs'][0]['wp_id']);
        self::assertSame('page', $plan['refs'][0]['wp_post_type']);
    }

    public function test_it_matches_page_manifest_type_to_local_article_label_by_wp_id(): void
    {
        $local = collect([
            (object) [
                'wp_post_id' => 10,
                'type' => 'article', // legacy label for both post + page
                'wp_post_type' => 'page',
                'updated_at' => Carbon::parse('2026-01-01 10:00:00'),
            ],
        ]);

        $manifest = [
            [
                'wp_id' => 10,
                'type' => 'page',
                'wp_post_type' => 'page',
                'wp_entity' => 'post',
                'post_modified' => '2026-01-01 10:00:00',
            ],
        ];

        $plan = (new DomainSyncManifestComparator)->resolveFetchRefs($manifest, $local);

        self::assertSame(1, $plan['skipped']);
        self::assertSame(0, $plan['new_count']);
        self::assertSame(0, $plan['update_count']);
    }

    public function test_it_fetches_when_local_wp_post_type_missing(): void
    {
        $local = collect([
            (object) [
                'wp_post_id' => 10,
                'type' => 'article',
                'wp_post_type' => null,
                'updated_at' => Carbon::parse('2026-06-15 12:00:00'),
            ],
        ]);

        $manifest = [
            [
                'wp_id' => 10,
                'type' => 'article',
                'wp_post_type' => 'post',
                'wp_entity' => 'post',
                'post_modified' => '2026-01-01 10:00:00',
            ],
        ];

        $plan = (new DomainSyncManifestComparator)->resolveFetchRefs($manifest, $local);

        self::assertSame(1, $plan['update_count']);
        self::assertSame(0, $plan['skipped']);
    }
}
