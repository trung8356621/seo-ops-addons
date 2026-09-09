<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\Prompt;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;

/**
 * Single source of truth for immutable Prompt Versions.
 *
 * A new version is created only when effective compile-time fields change.
 */
final class PromptVersionService
{
    public const CONNECTION = 'omi_seo_ai';

    /**
     * Fields that change compiled prompt / execution behavior.
     *
     * @var list<string>
     */
    public const VERSIONED_FIELDS = [
        'markdown_content',
        'hook_key',
        'hook_version',
        'hook_settings',
        'tools',
        'post_processing',
    ];

    public function tablesReady(): bool
    {
        return Schema::connection(self::CONNECTION)->hasTable('prompt_versions')
            && Schema::connection(self::CONNECTION)->hasColumn('prompts', 'current_prompt_version_id');
    }

    /**
     * Create initial version if missing, or a new version when effective content changed.
     */
    public function syncFromSavedPrompt(Prompt $prompt): ?PromptVersion
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $current = $this->currentVersion($prompt);
        $fingerprint = $this->fingerprint($prompt);

        if ($current instanceof PromptVersion) {
            if (hash_equals((string) $current->content_fingerprint, $fingerprint)) {
                return $current;
            }

            $version = $this->createVersion($prompt, $fingerprint, now());
        } else {
            $at = $prompt->updated_at instanceof \DateTimeInterface
                ? Carbon::instance($prompt->updated_at)
                : ($prompt->created_at instanceof \DateTimeInterface
                    ? Carbon::instance($prompt->created_at)
                    : now());
            $version = $this->createVersion($prompt, $fingerprint, $at);
        }

        $prompt->current_prompt_version_id = (int) $version->id;
        $prompt->saveQuietly();

        return $version;
    }

    /**
     * Ensure the prompt has a current version (execution write path).
     */
    public function ensureCurrentVersion(Prompt $prompt): ?PromptVersion
    {
        if (! $this->tablesReady()) {
            return null;
        }

        $current = $this->currentVersion($prompt);
        if ($current instanceof PromptVersion) {
            return $current;
        }

        return $this->syncFromSavedPrompt($prompt);
    }

    public function currentVersion(Prompt $prompt): ?PromptVersion
    {
        $id = (int) ($prompt->current_prompt_version_id ?? 0);
        if ($id > 0) {
            if ($prompt->relationLoaded('currentVersion')
                && $prompt->currentVersion instanceof PromptVersion) {
                return $prompt->currentVersion;
            }
            $found = PromptVersion::query()->find($id);
            if ($found instanceof PromptVersion) {
                return $found;
            }
        }

        $latest = PromptVersion::query()
            ->where('prompt_id', (int) $prompt->id)
            ->orderByDesc('sequence')
            ->first();

        return $latest instanceof PromptVersion ? $latest : null;
    }

    /**
     * @return list<PromptVersion>
     */
    public function historyForPrompt(Prompt $prompt, int $limit = 20): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        return PromptVersion::query()
            ->where('prompt_id', (int) $prompt->id)
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get()
            ->all();
    }

    public function fingerprint(Prompt $prompt): string
    {
        return self::hashFingerprintPayload($this->effectivePayload($prompt));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function hashFingerprintPayload(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Canonical compile-time payload — used by service + initial-version migration.
     *
     * @param  array<string, mixed>  $hookSettings
     * @param  array<string, mixed>  $postProcessing
     * @return array<string, mixed>
     */
    public static function fingerprintPayload(
        string $markdownContent,
        string $hookKey,
        string $hookVersion,
        array $hookSettings,
        string $tools,
        array $postProcessing,
    ): array {
        return [
            'markdown_content' => $markdownContent,
            'hook_key' => $hookKey,
            'hook_version' => $hookVersion,
            'hook_settings' => self::canonicalizeArray($hookSettings),
            'tools' => $tools,
            'post_processing' => self::canonicalizeArray($postProcessing),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function effectivePayload(Prompt $prompt): array
    {
        $settings = is_array($prompt->settings) ? $prompt->settings : [];
        $hookSettings = is_array($prompt->hook_settings) ? $prompt->hook_settings : [];

        return self::fingerprintPayload(
            (string) ($prompt->markdown_content ?? ''),
            (string) ($prompt->hook_key ?? ''),
            (string) ($prompt->hook_version ?? ''),
            $hookSettings,
            (string) ($prompt->tools ?? 'default'),
            is_array($settings['post_processing'] ?? null) ? $settings['post_processing'] : [],
        );
    }

    public function nextVersionLabel(int $promptId, Carbon $at): string
    {
        $base = $this->formatDateLabel($at);
        $sameDayCount = PromptVersion::query()
            ->where('prompt_id', $promptId)
            ->where(function ($query) use ($base): void {
                $query->where('version_label', $base)
                    ->orWhere('version_label', 'like', $base.'-r%');
            })
            ->count();

        if ($sameDayCount === 0) {
            return $base;
        }

        return $base.'-r'.($sameDayCount + 1);
    }

    public function formatDateLabel(Carbon $at): string
    {
        return sprintf(
            '%d.%d.%02d',
            (int) $at->format('j'),
            (int) $at->format('n'),
            (int) $at->format('y'),
        );
    }

    private function createVersion(Prompt $prompt, string $fingerprint, Carbon $at): PromptVersion
    {
        $promptId = (int) $prompt->id;
        $maxSequence = (int) PromptVersion::query()
            ->where('prompt_id', $promptId)
            ->max('sequence');

        $settings = is_array($prompt->settings) ? $prompt->settings : [];
        $hookSettings = is_array($prompt->hook_settings) ? $prompt->hook_settings : [];
        $variables = is_array($prompt->variables) ? $prompt->variables : [];

        return PromptVersion::query()->create([
            'prompt_id' => $promptId,
            'version_label' => $this->nextVersionLabel($promptId, $at),
            'sequence' => $maxSequence + 1,
            'markdown_content' => (string) ($prompt->markdown_content ?? ''),
            'hook_key' => $prompt->hook_key,
            'hook_version' => $prompt->hook_version,
            'hook_settings' => $hookSettings,
            'settings' => $settings,
            'tools' => $prompt->tools ?? 'default',
            'variables' => $variables,
            'content_fingerprint' => $fingerprint,
            'created_by' => $prompt->user_id
                ? (int) $prompt->user_id
                : ((int) (auth()->id() ?? 0) ?: null),
            'created_at' => $at,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public static function canonicalizeArray(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalizeArray($item);
            }
        }

        return $value;
    }
}
