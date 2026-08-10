<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Console;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordDomainResyncService;
use Omnichannel\Addons\Seo\Services\SeoKeywordSettingsService;
use Omnichannel\Addons\Seo\Support\CtaKeywordBlacklistFilter;
use Illuminate\Console\Command;

final class CleanCtaKeywordsCommand extends Command
{
    protected $signature = 'keywords:clean-cta
        {--site-id= : Chỉ dọn keyword của một site_id}
        {--dry-run : Chỉ thống kê, không xóa}';

    protected $description = 'Xóa keyword khớp CTA blacklist trong Settings (/seo/settings/keywords).';

    public function handle(
        SeoKeywordSettingsService $settings,
        KeywordDomainResyncService $resyncService,
    ): int {
        $siteId = (int) ($this->option('site-id') ?? 0);
        $dryRun = (bool) $this->option('dry-run');
        $blacklist = $settings->getCtaBlacklist();

        if ($blacklist === []) {
            $this->warn('CTA blacklist đang trống — không có gì để dọn.');

            return self::SUCCESS;
        }

        $this->info('CTA blacklist: '.implode(', ', $blacklist));
        $this->line('site_id='.($siteId > 0 ? (string) $siteId : 'ALL').', dry_run='.($dryRun ? 'yes' : 'no'));

        $matched = 0;
        $deleted = 0;

        $query = Keyword::query()->orderBy('id');
        if ($siteId > 0) {
            $query->forSite($siteId);
        }

        $query->chunkById(200, function ($keywords) use ($blacklist, $dryRun, $resyncService, $siteId, &$matched, &$deleted): void {
            foreach ($keywords as $keyword) {
                if (! $keyword instanceof Keyword) {
                    continue;
                }

                if (! CtaKeywordBlacklistFilter::matchesPhrase((string) $keyword->phrase, $blacklist)) {
                    continue;
                }

                $matched++;
                $this->line(sprintf(
                    '[match] #%d (%s): %s',
                    (int) $keyword->id,
                    (string) $keyword->type,
                    (string) $keyword->phrase,
                ));

                if ($dryRun) {
                    continue;
                }

                if ($siteId > 0) {
                    $resyncService->deleteKeywordForSite($keyword, $siteId);
                } else {
                    $resyncService->deleteKeywordRecord($keyword);
                }
                $deleted++;
            }
        });

        $this->newLine();
        $this->info("Khớp blacklist: {$matched}");
        $this->info($dryRun ? 'Dry-run — chưa xóa bản ghi nào.' : "Đã xóa: {$deleted}");

        return self::SUCCESS;
    }
}
