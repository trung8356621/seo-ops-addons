<?php

declare(strict_types=1);

/**
 * One-shot live smoke for Seeding → SystemAiClient cutover.
 * Run: php addons/seeding/bin/smoke-system-ai-comment.php
 */

use App\Models\User;
use App\System\Ai\Contracts\SystemAiClient;
use Omnichannel\Addons\Seeding\Services\SeedingCommentGenerateService;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;

$candidates = [
    dirname(__DIR__, 3), // when resolved via client/addons junction
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

$userIdOverride = isset($argv[1]) ? (int) $argv[1] : 0;
$user = null;
if ($userIdOverride > 0) {
    $user = User::query()->find($userIdOverride);
} else {
    // Prefer a staff Seeder with empty personal AI Routing — proves owner fallback.
    $staff = User::query()->where('role', User::ROLE_STAFF)->orderByDesc('id')->first();
    if ($staff instanceof User) {
        $prio = app(\Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService::class);
        $fast = count($prio->effectiveAreaModels(
            (int) $staff->id,
            \Omnichannel\Addons\AiPrompt\Support\AiModelArea::TextFast,
        ));
        if ($fast === 0) {
            $user = $staff;
        }
    }
    if (! $user instanceof User) {
        $user = User::query()
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])
            ->orderBy('id')
            ->first();
    }
}

if (! $user instanceof User) {
    fwrite(STDERR, json_encode(['error' => 'no_usable_user'], JSON_UNESCAPED_UNICODE).PHP_EOL);
    exit(1);
}

auth()->login($user);

/** @var SystemAiClient $client */
$client = app(SystemAiClient::class);
/** @var SeedingCommentGenerateService $service */
$service = app(SeedingCommentGenerateService::class);

$payload = [
    'content' => 'Balo laptop chống sốc cho học sinh — smoke test System AI cutover '.date('c'),
    'social' => 'threads',
    'quantity' => 1,
    'source_type' => 'text',
];

$started = microtime(true);
$error = null;
$comments = [];
$systemExecutionId = null;
$outputMeta = [];

try {
    // Direct SystemAiClient call first — proves capability + capture execution id.
    $aiResult = $client->execute(new App\System\Ai\Dto\AiExecutionRequest(
        capability: SeedingCommentGenerateCapabilityHandler::KEY,
        input: $payload,
        context: [
            'allow_domain_side_effects' => true,
            'stage' => 'seeding_comment_generate_smoke',
        ],
        correlation: [
            'stage' => 'seeding_comment_generate_smoke',
            'canonical_prompt_key' => SeedingCommentGenerateCapabilityHandler::KEY,
        ],
    ));
    $systemExecutionId = $aiResult->id;
    $outputMeta = [
        'status' => $aiResult->status,
        'error_code' => $aiResult->errorCode,
        'error_message' => $aiResult->errorMessage,
        'path' => $aiResult->output['path'] ?? null,
        'provider' => $aiResult->output['provider'] ?? null,
        'model' => $aiResult->output['model'] ?? null,
        'physical_route' => $aiResult->output['physical_route'] ?? null,
        'hook_key' => $aiResult->output['hook_key'] ?? null,
        'comments_count' => is_array($aiResult->output['comments'] ?? null)
            ? count($aiResult->output['comments'])
            : 0,
        'prompt_result_id' => $aiResult->output['prompt_result_id'] ?? null,
        'raw_output_preview' => isset($aiResult->output['raw_output'])
            ? mb_substr((string) $aiResult->output['raw_output'], 0, 500)
            : null,
    ];

    if ($aiResult->status !== 'completed') {
        throw new RuntimeException(
            'System AI failed: '.trim((string) ($aiResult->errorMessage ?? $aiResult->errorCode ?? 'unknown')),
        );
    }

    $comments = $aiResult->output['comments'] ?? [];
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$report = [
    'ok' => $error === null && is_array($comments) && $comments !== [],
    'capability' => SeedingCommentGenerateCapabilityHandler::KEY,
    'system_execution_id' => $systemExecutionId,
    'prompt_result_id' => $outputMeta['prompt_result_id'] ?? null,
    'provider' => $outputMeta['provider'] ?? null,
    'model' => $outputMeta['model'] ?? null,
    'physical_route' => $outputMeta['physical_route'] ?? null,
    'path' => $outputMeta['path'] ?? null,
    'hook_key' => $outputMeta['hook_key'] ?? null,
    'status' => $outputMeta['status'] ?? null,
    'error_state' => $error ?? ($outputMeta['error_message'] ?? null),
    'comments' => $comments,
    'raw_output_preview' => $outputMeta['raw_output_preview'] ?? null,
    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
    'user_id' => $user->id,
    'service_class' => $service::class,
    'client_class' => $client::class,
    'note' => 'PromptResult N/A for atomic AiTextExecutionPort path; System execution envelope is SoT.',
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;

exit($report['ok'] ? 0 : 2);
