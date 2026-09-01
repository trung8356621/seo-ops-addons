<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemContentLengthMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemGenerationMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemModelOverrideMode;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\Generation\ItemTitleProtection;

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
        public readonly ?string $toneOverride = null,
        public readonly ?ItemContentLengthMode $contentLengthOverride = null,
        public readonly ?int $contentLengthTargetWords = null,
        public readonly ?ItemGenerationMode $generationModeOverride = null,
        public readonly ?int $modelOverrideId = null,
        public readonly ?ItemModelOverrideMode $modelOverrideMode = null,
        public readonly ?ItemTitleProtection $titleProtection = null,
    ) {}

    /**
     * Per-item generation policy columns, keyed exactly like seo_project_tasks.
     *
     * @return array<string, mixed>
     */
    public function generationPolicyColumns(): array
    {
        return [
            'tone_override' => $this->toneOverride,
            'content_length_override' => $this->contentLengthOverride?->value,
            'content_length_target_words' => $this->contentLengthTargetWords,
            'generation_mode_override' => $this->generationModeOverride?->value,
            'model_override_id' => $this->modelOverrideId,
            'model_override_mode' => $this->modelOverrideMode?->value,
            'title_protection' => $this->titleProtection?->value,
        ];
    }

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
            ...$this->generationPolicyColumns(),
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
