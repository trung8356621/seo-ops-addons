<?php

declare(strict_types=1);

/**
 * Live proof for shared Prompt SSOT on seeding.comment.generate.
 * 1) Ensure install
 * 2) Patch prompt with identifiable marker
 * 3) One SystemAi generation
 * 4) Restore original body
 */

$candidates = [
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
    fwrite(STDERR, "client root missing\n");
    exit(1);
}

require $clientRoot.'/vendor/autoload.php';
$app = require $clientRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\System\Ai\Contracts\SystemAiClient;
use App\System\Ai\Dto\AiExecutionRequest;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookDefinitionLoader;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookEditorCatalog;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\PromptBindingResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptVersionService;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;

$install = app(DefaultSeedingCommentPromptInstaller::class)->install();

$loader = new PromptHookDefinitionLoader(
    PromptHookDefinitionLoader::defaultV01Directory(),
    PromptHookDefinitionLoader::defaultPhase1Directory(),
);
$catalog = new PromptHookEditorCatalog(new PromptHookRuntimeRegistry($loader));
$visible = $catalog->isSettingsVisible(DefaultSeedingCommentPromptInstaller::HOOK_KEY);

/** @var SeoPrompt $prompt */
$prompt = app(PromptBindingResolver::class)->resolveSettingsHook(DefaultSeedingCommentPromptInstaller::HOOK_KEY);
$original = (string) $prompt->markdown_content;
$marker = 'SEEDING_SHARED_PROMPT_SMOKE_MARKER_'.date('YmdHis');
$smokeBody = $original."\n\n[TEST ONLY] Always include the exact token {$marker} somewhere in each comment.\n";

$prompt->markdown_content = $smokeBody;
$prompt->save();
$version = app(PromptVersionService::class)->ensureCurrentVersion($prompt->fresh());

$user = User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->orderBy('id')->first();
if (! $user instanceof User) {
    // restore before exit
    $prompt->markdown_content = $original;
    $prompt->save();
    fwrite(STDERR, json_encode(['error' => 'no_user'])."\n");
    exit(1);
}
auth()->login($user);

$started = microtime(true);
$error = null;
$resultPayload = [];

try {
    $ai = app(SystemAiClient::class)->execute(new AiExecutionRequest(
        capability: SeedingCommentGenerateCapabilityHandler::KEY,
        input: [
            'content' => 'Balo laptop chống sốc — live proof shared Prompt '.$marker,
            'social' => 'threads',
            'quantity' => 1,
            'source_type' => 'text',
        ],
        context: [
            'allow_domain_side_effects' => true,
            'stage' => 'seeding_comment_generate_shared_prompt_smoke',
        ],
        correlation: [
            'stage' => 'seeding_comment_generate_shared_prompt_smoke',
            'canonical_prompt_key' => SeedingCommentGenerateCapabilityHandler::KEY,
        ],
    ));

    $resultPayload = [
        'status' => $ai->status,
        'system_execution_id' => $ai->id,
        'error_code' => $ai->errorCode,
        'error_message' => $ai->errorMessage,
        'path' => $ai->output['path'] ?? null,
        'hook_key' => $ai->output['hook_key'] ?? null,
        'shared_prompt_key' => $ai->output['shared_prompt_key'] ?? null,
        'prompt_id' => $ai->output['prompt_id'] ?? null,
        'prompt_version_id' => $ai->output['prompt_version_id'] ?? null,
        'prompt_result_id' => $ai->output['prompt_result_id'] ?? null,
        'provider' => $ai->output['provider'] ?? null,
        'model' => $ai->output['model'] ?? null,
        'physical_route' => $ai->output['physical_route'] ?? null,
        'comments' => $ai->output['comments'] ?? [],
        'final_prompt_has_marker' => str_contains((string) ($ai->output['final_prompt'] ?? ''), $marker),
        'compiled_has_marker' => str_contains((string) ($ai->output['compiled_prompt'] ?? ''), $marker),
    ];

    if ($ai->status !== 'completed') {
        throw new RuntimeException((string) ($ai->errorMessage ?? 'failed'));
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// Restore production prompt body
$prompt->markdown_content = $original;
$prompt->save();
app(PromptVersionService::class)->ensureCurrentVersion($prompt->fresh());

$report = [
    'ok' => $error === null && ($resultPayload['status'] ?? null) === 'completed',
    'install' => $install,
    'catalog_visible' => $visible,
    'marker' => $marker,
    'prompt_id' => (int) $prompt->id,
    'prompt_version_id_before_restore' => $version?->id,
    'capability' => SeedingCommentGenerateCapabilityHandler::KEY,
    'result' => $resultPayload,
    'error_state' => $error,
    'restored_original' => true,
    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
    'note' => 'Old seeding_comment_prompt_settings not used for execution.',
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
exit($report['ok'] ? 0 : 2);
