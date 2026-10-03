<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\AiPrompt\Models\Prompt;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;
use Omnichannel\Addons\AiPrompt\Models\PromptVersion;
use Omnichannel\Addons\AiPrompt\Models\SeoPromptResultLink;

/**
 * Destructive dev/test reset of AI History / execution runtime rows.
 * Never deletes Prompt definitions or Prompt Versions.
 */
final class AiHistoryResetService
{
    public const CONNECTION = 'omi_seo_ai';

    /**
     * @return array<string, int>
     */
    public function reset(): array
    {
        $counts = [
            'prompt_result_routing_attempts' => 0,
            'seo_prompt_result_links' => 0,
            'prompt_results' => 0,
            'prompts_preserved' => 0,
            'prompt_versions_preserved' => 0,
        ];

        $coreConn = (new PromptResult())->getConnectionName() ?: (string) config('database.core_connection', config('database.default', 'mysql'));
        $seoConn = 'omi_seo_ai';

        if (Schema::connection($coreConn)->hasTable('prompt_result_routing_attempts')) {
            $counts['prompt_result_routing_attempts'] = (int) PromptResultRoutingAttempt::query()->count();
            PromptResultRoutingAttempt::query()->delete();
        }

        if (Schema::connection($seoConn)->hasTable('seo_prompt_result_links')) {
            $counts['seo_prompt_result_links'] = (int) SeoPromptResultLink::query()->count();
            SeoPromptResultLink::query()->delete();
        }

        if (Schema::connection($coreConn)->hasTable('prompt_results')) {
            $counts['prompt_results'] = (int) PromptResult::query()->count();
            PromptResult::query()->delete();
        }

        if (Schema::connection($seoConn)->hasTable('articles')
            && Schema::connection($seoConn)->hasColumn('articles', 'prompt_result_id')) {
            DB::connection($seoConn)->table('articles')->whereNotNull('prompt_result_id')->update(['prompt_result_id' => null]);
        }

        $counts['prompts_preserved'] = Schema::connection($coreConn)->hasTable('prompts')
            ? (int) Prompt::query()->count()
            : 0;
        $counts['prompt_versions_preserved'] = Schema::connection($coreConn)->hasTable('prompt_versions')
            ? (int) PromptVersion::query()->count()
            : 0;

        return $counts;
    }
}
