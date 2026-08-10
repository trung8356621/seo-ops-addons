<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability;

use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentFeedback;
use Omnichannel\Addons\Agent\Models\AgentWorkspace\SeoAgentReview;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Dtos\AgentWorkspaceContext;
use Illuminate\Support\Str;

final class AgentReviewService
{
    /** @var list<string> */
    public const NEGATIVE_REASONS = [
        'wrong_skill',
        'wrong_data',
        'missing_context',
        'poor_plan',
        'unclear_result',
        'unsafe',
        'other',
    ];

    public function __construct(
        private readonly ?AgentObservabilityEventBus $bus = null,
        private readonly AgentObservabilityRedactor $redactor = new AgentObservabilityRedactor,
    ) {}

    /**
     * @param  array{code: string, severity: string, action: string}  $violation
     */
    public function createFromPolicy(array $violation, ?string $traceId, ?int $siteId): SeoAgentReview
    {
        $review = SeoAgentReview::query()->create([
            'hash_id' => 'arev_'.Str::lower((string) Str::ulid()),
            'trace_id' => $traceId,
            'site_id' => $siteId,
            'reason' => 'policy_violation',
            'severity' => $violation['severity'],
            'status' => 'open',
            'payload' => $this->redactor->redact($violation),
        ]);

        $this->bus?->dispatch([
            'event_type' => 'review.created',
            'trace_id' => $traceId ?? 'none',
            'attributes' => ['reason' => 'policy_violation', 'severity' => $violation['severity']],
            'severity' => $violation['severity'],
        ]);

        return $review;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(
        string $reason,
        string $severity,
        array $payload,
        ?string $traceId = null,
        ?int $siteId = null,
        ?int $createdBy = null,
    ): SeoAgentReview {
        return SeoAgentReview::query()->create([
            'hash_id' => 'arev_'.Str::lower((string) Str::ulid()),
            'trace_id' => $traceId,
            'site_id' => $siteId,
            'reason' => $reason,
            'severity' => $severity,
            'status' => 'open',
            'payload' => $this->redactor->redact($payload),
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpen(?int $siteId = null, int $limit = 50): array
    {
        $q = SeoAgentReview::query()->where('status', 'open')->orderByDesc('created_at')->limit($limit);
        if ($siteId !== null) {
            $q->where('site_id', $siteId);
        }

        return $q->get()->map(static fn (SeoAgentReview $r): array => [
            'hash_id' => $r->hash_id,
            'trace_id' => $r->trace_id,
            'reason' => $r->reason,
            'severity' => $r->severity,
            'status' => $r->status,
            'payload' => $r->payload,
            'assigned_to' => $r->assigned_to,
            'created_at' => optional($r->created_at)?->toIso8601String(),
        ])->all();
    }

    /**
     * Review actions never mutate business data.
     *
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function resolve(AgentWorkspaceContext $context, string $reviewHashId, array $action): array
    {
        $review = SeoAgentReview::query()->where('hash_id', $reviewHashId)->first();
        if ($review === null || (int) $review->site_id !== $context->siteId) {
            return ['ok' => false, 'code' => 'not_found'];
        }

        $status = (string) ($action['status'] ?? 'resolved');
        if (! in_array($status, ['resolved', 'dismissed'], true)) {
            $status = 'resolved';
        }

        $review->fill([
            'status' => $status,
            'rating' => isset($action['rating']) ? (string) $action['rating'] : $review->rating,
            'expected_skill' => isset($action['expected_skill']) ? (string) $action['expected_skill'] : $review->expected_skill,
            'comment' => isset($action['comment'])
                ? mb_substr((string) $action['comment'], 0, 1000)
                : $review->comment,
            'assigned_to' => isset($action['assigned_to']) ? (int) $action['assigned_to'] : $review->assigned_to,
            'resolved_at' => now(),
        ]);
        $review->save();

        return [
            'ok' => true,
            'code' => 'review_'.$status,
            'review_hash_id' => $review->hash_id,
            'business_mutated' => false,
        ];
    }
}
