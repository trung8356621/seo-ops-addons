<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;


use Omnichannel\Addons\AiPrompt\Services\WorkflowParserService;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookCallerBridge;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookExecutionInput;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Carbon\Carbon;

final class SeoProjectKeywordAiGeneratorService
{
    public function __construct(
        private readonly PromptRunnerService $promptRunner,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly SeoProjectKeywordListParser $keywordParser,
        private readonly PromptHookCallerBridge $promptHookBridge,
    ) {}

    /**
     * @return list<string>
     */
    public function generate(
        Carbon|string $month,
        int $count,
        string $brief = '',
        string $description = '',
    ): array {
        $promptId = $this->workflowSettings->getProjectKeywordsPromptId();
        if ($promptId === null) {
            throw new \InvalidArgumentException(
                'Chưa cấu hình Prompt AI từ khóa dự án. Vào SEO → Tùy chỉnh → Quy trình.',
            );
        }

        $prompt = SeoPrompt::query()->find($promptId);
        if ($prompt === null) {
            throw new \InvalidArgumentException('Prompt AI từ khóa dự án không tồn tại hoặc đã tắt.');
        }

        $carbonMonth = Carbon::parse($month)->startOfMonth();
        $count = max(1, $count);

        $variables = [
            'project_month' => $carbonMonth->format('m/Y'),
            'project_month_label' => $carbonMonth->translatedFormat('F Y'),
            'days_in_month' => (string) $carbonMonth->daysInMonth,
            'keyword_count' => (string) $count,
            'project_description' => trim($description) !== '' ? trim($description) : '(không có)',
            'user_brief' => trim($brief) !== '' ? trim($brief) : '(không có)',
        ];

        $seed = trim($brief) !== '' ? trim($brief) : trim($description);
        if ($seed === '') {
            $seed = (string) $variables['project_month_label'];
        }

        $envelope = PromptHookExecutionInput::fromArray([
            'context' => [],
            'input' => [
                'seed_topic' => $seed,
                'count' => $count,
                'brief' => trim($brief) !== '' ? trim($brief) : null,
            ],
            'previous_outputs' => [],
            'settings' => [],
        ]);

        /** @var list<string> $keywords */
        $keywords = $this->promptHookBridge->run(
            hookKey: 'keyword.discovery.structured',
            version: '0.1.0',
            envelope: $envelope,
            legacyExecute: function () use ($prompt, $variables, $count): array {
                try {
                    $result = $this->promptRunner->run($prompt, $variables);
                } catch (PromptRunException $exception) {
                    throw new \InvalidArgumentException($exception->getMessage(), 0, $exception);
                }

                $parsed = $this->extractKeywordsFromAiOutput((string) ($result->output_text ?? ''));
                if ($parsed === []) {
                    throw new \InvalidArgumentException(
                        'AI không trả về từ khóa hợp lệ. Prompt nên yêu cầu mỗi từ khóa một dòng (hoặc JSON mảng chuỗi).',
                    );
                }

                return array_slice($parsed, 0, $count);
            },
            mapHookResult: function ($runtimeResult) use ($count): array {
                $value = $runtimeResult->output['value'] ?? [];
                if (is_string($value)) {
                    return array_slice($this->extractKeywordsFromAiOutput($value), 0, $count);
                }
                if (! is_array($value)) {
                    return [];
                }
                $list = [];
                foreach ($value as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $list[] = trim($item);
                    } elseif (is_array($item) && isset($item['keyword'])) {
                        $list[] = trim((string) $item['keyword']);
                    }
                }

                return array_slice($list, 0, $count);
            },
        );

        return $keywords;
    }

    /**
     * @return list<string>
     */
    private function extractKeywordsFromAiOutput(string $output): array
    {
        $output = trim($output);
        if ($output === '') {
            return [];
        }

        if (str_starts_with($output, '[')) {
            $decoded = json_decode($output, true);
            if (is_array($decoded)) {
                $flat = [];
                array_walk_recursive($decoded, static function (mixed $value) use (&$flat): void {
                    if (is_string($value) && trim($value) !== '') {
                        $flat[] = trim($value);
                    }
                });

                if ($flat !== []) {
                    return array_values(array_unique($flat));
                }
            }
        }

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $output, $matches)) {
            $fromFence = $this->extractKeywordsFromAiOutput(trim($matches[1]));
            if ($fromFence !== []) {
                return $fromFence;
            }
        }

        $fromLines = $this->keywordParser->parse($output);
        if ($fromLines !== []) {
            return $fromLines;
        }

        $fromMarkdown = app(WorkflowParserService::class)->parseKeywords($output);
        $flat = [];
        foreach ($fromMarkdown as $items) {
            foreach ($items as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $flat[] = $item;
                }
            }
        }

        return array_values(array_unique($flat));
    }
}
