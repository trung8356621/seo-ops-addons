<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Http\Controllers\ServiceApi\Runner;

use App\Api\Middleware\AuthenticateServiceApi;
use App\Api\Services\ServiceApiContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Omnichannel\Addons\SocialAutomation\Artifacts\ArtifactAcceptPolicy;
use Omnichannel\Addons\SocialAutomation\Models\SaAgent;
use Omnichannel\Addons\SocialAutomation\Models\SaArtifact;
use Omnichannel\Addons\SocialAutomation\Models\SaJob;
use Omnichannel\Addons\SocialAutomation\Orchestration\JobOrchestrator;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationApiStatus;
use Omnichannel\Addons\SocialAutomation\Support\SocialAutomationSchema;

final class RunnerJobController extends Controller
{
    public function __construct(
        private readonly JobOrchestrator $orchestrator,
        private readonly ArtifactAcceptPolicy $artifacts = new ArtifactAcceptPolicy,
    ) {}

    public function claim(Request $request, string $service, string $agentUuid, string $jobUuid): JsonResponse
    {
        $result = $this->orchestrator->claim($agentUuid, $jobUuid, $this->credentialId($request));

        return response()->json($result, SocialAutomationApiStatus::fromResult(
            (bool) ($result['ok'] ?? false),
            $result['errors'] ?? []
        ));
    }

    public function acknowledge(Request $request, string $service, string $agentUuid, string $jobUuid): JsonResponse
    {
        $result = $this->orchestrator->acknowledge($agentUuid, $jobUuid, $request->all(), $this->credentialId($request));

        return response()->json($result, SocialAutomationApiStatus::fromResult(
            (bool) ($result['ok'] ?? false),
            $result['errors'] ?? []
        ));
    }

    public function progress(Request $request, string $service, string $agentUuid, string $jobUuid): JsonResponse
    {
        $result = $this->orchestrator->progress($agentUuid, $jobUuid, $request->all(), $this->credentialId($request));

        return response()->json($result, SocialAutomationApiStatus::fromResult(
            (bool) ($result['ok'] ?? false),
            $result['errors'] ?? []
        ));
    }

    public function result(Request $request, string $service, string $agentUuid, string $jobUuid): JsonResponse
    {
        $result = $this->orchestrator->complete($agentUuid, $jobUuid, $request->all(), $this->credentialId($request));

        return response()->json($result, SocialAutomationApiStatus::fromResult(
            (bool) ($result['ok'] ?? false),
            $result['errors'] ?? []
        ));
    }

    public function uploadArtifact(Request $request, string $service, string $agentUuid, string $jobUuid): JsonResponse
    {
        if (! SocialAutomationSchema::has('sa_artifacts')) {
            return response()->json(['ok' => false, 'errors' => ['schema_unavailable']], 503);
        }

        $credentialId = $this->credentialId($request);
        $agent = SaAgent::query()->where('agent_uuid', $agentUuid)->first();
        if (! $agent instanceof SaAgent) {
            return response()->json(['ok' => false, 'errors' => ['agent_not_found']], 404);
        }
        if ($credentialId !== null && $agent->credential_id !== null
            && (int) $agent->credential_id !== $credentialId) {
            return response()->json(['ok' => false, 'errors' => ['credential_mismatch']], 403);
        }

        $job = SaJob::query()->where('job_uuid', $jobUuid)->where('agent_id', $agent->id)->first();
        if (! $job instanceof SaJob) {
            return response()->json(['ok' => false, 'errors' => ['job_not_found_or_forbidden']], 404);
        }

        $attemptUuid = trim((string) $request->input('attempt_uuid', ''));
        $active = is_array($job->payload) ? ($job->payload['_fence']['attempt_uuid'] ?? null) : null;
        if ($attemptUuid === '' || $active === null || ! hash_equals((string) $active, $attemptUuid)) {
            return response()->json(
                ['ok' => false, 'errors' => ['stale_attempt']],
                SocialAutomationApiStatus::fromResult(false, ['stale_attempt'])
            );
        }

        $mime = strtolower((string) $request->input('mime', 'application/octet-stream'));
        $kind = strtolower((string) $request->input('kind', 'other'));
        $b64 = (string) $request->input('content_base64', '');
        $binary = $b64 !== '' ? base64_decode($b64, true) : false;
        if ($binary === false) {
            return response()->json(['ok' => false, 'errors' => ['content_invalid']], 422);
        }
        $meta = is_array($request->input('metadata')) ? $request->input('metadata') : [];
        $meta['attempt_uuid'] = $attemptUuid;
        $check = $this->artifacts->validate($mime, strlen($binary), $meta);
        if (! ($check['ok'] ?? false)) {
            return response()->json(['ok' => false, 'errors' => $check['errors']], 422);
        }

        if ($kind === 'screenshot') {
            $outcome = strtolower((string) ($request->input('outcome', $meta['outcome'] ?? 'success')));
            $shot = $this->artifacts->validateScreenshotMetadata($outcome, $meta);
            if (! ($shot['ok'] ?? false)) {
                return response()->json(['ok' => false, 'errors' => $shot['errors']], 422);
            }
            if (($shot['phase'] ?? null) !== null) {
                $meta['phase'] = $shot['phase'];
            }
        }

        $checksum = hash('sha256', $binary);
        $existing = SaArtifact::query()
            ->where('job_id', $job->id)
            ->where('checksum_sha256', $checksum)
            ->first();
        if ($existing instanceof SaArtifact
            && $this->artifacts->isDuplicateChecksum($existing->checksum_sha256, $checksum)) {
            return response()->json([
                'ok' => true,
                'idempotent' => true,
                'artifact' => [
                    'artifact_uuid' => $existing->artifact_uuid,
                    'kind' => $existing->kind,
                    'size_bytes' => $existing->size_bytes,
                    'checksum_sha256' => $existing->checksum_sha256,
                ],
            ]);
        }

        $path = 'social-automation/artifacts/'.$job->job_uuid.'/'.(string) Str::uuid();
        Storage::disk('local')->put($path, $binary);

        $artifact = SaArtifact::query()->create([
            'artifact_uuid' => (string) Str::uuid(),
            'job_id' => $job->id,
            'kind' => in_array($kind, ['screenshot', 'video', 'log', 'other'], true) ? $kind : 'other',
            'mime' => $mime,
            'size_bytes' => strlen($binary),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'checksum_sha256' => $checksum,
            'metadata' => $meta,
            'retain_until' => now()->addDays(30),
        ]);

        return response()->json([
            'ok' => true,
            'artifact' => [
                'artifact_uuid' => $artifact->artifact_uuid,
                'kind' => $artifact->kind,
                'size_bytes' => $artifact->size_bytes,
                'checksum_sha256' => $artifact->checksum_sha256,
            ],
        ], 201);
    }

    private function credentialId(Request $request): ?int
    {
        $ctx = $request->attributes->get(AuthenticateServiceApi::REQUEST_CONTEXT_KEY);

        return $ctx instanceof ServiceApiContext ? $ctx->credentialId() : null;
    }
}
