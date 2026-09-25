<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\Seeding\Services\SeedingReportService;
use Omnichannel\Addons\Seeding\Support\SeedingAccess;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Manager-only read/approve APIs for seeding_reports (inspection — no Seeder mutate).
 */
final class SeedingManagerReportsController extends Controller
{
    public function __construct(
        private readonly SeedingReportService $reports,
        private readonly SeedingAccess $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertCanManage();

        $filters = [
            'user_id' => $request->query('user_id'),
            'topic_id' => $request->query('topic_id'),
            'social' => $request->query('social'),
            'search' => $request->query('search'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'status' => $request->query('status'),
        ];

        $payload = $this->reports->listForManager($filters);

        return response()->json([
            'ok' => true,
            'reports' => $payload['reports'],
            'members' => $payload['members'],
        ]);
    }

    public function proof(int $reportId): SymfonyResponse
    {
        $this->access->assertCanManage();

        return $this->reports->streamProofForManager($reportId);
    }

    public function approve(Request $request, int $reportId): JsonResponse
    {
        $this->access->assertCanManage();
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $report = $this->reports->approveForManager($reportId, (int) $user->id);

        return response()->json([
            'ok' => true,
            'report' => $report,
            'message' => 'Đã duyệt báo cáo',
        ]);
    }
}
