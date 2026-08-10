<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation;

use App\Core\Capability\Contracts\KeywordIdentityCapability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical keyword identity — Search Intelligence/SEO must join via keyword_id.
 */
final class CanonicalKeywordIdentity implements KeywordIdentityCapability
{
    public function __construct(
        private readonly string $connection = 'omi_seo_ai',
    ) {}

    public function findIdByPhrase(int $siteId, string $phrase): ?int
    {
        if (! Schema::connection($this->connection)->hasTable('keywords')) {
            return null;
        }

        $id = DB::connection($this->connection)->table('keywords')
            ->where('site_id', $siteId)
            ->where('phrase', $phrase)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    public function getById(int $keywordId): ?array
    {
        if (! Schema::connection($this->connection)->hasTable('keywords')) {
            return null;
        }

        $row = DB::connection($this->connection)->table('keywords')->where('id', $keywordId)->first();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'phrase' => (string) $row->phrase,
            'site_id' => (int) $row->site_id,
        ];
    }
}
