<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\ModelContextCapability;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Exceptions\PromptBudgetException;
use Omnichannel\Addons\AiPrompt\PromptBudget\HtmlSafeRewriteSplitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\LongFormArticleMerger;
use Omnichannel\Addons\AiPrompt\PromptBudget\LongFormArticleSplitStrategy;
use Omnichannel\Addons\AiPrompt\PromptBudget\PromptChunkLedger;
use Omnichannel\Addons\AiPrompt\PromptBudget\SemanticContentChunk;

/**
 * Execute semantic chunks with ledger idempotency — never substr compiled prompts.
 */
final class SemanticSplitExecutor
{
    public function __construct(
        private readonly PromptBudgetPreflightService $preflight = new PromptBudgetPreflightService(),
        private readonly LongFormArticleMerger $longFormMerger = new LongFormArticleMerger(),
    ) {}

    /**
     * @param  list<SemanticContentChunk>|list<array{chunk_id: string, kind: string, heading: string, body: string, order: int, input_hash: string, prompt: string}>  $chunks
     * @param  callable(string $prompt, array $options): array{0: string, 1: array<string, mixed>|null}  $providerCall
     * @return array{0: string, 1: array<string, mixed>, 2: PromptChunkLedger}
     */
    public function executeChunks(
        RoutedAiCandidate $candidate,
        array $chunks,
        PromptChunkLedger $ledger,
        string $hookKey,
        callable $providerCall,
        string $mergeMode = 'longform',
        int $routeAttempt = 0,
    ): array {
        if ($chunks === []) {
            throw PromptBudgetException::unsplittable('Semantic split produced zero chunks.');
        }

        $capability = $this->preflight->capabilities()->resolve($candidate);
        $parts = [];
        $providerCalls = 0;

        foreach ($chunks as $index => $raw) {
            [$chunkId, $prompt, $inputHash, $kind, $order, $heading, $semantic] = $this->normalizeChunk($raw, $index);

            if ($ledger->isCompletedWithHash($chunkId, $inputHash)) {
                $cached = (string) $ledger->completedOutput($chunkId);
                $parts[] = $this->partPayload($semantic, $chunkId, $kind, $order, $heading, $cached);

                continue;
            }

            $ledger->planChunk($chunkId, $inputHash, $order, null, [
                'kind' => $kind,
                'heading' => $heading,
                'route_attempt' => $routeAttempt,
            ]);

            $plan = $this->preflight->assertSendable($candidate, $prompt, $hookKey, [
                'continuation_already_inlined' => true,
                'schema_already_inlined' => true,
                'desired_output_tokens' => min(1200, max(256, (int) floor($capability->maxOutputTokens * 0.45))),
                'minimum_required_output_tokens' => 128,
            ]);

            $ledger->markRunning($chunkId, $routeAttempt);
            [$output, $usage] = $providerCall($prompt, [
                'max_output' => $plan->requestedMaxOutputTokens,
                'budget_plan_id' => $plan->planId,
                'hook_key' => $hookKey,
            ]);
            $providerCalls++;
            unset($usage);
            $ledger->markCompleted($chunkId, $output);
            $parts[] = $this->partPayload($semantic, $chunkId, $kind, $order, $heading, $output);
        }

        $merged = match ($mergeMode) {
            'html_safe' => $this->mergeHtmlSafe($parts),
            default => $this->mergeLongForm($parts),
        };

        return [$merged, ['provider_calls' => $providerCalls, 'chunk_count' => count($chunks)], $ledger];
    }

    /**
     * @param  array<string, mixed>  $article
     * @param  callable(string $prompt, array $options): array{0: string, 1: array<string, mixed>|null}  $providerCall
     * @return array{0: string, 1: array<string, mixed>, 2: PromptChunkLedger}
     */
    public function executeLongForm(
        RoutedAiCandidate $candidate,
        LongFormArticleSplitStrategy $strategy,
        array $article,
        PromptChunkLedger $ledger,
        callable $providerCall,
        int $routeAttempt = 0,
    ): array {
        $capability = $this->preflight->capabilities()->resolve($candidate);
        $chunks = $strategy->buildChunks($article, $capability);
        if (count($chunks) > $strategy->maxChunks()) {
            throw PromptBudgetException::unsplittable('Long-form exceeded maxChunks.');
        }

        return $this->executeChunks(
            $candidate,
            $chunks,
            $ledger,
            $strategy->hookKey(),
            $providerCall,
            'longform',
            $routeAttempt,
        );
    }

    /**
     * @param  array{source?: string, instructions?: string, glossary?: string, language?: string}  $structured
     * @param  callable(string $prompt, array $options): array{0: string, 1: array<string, mixed>|null}  $providerCall
     * @return array{0: string, 1: array<string, mixed>, 2: PromptChunkLedger}
     */
    public function executeHtmlSafe(
        RoutedAiCandidate $candidate,
        HtmlSafeRewriteSplitStrategy $strategy,
        array $structured,
        PromptChunkLedger $ledger,
        callable $providerCall,
        int $routeAttempt = 0,
    ): array {
        $chunks = $strategy->buildChunks($structured);
        if ($chunks === []) {
            throw PromptBudgetException::unsplittable('HTML-safe split produced zero blocks.');
        }
        if (count($chunks) > $strategy->maxChunks()) {
            throw PromptBudgetException::unsplittable('HTML-safe exceeded maxChunks.');
        }

        return $this->executeChunks(
            $candidate,
            $chunks,
            $ledger,
            $strategy->hookKey(),
            $providerCall,
            'html_safe',
            $routeAttempt,
        );
    }

    /**
     * Re-split an oversized parent chunk; mark parent superseded.
     *
     * @param  list<SemanticContentChunk>  $children
     */
    public function supersedeWithChildren(PromptChunkLedger $ledger, string $parentId, array $children): void
    {
        $ledger->supersede($parentId);
        foreach ($children as $i => $child) {
            $c = $child->withHash();
            $ledger->planChunk($c->logicalId, $c->inputHash, $c->order, $parentId, [
                'kind' => $c->kind,
                're_split_from' => $parentId,
                'child_index' => $i,
            ]);
        }
    }

    /**
     * @param  SemanticContentChunk|array<string, mixed>  $raw
     * @return array{0: string, 1: string, 2: string, 3: string, 4: int, 5: string, 6: ?SemanticContentChunk}
     */
    private function normalizeChunk(mixed $raw, int $index): array
    {
        if ($raw instanceof SemanticContentChunk) {
            $c = $raw->withHash();

            return [$c->logicalId, $c->body, $c->inputHash, $c->kind, $c->order, (string) ($c->meta['heading'] ?? ''), $c];
        }

        $prompt = (string) ($raw['prompt'] ?? $raw['body'] ?? '');
        $chunkId = (string) ($raw['chunk_id'] ?? ('chunk-'.$index));
        $inputHash = (string) ($raw['input_hash'] ?? hash('sha256', $prompt));
        $kind = (string) ($raw['kind'] ?? 'section');
        $order = (int) ($raw['order'] ?? $index);
        $heading = (string) ($raw['heading'] ?? '');

        return [$chunkId, $prompt, $inputHash, $kind, $order, $heading, null];
    }

    /**
     * @return array{chunk: SemanticContentChunk, output: string}|array{chunk_id: string, kind: string, heading: string, body: string, order: int, output: string}
     */
    private function partPayload(
        ?SemanticContentChunk $semantic,
        string $chunkId,
        string $kind,
        int $order,
        string $heading,
        string $output,
    ): array {
        if ($semantic instanceof SemanticContentChunk) {
            return ['chunk' => $semantic, 'output' => $output];
        }

        return [
            'chunk_id' => $chunkId,
            'kind' => $kind,
            'heading' => $heading,
            'body' => $output,
            'order' => $order,
            'output' => $output,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function mergeLongForm(array $parts): string
    {
        if ($parts !== [] && isset($parts[0]['chunk']) && $parts[0]['chunk'] instanceof SemanticContentChunk) {
            /** @var list<array{chunk: SemanticContentChunk, output: string}> $typed */
            $typed = $parts;

            return $this->longFormMerger->merge($typed);
        }

        $strategy = new LongFormArticleSplitStrategy('article.content.generate');

        return $strategy->mergeResults($parts);
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function mergeHtmlSafe(array $parts): string
    {
        $strategy = new HtmlSafeRewriteSplitStrategy('article.content.rewrite');
        if ($parts !== [] && isset($parts[0]['chunk']) && $parts[0]['chunk'] instanceof SemanticContentChunk) {
            /** @var list<array{chunk: SemanticContentChunk, output: string}> $typed */
            $typed = $parts;

            return $strategy->mergeResults($typed);
        }

        throw PromptBudgetException::unsplittable('HTML-safe merge requires SemanticContentChunk parts.');
    }
}
