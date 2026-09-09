<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;

/**
 * Rebuild the compiled prompt on demand from an immutable Prompt Version + execution inputs.
 */
final class PromptReconstructor
{
    public function __construct(
        private readonly PromptRunnerService $runner,
        private readonly PromptVersionService $versions,
    ) {}

    /**
     * @return array{prompt: string, hash: ?string, matches_stored_hash: ?bool, version_label: ?string, mismatch: bool}
     */
    public function reconstruct(PromptResult $result): array
    {
        $version = $this->resolveVersion($result);
        $variables = $this->executionVariables($result);
        $compiled = '';

        if ($version instanceof PromptVersion) {
            $virtual = $version->toCompilePrompt();
            try {
                $compiled = $this->compileFromPrompt($virtual, $variables, $result);
            } catch (\Throwable) {
                $compiled = $this->interpolate((string) ($version->markdown_content ?? ''), $variables);
            }
        } elseif ($result->relationLoaded('prompt') && $result->prompt instanceof SeoPrompt) {
            try {
                $compiled = $this->compileFromPrompt($result->prompt, $variables, $result);
            } catch (\Throwable) {
                $compiled = $this->interpolate((string) ($result->prompt->markdown_content ?? ''), $variables);
            }
        }

        $storedHash = trim((string) ($result->compiled_prompt_hash ?? ''));
        if ($storedHash === '') {
            $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
            $storedHash = trim((string) ($snapshot['compiled_prompt_hash'] ?? ''));
        }
        $actualHash = $compiled !== '' ? hash('sha256', $compiled) : null;
        $matches = $storedHash !== '' && $actualHash !== null
            ? hash_equals($storedHash, $actualHash)
            : null;

        return [
            'prompt' => $compiled,
            'hash' => $actualHash,
            'matches_stored_hash' => $matches,
            'version_label' => $version instanceof PromptVersion ? (string) $version->version_label : null,
            'mismatch' => $matches === false,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function compileFromPrompt(SeoPrompt $prompt, array $variables, PromptResult $result): string
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        if (! empty($snapshot['sectioned_free_orchestrator']) || ! empty($snapshot['sectioned_free_section'])) {
            $label = trim((string) ($snapshot['display_name'] ?? ''));
            $body = $this->runner->compilePrompt($prompt, $this->stringVariables($variables));
            if ($label !== '') {
                return $label."\n\n".$body;
            }

            return $body;
        }

        if (! empty($snapshot['manual_compiled']) && trim((string) ($snapshot['section_id'] ?? '')) !== '') {
            return $this->runner->compilePrompt($prompt, $this->stringVariables($variables));
        }

        return $this->runner->compilePrompt($prompt, $this->stringVariables($variables));
    }

    private function resolveVersion(PromptResult $result): ?PromptVersion
    {
        if ($result->relationLoaded('promptVersion') && $result->promptVersion instanceof PromptVersion) {
            return $result->promptVersion;
        }

        $id = (int) ($result->prompt_version_id ?? 0);
        if ($id > 0) {
            $found = PromptVersion::query()->find($id);

            return $found instanceof PromptVersion ? $found : null;
        }

        $promptId = (int) ($result->prompt_id ?? 0);
        if ($promptId <= 0) {
            return null;
        }
        $prompt = SeoPrompt::query()->find($promptId);

        return $prompt instanceof SeoPrompt ? $this->versions->currentVersion($prompt) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function executionVariables(PromptResult $result): array
    {
        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];
        $articleId = (int) ($snapshot['article_id'] ?? $variables['article_id'] ?? $result->project_item_id ?? 0);
        if ($articleId <= 0) {
            try {
                $link = \Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink::query()
                    ->where('prompt_result_id', (int) $result->getKey())
                    ->orderByDesc('id')
                    ->first();
                if ($link !== null) {
                    $articleId = (int) ($link->article_id ?? 0);
                }
            } catch (\Throwable) {
                // Link table may be absent (unit tests / reset).
            }
        }

        return $this->mergeArticleContext($variables, $articleId);
    }

    /**
     * Fill missing compile variables from the live article (Content owns the blob).
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function mergeArticleContext(array $variables, int $articleId): array
    {
        if ($articleId <= 0 || ! class_exists(\Omnichannel\Addons\Content\Models\SeoArticle::class)) {
            return $variables;
        }

        try {
            $article = \Omnichannel\Addons\Content\Models\SeoArticle::query()->find($articleId);
        } catch (\Throwable) {
            return $variables;
        }
        if ($article === null) {
            return $variables;
        }

        $map = [
            'title' => (string) ($article->title ?? ''),
            'post_title' => (string) ($article->title ?? ''),
            'article_title' => (string) ($article->title ?? ''),
            'post_content' => (string) ($article->content ?? ''),
            'content' => (string) ($article->content ?? ''),
            'article_body' => (string) ($article->content ?? ''),
            'post_excerpt' => (string) ($article->excerpt ?? ''),
            'excerpt' => (string) ($article->excerpt ?? ''),
            'outline' => (string) ($article->outline ?? ''),
            'outline_markdown' => (string) ($article->outline ?? ''),
            'slug' => (string) ($article->slug ?? ''),
            'focus_keyword' => (string) ($article->focus_keyword ?? ''),
        ];
        foreach ($map as $key => $value) {
            $existing = $variables[$key] ?? null;
            if (($existing === null || $existing === '') && $value !== '') {
                $variables[$key] = $value;
            }
        }
        if (! isset($variables['article_id']) || (int) $variables['article_id'] <= 0) {
            $variables['article_id'] = $articleId;
        }

        return $variables;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, string>
     */
    private function stringVariables(array $variables): array
    {
        $out = [];
        foreach ($variables as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[$key] = (string) ($value ?? '');
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function interpolate(string $markdown, array $variables): string
    {
        $compiled = $markdown;
        foreach ($this->stringVariables($variables) as $name => $value) {
            $compiled = str_replace('{{'.$name.'}}', $value, $compiled);
        }

        return trim($compiled);
    }
}
