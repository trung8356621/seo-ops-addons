<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;

class AiTokenUsageAnalyticsService
{
    /**
     * @return array{start: Carbon, end: Carbon}
     */
    public function resolveDateRange(string $range): array
    {
        $now = now();

        return match ($range) {
            'today' => [
                'start' => $now->copy()->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            '7d' => [
                'start' => $now->copy()->subDays(6)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            'this_month' => [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
            ],
            '30d' => [
                'start' => $now->copy()->subDays(29)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
            default => [
                'start' => $now->copy()->subDays(29)->startOfDay(),
                'end' => $now->copy()->endOfDay(),
            ],
        };
    }

    /**
     * @return array{
     *     seo: array{calls: int, tokens: int},
     *     seeding: array{calls: int, tokens: int},
     *     total_tokens: int,
     *     total_calls: int
     * }
     */
    public function getSummary(string $range = '30d', string $addonFilter = 'all'): array
    {
        $dates = $this->resolveDateRange($range);

        $query = PromptResultRoutingAttempt::query()
            ->where('attempted', true)
            ->whereBetween('created_at', [$dates['start'], $dates['end']]);

        if ($addonFilter === 'seo') {
            $query->where('addon', AiUsageTaxonomy::ADDON_SEO);
        } elseif ($addonFilter === 'seeding') {
            $query->where('addon', AiUsageTaxonomy::ADDON_SEEDING);
        }

        $records = $query
            ->selectRaw('addon, COUNT(*) as calls, COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->groupBy('addon')
            ->get();

        $seoCalls = 0;
        $seoTokens = 0;
        $seedingCalls = 0;
        $seedingTokens = 0;
        $grandTotalTokens = 0;
        $grandTotalCalls = 0;

        foreach ($records as $row) {
            $addon = (string) $row->addon;
            $calls = (int) $row->calls;
            $tokens = (int) $row->total_tokens;

            $grandTotalCalls += $calls;
            $grandTotalTokens += $tokens;

            if ($addon === AiUsageTaxonomy::ADDON_SEO) {
                $seoCalls += $calls;
                $seoTokens += $tokens;
            } elseif ($addon === AiUsageTaxonomy::ADDON_SEEDING) {
                $seedingCalls += $calls;
                $seedingTokens += $tokens;
            }
        }

        return [
            'seo' => [
                'calls' => $seoCalls,
                'tokens' => $seoTokens,
            ],
            'seeding' => [
                'calls' => $seedingCalls,
                'tokens' => $seedingTokens,
            ],
            'total_tokens' => $grandTotalTokens,
            'total_calls' => $grandTotalCalls,
        ];
    }

    /**
     * Daily token trend data for SEO and Seeding.
     *
     * @return array{
     *     labels: list<string>,
     *     dates: list<string>,
     *     seo_series: list<int>,
     *     seeding_series: list<int>,
     *     total_series: list<int>,
     *     max_tokens: int
     * }
     */
    public function getDailyTrend(string $range = '30d'): array
    {
        $dates = $this->resolveDateRange($range);

        $rows = PromptResultRoutingAttempt::query()
            ->where('attempted', true)
            ->whereBetween('created_at', [$dates['start'], $dates['end']])
            ->selectRaw('DATE(created_at) as log_date, addon, COALESCE(SUM(total_tokens), 0) as total_tokens')
            ->groupBy(DB::raw('DATE(created_at)'), 'addon')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $d = (string) $row->log_date;
            $addon = (string) $row->addon;
            $map[$d][$addon] = (int) $row->total_tokens;
        }

        $labels = [];
        $dateKeys = [];
        $seoSeries = [];
        $seedingSeries = [];
        $totalSeries = [];
        $maxTokens = 0;

        $period = CarbonPeriod::create($dates['start']->copy()->startOfDay(), '1 day', $dates['end']->copy()->startOfDay());

        foreach ($period as $dt) {
            $key = $dt->format('Y-m-d');
            $dateKeys[] = $key;
            $labels[] = $dt->format('d/m');

            $seo = (int) ($map[$key][AiUsageTaxonomy::ADDON_SEO] ?? 0);
            $seeding = (int) ($map[$key][AiUsageTaxonomy::ADDON_SEEDING] ?? 0);
            $tot = $seo + $seeding;

            $seoSeries[] = $seo;
            $seedingSeries[] = $seeding;
            $totalSeries[] = $tot;

            if ($tot > $maxTokens) {
                $maxTokens = $tot;
            }
        }

        return [
            'labels' => $labels,
            'dates' => $dateKeys,
            'seo_series' => $seoSeries,
            'seeding_series' => $seedingSeries,
            'total_series' => $totalSeries,
            'max_tokens' => $maxTokens,
        ];
    }

    /**
     * Hierarchical table data: Addon (parent) -> Modules (children).
     *
     * @return list<array{
     *     key: string,
     *     name: string,
     *     calls: int,
     *     input_tokens: int,
     *     output_tokens: int,
     *     total_tokens: int,
     *     children: list<array{
     *         key: string,
     *         name: string,
     *         calls: int,
     *         input_tokens: int,
     *         output_tokens: int,
     *         total_tokens: int
     *     }>
     * }>
     */
    public function getTableData(string $range = '30d', string $addonFilter = 'all'): array
    {
        $dates = $this->resolveDateRange($range);

        $query = PromptResultRoutingAttempt::query()
            ->where('attempted', true)
            ->whereBetween('created_at', [$dates['start'], $dates['end']]);

        if ($addonFilter === 'seo') {
            $query->where('addon', AiUsageTaxonomy::ADDON_SEO);
        } elseif ($addonFilter === 'seeding') {
            $query->where('addon', AiUsageTaxonomy::ADDON_SEEDING);
        }

        $records = $query
            ->selectRaw('
                addon,
                module,
                COUNT(*) as calls,
                COALESCE(SUM(input_tokens), 0) as input_tokens,
                COALESCE(SUM(output_tokens), 0) as output_tokens,
                COALESCE(SUM(total_tokens), 0) as total_tokens
            ')
            ->groupBy('addon', 'module')
            ->get();

        $grouped = [];
        foreach ($records as $row) {
            $addon = (string) ($row->addon ?: AiUsageTaxonomy::ADDON_UNKNOWN);
            $module = (string) ($row->module ?: AiUsageTaxonomy::MODULE_UNKNOWN);

            $grouped[$addon][$module] = [
                'calls' => (int) $row->calls,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'total_tokens' => (int) $row->total_tokens,
            ];
        }

        $addonLabels = AiUsageTaxonomy::addonLabels();
        $moduleLabels = AiUsageTaxonomy::moduleLabels();

        // Định nghĩa các addons và modules theo đúng thứ tự mong muốn
        $orderedAddons = [
            AiUsageTaxonomy::ADDON_SEO => [
                AiUsageTaxonomy::MODULE_CONTENT_PROJECT,
                AiUsageTaxonomy::MODULE_TOPIC,
                AiUsageTaxonomy::MODULE_SEO_AUDIT,
                AiUsageTaxonomy::MODULE_SEO_SCORING,
            ],
            AiUsageTaxonomy::ADDON_SEEDING => [
                AiUsageTaxonomy::MODULE_SEEDING,
            ],
        ];

        $result = [];

        foreach ($orderedAddons as $addonKey => $expectedModules) {
            if ($addonFilter !== 'all' && $addonFilter !== $addonKey) {
                continue;
            }

            $children = [];
            $addonCalls = 0;
            $addonInput = 0;
            $addonOutput = 0;
            $addonTotal = 0;

            $modulesInDb = $grouped[$addonKey] ?? [];

            // Duyệt qua các expected modules
            foreach ($expectedModules as $modKey) {
                $stat = $modulesInDb[$modKey] ?? [
                    'calls' => 0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'total_tokens' => 0,
                ];

                $addonCalls += $stat['calls'];
                $addonInput += $stat['input_tokens'];
                $addonOutput += $stat['output_tokens'];
                $addonTotal += $stat['total_tokens'];

                $children[] = [
                    'key' => $modKey,
                    'name' => $moduleLabels[$modKey] ?? ucfirst(str_replace('_', ' ', $modKey)),
                    'calls' => $stat['calls'],
                    'input_tokens' => $stat['input_tokens'],
                    'output_tokens' => $stat['output_tokens'],
                    'total_tokens' => $stat['total_tokens'],
                ];
            }

            // Kiểm tra các module khác chưa trong expected list
            foreach ($modulesInDb as $otherModKey => $stat) {
                if (! in_array($otherModKey, $expectedModules, true)) {
                    $addonCalls += $stat['calls'];
                    $addonInput += $stat['input_tokens'];
                    $addonOutput += $stat['output_tokens'];
                    $addonTotal += $stat['total_tokens'];

                    $children[] = [
                        'key' => $otherModKey,
                        'name' => $moduleLabels[$otherModKey] ?? ucfirst(str_replace('_', ' ', $otherModKey)),
                        'calls' => $stat['calls'],
                        'input_tokens' => $stat['input_tokens'],
                        'output_tokens' => $stat['output_tokens'],
                        'total_tokens' => $stat['total_tokens'],
                    ];
                }
            }

            $result[$addonKey] = [
                'key' => $addonKey,
                'name' => $addonLabels[$addonKey] ?? ucfirst($addonKey),
                'calls' => $addonCalls,
                'input_tokens' => $addonInput,
                'output_tokens' => $addonOutput,
                'total_tokens' => $addonTotal,
                'children' => $children,
            ];
        }

        // Nếu có records thuộc unknown và filter là all, hiển thị nhóm Unknown
        if ($addonFilter === 'all' && isset($grouped[AiUsageTaxonomy::ADDON_UNKNOWN])) {
            $unknownModules = $grouped[AiUsageTaxonomy::ADDON_UNKNOWN];
            $unCalls = 0;
            $unInput = 0;
            $unOutput = 0;
            $unTotal = 0;
            $unChildren = [];

            foreach ($unknownModules as $mKey => $stat) {
                $unCalls += $stat['calls'];
                $unInput += $stat['input_tokens'];
                $unOutput += $stat['output_tokens'];
                $unTotal += $stat['total_tokens'];

                $unChildren[] = [
                    'key' => $mKey,
                    'name' => $moduleLabels[$mKey] ?? ucfirst(str_replace('_', ' ', $mKey)),
                    'calls' => $stat['calls'],
                    'input_tokens' => $stat['input_tokens'],
                    'output_tokens' => $stat['output_tokens'],
                    'total_tokens' => $stat['total_tokens'],
                ];
            }

            if ($unCalls > 0) {
                $result[AiUsageTaxonomy::ADDON_UNKNOWN] = [
                    'key' => AiUsageTaxonomy::ADDON_UNKNOWN,
                    'name' => $addonLabels[AiUsageTaxonomy::ADDON_UNKNOWN] ?? 'Chưa phân loại',
                    'calls' => $unCalls,
                    'input_tokens' => $unInput,
                    'output_tokens' => $unOutput,
                    'total_tokens' => $unTotal,
                    'children' => $unChildren,
                ];
            }
        }

        return $result;
    }
}
