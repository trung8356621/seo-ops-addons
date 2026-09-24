<?php

declare(strict_types=1);

/**
 * Diagnose seeding.comment.generate routing eligibility against live DB.
 * php addons/seeding/bin/diagnose-comment-routing.php
 */

use App\Models\User;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Services\AiCandidatePlanner;
use Omnichannel\Addons\AiPrompt\Services\AiModelPriorityService;
use Omnichannel\Addons\AiPrompt\Services\AiRoutingTargetService;
use Omnichannel\Addons\AiPrompt\Services\PromptExecutionProfileResolver;
use Omnichannel\Addons\AiPrompt\Services\PromptRoutingPolicyResolver;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;
use Omnichannel\Addons\AiPrompt\Support\AiRoutingPolicy;
use Omnichannel\Addons\Seeding\Services\SeedingSharedCommentPromptResolver;
use Omnichannel\Addons\Seeding\System\SeedingCommentGenerateCapabilityHandler;

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
    fwrite(STDERR, "Cannot locate omnichannel-client\n");
    exit(1);
}

require $clientRoot.'/vendor/autoload.php';
$app = require $clientRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = User::query()->whereIn('role', [User::ROLE_OWNER, User::ROLE_ADMIN])->orderBy('id')->first();
if (! $user instanceof User) {
    fwrite(STDERR, "no owner/admin\n");
    exit(1);
}
auth()->login($user);
$uid = (int) $user->id;

$shared = app(SeedingSharedCommentPromptResolver::class)->resolveActive();
$prompt = \Omnichannel\Addons\AiPrompt\Models\SeoPrompt::query()->find($shared['prompt_id']);
$profile = (new PromptExecutionProfileResolver())->resolve($prompt, SeedingCommentGenerateCapabilityHandler::KEY);
$policy = (new PromptRoutingPolicyResolver())->resolve($prompt, SeedingCommentGenerateCapabilityHandler::KEY);

$prio = app(AiModelPriorityService::class);
$targets = app(AiRoutingTargetService::class);

$fastModels = $prio->effectiveAreaModels($uid, AiModelArea::TextFast);
$freeModels = $prio->effectiveAreaModels($uid, AiModelArea::FreeModels);

$ctx = new AiRoutingContext(
    userId: $uid,
    hookKey: SeedingCommentGenerateCapabilityHandler::KEY,
    routingPolicy: $policy,
    routingPolicyEffective: $policy,
);

$eligible = $targets->eligibleCandidates($uid, $profile, $ctx);
$paid = $targets->paidAreaCandidates($uid, $profile, $ctx);
$freeCands = $targets->freeModelsCandidates($uid, $profile, $ctx);

$planner = new AiCandidatePlanner();
[$plan, $ordered] = $planner->plan(
    profile: $profile->value,
    context: $ctx,
    candidates: $eligible,
    maxAiAttempts: 6,
    maxFreeAttempts: 3,
    healthSkipReason: static fn ($c) => null,
    modelArea: $profile->value,
    secondaryCandidates: $paid,
    primaryArea: $profile->value,
    secondaryArea: AiModelArea::TextFast->value,
);

$report = [
    'user_id' => $uid,
    'prompt_id' => $shared['prompt_id'],
    'prompt_routing_profile_key' => $prompt?->routing_profile_key ?? null,
    'prompt_routing_policy' => $prompt?->routing_policy ?? null,
    'resolved_profile' => $profile->value,
    'resolved_policy' => $policy->value,
    'text_fast_membership' => array_map(static fn ($m) => [
        'id' => (int) $m->id,
        'model' => (string) $m->raw_model_name,
        'priority' => $m->effective_area_priority ?? null,
        'is_free' => (bool) ($m->is_free ?? false),
        'connection_id' => (int) $m->api_connection_id,
    ], $fastModels),
    'free_models_membership_count' => count($freeModels),
    'free_candidates' => array_map(static fn ($c) => [
        'model' => $c->model,
        'is_free' => $c->isFree,
        'priority' => $c->priority,
    ], $freeCands),
    'paid_candidates' => array_map(static fn ($c) => [
        'model' => $c->model,
        'is_free' => $c->isFree,
        'priority' => $c->priority,
    ], $paid),
    'eligible_candidates' => array_map(static fn ($c) => [
        'model' => $c->model,
        'is_free' => $c->isFree,
        'priority' => $c->priority,
    ], $eligible),
    'eligibility_diagnostics' => $targets->lastEligibilityDiagnostics(),
    'plan_path' => $plan->path ?? null,
    'plan_attemptable_count' => count($ordered),
    'plan_first' => isset($ordered[0]) ? [
        'model' => $ordered[0]->model,
        'is_free' => $ordered[0]->isFree,
    ] : null,
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
