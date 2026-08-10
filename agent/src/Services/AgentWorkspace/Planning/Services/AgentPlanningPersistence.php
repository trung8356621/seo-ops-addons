<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Services;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentConversation;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentPlanningRun;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data\AgentPlanningResponse;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Security\AgentPlanningInputSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Throwable;

final class AgentPlanningPersistence
{
    public function __construct(
        private readonly AgentPlanningInputSanitizer $sanitizer = new AgentPlanningInputSanitizer,
    ) {}

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function startRun(SeoAgentConversation $conversation, array $attrs = []): ?SeoAgentPlanningRun
    {
        try {
            return SeoAgentPlanningRun::query()->create(array_merge([
                'conversation_id' => $conversation->id,
                'planning_request_id' => 'aplanreq_'.Str::lower((string) Str::ulid()),
                'status' => 'running',
                'started_at' => Carbon::now(),
            ], $attrs));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    public function finishRun(?SeoAgentPlanningRun $run, array $attrs): void
    {
        if ($run === null) {
            return;
        }

        try {
            if (isset($attrs['structured_response']) && is_array($attrs['structured_response'])) {
                $attrs['structured_response'] = $this->sanitizer->sanitize($attrs['structured_response']);
            }
            $attrs['finished_at'] = Carbon::now();
            $run->fill($attrs);
            $run->save();
        } catch (Throwable) {
            // Persistence failure must not block chat.
        }
    }

    public function updateConversationSummary(
        SeoAgentConversation $conversation,
        string $summary,
        int $version,
        ?int $untilMessageId,
    ): void {
        try {
            $conversation->summary = $summary;
            $conversation->summary_version = $version;
            $conversation->summary_until_message_id = $untilMessageId;
            $conversation->summary_updated_at = Carbon::now();
            $conversation->save();
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnosticsPayload(?SeoAgentPlanningRun $run): array
    {
        if ($run === null) {
            return [];
        }

        return [
            'planning_request_id' => $run->planning_request_id,
            'status' => $run->status,
            'response_type' => $run->response_type,
            'provider' => $run->provider,
            'model' => $run->model,
            'routing_reason' => $run->routing_reason,
            'input_token_estimate' => $run->input_token_estimate,
            'output_token_estimate' => $run->output_token_estimate,
            'confidence' => $run->confidence,
            'adjusted_confidence' => $run->adjusted_confidence,
            'prompt_fingerprint' => $run->prompt_fingerprint,
            'context_manifest' => $run->context_manifest,
            'validation_errors' => $run->validation_errors,
            'repair_actions' => $run->repair_actions,
            'latency_ms' => $run->latency_ms,
            'error_category' => $run->error_category,
        ];
    }

    /**
     * Redact response before persist — drop oversized raw input blobs.
     *
     * @return array<string, mixed>
     */
    public function redactResponse(AgentPlanningResponse $response): array
    {
        return $this->sanitizer->sanitize($response->toArray());
    }
}
