<?php

declare(strict_types=1);

/**
 * DIAGNOSTIC ONLY — benchmark the canonical model-call path for seeding.comment.generate.
 * Usage: php addons/seeding/bin/measure-comment-latency.php [single_runs=5] [user_id]
 */

use App\Models\User;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\CanonicalAiTextExecutionService;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiLatencyDiag;

$roots = [dirname(__DIR__, 3), 'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.'omnichannel-client'];
$clientRoot = null;
foreach ($roots as $root) {
    if (is_file($root.'/vendor/autoload.php')) {
        $clientRoot = $root;
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

$singleRuns = isset($argv[1]) && is_numeric($argv[1]) ? max(5, (int) $argv[1]) : 5;
$userId = isset($argv[2]) && is_numeric($argv[2]) ? (int) $argv[2] : 0;
$requiredModelName = 'nvidia/nemotron-3-ultra-550b-a55b:free';
$requiredModel = SeoAiModel::query()
    ->with('apiConnection')
    ->where('raw_model_name', $requiredModelName)
    ->where('status', SeoAiModel::STATUS_ACTIVE)
    ->first();
$user = $userId > 0
    ? User::query()->find($userId)
    : User::query()->find((int) ($requiredModel?->apiConnection?->user_id ?? 0));
if (! $user instanceof User) {
    fwrite(STDERR, json_encode(['error' => 'no_usable_user']).PHP_EOL);
    exit(1);
}
auth()->login($user);

$profile = AiExecutionProfile::TextFast;
$hookKey = 'seeding.comment.generate';
$prompt = <<<'PROMPT'
Return exactly this JSON shape with three short Vietnamese comments about a shock-resistant student laptop backpack:
{"comments":["comment 1","comment 2","comment 3"]}
Do not include markdown or any text outside the JSON object.
PROMPT;

/** @var AiModelRouterService $router */
$router = app(AiModelRouterService::class);
$baseContext = new AiRoutingContext(
    userId: (int) $user->id,
    hookKey: $hookKey,
    freeOnly: true,
    canonicalPromptKey: $hookKey,
    promptTaskType: 'atomic_text',
    modelArea: $profile->value,
);
$model = null;
foreach ($router->resolveAll($profile->value, $baseContext) as $candidate) {
    if (strtolower($candidate->provider) === 'openrouter' && $candidate->model === $requiredModelName) {
        $model = $candidate;
        break;
    }
}
if ($model === null || (int) ($model->seoAiModelId ?? 0) <= 0) {
    fwrite(STDERR, json_encode(['error' => 'no_active_openrouter_free_model']).PHP_EOL);
    exit(1);
}

/** @var CanonicalAiTextExecutionService $executor */
$executor = app(CanonicalAiTextExecutionService::class);
$run = static function (string $mode, ?int $sequence, AiRoutingContext $context) use (
    $executor, $prompt, $hookKey, $profile
): array {
    AiLatencyDiag::enable();
    $started = hrtime(true);
    $error = null;
    $candidate = null;
    try {
        [, , $candidate] = $executor->generate(
            $prompt,
            $hookKey,
            $profile,
            $context,
            ['quantity' => 3, 'max_output' => 540, 'desired_output_tokens' => 540],
        );
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $logicalMs = round((hrtime(true) - $started) / 1_000_000, 3);
    $diag = AiLatencyDiag::report();
    AiLatencyDiag::disable();

    return [
        'mode' => $mode,
        'run' => $sequence,
        'ok' => $error === null,
        'error' => $error,
        'logical_request_total_ms' => $logicalMs,
        'routing_candidate_planning_ms' => $diag['spans_ms']['D_routing_candidate_planning_ms'] ?? null,
        'routing_resolve_all_ms' => $diag['spans_ms']['routing_resolve_all_ms'] ?? null,
        'catalog_bootstrap_check_ms' => $diag['spans_ms']['catalog_bootstrap_check_ms'] ?? null,
        'catalog_freshness_ms' => $diag['spans_ms']['catalog_freshness_ms'] ?? null,
        'target_lookup_ms' => $diag['spans_ms']['target_lookup_ms'] ?? null,
        'eligible_candidates_ms' => $diag['spans_ms']['eligible_candidates_ms'] ?? null,
        'routing_preferences_ms' => $diag['spans_ms']['routing_preferences_ms'] ?? null,
        'routing_owner_resolve_ms' => $diag['spans_ms']['routing_owner_resolve_ms'] ?? null,
        'routing_resilience_settings_ms' => $diag['spans_ms']['routing_resilience_settings_ms'] ?? null,
        'routing_context_enrich_ms' => $diag['spans_ms']['routing_context_enrich_ms'] ?? null,
        'routing_secondary_lane_ms' => $diag['spans_ms']['routing_secondary_lane_ms'] ?? null,
        'routing_candidate_planner_ms' => $diag['spans_ms']['routing_candidate_planner_ms'] ?? null,
        'routing_route_revision_ms' => $diag['spans_ms']['routing_route_revision_ms'] ?? null,
        'budget_preflight_ms' => $diag['spans_ms']['budget_preflight_ms'] ?? null,
        'provider_adapter_prepare_ms' => $diag['spans_ms']['provider_adapter_prepare_ms'] ?? null,
        'outbound_budget_gate_ms' => $diag['spans_ms']['outbound_budget_gate_ms'] ?? null,
        'url_guard_dns_ms' => $diag['spans_ms']['url_guard_dns_ms'] ?? null,
        'http_roundtrip_ms' => $diag['spans_ms']['http_roundtrip_ms'] ?? null,
        'response_parse_ms' => $diag['spans_ms']['response_parse_ms'] ?? null,
        'candidate_execute_total_ms' => $diag['spans_ms']['E_provider_http_total_ms'] ?? null,
        'provider' => $candidate?->provider,
        'model' => $candidate?->model,
        'physical_route' => $candidate?->physicalRouteKey(),
        'actual_attempts' => $diag['meta']['actual_attempts'] ?? count($diag['provider_attempts']),
        'free_attempts' => $diag['meta']['free_attempts'] ?? null,
        'fallback_count' => $diag['meta']['fallback_count'] ?? null,
        'candidates_tried' => $diag['meta']['candidates_tried'] ?? null,
        'candidates_skipped' => $diag['meta']['candidates_skipped'] ?? null,
        'exact_model_fast_path' => $diag['meta']['routing_exact_model_fast_path'] ?? false,
        'attempts' => $diag['provider_attempts'],
    ];
};

$singleContext = $baseContext->with([
    'preferredModelId' => (int) $model->seoAiModelId,
    'requirePreferredModel' => true,
    'maxAiAttempts' => 1,
    'maxFreeAttempts' => 1,
]);
$single = [];
for ($i = 1; $i <= $singleRuns; $i++) {
    $single[] = $run('single_attempt', $i, $singleContext);
}

$production = $run('production_retry', null, $baseContext->with([
    'preferredModelId' => (int) $model->seoAiModelId,
    'requirePreferredModel' => false,
]));

$median = static function (array $rows, string $key): ?float {
    $values = [];
    foreach ($rows as $row) {
        if (is_numeric($row[$key] ?? null)) {
            $values[] = (float) $row[$key];
        }
    }
    if ($values === []) {
        return null;
    }
    sort($values);
    $middle = intdiv(count($values), 2);

    return count($values) % 2 === 1
        ? $values[$middle]
        : round(($values[$middle - 1] + $values[$middle]) / 2, 3);
};

$report = [
    'benchmark_model' => $model->model,
    'benchmark_model_id' => (int) $model->seoAiModelId,
    'prompt_sha256' => hash('sha256', $prompt),
    'single_attempt' => $single,
    'single_attempt_median_ms' => [
        'routing' => $median($single, 'routing_candidate_planning_ms'),
        'budget' => $median($single, 'budget_preflight_ms'),
        'adapter_prepare' => $median($single, 'provider_adapter_prepare_ms'),
        'outbound_budget_gate' => $median($single, 'outbound_budget_gate_ms'),
        'dns_guard' => $median($single, 'url_guard_dns_ms'),
        'http_roundtrip' => $median($single, 'http_roundtrip_ms'),
        'parse' => $median($single, 'response_parse_ms'),
        'candidate_execute_total' => $median($single, 'candidate_execute_total_ms'),
        'logical_total' => $median($single, 'logical_request_total_ms'),
    ],
    'production_retry' => $production,
];

$outPath = $clientRoot.'/storage/app/_seeding_comment_model_latency_'.date('Ymd_His').'.json';
file_put_contents($outPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
fwrite(STDERR, "Wrote {$outPath}\n");

$allOk = array_reduce($single, static fn (bool $ok, array $row): bool => $ok && $row['ok'], true)
    && $production['ok'];
exit($allOk ? 0 : 2);
