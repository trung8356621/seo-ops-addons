<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Tests\Unit;

use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\WordPress\Services\SyncDomainContentService;
use App\Addons\SeoContentAi\Tests\Compat\UsesSeoDatabase;
use App\Models\Site;
use App\Models\SiteMeta;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

final class WpPushLifecycleImportTest extends TestCase
{
    use DatabaseTransactions;
    use UsesSeoDatabase;

    protected $connectionsToTransact = ['mysql', 'omi_seo_ai'];

    public function test_trash_action_soft_deletes_synced_article(): void
    {
        $this->requireSeoDatabaseConnection();

        [$site, $article] = $this->createLinkedProduct();

        $result = app(SyncDomainContentService::class)->importPushedItems($site, [
            [
                'wp_id' => (int) $article->wp_post_id,
                'type' => 'product',
                'action' => 'trash',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) ($result['synced']['trashed'] ?? 0));
        $this->assertTrue($article->fresh()->trashed());
    }

    public function test_force_delete_action_removes_synced_article(): void
    {
        $this->requireSeoDatabaseConnection();

        [$site, $article] = $this->createLinkedProduct();
        $wpId = (int) $article->wp_post_id;

        $result = app(SyncDomainContentService::class)->importPushedItems($site, [
            [
                'wp_id' => $wpId,
                'type' => 'product',
                'action' => 'force_delete',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) ($result['synced']['force_deleted'] ?? 0));
        $this->assertNull(
            SeoArticle::withTrashed()
                ->where('site_id', $site->id)
                ->where('wp_post_id', $wpId)
                ->first()
        );
    }

    public function test_restore_action_undeletes_synced_article(): void
    {
        $this->requireSeoDatabaseConnection();

        [$site, $article] = $this->createLinkedProduct();
        $article->delete();
        $this->assertTrue($article->fresh()->trashed());

        $result = app(SyncDomainContentService::class)->importPushedItems($site, [
            [
                'wp_id' => (int) $article->wp_post_id,
                'type' => 'product',
                'action' => 'restore',
            ],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame(1, (int) ($result['synced']['restored'] ?? 0));
        $this->assertFalse($article->fresh()->trashed());
    }

    /**
     * @return array{0: Site, 1: SeoArticle}
     */
    private function createLinkedProduct(): array
    {
        $site = Site::query()->create([
            'name' => 'WP lifecycle test',
            'domain' => 'lifecycle-sync.test',
            'user_id' => 1,
            'status' => 1,
        ]);

        SiteMeta::query()->create([
            'site_id' => $site->id,
            'meta_key' => 'seo_platform',
            'meta_value' => 'wordpress',
        ]);

        $article = SeoArticle::query()->create([
            'site_id' => $site->id,
            'wp_post_id' => 24405,
            'title' => 'Balo Quà Tặng Khuyến Mãi',
            'body' => '<p>Synced product</p>',
            'status' => 'published',
            'type' => 'product',
        ]);

        return [$site->fresh(['metas']), $article];
    }
}
