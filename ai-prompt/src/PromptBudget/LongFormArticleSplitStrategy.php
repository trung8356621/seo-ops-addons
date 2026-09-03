<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptBudget;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\Services\PromptTokenEstimator;
use Omnichannel\Addons\AiPrompt\Support\PromptSplitClass;

/**
 * Long-form article semantic split by outline H2/H3 units — never compiled-string substr.
 */
final class LongFormArticleSplitStrategy implements PromptSplitStrategy
{
    public function __construct(
        private readonly string $hook,
        private readonly PromptTokenEstimator $estimator = new PromptTokenEstimator(),
    ) {}

    public function hookKey(): string
    {
        return $this->hook;
    }

    public function splitClass(): PromptSplitClass
    {
        return PromptSplitClass::SemanticSplit;
    }

    public function supportsSplit(): bool
    {
        return true;
    }

    public function estimateOutputReserve(array $options, ModelContextCapability $capability): int
    {
        unset($capability);
        $sections = max(1, (int) ($options['section_count'] ?? 1));
        $perSection = (int) ($options['tokens_per_section'] ?? 900);

        // Desired only — preflight rejects when minimumRequired > modelMax.
        return ($sections * $perSection) + 200;
    }

    /**
     * @param  array{title?: string, keyword?: string, outline?: string, body?: string, language?: string}  $article
     * @return list<array{
     *   chunk_id: string,
     *   kind: string,
     *   heading: string,
     *   body: string,
     *   order: int,
     *   input_hash: string
     * }>
     */
    public function buildChunks(array $article, ModelContextCapability $capability, array $immutableContext = []): array
    {
        $units = $this->parseOutlineUnits(
            (string) ($article['outline'] ?? ''),
            (string) ($article['body'] ?? ''),
        );
        if ($units === []) {
            $units = [[
                'kind' => 'body',
                'heading' => (string) ($article['title'] ?? 'Article'),
                'body' => trim((string) ($article['body'] ?? $article['outline'] ?? '')),
            ]];
        }

        $immutablePrefix = $this->immutablePrefix($article, $immutableContext);
        $immutableTokens = $this->estimator->estimate($immutablePrefix, $capability->estimatorFamily);
        $safe = $capability->safeContextBudget();
        $reserve = min($capability->maxOutputTokens, 1200);
        $available = max(200, $safe - $immutableTokens - $capability->providerMessageOverheadTokens - $reserve);

        $chunks = [];
        $buffer = null;
        $order = 0;
        foreach ($units as $unit) {
            $unitTokens = $this->estimator->estimate($unit['heading']."\n".$unit['body'], $capability->estimatorFamily);
            if ($unitTokens > $available && $unit['kind'] === 'h2') {
                // Split H2 into H3 children if present; else paragraph groups.
                foreach ($this->splitOversizedUnit($unit, $available) as $sub) {
                    $chunks[] = $this->makeChunk($sub, $order++, $immutablePrefix);
                }
                $buffer = null;
                continue;
            }

            if ($buffer === null) {
                $buffer = $unit;
                continue;
            }

            $combinedTokens = $this->estimator->estimate(
                $buffer['heading']."\n".$buffer['body']."\n".$unit['heading']."\n".$unit['body'],
                $capability->estimatorFamily,
            );
            if ($combinedTokens <= $available && $unit['kind'] !== 'introduction' && $unit['kind'] !== 'conclusion') {
                $buffer['body'] = trim($buffer['body']."\n\n## ".$unit['heading']."\n".$unit['body']);
                continue;
            }

            $chunks[] = $this->makeChunk($buffer, $order++, $immutablePrefix);
            $buffer = $unit;
        }
        if ($buffer !== null) {
            $chunks[] = $this->makeChunk($buffer, $order, $immutablePrefix);
        }

        return $chunks;
    }

    /**
     * @param  list<array{chunk_id: string, kind: string, heading: string, body: string, order: int, output?: string}>  $completed
     */
    public function mergeResults(array $completed): string
    {
        usort($completed, static fn (array $a, array $b): int => ((int) $a['order']) <=> ((int) $b['order']));
        $parts = [];
        $seenIntro = false;
        $seenConclusion = false;
        $seenHeadings = [];
        foreach ($completed as $chunk) {
            $kind = (string) ($chunk['kind'] ?? '');
            $heading = trim((string) ($chunk['heading'] ?? ''));
            $output = trim((string) ($chunk['output'] ?? $chunk['body'] ?? ''));
            if ($output === '') {
                continue;
            }
            if ($kind === 'introduction') {
                if ($seenIntro) {
                    continue;
                }
                $seenIntro = true;
            }
            if ($kind === 'conclusion') {
                if ($seenConclusion) {
                    continue;
                }
                $seenConclusion = true;
            }
            $headingKey = mb_strtolower($heading);
            if ($heading !== '' && isset($seenHeadings[$headingKey])) {
                // Drop duplicate heading block.
                continue;
            }
            if ($heading !== '') {
                $seenHeadings[$headingKey] = true;
            }
            $parts[] = $output;
        }

        return trim(implode("\n\n", $parts));
    }

    public function maxChunks(): int
    {
        return 40;
    }

    public function maxReplans(): int
    {
        return 2;
    }

    /**
     * @return list<array{kind: string, heading: string, body: string}>
     */
    private function parseOutlineUnits(string $outline, string $body): array
    {
        $source = trim($outline) !== '' ? $outline : $body;
        if ($source === '') {
            return [];
        }

        $lines = preg_split("/\r\n|\n|\r/", $source) ?: [];
        $units = [];
        $current = null;
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,3})\s+(.+)$/u', $line, $m)) {
                if ($current !== null) {
                    $units[] = $current;
                }
                $level = strlen($m[1]);
                $heading = trim($m[2]);
                $kind = match (true) {
                    $level === 1 && $this->looksIntro($heading) => 'introduction',
                    $level === 1 && $this->looksConclusion($heading) => 'conclusion',
                    $level === 1, $level === 2 => 'h2',
                    default => 'h3',
                };
                $current = ['kind' => $kind, 'heading' => $heading, 'body' => ''];
                continue;
            }
            if ($current === null) {
                $current = ['kind' => 'introduction', 'heading' => 'Introduction', 'body' => $line];
            } else {
                $current['body'] .= ($current['body'] === '' ? '' : "\n").$line;
            }
        }
        if ($current !== null) {
            $units[] = $current;
        }

        return $units;
    }

    /**
     * @param  array{kind: string, heading: string, body: string}  $unit
     * @return list<array{kind: string, heading: string, body: string}>
     */
    private function splitOversizedUnit(array $unit, int $availableTokens): array
    {
        $paras = preg_split("/\n{2,}/", trim($unit['body'])) ?: [];
        if (count($paras) <= 1) {
            return [$unit];
        }
        $out = [];
        $buf = '';
        $part = 1;
        foreach ($paras as $para) {
            $candidate = trim($buf === '' ? $para : $buf."\n\n".$para);
            if ($this->estimator->estimate($unit['heading']."\n".$candidate) > $availableTokens && $buf !== '') {
                $out[] = [
                    'kind' => $unit['kind'],
                    'heading' => $unit['heading'].' ('.$part.')',
                    'body' => $buf,
                ];
                $part++;
                $buf = $para;
            } else {
                $buf = $candidate;
            }
        }
        if ($buf !== '') {
            $out[] = [
                'kind' => $unit['kind'],
                'heading' => $part > 1 ? $unit['heading'].' ('.$part.')' : $unit['heading'],
                'body' => $buf,
            ];
        }

        return $out !== [] ? $out : [$unit];
    }

    /**
     * @param  array{kind: string, heading: string, body: string}  $unit
     * @return array{chunk_id: string, kind: string, heading: string, body: string, order: int, input_hash: string, prompt: string}
     */
    private function makeChunk(array $unit, int $order, string $immutablePrefix): array
    {
        $body = trim($unit['body']);
        $prompt = trim($immutablePrefix."\n\n## ".$unit['heading']."\n".$body);
        $hash = hash('sha256', $prompt);

        return [
            'chunk_id' => 'lf-'.$order.'-'.substr($hash, 0, 12),
            'kind' => $unit['kind'],
            'heading' => $unit['heading'],
            'body' => $body,
            'order' => $order,
            'input_hash' => $hash,
            'prompt' => $prompt,
        ];
    }

    /**
     * @param  array<string, mixed>  $article
     * @param  array<string, mixed>  $immutableContext
     */
    private function immutablePrefix(array $article, array $immutableContext): string
    {
        $lines = [
            'ARTICLE CONTEXT (immutable):',
            'Title: '.trim((string) ($article['title'] ?? $immutableContext['title'] ?? '')),
            'Primary keyword: '.trim((string) ($article['keyword'] ?? $immutableContext['keyword'] ?? '')),
            'Language: '.trim((string) ($article['language'] ?? $immutableContext['language'] ?? 'vi')),
            'Write ONLY the assigned section. Do not repeat introduction/conclusion unless this chunk is that section.',
        ];

        return implode("\n", $lines);
    }

    private function looksIntro(string $heading): bool
    {
        $h = mb_strtolower($heading);

        return str_contains($h, 'intro') || str_contains($h, 'mở đầu') || str_contains($h, 'giới thiệu');
    }

    private function looksConclusion(string $heading): bool
    {
        $h = mb_strtolower($heading);

        return str_contains($h, 'conclusion') || str_contains($h, 'kết luận') || str_contains($h, 'tóm tắt');
    }
}
