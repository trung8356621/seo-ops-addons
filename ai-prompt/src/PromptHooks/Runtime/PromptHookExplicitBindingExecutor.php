<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Runtime;

use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\InvalidInput;
use Omnichannel\Addons\AiPrompt\PromptHooks\Exceptions\PromptHookFailure;
use Omnichannel\Addons\AiPrompt\PromptHooks\Support\PromptHookRequireAnyOf;
use Omnichannel\Addons\Content\Services\ArticleWritingLegacyRewriteAdapter;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectItemIdentity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Editor/workflow execution when SeoPrompt has explicit versioned hook binding.
 * Does not use global migration mode. Never falls back to legacy after provider call.
 */
final class PromptHookExplicitBindingExecutor
{
    public function __construct(
        private readonly PromptHookRuntimeEngine $engine,
        private readonly PromptHookRuntimeRegistry $registry,
        private readonly PromptHookMigrationFlags $flags,
        private readonly PromptRunnerService $promptRunner,
        private readonly ArticleWritingLegacyRewriteAdapter $legacyRewriteAdapter,
    ) {}

    /**
     * @param  array<string, mixed>  $variables  Workflow / test variables (scalars)
     * @param  array<string, mixed>  $contextExtras  site_id, locale, node_id, task_id, …
     * @param  array<string, mixed>  $previousOutputs
     * @return array{
     *   output: string,
     *   raw: string,
     *   value: mixed,
     *   sections?: array<string, string>,
     *   ports?: array<string, string>,
     *   correlation_id: string,
     *   prompt_result_id: ?int,
     *   provider: ?string,
     *   model: ?string,
     *   usage: array<string, mixed>,
     *   duration_ms: int,
     *   execution_source: string,
     *   hook_key: string,
     *   hook_version: string,
     *   audit_fingerprint: ?string
     * }
     */
    public function execute(
        SeoPrompt $prompt,
        array $variables = [],
        array $contextExtras = [],
        array $previousOutputs = [],
    ): array {
        $started = (int) round(microtime(true) * 1000);
        $binding = PromptHookBinding::tryFromPrompt($prompt);
        if ($binding === null) {
            throw new InvalidArgumentException('Prompt has no explicit hook binding.');
        }

        // DEPRECATED COMPATIBILITY ONLY: article.content.rewrite → generate.
        // Binding generate mới không đi qua adapter / không log legacy.
        $effectiveHookKey = $binding->hookKey;
        $effectiveVersion = $binding->hookVersion;
        if ($this->legacyRewriteAdapter->isLegacyRewriteHook($binding->hookKey)) {
            $effectiveHookKey = $this->legacyRewriteAdapter->canonicalizeHookKey($binding->hookKey);
            $effectiveVersion = '0.1.0';
            $this->legacyRewriteAdapter->logLegacyAdapterUsed(
                caller: self::class.'::execute',
                articleId: isset($contextExtras['article_id']) ? (int) $contextExtras['article_id'] : null,
                runId: isset($contextExtras['run_id']) ? (int) $contextExtras['run_id'] : null,
                oldHook: $binding->hookKey,
                mappedSourceType: (string) ($variables['article_writing_source_type']
                    ?? $variables['source_type']
                    ?? 'existing_article'),
                destinationCapability: $effectiveHookKey,
            );
        }

        $definition = $this->registry->get($effectiveHookKey, $effectiveVersion);
        $this->registry->assertExecutable(
            $definition,
            true, // explicit editor selection allows experimental
            $this->flags->experimentalAllowlist(),
        );

        $correlationId = (string) ($contextExtras['correlation_id'] ?? Str::uuid()->toString());
        $input = $this->mapInput($definition->inputSchema->fields, $variables, $previousOutputs);
        // Schema-safe generation title seed when post_title empty but subject known.
        $input = $this->seedEmptyPostTitleFromSubject($input, $definition->inputSchema->fields);
        // Only inject topic when the hook schema declares it (e.g. outline) —
        // never leak into comment/content hooks → Unknown input key [topic].
        $input = $this->enrichTopicInput($input, $definition->inputSchema->fields);
        PromptHookRequireAnyOf::assertSatisfied($input, $definition->metadata);
        $settings = is_array($prompt->hook_settings) ? $prompt->hook_settings : [];

        $context = [
            'prompt_id' => (int) $prompt->id,
            'correlation_id' => $correlationId,
            'site_id' => isset($contextExtras['site_id']) ? (int) $contextExtras['site_id'] : null,
            'locale' => isset($contextExtras['locale']) ? (string) $contextExtras['locale'] : ($variables['language'] ?? null),
            'language' => $variables['language'] ?? ($contextExtras['locale'] ?? null),
        ];
        foreach (['team_id', 'connection_id', 'article_id', 'actor_id'] as $key) {
            if (array_key_exists($key, $contextExtras) && $contextExtras[$key] !== null) {
                $context[$key] = $contextExtras[$key];
            }
        }

        if (($definition->template['source'] ?? '') === 'legacy_prompt_content') {
            // Schema-whitelist only — never merge full shared workflow payload (topic leak).
            // Alias mirrors (focus_keyword/title/…) are derived from mapped input for compile only.
            $compileVars = $this->expandCompileAliasMirrors($input);
            $context['legacy_compiled_prompt'] = $this->promptRunner->compilePrompt($prompt, $compileVars);
        }

        $envelope = new PromptHookExecutionInput(
            context: array_filter(
                $context,
                static fn (mixed $v): bool => $v !== null && $v !== '',
            ),
            input: $input,
            previousOutputs: $this->scalarPreviousOutputs($previousOutputs),
            settings: $settings,
        );

        try {
            $result = $this->engine->execute(
                $effectiveHookKey,
                $effectiveVersion,
                $envelope,
                $correlationId,
            );
        } catch (PromptHookFailure $exception) {
            $promptResultId = $exception->promptResultId();
            if ($promptResultId !== null && $promptResultId > 0) {
                PromptResult::query()->whereKey($promptResultId)->update([
                    'status' => 'failed',
                    'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                    'finished_at' => now(),
                ]);
            }

            Log::warning('prompt_hook.explicit_binding_failed', [
                'hook_key' => $binding->hookKey,
                'hook_version' => $binding->hookVersion,
                'execution_source' => PromptHookExecutionIntent::ExplicitBinding->value,
                'prompt_id' => (int) $prompt->id,
                'prompt_result_id' => $promptResultId,
                'node_id' => $contextExtras['node_id'] ?? null,
                'site_id' => $contextExtras['site_id'] ?? null,
                'article_id' => $contextExtras['article_id'] ?? null,
                'correlation_id' => $correlationId,
                'failure_category' => $exception->failureCode->value,
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        $durationMs = max(0, (int) round(microtime(true) * 1000) - $started);
        $ports = is_array($result->output['ports'] ?? null) ? $result->output['ports'] : null;
        $sections = is_array($result->output['sections'] ?? null) ? $result->output['sections'] : null;
        $outputText = $this->normalizeWorkflowText($result->output, $ports);

        Log::info('prompt_hook.explicit_binding_executed', [
            'hook_key' => $effectiveHookKey,
            'hook_version' => $effectiveVersion,
            'legacy_hook_key' => $effectiveHookKey !== $binding->hookKey ? $binding->hookKey : null,
            'execution_source' => PromptHookExecutionIntent::ExplicitBinding->value,
            'prompt_id' => (int) $prompt->id,
            'node_id' => $contextExtras['node_id'] ?? null,
            'workflow_id' => $contextExtras['task_id'] ?? $contextExtras['workflow_id'] ?? null,
            'site_id' => $contextExtras['site_id'] ?? null,
            'correlation_id' => $correlationId,
            'provider' => $result->meta['provider'] ?? null,
            'model' => $result->meta['model'] ?? null,
            'duration_ms' => $durationMs,
            'usage' => [
                'source' => $result->meta['usage_source'] ?? null,
            ],
            'validation_status' => 'ok',
            'request_fingerprint' => $result->auditFingerprint,
        ]);

        $payload = [
            'output' => $outputText,
            'raw' => (string) ($result->output['raw'] ?? $outputText),
            'value' => $result->output['value'] ?? $outputText,
            'correlation_id' => $correlationId,
            'prompt_result_id' => isset($result->meta['prompt_result_id'])
                ? (int) $result->meta['prompt_result_id']
                : null,
            'provider' => isset($result->meta['provider']) ? (string) $result->meta['provider'] : null,
            'model' => isset($result->meta['model']) ? (string) $result->meta['model'] : null,
            'usage' => [
                'source' => $result->meta['usage_source'] ?? null,
            ],
            'duration_ms' => $durationMs,
            'execution_source' => PromptHookExecutionIntent::ExplicitBinding->value,
            'hook_key' => $effectiveHookKey,
            'hook_version' => $effectiveVersion,
            'audit_fingerprint' => $result->auditFingerprint,
        ];
        if ($effectiveHookKey !== $binding->hookKey) {
            $payload['legacy_hook_key'] = $binding->hookKey;
        }
        if ($sections !== null) {
            $payload['sections'] = $sections;
        }
        if ($ports !== null) {
            $payload['ports'] = $ports;
        }

        $lengthValidation = is_array($result->output['length_validation'] ?? null)
            ? $result->output['length_validation']
            : null;
        if ($lengthValidation !== null) {
            $payload['length_validation'] = $lengthValidation;
            $payload['actual_word_count'] = $lengthValidation['actual_word_count'] ?? null;
            $payload['minimum_acceptable_words'] = $lengthValidation['minimum_acceptable_words'] ?? null;
            $payload['target_article_length'] = $lengthValidation['target_article_length'] ?? null;
            $payload['length_validation_result'] = $lengthValidation['length_validation_result'] ?? null;
            $this->persistLengthValidationToPromptResult(
                isset($payload['prompt_result_id']) ? (int) $payload['prompt_result_id'] : 0,
                $lengthValidation,
            );
        }

        return $payload;
    }

    /**
     * @param  array{
     *     actual_word_count: int,
     *     minimum_acceptable_words: int,
     *     target_article_length: int,
     *     length_validation_result: string
     * }  $lengthValidation
     */
    private function persistLengthValidationToPromptResult(int $promptResultId, array $lengthValidation): void
    {
        if ($promptResultId <= 0) {
            return;
        }

        $result = PromptResult::query()->find($promptResultId);
        if ($result === null) {
            return;
        }

        $snapshot = is_array($result->input_snapshot) ? $result->input_snapshot : [];
        $variables = is_array($snapshot['variables'] ?? null) ? $snapshot['variables'] : [];
        foreach ($lengthValidation as $key => $value) {
            $variables[$key] = $value;
            $snapshot[$key] = $value;
        }
        $snapshot['variables'] = $variables;
        $result->input_snapshot = $snapshot;
        $result->save();
    }

    /**
     * When schema declares post_title and it is empty, seed from effectiveSubject
     * (topic/keyword already mapped). Does not invent keyword from title.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function seedEmptyPostTitleFromSubject(array $input, array $fields): array
    {
        if (! array_key_exists('post_title', $fields)) {
            return $input;
        }

        $existing = isset($input['post_title']) ? ContentProjectItemIdentity::normalize((string) $input['post_title']) : '';
        if ($existing !== '') {
            return $input;
        }

        $fromTopic = isset($input['topic'])
            ? ContentProjectItemIdentity::normalize((string) $input['topic'])
            : '';
        $seed = $fromTopic !== ''
            ? $fromTopic
            : ContentProjectItemIdentity::effectiveSubject(
                null,
                isset($input['keyword']) ? (string) $input['keyword'] : (
                    isset($input['focus_keyword']) ? (string) $input['focus_keyword'] : null
                ),
            );
        if ($seed !== '') {
            $input['post_title'] = $seed;
        }

        return $input;
    }

    /**
     * Runtime topic for prompts that declare it — never invents keyword from title.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function enrichTopicInput(array $input, array $fields): array
    {
        if (! array_key_exists('topic', $fields)) {
            unset($input['topic']);

            return $input;
        }

        $existing = isset($input['topic']) ? trim((string) $input['topic']) : '';
        if ($existing !== '') {
            return $input;
        }

        $topic = ContentProjectItemIdentity::effectiveSubject(
            isset($input['post_title']) ? (string) $input['post_title'] : null,
            isset($input['keyword']) ? (string) $input['keyword'] : (
                isset($input['focus_keyword']) ? (string) $input['focus_keyword'] : null
            ),
        );
        if ($topic !== '') {
            $input['topic'] = $topic;
        }

        return $input;
    }

    /**
     * Derive legacy template synonym mirrors from mapped schema input only.
     * Used for compilePrompt — must not be merged into envelope input (unknown-key guard).
     *
     * Pairs: keyword↔focus_keyword, post_title↔title, site_description↔site_short_description.
     * Fill counterpart only when missing/empty; never override explicit non-empty.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function expandCompileAliasMirrors(array $input): array
    {
        $out = $input;

        $keyword = ContentProjectItemIdentity::normalize(
            isset($out['keyword']) ? (string) $out['keyword'] : null,
        );
        if ($keyword === '') {
            $keyword = ContentProjectItemIdentity::normalize(
                isset($out['focus_keyword']) ? (string) $out['focus_keyword'] : null,
            );
        }
        if ($keyword !== '') {
            if (ContentProjectItemIdentity::normalize(isset($out['keyword']) ? (string) $out['keyword'] : null) === '') {
                $out['keyword'] = $keyword;
            }
            if (ContentProjectItemIdentity::normalize(isset($out['focus_keyword']) ? (string) $out['focus_keyword'] : null) === '') {
                $out['focus_keyword'] = $keyword;
            }
        }

        $title = ContentProjectItemIdentity::normalize(
            isset($out['post_title']) ? (string) $out['post_title'] : null,
        );
        if ($title === '') {
            $title = ContentProjectItemIdentity::normalize(
                isset($out['title']) ? (string) $out['title'] : null,
            );
        }
        if ($title !== '') {
            if (ContentProjectItemIdentity::normalize(isset($out['post_title']) ? (string) $out['post_title'] : null) === '') {
                $out['post_title'] = $title;
            }
            if (ContentProjectItemIdentity::normalize(isset($out['title']) ? (string) $out['title'] : null) === '') {
                $out['title'] = $title;
            }
        }

        $site = ContentProjectItemIdentity::normalize(
            isset($out['site_description']) ? (string) $out['site_description'] : null,
        );
        if ($site === '') {
            $site = ContentProjectItemIdentity::normalize(
                isset($out['site_short_description']) ? (string) $out['site_short_description'] : null,
            );
        }
        if ($site !== '') {
            if (ContentProjectItemIdentity::normalize(isset($out['site_description']) ? (string) $out['site_description'] : null) === '') {
                $out['site_description'] = $site;
            }
            if (ContentProjectItemIdentity::normalize(isset($out['site_short_description']) ? (string) $out['site_short_description'] : null) === '') {
                $out['site_short_description'] = $site;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>  $previousOutputs
     * @return array<string, mixed>
     */
    private function mapInput(array $fields, array $variables, array $previousOutputs): array
    {
        $aliases = [
            'input' => ['input', 'post_content', 'existing_body', 'article_content', 'content'],
            'keyword' => ['keyword', 'focus_keyword', 'focusKeyword'],
            'focus_keyword' => ['focus_keyword', 'keyword', 'focusKeyword'],
            'post_title' => ['post_title', 'title', 'article_title'],
            'title' => ['title', 'post_title', 'article_title'],
            'topic' => ['topic', 'subject'],
            'site_short_description' => ['site_short_description', 'site_description', 'short_description'],
            'site_description' => ['site_description', 'site_short_description', 'short_description'],
            'site_cta' => ['site_cta', 'cta'],
            'rewrite_instruction' => ['rewrite_instruction', 'rewrite_notes', 'instruction'],
            'heading_context' => ['heading_context', 'input', 'context', 'outline_context'],
            'language' => ['language', 'locale', 'lang'],
            'search_intent' => ['search_intent', 'intent'],
            'article_length' => ['article_length', 'article_length_default', 'article_length_product'],
            'keyword_density' => ['keyword_density', 'keyword_density_default', 'keyword_density_product'],
        ];

        $input = [];
        foreach ($fields as $field => $schema) {
            if (! is_array($schema)) {
                continue;
            }
            $field = (string) $field;
            $value = $variables[$field] ?? null;
            // article_length: ưu tiên key đã resolve theo post_type; không nhảy product trước default.
            if ($field === 'article_length') {
                if ($value === null || $value === '') {
                    foreach (['article_length_default', 'article_length_product'] as $alias) {
                        if (isset($variables[$alias]) && $variables[$alias] !== '' && $variables[$alias] !== null) {
                            $value = $variables[$alias];
                            break;
                        }
                    }
                }
            } elseif (($value === null || $value === '') && isset($aliases[$field])) {
                foreach ($aliases[$field] as $alias) {
                    if ($alias === $field) {
                        continue;
                    }
                    if (isset($variables[$alias]) && $variables[$alias] !== '' && $variables[$alias] !== null) {
                        $value = $variables[$alias];
                        break;
                    }
                }
            }
            if (($value === null || $value === '') && isset($previousOutputs[$field])) {
                $value = $previousOutputs[$field];
            }
            if ($field === 'article_length') {
                if (($value === null || $value === '')) {
                    foreach (['article_length_default', 'article_length_product'] as $alias) {
                        if (isset($previousOutputs[$alias]) && $previousOutputs[$alias] !== '' && $previousOutputs[$alias] !== null) {
                            $value = $previousOutputs[$alias];
                            break;
                        }
                    }
                }
            } elseif (($value === null || $value === '') && isset($aliases[$field])) {
                foreach ($aliases[$field] as $alias) {
                    if ($alias === $field) {
                        continue;
                    }
                    if (isset($previousOutputs[$alias]) && $previousOutputs[$alias] !== '' && $previousOutputs[$alias] !== null) {
                        $value = $previousOutputs[$alias];
                        break;
                    }
                }
            }

            if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                throw new InvalidInput("Hook input [{$field}] must not be an Eloquent model.");
            }

            if (is_array($value) || (is_object($value) && ! $value instanceof \UnitEnum)) {
                throw new InvalidInput("Hook input [{$field}] must be a scalar.");
            }

            $type = (string) ($schema['type'] ?? 'string');
            if ($value !== null && $value !== '') {
                $value = $this->coerceScalarInput($field, $type, $value, $schema);
            }

            if (($schema['required'] ?? false) === true && ($value === null || $value === '')) {
                throw new InvalidInput("Missing required hook input [{$field}].");
            }

            if ($value !== null && $value !== '') {
                $input[$field] = $value;
            } elseif (($schema['nullable'] ?? false) === true) {
                $input[$field] = null;
            } elseif (array_key_exists('default', $schema)) {
                $input[$field] = $schema['default'];
            }
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function coerceScalarInput(string $field, string $type, mixed $value, array $schema): mixed
    {
        if (in_array($type, ['boolean', 'bool'], true)) {
            if (is_bool($value)) {
                return $value;
            }
            $normalized = strtolower(trim((string) $value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
            throw new InvalidInput("Hook input [{$field}] must be boolean.");
        }

        if (in_array($type, ['integer', 'int'], true)) {
            if (is_int($value)) {
                $int = $value;
            } elseif (is_numeric($value)) {
                $int = (int) $value;
            } elseif (is_string($value) && preg_match('/(\d+)/', $value, $matches) === 1) {
                $int = (int) $matches[1];
            } else {
                throw new InvalidInput("Hook input [{$field}] must be integer.");
            }
            if (isset($schema['minimum']) && $int < (int) $schema['minimum']) {
                throw new InvalidInput("Hook input [{$field}] below minimum.");
            }
            if (isset($schema['maximum']) && $int > (int) $schema['maximum']) {
                throw new InvalidInput("Hook input [{$field}] above maximum.");
            }

            return $int;
        }

        return is_string($value) ? $value : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $previousOutputs
     * @return array<string, mixed>
     */
    private function scalarPreviousOutputs(array $previousOutputs): array
    {
        $out = [];
        foreach ($previousOutputs as $key => $value) {
            if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                throw new InvalidInput('previous_outputs must not contain Eloquent models.');
            }
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = $value;
            } elseif (is_string($value) || is_numeric($value)) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @param  array{type?: string, raw?: string, value?: mixed, warnings?: list<string>, ports?: array<string, string>}  $output
     * @param  array<string, string>|null  $ports
     */
    private function normalizeWorkflowText(array $output, ?array $ports): string
    {
        if (is_array($ports) && isset($ports['total']) && is_string($ports['total'])) {
            return $ports['total'];
        }

        $raw = (string) ($output['raw'] ?? '');
        if (($output['type'] ?? '') === 'markdown_sections' && $raw !== '') {
            return $raw;
        }

        $value = $output['value'] ?? $raw;
        if (is_string($value)) {
            $text = trim($value);
        } elseif (is_scalar($value)) {
            $text = trim((string) $value);
        } else {
            $text = $raw !== '' ? $raw : trim((string) json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        // Compatibility: strip legacy transport markers if model leaked them (single-output hooks only).
        $text = (string) preg_replace('/^\s*\[START[^\]]*\]\s*/imu', '', $text);
        $text = (string) preg_replace('/\s*\[END[^\]]*\]\s*$/imu', '', $text);

        return trim($text);
    }
}
