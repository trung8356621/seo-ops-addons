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
            $prompts = SeoPrompt::query()->where('user_id', $userId)->get(['settings']);
            if ($prompts->isEmpty()) {
                return false;
            }

            // Fresh migrations install default prompts with settings['is_system_default'] = true.
            // A non-blank client has user-created prompts or modified prompts without system default flag.
            foreach ($prompts as $prompt) {
                $settings = is_array($prompt->settings) ? $prompt->settings : [];
                if (($settings['is_system_default'] ?? false) !== true) {
                    return true;
                }
            }

            return false;
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

            $rows = DB::table('wp_options')->whereIn('option_name', self::SETTINGS_OPTIONS)->get(['option_name', 'option_value']);
            if ($rows->isEmpty()) {
                return false;
            }

            foreach ($rows as $row) {
                // seo_create_article_task is seeded with empty/default bindings by migrations.
                // It indicates existing user configuration only if user configured custom fields beyond default bindings.
                if ($row->option_name === 'seo_create_article_task') {
                    $decoded = json_decode((string) $row->option_value, true);
                    if (is_array($decoded)) {
                        // Check if user set custom values like article_title_suggestion_prompt_id or custom sources
                        $customKeys = [
                            'article_title_suggestion_prompt_id',
                            'article_meta_description_suggestion_prompt_id',
                            'featured_snippet_prompt_id',
                            'outline_heading_regenerator_prompt_id',
                            'translate_article_prompt_id',
                        ];
                        foreach ($customKeys as $key) {
                            if (! empty($decoded[$key])) {
                                return true;
                            }
                        }
                        // Default migration seeding leaves all prompt IDs null (except renew_faq_prompt_id pointing to default FAQ)
                        continue;
                    }
                }

                // Any other settings option (e.g. seo_keyword_settings, seo_content_language_settings) means configured client
                return true;
            }

            return false;
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
