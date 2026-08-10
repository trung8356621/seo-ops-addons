<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\ArticleAiHistory;

/**
 * Stable, opaque ref cho một artifact trong Article AI History.
 *
 * - `pr:{prompt_result_id}` — artifact có PromptResult (đường ưu tiên).
 * - `ri:{run_item_id}:{step_index}` — artifact chỉ tồn tại trong output_snapshot.steps
 *   của một run item (không có PromptResult riêng).
 */
final class ArticleAiHistoryArtifactRef
{
    public const KIND_PROMPT_RESULT = 'pr';

    public const KIND_RUN_ITEM_STEP = 'ri';

    public static function encodePromptResult(int $promptResultId): string
    {
        return sprintf('%s:%d', self::KIND_PROMPT_RESULT, $promptResultId);
    }

    public static function encodeRunItemStep(int $runItemId, int $stepIndex): string
    {
        return sprintf('%s:%d:%d', self::KIND_RUN_ITEM_STEP, $runItemId, $stepIndex);
    }

    /**
     * @return array{kind: string, prompt_result_id?: int, run_item_id?: int, step_index?: int}|null
     */
    public static function parse(string $ref): ?array
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }

        $parts = explode(':', $ref);

        if (count($parts) === 2 && $parts[0] === self::KIND_PROMPT_RESULT) {
            $promptResultId = (int) $parts[1];
            if ($promptResultId <= 0) {
                return null;
            }

            return [
                'kind' => self::KIND_PROMPT_RESULT,
                'prompt_result_id' => $promptResultId,
            ];
        }

        if (count($parts) === 3 && $parts[0] === self::KIND_RUN_ITEM_STEP) {
            $runItemId = (int) $parts[1];
            $stepIndex = (int) $parts[2];
            if ($runItemId <= 0 || $stepIndex < 0) {
                return null;
            }

            return [
                'kind' => self::KIND_RUN_ITEM_STEP,
                'run_item_id' => $runItemId,
                'step_index' => $stepIndex,
            ];
        }

        return null;
    }
}
