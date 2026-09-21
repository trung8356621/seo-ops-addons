<?php

declare(strict_types=1);

$clientRoot = 'D:'.DIRECTORY_SEPARATOR.'work'.DIRECTORY_SEPARATOR.'omnichannel-client';
require $clientRoot.'/vendor/autoload.php';
$app = require $clientRoot.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Omnichannel\Addons\AiPrompt\Filament\Resources\PromptResource;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

$u2 = User::query()->find(2);
auth()->login($u2);

echo json_encode([
    'auth' => auth()->id(),
    'owner' => SeoAccessControl::accountSiteOwnerId(),
    'scope' => SeoAccessControl::shouldScopeToAccountOwner(),
    'list_count' => PromptResource::getEloquentQuery()->count(),
    'has_26' => PromptResource::getEloquentQuery()->where('id', 26)->exists(),
    'binding' => app(SeoCreateArticleSettingsService::class)->getBoundPromptId('seeding.comment.generate'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
