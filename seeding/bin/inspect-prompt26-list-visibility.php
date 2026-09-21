<?php

declare(strict_types=1);

$candidates = [
    'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.'omnichannel-client',
];
$clientRoot = $candidates[0];
require $clientRoot.'/vendor/autoload.php';
$app = require $clientRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

$row26 = DB::connection('omi_seo_ai')->table('prompts')->where('id', 26)->first();
$visibleSample = DB::connection('omi_seo_ai')->table('prompts')
    ->whereNull('deleted_at')
    ->orderBy('id')
    ->limit(3)
    ->get(['id', 'name', 'hook_key', 'user_id', 'is_active', 'current_prompt_version_id', 'deleted_at', 'created_at', 'updated_at']);

$userIds = DB::connection('omi_seo_ai')->table('prompts')
    ->whereNull('deleted_at')
    ->selectRaw('user_id, count(*) as c')
    ->groupBy('user_id')
    ->get();

$totalAll = DB::connection('omi_seo_ai')->table('prompts')->whereNull('deleted_at')->count();
$totalSoftDeleted = DB::connection('omi_seo_ai')->table('prompts')->whereNotNull('deleted_at')->count();

$ownerId = null;
try {
    $ownerId = SeoAccessControl::accountSiteOwnerId();
} catch (Throwable $e) {
    $ownerId = 'error: '.$e->getMessage();
}

// Simulate list query as owner of prompts if auth available
$authUser = \App\Models\User::query()->orderBy('id')->first();
if ($authUser) {
    auth()->login($authUser);
}
$listCount = null;
$listHas26 = null;
$listOwnerFilter = null;
try {
    $q = PromptResource::getEloquentQuery();
    $listCount = (clone $q)->count();
    $listHas26 = (clone $q)->where('id', 26)->exists();
    $sql = $q->toSql();
    $bindings = $q->getBindings();
} catch (Throwable $e) {
    $sql = $e->getMessage();
    $bindings = [];
}

$visibleOne = DB::connection('omi_seo_ai')->table('prompts')
    ->whereNull('deleted_at')
    ->where('id', '!=', 26)
    ->orderBy('id')
    ->first();

echo json_encode([
    'prompt_26' => $row26,
    'visible_samples' => $visibleSample,
    'user_id_counts' => $userIds,
    'total_not_deleted' => $totalAll,
    'soft_deleted' => $totalSoftDeleted,
    'account_site_owner_id' => $ownerId,
    'auth_user_id' => $authUser?->id,
    'list_count' => $listCount,
    'list_has_26' => $listHas26,
    'list_sql' => $sql ?? null,
    'list_bindings' => $bindings ?? null,
    'compare_visible' => $visibleOne,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
