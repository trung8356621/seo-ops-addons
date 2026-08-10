<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Models;

use Omnichannel\Addons\SearchFoundation\Enums\KeywordMetaKey;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    protected $connection = 'omi_seo_ai';

    protected $table = 'keyword_tags';

    protected $guarded = [];

    public function keywordsUsingTagExist(): bool
    {
        return $this->keywordsUsageCount() > 0;
    }

    public function keywordsUsageCount(): int
    {
        $tagId = (int) $this->id;
        if ($tagId <= 0) {
            return 0;
        }

        return KeywordMeta::query()
            ->where('meta_key', KeywordMetaKey::Tags->value)
            ->whereRaw('JSON_CONTAINS(meta_value, ?, "$")', [json_encode($tagId, JSON_THROW_ON_ERROR)])
            ->count();
    }
}
