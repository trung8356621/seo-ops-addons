<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Jobs\PollKeywordGroupAnalysisJob;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupAnalysisException;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupAnalysisFinalizer;

/**
 * Start or resume async keyword-group finalize. Does not replace the editor button.
 */
final class KeywordGroupAnalysisCommand extends Command
{
    protected $signature = 'semantic:keyword-group-analysis
        {siteId : Site id}
        {--language= : Dataset language}
        {--queue : Dispatch one resumable poll job}';

    protected $description = 'Sync the keyword dataset and finalize a completed Python analysis run';

    public function handle(KeywordGroupAnalysisFinalizer $finalizer): int
    {
        $siteId = (int) $this->argument('siteId');
        $language = $this->option('language');
        $language = is_string($language) && trim($language) !== '' ? trim($language) : null;
        if ($this->option('queue')) {
            PollKeywordGroupAnalysisJob::dispatch($siteId, $language);
            $this->line((string) json_encode(['status' => 'queued', 'site_id' => $siteId], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        try {
            $step = $finalizer->advance($siteId, $language);
        } catch (KeywordGroupAnalysisException $e) {
            $this->line((string) json_encode([
                'status' => 'retry',
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ], JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            'status' => $step->phase,
            'run_id' => $step->runId,
            'poll_again' => $step->pollAgain,
            'group_count' => $step->groupCount,
            'member_count' => $step->memberCount,
            'error' => $step->error,
        ], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
