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

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 409);
    }

    public function acknowledge(Request $request, string $service, string $agentUuid, string $jobUuid): JsonResponse
    {
        $result = $this->orchestrator->acknowledge($agentUuid, $jobUuid, $request->all(), $this->credentialId($request));

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 409);
    }

    public function progress(Request $request, string $service, string $agentUuid, string $jobUuid): JsonResponse
    {
        $result = $this->orchestrator->progress($agentUuid, $jobUuid, $request->all(), $this->credentialId($request));

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 409);
    }

    public function result(Request $request, string $service, string $agentUuid, string $jobUuid): JsonResponse
    {
        $result = $this->orchestrator->complete($agentUuid, $jobUuid, $request->all(), $this->credentialId($request));

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 409);
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

        $mime = strtolower((string) $request->input('mime', 'application/octet-stream'));
        $kind = strtolower((string) $request->input('kind', 'other'));
        $b64 = (string) $request->input('content_base64', '');
        $binary = $b64 !== '' ? base64_decode($b64, true) : false;
        if ($binary === false) {
            return response()->json(['ok' => false, 'errors' => ['content_invalid']], 422);
        }
        $meta = is_array($request->input('metadata')) ? $request->input('metadata') : [];
        $check = $this->artifacts->validate($mime, strlen($binary), $meta);
        if (! ($check['ok'] ?? false)) {
            return response()->json(['ok' => false, 'errors' => $check['errors']], 422);
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
            'checksum_sha256' => hash('sha256', $binary),
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
