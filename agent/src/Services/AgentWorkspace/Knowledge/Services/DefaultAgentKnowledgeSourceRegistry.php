<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Services;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Contracts\AgentKnowledgeSourceRegistry;
use RuntimeException;

final class DefaultAgentKnowledgeSourceRegistry implements AgentKnowledgeSourceRegistry
{
    public function supportedSources(): array
    {
        return [
            'manual',
            'conversation',
            'execution_result',
            'system_reference',
            'uploaded_document',
            'import',
        ];
    }

    public function supports(string $sourceType): bool
    {
        return in_array($sourceType, $this->supportedSources(), true);
    }

    public function extract(string $sourceType, array $payload): array
    {
        if (! $this->supports($sourceType)) {
            throw new RuntimeException('unsupported_source:'.$sourceType);
        }

        return match ($sourceType) {
            'manual', 'conversation', 'import' => [
                'title' => (string) ($payload['title'] ?? 'Untitled'),
                'content' => (string) ($payload['content'] ?? ''),
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            ],
            'execution_result' => [
                'title' => (string) ($payload['title'] ?? ('Execution '.(string) ($payload['source_ref'] ?? ''))),
                'content' => (string) ($payload['content'] ?? $payload['summary'] ?? ''),
                'metadata' => [
                    'execution_ref' => $payload['source_ref'] ?? null,
                    'skill_key' => $payload['skill_key'] ?? null,
                    'status' => $payload['status'] ?? null,
                ],
            ],
            'system_reference' => [
                'title' => (string) ($payload['title'] ?? 'System reference'),
                'content' => (string) ($payload['content'] ?? ''),
                'metadata' => [
                    'capability' => $payload['capability'] ?? null,
                    'note' => 'snapshot via public read capability only',
                ],
            ],
            'uploaded_document' => $this->extractUpload($payload),
            default => throw new RuntimeException('unsupported_source:'.$sourceType),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{title: string, content: string, metadata: array<string, mixed>}
     */
    private function extractUpload(array $payload): array
    {
        $mime = (string) ($payload['mime'] ?? $payload['mime_type'] ?? '');
        $filename = (string) ($payload['filename'] ?? 'document.txt');
        $allowed = ['text/plain', 'text/markdown', 'application/json'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $extOk = in_array($ext, ['txt', 'md', 'json'], true);

        if ($mime !== '' && ! in_array($mime, $allowed, true) && ! $extOk) {
            throw new RuntimeException('unsupported_file_type');
        }
        if (! $extOk && $mime === '') {
            throw new RuntimeException('unsupported_file_type');
        }

        $content = (string) ($payload['content'] ?? $payload['text'] ?? '');
        if ($content === '') {
            throw new RuntimeException('unsupported_file_type:empty_or_no_extractor');
        }

        return [
            'title' => (string) ($payload['title'] ?? $filename),
            'content' => $content,
            'metadata' => [
                'filename' => $filename,
                'mime' => $mime,
            ],
        ];
    }
}
