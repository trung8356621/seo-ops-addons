<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Omnichannel\Addons\SearchFoundation\Services\TagPersistenceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class TagPersistenceServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['omi_seo_ai'];

    public function test_find_or_create_reuses_existing_tag_by_normalized_name(): void
    {
        $service = app(TagPersistenceService::class);

        $existing = Tag::query()->create([
            'name' => 'Pillar',
            'slug' => 'pillar',
        ]);

        $resolved = $service->findOrCreate('  pillar  ');

        $this->assertSame((int) $existing->id, (int) $resolved->id);
        $this->assertSame(1, Tag::query()->whereRaw('LOWER(TRIM(name)) = ?', ['pillar'])->count());
    }

    public function test_create_rejects_duplicate_name(): void
    {
        Tag::query()->create([
            'name' => 'Cluster',
            'slug' => 'cluster',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(TagPersistenceService::class)->create('cluster');
    }
}
