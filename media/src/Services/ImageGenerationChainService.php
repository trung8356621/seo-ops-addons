<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

/**
 * Chuỗi task → sub_task sinh ảnh. Không gọi AiExecutionService (Claude).
 *
 * @see MediaGenerationService
 * @see PromptRunnerService::run()
 */
final class ImageGenerationChainService
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
    ) {}

    /**
     * Điểm vào theo tools=image: chuỗi hoặc một bước Imagen (không qua Claude).
     *
     * @param  array<string, string>  $variables
     * @return array<string, mixed>|string
     */
    public function generate(SeoPrompt $prompt, array $variables = [], ?string $inputData = null): array|string
    {
        return app(MediaGenerationService::class)->generate($prompt, $variables, $inputData);
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{
     *     parent_result: array{name: string, value: string, type: string},
     *     sub_results: list<array{name: string, value: string}>
     * }
     */
    public function generateImageChain(SeoPrompt $prompt, array $variables = []): array
    {
        $prompt->loadMissing(['aiConnection']);

        if ($prompt->aiConnection === null) {
            throw new PromptRunException('Không tìm thấy kết nối API AI.');
        }

        if ($prompt->aiConnection->provider !== 'gemini') {
            throw new PromptRunException(
                'Chuỗi sinh ảnh yêu cầu kết nối Gemini. Không dùng Claude/AiExecutionService.',
            );
        }

        if (! $this->promptRunner->hasDependentSubTasks($prompt)) {
            throw new PromptRunException(
                'Prompt thiếu khối sub_task hoặc dùng MediaGenerationService::generate() khi không có chuỗi.',
            );
        }

        $parts = $prompt->resolvedParts();
        $mainTask = $parts->firstWhere('role', 'task');
        $subTasks = $parts->where('role', 'sub_task');
        $toolType = $this->normalizeToolType((string) ($prompt->tools ?? 'default'));

        if ($mainTask === null) {
            throw new PromptRunException("Prompt thiếu khối 'Nhiệm vụ chính' (task).");
        }

        $chainVariables = $variables;

        $parentOutput = $this->promptRunner->runChainStepOutput($prompt, $mainTask, $chainVariables);
        $chainVariables['PARENT_RESULT'] = $parentOutput;

        $results = [
            'parent_result' => [
                'name' => filled($mainTask->name) ? (string) $mainTask->name : 'Main Parent Result',
                'value' => $parentOutput,
                'type' => $toolType,
            ],
            'sub_results' => [],
        ];

        foreach ($subTasks as $subTask) {
            if (trim((string) $subTask->content) === '') {
                continue;
            }

            $subOutput = $this->promptRunner->runChainStepOutput($prompt, $subTask, $chainVariables);
            $results['sub_results'][] = [
                'name' => filled($subTask->name) ? (string) $subTask->name : 'Sub Prompt',
                'value' => $subOutput,
            ];
            $chainVariables['PARENT_RESULT'] = $subOutput;
        }

        return $results;
    }

    private function normalizeToolType(string $tool): string
    {
        return \Omnichannel\Addons\Media\Support\ImageToolType::fromMixed($tool)->value;
    }
}
