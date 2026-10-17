<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

final class KeywordGroupDatasetSyncResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?int $revision,
        public readonly ?string $datasetHash,
        public readonly int $keywordCount,
        public readonly int $batchCount,
    ) {}
}
