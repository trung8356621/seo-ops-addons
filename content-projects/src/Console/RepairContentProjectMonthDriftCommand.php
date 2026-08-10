<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource;
use Omnichannel\Addons\ContentProjects\Models\SeoProject;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Conservative one-off repair cho `seo_projects.month` bị lệch (vd. project 6/2026 từng bị
 * `SeoProjectTaskMoveService::deleteProjectRollingBackToPreviousMonth` rollback nhầm về
 * tháng trước khi project chỉ còn task đã archive — xem `SeoProjectTaskMoveService::deleteProject`
 * đã fix hành vi này). Lệnh CHỈ set lại cột `month` (và `name` nếu đang theo pattern mặc định
 * "project m/Y"), KHÔNG đụng vào task/target_date/run history.
 *
 * Không tự đoán hàng loạt: chỉ chạy khi có `--project` cụ thể, hoặc `--from`/`--to` được truyền
 * rõ ràng (mặc định trỏ đúng sự cố 4/2026 → 6/2026 đang cần dọn).
 */
final class RepairContentProjectMonthDriftCommand extends Command
{
    protected $signature = 'seo:repair-content-project-month-drift
        {--project= : Chỉ sửa một project theo ID}
        {--dry-run : Chỉ in kế hoạch, không ghi DB}
        {--from=4/2026 : Tháng hiện đang sai (định dạng m/Y)}
        {--to=6/2026 : Tháng đúng cần khôi phục (định dạng m/Y)}';

    protected $description = 'Khôi phục cột month cho content project bị lệch tháng do bug rollback xóa project (cũ).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $projectId = (int) ($this->option('project') ?? 0);

        $from = $this->parseMonthOption((string) $this->option('from'));
        $to = $this->parseMonthOption((string) $this->option('to'));

        if (! $from instanceof Carbon || ! $to instanceof Carbon) {
            $this->error('Tùy chọn --from/--to phải theo định dạng m/Y (ví dụ 4/2026).');

            return self::FAILURE;
        }

        if ($from->equalTo($to)) {
            $this->error('--from và --to giống nhau, không có gì để sửa.');

            return self::FAILURE;
        }

        $query = SeoProject::query()
            ->where(function ($builder): void {
                $builder->where('kind', SeoProject::KIND_MONTHLY)->orWhereNull('kind');
            })
            ->whereDate('month', $from->format('Y-m-d'));

        if ($projectId > 0) {
            $query->whereKey($projectId);
        }

        $projects = $query->orderBy('id')->get();

        if ($projects->isEmpty()) {
            $this->warn(sprintf(
                'Không tìm thấy project%s có month = %s.',
                $projectId > 0 ? " #{$projectId}" : '',
                $from->format('m/Y'),
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d project: %s → %s',
            $dryRun ? 'DRY-RUN — sẽ khôi phục' : 'Khôi phục',
            $projects->count(),
            $from->format('m/Y'),
            $to->format('m/Y'),
        ));

        $repaired = 0;
        $skipped = 0;

        foreach ($projects as $project) {
            if (! $project instanceof SeoProject) {
                continue;
            }

            $siteId = (int) ($project->site_id ?? 0);
            $collides = $siteId > 0 && SeoProjectResource::monthlyProjectExistsForSiteMonth(
                $siteId,
                $to->format('Y-m-d'),
                (int) $project->getKey(),
            );

            if ($collides) {
                $skipped++;
                $this->warn(sprintf(
                    '[skip] project #%d (%s) — đã có project khác cùng site_id=%d ở tháng %s.',
                    (int) $project->getKey(),
                    (string) $project->name,
                    $siteId,
                    $to->format('m/Y'),
                ));

                continue;
            }

            $oldName = (string) $project->name;
            $newName = $oldName === SeoProject::defaultNameFromMonth($from)
                ? SeoProject::defaultNameFromMonth($to)
                : $oldName;

            $this->line(sprintf(
                'project #%d: month %s → %s%s',
                (int) $project->getKey(),
                $from->format('m/Y'),
                $to->format('m/Y'),
                $newName !== $oldName ? sprintf(', name "%s" → "%s"', $oldName, $newName) : '',
            ));

            if ($dryRun) {
                $repaired++;

                continue;
            }

            $project->forceFill([
                'month' => $to->format('Y-m-d'),
                'name' => $newName,
            ])->save();

            $repaired++;
        }

        $this->newLine();
        $this->table(['Metric', 'Count'], [
            ['matched', (string) $projects->count()],
            ['repaired', $dryRun ? '0' : (string) $repaired],
            ['planned', $dryRun ? (string) $repaired : '0'],
            ['skipped_collision', (string) $skipped],
        ]);

        return self::SUCCESS;
    }

    private function parseMonthOption(string $value): ?Carbon
    {
        $value = trim($value);
        if (preg_match('/^(\d{1,2})\/(\d{4})$/', $value, $matches) !== 1) {
            return null;
        }

        $month = (int) $matches[1];
        $year = (int) $matches[2];
        if ($month < 1 || $month > 12) {
            return null;
        }

        return Carbon::create($year, $month, 1)->startOfMonth();
    }
}
