<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Testing;

use App\System\Workflow\Contracts\SystemWorkflowClient;
use App\System\Workflow\Dto\WorkflowExecutionMode;
use App\System\Workflow\Dto\WorkflowRunRequest;
use InvalidArgumentException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\Media\Support\ImageToolType;

class AgentTestExecutionService
{
    public function __construct(
        private readonly AgentTestInputResolver $inputs,
        private readonly PromptRunnerService $prompts,
        private readonly SystemWorkflowClient $workflows,
    ) {}

    /** @param array<string, mixed> $payload */
    public function run(int $ownerId, int $siteId, array $payload): array
    {
        $type = (string) ($payload['target_type'] ?? '');
        $targetId = (int) ($payload['target_id'] ?? 0);
        if (! in_array($type, ['prompt', 'task'], true) || $targetId <= 0) {
            throw new InvalidArgumentException('A valid Test target is required.');
        }

        $context = $this->inputs->resolve($siteId, $payload);

        return $type === 'prompt'
            ? $this->runPrompt($ownerId, $targetId, $context->variables, $context->summary)
            : $this->runTask($ownerId, $targetId, $context->variables, $context->summary);
    }

    /** @param array<string, mixed> $variables */
    private function runPrompt(int $ownerId, int $targetId, array $variables, string $summary): array
    {
        $prompt = SeoPrompt::query()
            ->whereKey($targetId)
            ->where('user_id', $ownerId)
            ->where('is_active', true)
            ->firstOrFail();
        if ((bool) $prompt->is_flow_prompt) {
            throw new InvalidArgumentException('Flow-only Prompts cannot run standalone.');
        }

        $outputType = $this->promptOutputType($prompt->tools);
        try {
            $result = $this->prompts->run($prompt, $variables, isTaskMode: false);
        } catch (\Throwable $exception) {
            return $this->failed('prompt', (int) $prompt->id, (string) $prompt->name, $outputType, $summary, $exception);
        }
        $output = (string) ($result->output_text ?? '');

        return [
            'target_type' => 'prompt',
            'target_id' => (int) $prompt->id,
            'target_label' => (string) $prompt->name,
            'output_type' => $outputType,
            'context_summary' => $summary,
            'status' => (string) ($result->status ?? 'completed'),
            'output' => $output,
            'media' => $this->mediaFrom($output, $outputType),
            'error' => $result->error_message ?: null,
        ];
    }

    /** @param array<string, mixed> $variables */
    private function runTask(int $ownerId, int $targetId, array $variables, string $summary): array
    {
        $task = SeoTask::query()
            ->select(['id', 'name', 'output_type'])
            ->whereKey($targetId)
            ->where('user_id', $ownerId)
            ->where('is_active', true)
            ->firstOrFail();

        $outputType = $this->normalizeOutputType($task->output_type);
        $label = $this->taskLabel((string) $task->name);
        try {
            $workflow = $this->workflows->run(new WorkflowRunRequest(
                definitionId: (int) $task->id,
                input: $variables,
                context: ['source' => 'agent_test', 'context_summary' => $summary],
                correlation: ['capability' => 'workflow.agent_test', 'task_id' => (int) $task->id],
                executionMode: WorkflowExecutionMode::FullRun->value,
            ));
        } catch (\Throwable $exception) {
            return $this->failed('task', (int) $task->id, $label, $outputType, $summary, $exception);
        }
        $steps = $workflow->meta['ordered_steps'] ?? $workflow->steps;
        $steps = is_array($steps) ? array_values($steps) : [];
        $output = $this->finalOutput($workflow->artifacts, $steps);
        $media = $this->mediaFrom([$workflow->artifacts, $steps, $output], $outputType);

        return [
            'target_type' => 'task',
            'target_id' => (int) $task->id,
            'target_label' => $label,
            'output_type' => $outputType,
            'context_summary' => $summary,
            'status' => $workflow->status,
            'ordered_steps' => $this->safeSteps($steps),
            'output' => $output,
            'media' => $media,
            'error' => $workflow->errorMessage ?? $workflow->errorCode,
        ];
    }

    private function finalOutput(array $artifacts, array $steps): string
    {
        foreach (['final_output', 'output', 'output_text', 'text', 'url'] as $key) {
            if (isset($artifacts[$key]) && is_scalar($artifacts[$key])) {
                return (string) $artifacts[$key];
            }
        }
        $last = end($steps);
        if (is_array($last)) {
            foreach (['output', 'output_text', 'result', 'text', 'url'] as $key) {
                if (isset($last[$key]) && is_scalar($last[$key])) {
                    return (string) $last[$key];
                }
            }
        }

        return '';
    }

    /** @return list<array<string, scalar|null>> */
    private function safeSteps(array $steps): array
    {
        return array_values(array_map(static function (mixed $step): array {
            if (! is_array($step)) {
                return ['status' => 'unknown'];
            }
            $safe = [];
            foreach (['node_id', 'name', 'label', 'status', 'output', 'output_text', 'result', 'text', 'url', 'message', 'error'] as $key) {
                if (array_key_exists($key, $step) && (is_scalar($step[$key]) || $step[$key] === null)) {
                    $safe[$key] = $step[$key];
                }
            }

            return $safe;
        }, $steps));
    }

    /** @return list<array{type: string, url: string}> */
    private function mediaFrom(mixed $value, string $outputType): array
    {
        if (! in_array($outputType, ['image', 'video'], true)) {
            return [];
        }
        $urls = [];
        $visit = function (mixed $item) use (&$visit, &$urls): void {
            if (is_array($item)) {
                foreach ($item as $child) {
                    $visit($child);
                }
            } elseif (is_string($item)) {
                preg_match_all('~https?://[^\s<>"\']+~i', $item, $matches);
                foreach ($matches[0] ?? [] as $url) {
                    $urls[$url] = $url;
                }
            }
        };
        $visit($value);

        return array_values(array_map(
            static fn (string $url): array => ['type' => $outputType, 'url' => $url],
            $urls,
        ));
    }

    private function promptOutputType(mixed $tools): string
    {
        $tool = ImageToolType::fromMixed($tools);
        return $tool === ImageToolType::Video ? 'video' : ($tool->isImagePipeline() ? 'image' : 'text');
    }

    private function normalizeOutputType(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, ['text', 'image', 'video'], true) ? $value : 'text';
    }

    private function taskLabel(string $name): string
    {
        $name = trim($name);
        return str_starts_with(strtolower($name), 'task.') || str_starts_with($name, 'Task ')
            ? $name
            : 'Task · '.$name;
    }

    private function failed(string $type, int $id, string $label, string $outputType, string $summary, \Throwable $exception): array
    {
        return [
            'target_type' => $type,
            'target_id' => $id,
            'target_label' => $label,
            'output_type' => $outputType,
            'context_summary' => $summary,
            'status' => 'failed',
            'ordered_steps' => [],
            'output' => '',
            'media' => [],
            'error' => $exception->getMessage(),
        ];
    }
}
