<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

/**
 * Token/context budget for planning prompts.
 * Never truncates mid-JSON; drops whole sections by priority.
 */
final class AgentContextBudgetManager
{
    /** @var array<string, int> section => weight (higher kept longer) */
    private const PRIORITY = [
        'current_message' => 100,
        'system_policy' => 95,
        'working_context' => 90,
        'skill_catalog' => 85,
        'grounded_knowledge' => 82,
        'user_corrections' => 75,
        'execution_summaries' => 60,
        'summary' => 50,
        'recent_messages' => 40,
        'output_reserve' => 30,
    ];

    public function __construct(
        private readonly int $defaultLimitTokens = 12000,
        private readonly float $charsPerToken = 4.0,
    ) {}

    /**
     * @param  array<string, mixed>  $sections  section_name => scalar|array payload
     * @return array{
     *     sections: array<string, mixed>,
     *     dropped: list<string>,
     *     input_token_estimate: int,
     *     estimate_method: string,
     *     limit_tokens: int
     * }
     */
    public function fit(array $sections, int $modelContextLimit, int $outputReserve = 1500): array
    {
        $limit = max(512, min($this->defaultLimitTokens, $modelContextLimit - max(0, $outputReserve)));
        $dropped = [];
        $working = $sections;

        // Always keep current_message + system_policy if present.
        $estimate = $this->estimateTokens($working);
        if ($estimate <= $limit) {
            return [
                'sections' => $working,
                'dropped' => [],
                'input_token_estimate' => $estimate,
                'estimate_method' => 'char_div_'.$this->charsPerToken,
                'limit_tokens' => $limit,
            ];
        }

        $dropOrder = array_keys(self::PRIORITY);
        usort($dropOrder, static fn (string $a, string $b): int => (self::PRIORITY[$a] ?? 0) <=> (self::PRIORITY[$b] ?? 0));

        foreach ($dropOrder as $section) {
            if (in_array($section, ['current_message', 'system_policy', 'working_context'], true)) {
                continue;
            }
            if (! array_key_exists($section, $working)) {
                continue;
            }
            unset($working[$section]);
            $dropped[] = $section;
            $estimate = $this->estimateTokens($working);
            if ($estimate <= $limit) {
                break;
            }
        }

        // Trim recent_messages / skill_catalog arrays from the end if still over.
        foreach (['recent_messages', 'skill_catalog', 'execution_summaries', 'grounded_knowledge'] as $listSection) {
            if ($estimate <= $limit || ! isset($working[$listSection]) || ! is_array($working[$listSection])) {
                continue;
            }
            while (count($working[$listSection]) > 1 && $estimate > $limit) {
                array_pop($working[$listSection]);
                $estimate = $this->estimateTokens($working);
                $dropped[] = $listSection.'_trim';
            }
        }

        return [
            'sections' => $working,
            'dropped' => array_values(array_unique($dropped)),
            'input_token_estimate' => $this->estimateTokens($working),
            'estimate_method' => 'char_div_'.$this->charsPerToken,
            'limit_tokens' => $limit,
        ];
    }

    public function estimateTokens(mixed $payload): int
    {
        $json = is_string($payload) ? $payload : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);

        return (int) max(1, (int) ceil(mb_strlen($json) / $this->charsPerToken));
    }
}
