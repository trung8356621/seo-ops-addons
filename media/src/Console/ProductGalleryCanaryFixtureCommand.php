<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Console;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryCanaryFixtureService;
use Illuminate\Console\Command;

final class ProductGalleryCanaryFixtureCommand extends Command
{
    protected $signature = 'seo:product-gallery-canary-fixture
        {--site= : Site/domain id (required)}
        {--media= : Comma-separated SeoMedia IDs (min 2)}
        {--title= : Optional product title override}
        {--user= : Owner user id (default auth/1)}';

    protected $description = 'Create a real Content Project product shell for Product Gallery canary (no AI images).';

    public function handle(ProductGalleryCanaryFixtureService $fixture): int
    {
        $siteId = (int) $this->option('site');
        if ($siteId <= 0) {
            $this->error('--site= is required');

            return self::FAILURE;
        }

        $mediaRaw = trim((string) $this->option('media'));
        $mediaIds = array_values(array_filter(array_map(
            static fn (string $part): int => (int) trim($part),
            $mediaRaw !== '' ? explode(',', $mediaRaw) : [],
        ), static fn (int $id): bool => $id > 0));

        $overrides = [];
        $title = trim((string) $this->option('title'));
        if ($title !== '') {
            $overrides['title'] = $title;
        }

        $userId = (int) $this->option('user');
        if ($userId <= 0) {
            $userId = (int) (auth()->id() ?: 1);
        }

        try {
            $result = $fixture->create($siteId, $mediaIds, $userId, $overrides);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['project_id', (string) $result['project_id']],
            ['task_id', (string) $result['task_id']],
            ['article_id', (string) $result['article_id']],
            ['original_media_ids', implode(',', $result['original_media_ids'])],
            ['editor_url', $result['editor_url']],
            ['project_url', $result['project_url']],
            ['canary_page_url', $result['canary_page_url']],
        ]);

        $this->newLine();
        $this->info('Next:');
        $this->line('  php artisan seo:product-gallery-prompts-doctor');
        $this->line('  php artisan seo:product-gallery-parent-child-canary '.$result['article_id'].' --dry-run --model=gemini-2.5-flash-image');
        $this->line('  Open editor → Product Gallery modal → Sprite / Parent-Child / Auto');

        return self::SUCCESS;
    }
}
