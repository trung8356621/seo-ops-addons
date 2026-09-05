<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use Omnichannel\Addons\Seeding\Enums\SeedingTopicStatus;
use Omnichannel\Addons\Seeding\LinkIntelligence\Models\LinkResource;
use Omnichannel\Addons\Seeding\Services\SeedingTopicService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeedingTopicServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['omi_seo_ai'];

    private SeedingTopicService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTables();
        $this->service = app(SeedingTopicService::class);
    }

    public function test_create_without_social_url_is_draft(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'created_by' => 9,
            'full_text' => 'Hello without social',
        ]);

        self::assertSame(SeedingTopicStatus::Draft, $topic->status);
        self::assertNull($topic->social_url);
        self::assertNull($topic->published_at);
    }

    public function test_create_with_social_url_is_active(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => 'Hello with social',
            'social_url' => 'https://www.threads.net/@u/post/1',
        ]);

        self::assertSame(SeedingTopicStatus::Active, $topic->status);
        self::assertNotNull($topic->published_at);
        self::assertSame('threads', $topic->social_platform?->value);
    }

    public function test_update_draft_with_social_url_becomes_active_and_sets_published_at(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => 'Draft topic',
        ]);
        self::assertNull($topic->published_at);

        $updated = $this->service->updateSocialUrl($topic, 'https://facebook.com/posts/1');

        self::assertSame(SeedingTopicStatus::Active, $updated->status);
        self::assertNotNull($updated->published_at);
        self::assertSame('facebook', $updated->social_platform?->value);
    }

    public function test_update_content_syncs_pivot_and_keeps_detached_link_resource(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => "A https://example.com/a\nB https://example.com/b",
        ]);

        $idsBefore = $topic->linkResources()->pluck('link_resources.id')->map(static fn ($id): int => (int) $id)->sort()->values()->all();
        self::assertCount(2, $idsBefore);

        $resourceB = LinkResource::query()
            ->where('normalized_url', 'https://example.com/b')
            ->first();
        self::assertNotNull($resourceB);
        $resourceBId = (int) $resourceB->id;

        $updated = $this->service->update($topic, [
            'full_text' => "A https://example.com/a\nC https://example.com/c",
        ]);

        $urls = $updated->linkResources()->pluck('normalized_url')->sort()->values()->all();
        self::assertSame(['https://example.com/a', 'https://example.com/c'], $urls);
        self::assertTrue(LinkResource::query()->whereKey($resourceBId)->exists());
    }

    public function test_two_topics_reuse_same_link_resource(): void
    {
        $first = $this->service->create([
            'site_id' => 1,
            'full_text' => 'Share https://EXAMPLE.com/shared',
        ]);
        $second = $this->service->create([
            'site_id' => 1,
            'full_text' => 'Again https://example.com/shared',
        ]);

        $firstIds = $first->linkResources()->pluck('link_resources.id')->all();
        $secondIds = $second->linkResources()->pluck('link_resources.id')->all();

        self::assertCount(1, $firstIds);
        self::assertSame($firstIds, $secondIds);
        self::assertSame(1, LinkResource::query()->count());
    }

    public function test_copy_payload_uses_full_text_not_source_html(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => "Line1\nLine2",
            'source_html' => '<a href="https://example.com/x">Line1</a><br>Line2',
        ]);

        self::assertSame("Line1\nLine2", $this->service->copyPayload($topic));
        self::assertNotSame((string) $topic->source_html, $this->service->copyPayload($topic));
    }

    public function test_removing_social_url_returns_to_draft(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => 'Active then draft',
            'social_url' => 'https://tiktok.com/@u/video/1',
        ]);
        self::assertSame(SeedingTopicStatus::Active, $topic->status);

        $updated = $this->service->updateSocialUrl($topic, null);

        self::assertSame(SeedingTopicStatus::Draft, $updated->status);
        self::assertNull($updated->social_url);
        self::assertNull($updated->social_platform);
    }

    public function test_html_and_plain_dedupe_to_one_pivot(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => 'https://example.com/same',
            'source_html' => '<a href="https://example.com/same">same</a>',
        ]);

        self::assertSame(1, $topic->linkResources()->count());
    }

    public function test_create_allows_empty_full_text_for_workspace_draft(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => '',
        ]);

        self::assertSame('', $topic->full_text);
        self::assertSame(SeedingTopicStatus::Draft, $topic->status);
    }

    public function test_partial_patch_content_resyncs_links(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => 'https://example.com/old',
        ]);

        $updated = $this->service->update($topic, [
            'full_text' => 'https://example.com/new',
            'source_html' => null,
        ]);

        self::assertSame(['https://example.com/new'], $updated->linkResources()->pluck('normalized_url')->all());
    }

    public function test_partial_patch_social_url_only_does_not_drop_links(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => 'Keep https://example.com/keep',
        ]);

        $updated = $this->service->update($topic, [
            'social_url' => 'https://www.threads.net/@u/post/9',
        ]);

        self::assertSame(SeedingTopicStatus::Active, $updated->status);
        self::assertSame(1, $updated->linkResources()->count());
        self::assertSame('https://example.com/keep', $updated->linkResources()->first()?->normalized_url);
    }

    public function test_archive_and_restore_do_not_change_status_or_delete_link_resources(): void
    {
        $topic = $this->service->create([
            'site_id' => 1,
            'full_text' => 'Archive me https://example.com/a',
            'social_url' => 'https://facebook.com/posts/1',
        ]);
        $linkId = (int) $topic->linkResources()->first()?->id;

        $archived = $this->service->archive($topic);
        self::assertTrue($archived->isArchived());
        self::assertSame(SeedingTopicStatus::Active, $archived->status);
        self::assertSame(0, $this->service->listForSite(1, false)->count());
        self::assertSame(1, $this->service->listForSite(1, true)->count());
        self::assertTrue(LinkResource::query()->whereKey($linkId)->exists());

        $restored = $this->service->restore($archived);
        self::assertFalse($restored->isArchived());
        self::assertSame(SeedingTopicStatus::Active, $restored->status);
        self::assertSame(1, $this->service->listForSite(1, false)->count());
    }

    private function ensureTables(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('link_resources')) {
            $schema->create('link_resources', function (Blueprint $table): void {
                $table->id();
                $table->text('original_url');
                $table->string('normalized_url', 2048);
                $table->string('normalized_url_hash', 64)->unique();
                $table->string('domain', 255);
                $table->string('title', 512)->nullable();
                $table->text('description')->nullable();
                $table->string('fetch_status', 32)->nullable();
                $table->timestamp('fetched_at')->nullable();
                $table->json('metadata_json')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seeding_topics')) {
            $schema->create('seeding_topics', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->longText('full_text');
                $table->longText('source_html')->nullable();
                $table->text('social_url')->nullable();
                $table->string('social_platform', 32)->nullable();
                $table->string('status', 16)->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        } elseif (! $schema->hasColumn('seeding_topics', 'archived_at')) {
            $schema->table('seeding_topics', function (Blueprint $table): void {
                $table->timestamp('archived_at')->nullable();
            });
        }

        if (! $schema->hasTable('seeding_topic_links')) {
            $schema->create('seeding_topic_links', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('topic_id');
                $table->unsignedBigInteger('link_resource_id');
                $table->timestamps();
                $table->unique(['topic_id', 'link_resource_id']);
            });
        }
    }
}
