<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;

/**
 * A blank Client is the absence of prompts, workflows, settings rows, and a completed bootstrap.
 * An empty prompt table by itself is not enough.
 */
final class BlankInstallationDetector
{
    /** @var list<string> */
    private const SETTINGS_OPTIONS = [
        'seo_keyword_settings',
        'seo_create_article_task',
        'seo_overview_settings',
        'seo_analytics_scope_settings',
        'seo_content_language_settings',
        'seo_datetime_settings',
        'seo_scoring_rules_settings',
    ];

    public function __construct(
        private readonly ConfigurationSyncStateStore $state,
    ) {}

    /**
     * @return array{blank: bool, signals: array<string, bool>}
     */
    public function assess(int $userId): array
    {
        $signals = [
            'prompts' => $this->promptsExist($userId),
            'workflows' => $this->workflowsExist($userId),
            'settings' => $this->settingsExist(),
            'bootstrap_record' => $this->bootstrapCompleted(),
        ];

        return [
            'blank' => ! in_array(true, $signals, true),
            'signals' => $signals,
        ];
    }

    private function promptsExist(int $userId): bool
    {
        try {
            return SeoPrompt::query()->where('user_id', $userId)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function workflowsExist(int $userId): bool
    {
        try {
            return SeoTask::query()->where('user_id', $userId)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function settingsExist(): bool
    {
        try {
            if (! Schema::hasTable('wp_options')) {
                return false;
            }

            return DB::table('wp_options')->whereIn('option_name', self::SETTINGS_OPTIONS)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function bootstrapCompleted(): bool
    {
        $bootstrap = $this->state->read()['bootstrap'] ?? null;

        return is_array($bootstrap) && ($bootstrap['status'] ?? '') === 'completed';
    }
}
