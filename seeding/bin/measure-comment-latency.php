<?php

declare(strict_types=1);

/**
 * DIAGNOSTIC ONLY — measure seeding.comment.generate latency (quantity=3).
 *
 * Usage (from anywhere):
 *   php addons/seeding/bin/measure-comment-latency.php [runs=5] [user_id]
 *
 * Does NOT change routing policy, models, budgets, or prompt body.
 * Enable flag is process-local via AiLatencyDiag::enable().
 */

use App\Models\User;
use Omnichannel\Addons\AiPrompt\Models\PromptResult;
use Omnichannel\Addons\AiPrompt\Models\PromptResultRoutingAttempt;
use Omnichannel\Addons\AiPrompt\Services\AiUsageTaxonomy;
use Omnichannel\Addons\AiPrompt\Support\AiLatencyDiag;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;

$candidates = [
    dirname(__DIR__, 3),
    dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'omnichannel-client',
    'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.'omnichannel-client',
];

$clientRoot = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php')) {
        $clientRoot = $candidate;
        break;
    }
}

if ($clientRoot === null) {
    fwrite(STDERR, "Cannot locate omnichannel-client vendor/autoload.php\n");
    exit(1);
}

require $clientRoot.'/vendor/autoload.php';
$app = require $clientRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$runs = isset($argv[1]) && is_numeric($argv[1]) ? max(1, (int) $argv[1]) : 5;
$userIdOverride = isset($argv[2]) && is_numeric($argv[2]) ? (int) $argv[2] : 0;

$user = null;
if ($userIdOverride > 0) {
    $user = User::query()->find($userIdOverride);
} else {
    $user = User::query()
        ->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])
        ->orderBy('id')
        ->first();
}

if (! $user instanceof User) {
    fwrite(STDERR, json_encode(['error' => 'no_usable_user'], JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
}

auth()->login($user);

/** @var SeedingCommentGenerateService $service */
$service = app(SeedingCommentGenerateService::class);

$results = [];

for ($i = 1; $i <= $runs; $i++) {
    AiLatencyDiag::enable();

    $payload = [
        'content' => 'Balo laptop chống sốc cho học sinh — latency diag run '.$i.' @ '.date('c'),
        'social' => 'threads',
        'quantity' => 3,
        'source_type' => 'text',
        // Unique key each run — avoid idempotent replay short-circuiting provider timing.
        'idempotency_key' => 'latency-diag-'.date('YmdHis').'-'.$i.'-'.bin2hex(random_bytes(4)),
    ];

    $wallStarted = hrtime(true);
    $error = null;
    $comments = [];

    try {
        $comments = $service->generateFromPayload($payload);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $wallMs = round((hrtime(true) - $wallStarted) / 1_000_000, 3);
    $diag = AiLatencyDiag::report();
    $spans = $diag['spans_ms'];
    $meta = $diag['meta'];
    $providerAttempts = $diag['provider_attempts'];

    $usage = is_array($meta['usage'] ?? null) ? $meta['usage'] : null;
    $tokens = AiUsageTaxonomy::extractTokens($usage);

    // Enrich from Persist PromptResult routing attempts if linked recently.
    $promptResultId = isset($meta['prompt_result_id']) ? (int) $meta['prompt_result_id'] : 0;
    $persistedAttempts = [];
    if ($promptResultId > 0) {
        try {
            $persistedAttempts = PromptResultRoutingAttempt::query()
                ->where('prompt_result_id', $promptResultId)
                ->orderBy('sequence')
                ->get()
                ->map(static fn (PromptResultRoutingAttempt $row): array => [
                    'sequence' => $row->sequence,
                    'state' => $row->state,
                    'attempted' => $row->attempted,
                    'provider' => $row->provider,
                    'provider_model' => $row->provider_model,
                    'physical_route' => $row->physical_route,
                    'cost_class' => $row->cost_class,
                    'skip_reason' => $row->skip_reason,
                    'failure_category' => $row->failure_category,
                    'duration_ms' => $row->duration_ms,
                    'input_tokens' => $row->input_tokens,
                    'output_tokens' => $row->output_tokens,
                ])
                ->all();
        } catch (Throwable) {
            // Orphaned attempts may use prompt_result_id=null — fall back to diag meta.
        }
    }

    // Also pull recent orphaned attempts for this hook (canonical path may not link PR id).
    if ($persistedAttempts === []) {
        try {
            $since = now()->subMinutes(5);
            $persistedAttempts = PromptResultRoutingAttempt::query()
                ->where('created_at', '>=', $since)
                ->where(function ($q) {
                    $q->where('action', 'like', '%comment%')
                        ->orWhere('module', 'like', '%seeding%')
                        ->orWhereNull('prompt_result_id');
                })
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(static fn (PromptResultRoutingAttempt $row): array => [
                    'sequence' => $row->sequence,
                    'state' => $row->state,
                    'attempted' => $row->attempted,
                    'provider' => $row->provider,
                    'provider_model' => $row->provider_model,
                    'physical_route' => $row->physical_route,
                    'cost_class' => $row->cost_class,
                    'skip_reason' => $row->skip_reason,
                    'failure_category' => $row->failure_category,
                    'duration_ms' => $row->duration_ms,
                    'input_tokens' => $row->input_tokens,
                    'output_tokens' => $row->output_tokens,
                    'prompt_result_id' => $row->prompt_result_id,
                ])
                ->all();
        } catch (Throwable) {
            $persistedAttempts = [];
        }
    }

    $providerMs = (float) ($spans['E_provider_http_total_ms'] ?? 0);
    $backendTotal = (float) ($spans['H_backend_total_ms'] ?? $wallMs);
    $nonProvider = round(max(0, $backendTotal - $providerMs), 3);

    $freeAttemptOccurred = false;
    foreach ($providerAttempts as $pa) {
        if (($pa['is_free'] ?? false) === true) {
            $freeAttemptOccurred = true;
            break;
        }
    }
    if (! $freeAttemptOccurred) {
        foreach (($meta['routing_attempts_summary'] ?? []) as $ra) {
            if (($ra['is_free'] ?? false) === true && in_array(($ra['result'] ?? ''), ['success', 'failed'], true)) {
                $freeAttemptOccurred = true;
                break;
            }
        }
    }

    $results[] = [
        'run' => $i,
        'ok' => $error === null && $comments !== [],
        'error' => $error,
        'warm_vs_first' => $i === 1 ? 'first' : 'warm',
        'comments_count' => count($comments),
        'A_frontend_request_total_ms' => null, // CLI path — no HTTP shell
        'H_backend_total_ms' => $backendTotal,
        'wall_ms' => $wallMs,
        'B_service_prep_ms' => $spans['B_service_prep_ms'] ?? null,
        'C_service_context_prompt_prep_ms' => $spans['C_service_context_prompt_prep_ms'] ?? null,
        'C_handler_context_prompt_prep_ms' => $spans['C_handler_context_prompt_prep_ms'] ?? null,
        'C_duplicated_prep_total_ms' => round(
            (float) ($spans['C_service_context_prompt_prep_ms'] ?? 0)
            + (float) ($spans['C_handler_context_prompt_prep_ms'] ?? 0),
            3,
        ),
        'D_routing_candidate_planning_ms' => $spans['D_routing_candidate_planning_ms'] ?? null,
        'E_provider_http_total_ms' => $providerMs > 0 ? $providerMs : null,
        'E_text_port_generate_ms' => $spans['E_text_port_generate_ms'] ?? null,
        'F_parse_validate_ms' => $spans['F_parse_validate_ms'] ?? null,
        'G_prompt_result_persist_ms' => $spans['G_prompt_result_persist_ms'] ?? null,
        'G_seeding_history_persist_ms' => $spans['G_seeding_history_persist_ms'] ?? null,
        'non_provider_backend_ms' => $nonProvider,
        'routing' => [
            'hook_key' => $meta['hook_key'] ?? 'seeding.comment.generate',
            'execution_profile' => $meta['execution_profile'] ?? null,
            'routing_policy_requested' => $meta['routing_policy_requested'] ?? null,
            'routing_policy_effective' => $meta['routing_policy_effective'] ?? null,
            'provider' => $meta['provider'] ?? null,
            'model' => $meta['model'] ?? null,
            'physical_route' => $meta['physical_route'] ?? null,
            'routing_path' => $meta['routing_path'] ?? null,
            'plan_attemptable_count' => $meta['plan_attemptable_count'] ?? null,
            'actual_attempts' => $meta['actual_attempts'] ?? null,
            'free_attempts' => $meta['free_attempts'] ?? null,
            'paid_attempts' => $meta['paid_attempts'] ?? null,
            'fallback_count' => $meta['fallback_count'] ?? null,
            'candidates_tried' => $meta['candidates_tried'] ?? null,
            'candidates_skipped' => $meta['candidates_skipped'] ?? null,
            'winning_is_free' => $meta['winning_is_free'] ?? null,
            'free_attempt_occurred' => $freeAttemptOccurred,
            'routing_attempts_summary' => $meta['routing_attempts_summary'] ?? [],
        ],
        'tokens' => [
            'max_output_ceiling' => $meta['max_output_ceiling'] ?? 540,
            'input_tokens' => $tokens['input_tokens'],
            'output_tokens' => $tokens['output_tokens'],
            'total_tokens' => $tokens['total_tokens'],
        ],
        'provider_attempts' => $providerAttempts,
        'persisted_routing_attempts' => $persistedAttempts,
        'prompt_result_id' => $promptResultId > 0 ? $promptResultId : null,
        'system_execution_id' => $meta['system_execution_id'] ?? null,
        'spans_ms_raw' => $spans,
    ];

    AiLatencyDiag::disable();

    // Brief pause between runs to separate warm connections vs burst.
    if ($i < $runs) {
        usleep(250_000);
    }
}

$totals = array_values(array_filter(array_map(
    static fn (array $r): ?float => $r['ok'] ? (float) $r['H_backend_total_ms'] : null,
    $results,
)));
sort($totals);
$countOk = count($totals);
$median = null;
if ($countOk > 0) {
    $mid = intdiv($countOk, 2);
    $median = $countOk % 2 === 1
        ? $totals[$mid]
        : round(($totals[$mid - 1] + $totals[$mid]) / 2, 3);
}

$providerTotals = array_values(array_filter(array_map(
    static fn (array $r): ?float => $r['ok'] && $r['E_provider_http_total_ms'] !== null
        ? (float) $r['E_provider_http_total_ms']
        : null,
    $results,
)));

$report = [
    'diagnostic' => 'seeding.comment.generate latency',
    'quantity' => 3,
    'max_output_ceiling_formula' => 'min(2048, max(256, quantity * 180)) => 540 for qty=3',
    'user_id' => (int) $user->id,
    'client_root' => $clientRoot,
    'note' => 'CLI path through SeedingCommentGenerateService (no HTTP/frontend shell). A_frontend = null.',
    'runs' => $results,
    'summary' => [
        'ok_count' => $countOk,
        'fail_count' => $runs - $countOk,
        'total_ms' => [
            'min' => $countOk > 0 ? $totals[0] : null,
            'median' => $median,
            'max' => $countOk > 0 ? $totals[$countOk - 1] : null,
        ],
        'provider_ms' => [
            'min' => $providerTotals !== [] ? min($providerTotals) : null,
            'max' => $providerTotals !== [] ? max($providerTotals) : null,
            'avg' => $providerTotals !== []
                ? round(array_sum($providerTotals) / count($providerTotals), 3)
                : null,
        ],
        'free_attempt_any_run' => (bool) array_reduce(
            $results,
            static fn (bool $carry, array $r): bool => $carry || (bool) ($r['routing']['free_attempt_occurred'] ?? false),
            false,
        ),
        'fallback_any_run' => (bool) array_reduce(
            $results,
            static fn (bool $carry, array $r): bool => $carry || ((int) ($r['routing']['fallback_count'] ?? 0) > 0),
            false,
        ),
    ],
];

$outPath = $clientRoot.'/storage/app/_seeding_comment_latency_'.date('Ymd_His').'.json';
@file_put_contents($outPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
fwrite(STDERR, "Wrote {$outPath}\n");

exit($countOk === $runs ? 0 : 2);
