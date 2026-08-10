<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Operations;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI cost aggregates from prompt_results — never reads prompt text.
 */
final class ContentProjectAiCostAggregateService
{
    private const CONNECTION = 'omi_seo_ai';

    /**
     * @param  list<int>|null  $siteIds
     * @return array{
     *     totals: array{prompt_tokens: int, completion_tokens: int, estimated_cost: float},
     *     by_model: list<array{model: string, prompt_tokens: int, completion_tokens: int, estimated_cost: float}>,
     *     by_site: list<array{site_id: int, prompt_tokens: int, completion_tokens: int, estimated_cost: float}>,
     * }
     */
    public function aggregate(?Carbon $date = null, ?array $siteIds = null): array
    {
        if (! Schema::connection(self::CONNECTION)->hasTable('prompt_results')) {
            return $this->empty();
        }

        $date = ($date ?? Carbon::today())->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        $modelExpr = $this->modelExpression();

        $base = DB::connection(self::CONNECTION)
            ->table('prompt_results')
            ->whereBetween('finished_at', [$date, $end])
            ->where('status', 'completed');

        if (is_array($siteIds) && $siteIds !== []) {
            $base->whereIn('site_id', $siteIds);
        }

        $totalsRow = (clone $base)->selectRaw("
            COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.prompt_tokens')) AS UNSIGNED)), 0) AS prompt_tokens,
            COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.completion_tokens')) AS UNSIGNED)), 0) AS completion_tokens,
            COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.estimated_cost')) AS DECIMAL(14,6))), 0) AS estimated_cost
        ")->first();

        $byModel = (clone $base)
            ->selectRaw("
                {$modelExpr} AS model,
                COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.prompt_tokens')) AS UNSIGNED)), 0) AS prompt_tokens,
                COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.completion_tokens')) AS UNSIGNED)), 0) AS completion_tokens,
                COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.estimated_cost')) AS DECIMAL(14,6))), 0) AS estimated_cost
            ")
            ->groupBy('model')
            ->orderByDesc('estimated_cost')
            ->get()
            ->map(static fn ($row): array => [
                'model' => (string) ($row->model ?? 'unknown'),
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'estimated_cost' => round((float) $row->estimated_cost, 6),
            ])
            ->values()
            ->all();

        $bySite = (clone $base)
            ->selectRaw("
                site_id,
                COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.prompt_tokens')) AS UNSIGNED)), 0) AS prompt_tokens,
                COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.completion_tokens')) AS UNSIGNED)), 0) AS completion_tokens,
                COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.estimated_cost')) AS DECIMAL(14,6))), 0) AS estimated_cost
            ")
            ->groupBy('site_id')
            ->orderByDesc('estimated_cost')
            ->get()
            ->map(static fn ($row): array => [
                'site_id' => (int) $row->site_id,
                'prompt_tokens' => (int) $row->prompt_tokens,
                'completion_tokens' => (int) $row->completion_tokens,
                'estimated_cost' => round((float) $row->estimated_cost, 6),
            ])
            ->values()
            ->all();

        return [
            'totals' => [
                'prompt_tokens' => (int) ($totalsRow->prompt_tokens ?? 0),
                'completion_tokens' => (int) ($totalsRow->completion_tokens ?? 0),
                'estimated_cost' => round((float) ($totalsRow->estimated_cost ?? 0), 6),
            ],
            'by_model' => $byModel,
            'by_site' => $bySite,
        ];
    }

    private function modelExpression(): string
    {
        $schema = Schema::connection(self::CONNECTION);

        if ($schema->hasColumn('prompt_results', 'model_name')) {
            return "COALESCE(NULLIF(model_name, ''), JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.model')), 'unknown')";
        }

        if ($schema->hasColumn('prompt_results', 'model')) {
            return "COALESCE(NULLIF(model, ''), JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.model')), 'unknown')";
        }

        return "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(token_usage, '$.model')), 'unknown')";
    }

    /**
     * @return array{
     *     totals: array{prompt_tokens: int, completion_tokens: int, estimated_cost: float},
     *     by_model: list<array{model: string, prompt_tokens: int, completion_tokens: int, estimated_cost: float}>,
     *     by_site: list<array{site_id: int, prompt_tokens: int, completion_tokens: int, estimated_cost: float}>,
     * }
     */
    private function empty(): array
    {
        return [
            'totals' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'estimated_cost' => 0.0,
            ],
            'by_model' => [],
            'by_site' => [],
        ];
    }
}
