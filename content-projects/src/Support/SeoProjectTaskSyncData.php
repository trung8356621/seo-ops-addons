<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * Normalized task row for Content Project sync (Phase 3C2).
 */
final class SeoProjectTaskSyncData
{
    public function __construct(
        public readonly ?int $taskId,
        public readonly int $projectId,
        public readonly int $siteId,
        public readonly string $type,
        public readonly ?string $postType,
        public readonly string $sourceContent,
        public readonly string $sourceKey,
        public readonly ?string $keyword,
        public readonly ?string $title,
        public readonly ?string $secondaryDescription,
        public readonly ?string $rewriteMode,
        public readonly ?string $rewriteNotes,
        public readonly ?string $description,
        public readonly ?string $loaiSanPham,
        public readonly ?string $targetDate,
        public readonly ?string $requestedStatus,
        public readonly int $inputIndex,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        $row = [
            'id' => $this->taskId,
            'site_id' => $this->siteId,
            'type' => $this->type,
            'source_content' => $this->sourceContent,
            'source_key' => $this->sourceKey,
            'keyword' => $this->keyword,
            'title' => $this->title,
            'secondary_description' => $this->secondaryDescription,
            'loai_san_pham' => $this->loaiSanPham,
            'description' => $this->description,
            'post_type' => $this->postType,
            'input_index' => $this->inputIndex,
        ];

        if ($this->rewriteMode !== null) {
            $row['rewrite_mode'] = $this->rewriteMode;
        }
        if ($this->rewriteNotes !== null) {
            $row['rewrite_notes'] = $this->rewriteNotes;
        }
        if ($this->targetDate !== null) {
            $row['target_date'] = $this->targetDate;
        }

        return $row;
    }
}
