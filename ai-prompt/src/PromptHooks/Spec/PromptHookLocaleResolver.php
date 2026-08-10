<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Spec;

/**
 * Phase 5A — locale resolve proposal (site → article → fixed → caller).
 */
final class PromptHookLocaleResolver
{
    /**
     * @param  array{mode?: string, fallback?: string, fixed?: string}  $localeSpec
     * @param  array{site_locale?: ?string, article_locale?: ?string, caller_locale?: ?string}  $context
     */
    public function resolve(array $localeSpec, array $context): string
    {
        $fallback = trim((string) ($localeSpec['fallback'] ?? 'en'));
        if ($fallback === '') {
            $fallback = 'en';
        }

        $mode = (string) ($localeSpec['mode'] ?? 'site');

        $candidate = match ($mode) {
            'fixed' => trim((string) ($localeSpec['fixed'] ?? '')),
            'article' => trim((string) ($context['article_locale'] ?? '')),
            'caller' => trim((string) ($context['caller_locale'] ?? '')),
            default => trim((string) ($context['site_locale'] ?? '')),
        };

        if ($candidate !== '') {
            return $candidate;
        }

        // Fallback chain when primary empty.
        foreach (['article_locale', 'site_locale', 'caller_locale'] as $key) {
            $value = trim((string) ($context[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $fallback;
    }
}
