<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordIntelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Services\SiteMcp\SiteMcpTopicalProfileStaleState;
use RuntimeException;

/**
 * Keyword-level MCP skip — SEO eligible, excluded from Site MCP context.
 */
final class SkipKeywordFromMcpService
{
    public function isSkipped(int $keywordId): bool
    {
        if ($keywordId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return false;
        }

        return Keyword::query()
            ->whereKey($keywordId)
            ->whereHas('metas', static function ($q): void {
                $q->where('meta_key', KeywordMetaKey::McpExcluded->value)
                    ->where('meta_value', '1');
            })
            ->exists();
    }

    /**
     * @return array{keyword_id: int, phrase: string, skipped: bool}
     */
    public function skip(int $keywordId, ?int $siteId = null): array
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            throw new RuntimeException('keyword_not_found');
        }

        $this->writeMeta($keywordId, true);

        if ($siteId !== null && $siteId > 0) {
            TopicClusterDirtyState::mark($siteId, 'keyword_mcp_skipped');
            SiteMcpTopicalProfileStaleState::mark($siteId, 'keyword_mcp_skipped');
        }

        return [
            'keyword_id' => $keywordId,
            'phrase' => (string) $keyword->phrase,
            'skipped' => true,
        ];
    }

    /**
     * @return array{keyword_id: int, phrase: string, restored: bool}
     */
    public function restore(int $keywordId, ?int $siteId = null): array
    {
        $keyword = Keyword::query()->find($keywordId);
        if (! $keyword instanceof Keyword) {
            throw new RuntimeException('keyword_not_found');
        }

        $this->writeMeta($keywordId, false);

        if ($siteId !== null && $siteId > 0) {
            TopicClusterDirtyState::mark($siteId, 'keyword_mcp_restored');
            SiteMcpTopicalProfileStaleState::mark($siteId, 'keyword_mcp_restored');
        }

        return [
            'keyword_id' => $keywordId,
            'phrase' => (string) $keyword->phrase,
            'restored' => true,
        ];
    }

    /**
     * @param  list<int>  $keywordIds
     * @return array<int, true>
     */
    public function skippedKeywordIdMap(array $keywordIds): array
    {
        $keywordIds = array_values(array_filter(array_map('intval', $keywordIds)));
        if ($keywordIds === [] || ! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            return [];
        }

        $ids = DB::connection('omi_seo_ai')->table('keyword_meta')
            ->whereIn('keyword_id', $keywordIds)
            ->where('meta_key', KeywordMetaKey::McpExcluded->value)
            ->where('meta_value', '1')
            ->pluck('keyword_id');

        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    private function writeMeta(int $keywordId, bool $skipped): void
    {
        if (! Schema::connection('omi_seo_ai')->hasTable('keyword_meta')) {
            throw new RuntimeException('keyword_meta_missing');
        }

        $key = KeywordMetaKey::McpExcluded->value;
        if ($skipped) {
            $exists = DB::connection('omi_seo_ai')->table('keyword_meta')
                ->where('keyword_id', $keywordId)
                ->where('meta_key', $key)
                ->exists();
            if ($exists) {
                DB::connection('omi_seo_ai')->table('keyword_meta')
                    ->where('keyword_id', $keywordId)
                    ->where('meta_key', $key)
                    ->update(['meta_value' => '1', 'updated_at' => now()]);
            } else {
                DB::connection('omi_seo_ai')->table('keyword_meta')->insert([
                    'keyword_id' => $keywordId,
                    'meta_key' => $key,
                    'meta_value' => '1',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return;
        }

        DB::connection('omi_seo_ai')->table('keyword_meta')
            ->where('keyword_id', $keywordId)
            ->where('meta_key', $key)
            ->delete();
    }
}
