<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Data\AgentKnowledgeChunkData;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Security\AgentKnowledgeContentSanitizer;

final class AgentKnowledgeChunker
{
    public function __construct(
        private readonly AgentKnowledgeContentSanitizer $sanitizer = new AgentKnowledgeContentSanitizer,
        private readonly int $maxChunks = 40,
        private readonly int $targetChars = 800,
        private readonly float $charsPerToken = 4.0,
    ) {}

    /**
     * @return list<AgentKnowledgeChunkData>
     */
    public function chunk(string $content, ?string $title = null): array
    {
        $normalized = trim($content);
        if ($normalized === '') {
            return [];
        }

        // Prefer paragraph/heading blocks; never split mid-JSON object.
        if ($this->looksLikeJson($normalized)) {
            return [$this->makeChunk(0, $normalized, $title)];
        }

        $blocks = preg_split('/\n{2,}/u', $normalized) ?: [$normalized];
        $chunks = [];
        $buffer = '';
        $heading = $title;

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (preg_match('/^#{1,4}\s+(.+)$/u', $block, $m) === 1) {
                if ($buffer !== '') {
                    $chunks[] = $this->makeChunk(count($chunks), $buffer, $heading);
                    $buffer = '';
                }
                $heading = trim($m[1]);
                continue;
            }
            if (mb_strlen($buffer) + mb_strlen($block) + 2 > $this->targetChars && $buffer !== '') {
                $chunks[] = $this->makeChunk(count($chunks), $buffer, $heading);
                $buffer = $block;
            } else {
                $buffer = $buffer === '' ? $block : $buffer."\n\n".$block;
            }
            if (count($chunks) >= $this->maxChunks) {
                break;
            }
        }

        if ($buffer !== '' && count($chunks) < $this->maxChunks) {
            $chunks[] = $this->makeChunk(count($chunks), $buffer, $heading);
        }

        return $this->dedupe($chunks);
    }

    private function makeChunk(int $index, string $content, ?string $heading): AgentKnowledgeChunkData
    {
        $hash = $this->sanitizer->contentHash($content);

        return new AgentKnowledgeChunkData(
            chunkIndex: $index,
            content: $content,
            tokenEstimate: (int) max(1, (int) ceil(mb_strlen($content) / $this->charsPerToken)),
            contentHash: $hash,
            heading: $heading,
        );
    }

    /**
     * @param  list<AgentKnowledgeChunkData>  $chunks
     * @return list<AgentKnowledgeChunkData>
     */
    private function dedupe(array $chunks): array
    {
        $seen = [];
        $out = [];
        $i = 0;
        foreach ($chunks as $chunk) {
            if (isset($seen[$chunk->contentHash])) {
                continue;
            }
            $seen[$chunk->contentHash] = true;
            $out[] = new AgentKnowledgeChunkData(
                chunkIndex: $i,
                content: $chunk->content,
                tokenEstimate: $chunk->tokenEstimate,
                contentHash: $chunk->contentHash,
                heading: $chunk->heading,
                metadata: $chunk->metadata,
            );
            $i++;
        }

        return $out;
    }

    private function looksLikeJson(string $content): bool
    {
        $trim = ltrim($content);

        return (str_starts_with($trim, '{') && str_ends_with(rtrim($content), '}'))
            || (str_starts_with($trim, '[') && str_ends_with(rtrim($content), ']'));
    }
}
