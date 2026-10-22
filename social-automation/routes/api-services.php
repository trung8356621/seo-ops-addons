<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Admin\ManualDispatchController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Admin\TaskAdminController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Admin\WidgetInputController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Admin\WorkflowComposeController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\PackageRegistryController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Runner\RunnerAgentController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Runner\RunnerJobController;
use Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Runner\RunnerSessionController;
use Omnichannel\Addons\SocialAutomation\Http\Middleware\EnsureSocialAutomationServiceApi;

/*
| Social Automation Service API
| Auth: service_api_credentials only — never ops-server Service secret.
|
| Admin scopes:
|   social-automation:package:read|publish
|   social-automation:admin:dispatch
|   social-automation:admin:task   (also widgets/workflows compose)
|
| Runner scopes (minted via Admin Service API Access — see RunnerEnrollmentContract):
|   social-automation:runner:agent
|   social-automation:runner:session
|   social-automation:runner:job
*/

if (! class_exists(ManualDispatchController::class) || ! class_exists(EnsureSocialAutomationServiceApi::class)) {
    return;
}

Route::middleware([
    EnsureSocialAutomationServiceApi::class,
    'service.api.scope:social-automation:package:read',
])->group(function (): void {
    Route::get('{service}/social-automation/packages', [PackageRegistryController::class, 'index'])
        ->name('api.v1.services.social-automation.packages.index');
    Route::get('{service}/social-automation/packages/{packageKey}', [PackageRegistryController::class, 'show'])
        ->where('packageKey', '[A-Za-z0-9._-]+')
        ->name('api.v1.services.social-automation.packages.show');
});

Route::middleware([
    EnsureSocialAutomationServiceApi::class,
    'service.api.scope:social-automation:package:publish',
])->group(function (): void {
    Route::post('{service}/social-automation/packages/publish', [PackageRegistryController::class, 'publish'])
        ->name('api.v1.services.social-automation.packages.publish');
});

Route::middleware([
    EnsureSocialAutomationServiceApi::class,
    'service.api.scope:social-automation:admin:task',
])->group(function (): void {
    Route::get('{service}/social-automation/tasks', [TaskAdminController::class, 'index'])
        ->name('api.v1.services.social-automation.tasks.index');
    Route::post('{service}/social-automation/tasks', [TaskAdminController::class, 'store'])
        ->name('api.v1.services.social-automation.tasks.store');
    Route::post('{service}/social-automation/schedules', [TaskAdminController::class, 'storeSchedule'])
        ->name('api.v1.services.social-automation.schedules.store');
    Route::post('{service}/social-automation/widgets/compose-input', [WidgetInputController::class, 'compose'])
        ->name('api.v1.services.social-automation.widgets.compose-input');
    Route::post('{service}/social-automation/workflows/compose-preview', [WorkflowComposeController::class, 'preview'])
        ->name('api.v1.services.social-automation.workflows.compose-preview');
});

Route::middleware([
    EnsureSocialAutomationServiceApi::class,
    'service.api.scope:social-automation:admin:dispatch',
])->group(function (): void {
    Route::post('{service}/social-automation/dispatch/manual', ManualDispatchController::class)
        ->name('api.v1.services.social-automation.dispatch.manual');
});

Route::middleware([
    EnsureSocialAutomationServiceApi::class,
    'service.api.scope:social-automation:runner:agent',
])->group(function (): void {
    Route::post('{service}/social-automation/runner/register', [RunnerAgentController::class, 'register'])
        ->name('api.v1.services.social-automation.runner.register');
    Route::post('{service}/social-automation/runner/agents/{agentUuid}/heartbeat', [RunnerAgentController::class, 'heartbeat'])
        ->where('agentUuid', '[A-Za-z0-9-]+')
        ->name('api.v1.services.social-automation.runner.heartbeat');
});

Route::middleware([
    EnsureSocialAutomationServiceApi::class,
    'service.api.scope:social-automation:runner:session',
])->group(function (): void {
    Route::post('{service}/social-automation/runner/agents/{agentUuid}/sessions/sync', [RunnerSessionController::class, 'sync'])
        ->where('agentUuid', '[A-Za-z0-9-]+')
        ->name('api.v1.services.social-automation.runner.sessions.sync');
});

Route::middleware([
    EnsureSocialAutomationServiceApi::class,
    'service.api.scope:social-automation:runner:job',
])->group(function (): void {
    Route::post('{service}/social-automation/runner/agents/{agentUuid}/jobs/{jobUuid}/claim', [RunnerJobController::class, 'claim'])
        ->where(['agentUuid' => '[A-Za-z0-9-]+', 'jobUuid' => '[A-Za-z0-9-]+'])
        ->name('api.v1.services.social-automation.runner.jobs.claim');
    Route::post('{service}/social-automation/runner/agents/{agentUuid}/jobs/{jobUuid}/ack', [RunnerJobController::class, 'acknowledge'])
        ->where(['agentUuid' => '[A-Za-z0-9-]+', 'jobUuid' => '[A-Za-z0-9-]+'])
        ->name('api.v1.services.social-automation.runner.jobs.ack');
    Route::post('{service}/social-automation/runner/agents/{agentUuid}/jobs/{jobUuid}/progress', [RunnerJobController::class, 'progress'])
        ->where(['agentUuid' => '[A-Za-z0-9-]+', 'jobUuid' => '[A-Za-z0-9-]+'])
        ->name('api.v1.services.social-automation.runner.jobs.progress');
    Route::post('{service}/social-automation/runner/agents/{agentUuid}/jobs/{jobUuid}/result', [RunnerJobController::class, 'result'])
        ->where(['agentUuid' => '[A-Za-z0-9-]+', 'jobUuid' => '[A-Za-z0-9-]+'])
        ->name('api.v1.services.social-automation.runner.jobs.result');
    Route::post('{service}/social-automation/runner/agents/{agentUuid}/jobs/{jobUuid}/artifacts', [RunnerJobController::class, 'uploadArtifact'])
        ->where(['agentUuid' => '[A-Za-z0-9-]+', 'jobUuid' => '[A-Za-z0-9-]+'])
        ->name('api.v1.services.social-automation.runner.jobs.artifacts');
});
