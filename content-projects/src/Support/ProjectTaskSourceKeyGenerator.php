<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Support;

/**
 * Fingerprint ổn định cho Content Project task identity.
 * Phase 2: primitive only — chưa gắn write path.
 */
final class ProjectTaskSourceKeyGenerator
{
    public function generate(
        int|string $projectId,
        ?string $type,
        ?string $postType,
        ?string $sourceContent,
    ): string {
        $payload = json_encode([
            'project_id' => (string) $projectId,
            'type' => $this->normalizeScalar($type),
            'post_type' => $this->normalizeScalar($postType),
            'source_content' => $this->normalizeSourceContent($sourceContent),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    public function normalizeSourceContent(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', $trimmed);
        if (! is_string($collapsed)) {
            $collapsed = $trimmed;
        }

        // Luôn NFC khi intl có — Café (NFD) và Café (NFC) cùng key.
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($collapsed, \Normalizer::FORM_C);
            if (is_string($normalized) && $normalized !== '') {
                $collapsed = $normalized;
            }
        }

        return mb_strtolower($collapsed, 'UTF-8');
    }

    private function normalizeScalar(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim($value);
    }
}
