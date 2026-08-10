<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Application\Handlers;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommand;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Contracts\ContentProjectCommandHandler;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ActivateSiteSyncV2Command;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\EnterSiteSyncShadowModeCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ExecuteSiteSyncRepairCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\ExitSiteSyncShadowModeCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\GenerateSiteSyncComparisonReportCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewSiteSyncCutoverCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\PreviewSiteSyncRepairCommand;
use Omnichannel\Addons\SiteSync\Services\Application\Commands\RollbackSiteSyncToLegacyCommand;
use Omnichannel\Addons\SiteSync\Services\Comparison\SiteSyncComparisonExportService;
use Omnichannel\Addons\SiteSync\Services\Comparison\SiteSyncComparisonService;
use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverModes;
use Omnichannel\Addons\SiteSync\Services\Cutover\SiteSyncCutoverStateService;
use Omnichannel\Addons\SiteSync\Services\Repair\SiteSyncRepairPlanner;
use App\Models\Site;

/**
 * Cutover / comparison / repair command handler (Wave 4).
 */
final class SiteSyncCutoverCommandHandler implements ContentProjectCommandHandler
{
    public function __construct() {}

    private function cutover(): SiteSyncCutoverStateService
    {
        return app(SiteSyncCutoverStateService::class);
    }

    private function comparison(): SiteSyncComparisonService
    {
        return app(SiteSyncComparisonService::class);
    }

    private function export(): SiteSyncComparisonExportService
    {
        return app(SiteSyncComparisonExportService::class);
    }

    private function repair(): SiteSyncRepairPlanner
    {
        return app(SiteSyncRepairPlanner::class);
    }

    public function handle(ContentProjectCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        return match (true) {
            $command instanceof PreviewSiteSyncCutoverCommand => $this->preview($command),
            $command instanceof EnterSiteSyncShadowModeCommand => $this->transition(
                $command->siteId,
                SiteSyncCutoverModes::V2_SHADOW,
                $actor,
                $command->reason,
                $command->confirmationToken,
            ),
            $command instanceof ExitSiteSyncShadowModeCommand => $this->transition(
                $command->siteId,
                SiteSyncCutoverModes::LEGACY_ACTIVE,
                $actor,
                $command->reason,
                null,
            ),
            $command instanceof ActivateSiteSyncV2Command => $this->transition(
                $command->siteId,
                SiteSyncCutoverModes::V2_ACTIVE,
                $actor,
                $command->reason,
                $command->confirmationToken,
            ),
            $command instanceof RollbackSiteSyncToLegacyCommand => $this->transition(
                $command->siteId,
                SiteSyncCutoverModes::LEGACY_ACTIVE,
                $actor,
                $command->reason,
                $command->confirmationToken,
            ),
            $command instanceof GenerateSiteSyncComparisonReportCommand => $this->compare($command),
            $command instanceof PreviewSiteSyncRepairCommand => $this->previewRepair($command),
            $command instanceof ExecuteSiteSyncRepairCommand => $this->executeRepair($command, $actor),
            default => ContentProjectActionResult::fail('site.cutover_unsupported', 'Unsupported cutover command.'),
        };
    }

    private function preview(PreviewSiteSyncCutoverCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->cutover()->preview($site);

        return ContentProjectActionResult::ok('site.cutover_preview_ok', (string) $result['message'], metadata: $result);
    }

    private function transition(
        int $siteId,
        string $to,
        ActorContext $actor,
        ?string $reason,
        ?string $confirmationToken,
    ): ContentProjectActionResult {
        $site = Site::query()->find($siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        if (! in_array($actor->actorType, ['user', 'system'], true)) {
            return ContentProjectActionResult::fail('site.cutover_forbidden', 'Cutover requires user/admin actor.');
        }

        $result = $this->cutover()->transition($site, $to, [
            'actor_type' => $actor->actorType,
            'actor_id' => $actor->actorId,
            'reason' => $reason,
            'confirmation_token' => $confirmationToken,
        ]);

        if (! ($result['success'] ?? false)) {
            return ContentProjectActionResult::fail(
                (string) ($result['code'] ?? 'site.cutover_failed'),
                (string) ($result['message'] ?? 'failed'),
                metadata: $result,
            );
        }

        return ContentProjectActionResult::ok('site.cutover_ok', (string) $result['message'], metadata: $result);
    }

    private function compare(GenerateSiteSyncComparisonReportCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->comparison()->compare($site, $command->scope);
        if (($result['success'] ?? false) && isset($result['run_id'])) {
            $export = $this->export()->exportCsv($site, (int) $result['run_id']);
            $result['export'] = $export;
        }

        return ContentProjectActionResult::ok('site.comparison_ok', (string) ($result['message'] ?? 'ok'), metadata: $result);
    }

    private function previewRepair(PreviewSiteSyncRepairCommand $command): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        $result = $this->repair()->preview($site);

        return ($result['success'] ?? false)
            ? ContentProjectActionResult::ok('site.repair_preview_ok', (string) $result['message'], metadata: $result)
            : ContentProjectActionResult::fail('site.repair_disabled', (string) $result['message'], metadata: $result);
    }

    private function executeRepair(ExecuteSiteSyncRepairCommand $command, ActorContext $actor): ContentProjectActionResult
    {
        $site = Site::query()->find($command->siteId);
        if ($site === null) {
            return ContentProjectActionResult::fail('site.not_found', 'Site not found.');
        }
        if (! $command->dryRun && trim((string) ($command->confirmationToken ?? '')) === '' && count($command->selectedIds) > 5) {
            return ContentProjectActionResult::fail('site.confirmation_required', 'Confirmation token required for large repair.');
        }
        $result = $this->repair()->execute(
            $site,
            $command->planId,
            $command->selectedIds,
            $command->dryRun,
            $actor->actorId,
        );

        return ($result['success'] ?? false)
            ? ContentProjectActionResult::ok('site.repair_ok', (string) $result['message'], metadata: $result)
            : ContentProjectActionResult::fail('site.repair_failed', (string) $result['message'], metadata: $result);
    }
}
