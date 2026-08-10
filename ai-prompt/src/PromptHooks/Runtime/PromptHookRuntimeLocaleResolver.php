<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\PromptHooks\Canonical\PromptHookLocalePolicy;

final class PromptHookRuntimeLocaleResolver
{
    /** @var array<string, string> */
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'en-US' => 'English',
        'en-GB' => 'English',
        'vi' => 'Vietnamese',
        'vi-VN' => 'Vietnamese',
        'fr' => 'French',
        'fr-FR' => 'French',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'de' => 'German',
        'es' => 'Spanish',
        'th' => 'Thai',
        'id' => 'Indonesian',
    ];

    /**
     * Order: explicit execution locale → site → hook default/fixed → system fallback.
     *
     * @param  array{locale?: ?string, site_locale?: ?string, article_locale?: ?string, caller_locale?: ?string}  $context
     * @return array{locale_code: string, language_name: string}
     */
    public function resolve(PromptHookLocalePolicy $policy, array $context): array
    {
        $explicit = trim((string) ($context['locale'] ?? $context['caller_locale'] ?? ''));
        $site = trim((string) ($context['site_locale'] ?? ''));
        $article = trim((string) ($context['article_locale'] ?? ''));

        $code = match ($policy->mode) {
            'fixed' => trim((string) ($policy->fixed ?? $policy->fallback)),
            'article' => $article !== '' ? $article : ($explicit !== '' ? $explicit : ($site !== '' ? $site : $policy->fallback)),
            'caller' => $explicit !== '' ? $explicit : ($site !== '' ? $site : $policy->fallback),
            default => $explicit !== '' ? $explicit : ($site !== '' ? $site : ($article !== '' ? $article : $policy->fallback)),
        };

        if ($code === '') {
            $code = $policy->fallback !== '' ? $policy->fallback : 'en';
        }

        $normalized = $this->normalizeCode($code);

        return [
            'locale_code' => $normalized,
            'language_name' => $this->languageName($normalized),
        ];
    }

    private function normalizeCode(string $code): string
    {
        $code = str_replace('_', '-', trim($code));
        if (strlen($code) === 2) {
            $map = ['vi' => 'vi-VN', 'en' => 'en-US', 'fr' => 'fr-FR', 'ja' => 'ja-JP', 'de' => 'de-DE', 'es' => 'es-ES'];

            return $map[strtolower($code)] ?? $code;
        }

        return $code;
    }

    private function languageName(string $localeCode): string
    {
        if (isset(self::LANGUAGE_NAMES[$localeCode])) {
            return self::LANGUAGE_NAMES[$localeCode];
        }
        $short = strtolower(explode('-', $localeCode)[0] ?? '');

        return self::LANGUAGE_NAMES[$short] ?? $localeCode;
    }
}
