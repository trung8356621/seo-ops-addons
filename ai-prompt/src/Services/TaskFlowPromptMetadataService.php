<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Media\Support\ImageToolType;

final class TaskFlowPromptMetadataService
{
    /** @param array<string, mixed> $flowData */
    public function outputType(array $flowData): string
    {
        $ids = $this->promptIds($flowData);
        if ($ids === []) {
            return 'text';
        }

        $tools = SeoPrompt::query()->whereIn('id', $ids)->pluck('tools');
        $hasImage = false;
        foreach ($tools as $toolValue) {
            $tool = ImageToolType::fromMixed($toolValue);
            if ($tool === ImageToolType::Video) {
                return 'video';
            }
            $hasImage = $hasImage || $tool->isImagePipeline();
        }

        return $hasImage ? 'image' : 'text';
    }

    /**
     * @param array<string, mixed> $flowData
     * @return list<int>
     */
    private function promptIds(array $flowData): array
    {
        $ids = [];
        foreach (is_array($flowData['nodes'] ?? null) ? $flowData['nodes'] : [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $id = (int) ($data['promptId'] ?? $data['prompt_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }
}
