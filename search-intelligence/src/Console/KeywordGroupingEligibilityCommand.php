<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupingEligibilityCandidate;
use Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup\KeywordGroupingEligibilityGate;

/**
 * Structural Keyword Grouping eligibility. No DB writes. Not concept matching.
 */
final class KeywordGroupingEligibilityCommand extends Command
{
    protected $signature = 'semantic:keyword-eligibility
        {--text=* : Candidate text values (repeatable)}';

    protected $description = 'Classify structural Keyword Grouping eligibility (no semantic call, no DB writes)';

    public function handle(KeywordGroupingEligibilityGate $gate): int
    {
        $texts = array_values(array_filter(
            array_map(static fn ($text): string => trim((string) $text), (array) $this->option('text')),
            static fn (string $text): bool => $text !== '',
        ));
        if ($texts === []) {
            $this->error('Provide at least one --text=');

            return self::FAILURE;
        }

        $candidates = [];
        foreach ($texts as $index => $text) {
            $candidates[] = new KeywordGroupingEligibilityCandidate(ref: 'text:'.($index + 1), text: $text);
        }

        $rows = [];
        foreach ($gate->decide($candidates) as $decision) {
            $rows[] = [
                $decision->text,
                $decision->eligible ? 'true' : 'false',
                $decision->flags === [] ? '—' : implode(',', $decision->flags),
                $decision->excludeReasons === [] ? '—' : implode(',', $decision->excludeReasons),
            ];
        }

        $this->table(['TEXT', 'ELIGIBLE', 'FLAGS', 'REASONS'], $rows);

        return self::SUCCESS;
    }
}
