<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Services\CtaAutomation;

use Omnichannel\Addons\AiPrompt\Services\InteractivePromptExecutor;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use RuntimeException;

/**
 * One batch CTA copy call through the shared interactive executor.
 */
class CtaTextGenerator
{
    public const HOOK_KEY = 'article.cta.generate';

    public function __construct(
        private readonly InteractivePromptExecutor $executor,
        private readonly CtaOutputValidator $validator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $placements
     * @param  list<array<string, mixed>>  $sections
     * @return array{ok: bool, ctas: list<array{placement_id: string, section_id: string, text: string}>, errors: list<array{placement_id: string, code: string}>}
     */
    public function generate(string $language, string $tone, string $businessContext, array $placements, array $sections): array
    {
        $expected = [];
        foreach ($placements as $placement) {
            $expected[] = [
                'placement_id' => (string) $placement['placement_id'],
                'section_id' => (string) $placement['section_id'],
                'alias' => $placement['alias'] ?? null,
            ];
        }
        $prompt = $this->compile($language, $tone, $businessContext, $placements, $sections);
        $errors = [];
        $parsed = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $body = $attempt === 0
                ? $prompt
                : $prompt."\n\nPrevious output failed validation codes: ".implode(',', array_column($errors, 'code')).'. Return JSON only.';
            $result = $this->executor->executeCompiled(
                $body,
                self::HOOK_KEY,
                null,
                AiRoutingPolicy::QuickFree,
            );
            $parsed = $this->decode((string) $result->text);
            if ($parsed === null) {
                $errors = [['placement_id' => '', 'code' => 'invalid_json']];
                continue;
            }
            $validated = $this->validator->validate($expected, $parsed, $language);
            if ($validated['ok']) {
                return $validated;
            }
            $errors = $validated['errors'];
        }

        return ['ok' => false, 'ctas' => [], 'errors' => $errors];
    }

    /**
     * @param  list<array<string, mixed>>  $placements
     * @param  list<array<string, mixed>>  $sections
     */
    public function compile(string $language, string $tone, string $businessContext, array $placements, array $sections): string
    {
        $template = $this->template();
        $plan = [];
        $contexts = [];
        $bySection = [];
        foreach ($sections as $section) {
            $bySection[(string) $section['section_id']] = $section;
        }
        foreach ($placements as $placement) {
            $sectionId = (string) $placement['section_id'];
            $section = $bySection[$sectionId] ?? [];
            $plan[] = [
                'placement_id' => $placement['placement_id'],
                'section_id' => $sectionId,
                'intent' => $placement['intent'],
                'allowed_shortcode' => $placement['alias'],
            ];
            $contexts[] = [
                'section_id' => $sectionId,
                'heading' => (string) ($section['heading'] ?? ''),
                'content' => mb_substr((string) ($section['content'] ?? ''), 0, 700),
            ];
        }
        $replacements = [
            '{{language}}' => $language,
            '{{tone}}' => $tone !== '' ? $tone : 'informative',
            '{{business_context}}' => $businessContext !== '' ? $businessContext : 'general',
            '{{cta_plan}}' => json_encode($plan, JSON_UNESCAPED_UNICODE) ?: '[]',
            '{{section_contexts}}' => json_encode($contexts, JSON_UNESCAPED_UNICODE) ?: '[]',
        ];

        return strtr($template, $replacements);
    }

    private function template(): string
    {
        $path = dirname(__DIR__, 4).'/ai-prompt/resources/prompt-hooks/v01/article.cta.generate@0.1.0.json';
        if (! is_file($path)) {
            throw new RuntimeException('cta_prompt_missing');
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $template = is_array($decoded['template'] ?? null) ? $decoded['template'] : [];
        $system = trim((string) ($template['system'] ?? ''));
        $user = trim((string) ($template['user'] ?? ''));
        if ($system === '' || $user === '') {
            throw new RuntimeException('cta_prompt_missing');
        }

        return $system."\n\n".$user;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function decode(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        $decoded = json_decode(trim($text), true);
        if (! is_array($decoded) || ! is_array($decoded['ctas'] ?? null)) {
            return null;
        }

        return $decoded['ctas'];
    }
}
