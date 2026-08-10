<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Knowledge\Security;

/**
 * Knowledge content sanitizer — untrusted input.
 */
final class AgentKnowledgeContentSanitizer
{
    private const SECRET_PATTERNS = [
        '/api[_-]?key\s*[:=]\s*\S+/i',
        '/password\s*[:=]\s*\S+/i',
        '/authorization\s*[:=]\s*\S+/i',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/sk-[a-zA-Z0-9]{20,}/',
        '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/',
    ];

    /**
     * @return array{ok: bool, content: string, title: string, secrets_found: bool, reason?: string}
     */
    public function sanitize(string $title, string $content, int $maxChars = 20000): array
    {
        $title = trim(strip_tags($title));
        $content = $this->stripUnsafeHtml($content);

        if ($title === '' || $content === '') {
            return ['ok' => false, 'content' => '', 'title' => '', 'secrets_found' => false, 'reason' => 'empty_content'];
        }

        if (mb_strlen($content) > $maxChars) {
            return ['ok' => false, 'content' => '', 'title' => $title, 'secrets_found' => false, 'reason' => 'content_too_large'];
        }

        if ($this->containsSecrets($title."\n".$content)) {
            return ['ok' => false, 'content' => '', 'title' => $title, 'secrets_found' => true, 'reason' => 'secret_detected'];
        }

        return [
            'ok' => true,
            'content' => $content,
            'title' => mb_substr($title, 0, 255),
            'secrets_found' => false,
        ];
    }

    public function containsSecrets(string $text): bool
    {
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public function contentHash(string $content): string
    {
        return hash('sha256', mb_strtolower(trim(preg_replace('/\s+/u', ' ', $content) ?? $content)));
    }

    private function stripUnsafeHtml(string $content): string
    {
        $content = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $content) ?? $content;
        $content = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $content) ?? $content;
        $content = strip_tags($content, '<p><br><ul><ol><li><strong><em><h1><h2><h3><h4>');
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($content);
    }
}
