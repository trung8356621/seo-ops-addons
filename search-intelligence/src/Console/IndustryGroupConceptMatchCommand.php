<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupMatchEntity;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupSemanticMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;

/**
 * Diagnostic: Industry Groups → Concept Matching API (no DB writes).
 */
final class IndustryGroupConceptMatchCommand extends Command
{
    protected $signature = 'semantic:industry-groups:match
        {--industry= : Industry context key}
        {--site= : Site id (optional)}
        {--locale=vi : Locale / language}
        {--text=* : Entity text values (repeatable)}';

    protected $description = 'Match caller-supplied texts against active Industry Groups via seo-ops-semantic';

    public function handle(IndustryGroupSemanticMatcher $matcher): int
    {
        $texts = array_values(array_filter(
            array_map(static fn ($t): string => trim((string) $t), (array) $this->option('text')),
            static fn (string $t): bool => $t !== '',
        ));
        if ($texts === []) {
            $this->error('Provide at least one --text=');

            return self::FAILURE;
        }

        $industry = trim((string) ($this->option('industry') ?? ''));
        $industryKey = $industry !== '' ? $industry : null;
        $siteRaw = $this->option('site');
        $siteId = $siteRaw !== null && $siteRaw !== '' ? (int) $siteRaw : null;
        $locale = trim((string) ($this->option('locale') ?? 'vi'));
        if ($locale === '') {
            $locale = 'vi';
        }

        $entities = [];
        foreach ($texts as $i => $text) {
            $entities[] = new IndustryGroupMatchEntity(ref: 'text:'.($i + 1), text: $text);
        }

        $scope = 'cli:industry-groups'
            .($industryKey !== null ? ':'.$industryKey : '')
            .($siteId !== null ? ':site-'.$siteId : '');

        try {
            $result = $matcher->match(
                scopeRef: $scope,
                entities: $entities,
                siteId: $siteId,
                industryContextKey: $industryKey,
                locale: $locale,
            );
        } catch (SemanticHttpException $e) {
            $this->error('['.$e->errorCode.'] '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('called_python='.($result->calledPython ? 'yes' : 'no'));
        $this->line('concepts_used='.$result->conceptsUsed);
        $this->line('stale_skipped='.$result->staleGroupsSkipped);
        $this->line('disabled_skipped='.$result->disabledGroupsSkipped);
        if ($result->reason !== '') {
            $this->line('reason='.$result->reason);
        }
        if ($result->analysisId !== null) {
            $this->line('analysis_id='.$result->analysisId);
        }

        $rows = [];
        foreach ($result->entities as $entity) {
            if ($entity->evidence === []) {
                $rows[] = [
                    $entity->text,
                    '—',
                    '—',
                    '—',
                    '—',
                    '—',
                    '—',
                ];

                continue;
            }
            foreach ($entity->evidence as $ev) {
                $rows[] = [
                    $entity->text,
                    $ev->industryGroupKey,
                    (string) ($ev->groupType ?? ''),
                    $ev->lexicalMatched ? 'true' : 'false',
                    $ev->positiveMax === null ? 'null' : number_format($ev->positiveMax, 4, '.', ''),
                    $ev->margin === null ? 'null' : number_format($ev->margin, 4, '.', ''),
                    $ev->suggestedMatch === null ? 'null' : ($ev->suggestedMatch ? 'true' : 'false'),
                ];
            }
        }

        $this->table(
            ['TEXT', 'INDUSTRY GROUP', 'TYPE', 'LEXICAL', 'POSITIVE MAX', 'MARGIN', 'SUGGESTED'],
            $rows,
        );

        return self::SUCCESS;
    }
}
