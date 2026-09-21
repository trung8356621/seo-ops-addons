<?php

declare(strict_types=1);

$clientRoot = 'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.'omnichannel-client';
require $clientRoot.'/vendor/autoload.php';
$app = require $clientRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultSeedingCommentPromptInstaller;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

$ownerUser = User::query()->find(2) ?? User::query()->orderBy('id')->skip(1)->first() ?? User::query()->orderBy('id')->first();
auth()->login($ownerUser);

$installer = app(DefaultSeedingCommentPromptInstaller::class);
$managedOwner = $installer->managedPromptOwnerUserId();
$accountOwner = SeoAccessControl::accountSiteOwnerId();

$listQuery = PromptResource::getEloquentQuery();
$listTotal = (clone $listQuery)->count();
$listRow = (clone $listQuery)->where('id', 26)->first();
$hookRows = (clone $listQuery)->where('hook_key', DefaultSeedingCommentPromptInstaller::HOOK_KEY)->pluck('id')->all();

$raw26 = DB::connection('omi_seo_ai')->table('prompts')->where('id', 26)->first();
$allSeeding = DB::connection('omi_seo_ai')->table('prompts')
    ->where('hook_key', DefaultSeedingCommentPromptInstaller::HOOK_KEY)
    ->whereNull('deleted_at')
    ->get(['id', 'name', 'user_id', 'deleted_at']);

$binding = app(SeoCreateArticleSettingsService::class)
    ->getBoundPromptId(DefaultSeedingCommentPromptInstaller::HOOK_KEY);

// Internal/system prompts that must stay out of the managed owner list when owned elsewhere
$user1Only = DB::connection('omi_seo_ai')->table('prompts')
    ->where('user_id', 1)
    ->whereNull('deleted_at')
    ->pluck('id')
    ->all();
$leakedUser1 = (clone $listQuery)->whereIn('id', $user1Only)->pluck('id')->all();

$installResult = $installer->install();

echo json_encode([
    'PROMPT_LIST_VISIBLE' => $listRow !== null,
    'prompt_id' => $listRow !== null ? (int) $listRow->id : null,
    'hook_key' => $listRow?->hook_key,
    'list_total' => $listTotal,
    'auth_user_id' => (int) auth()->id(),
    'account_owner_id' => $accountOwner,
    'managed_owner_id' => $managedOwner,
    'raw_prompt_26_user_id' => $raw26->user_id ?? null,
    'raw_prompt_26_version' => $raw26->current_prompt_version_id ?? null,
    'binding_prompt_id' => $binding,
    'list_hook_prompt_ids' => $hookRows,
    'all_seeding_prompt_rows' => $allSeeding,
    'duplicate_seeding_count' => $allSeeding->count(),
    'user1_prompt_ids_leaked_into_list' => $leakedUser1,
    'idempotent_install' => $installResult,
    'can_edit_via_resource' => $listRow instanceof SeoPrompt
        ? PromptResource::canEdit($listRow)
        : false,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
