<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Extension;

use Omnichannel\Addons\Publishing\Extension\Contracts\PublisherDriver;
use Omnichannel\Addons\Publishing\Application\Publishing\ArticlePublishPayload;
use Omnichannel\Addons\Publishing\Application\Publishing\PublishAttemptRefs;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * SDK array adapter — wraps WordPressPublisher (ContentPublisher contract).
 */
final class WordpressPublisherDriver implements PublisherDriver
{
    public function __construct(
        private readonly WordPressPublisher $publisher,
    ) {}

    public function id(): string
    {
        return 'wordpress';
    }

    public function label(): string
    {
        return 'WordPress Publisher';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function publish(array $payload): array
    {
        $articlePublishPayload = new ArticlePublishPayload(
            articleId: (int) ($payload['article_id'] ?? 0),
            siteId: (int) ($payload['site_id'] ?? 0),
            wpPostId: isset($payload['wp_post_id']) ? (int) $payload['wp_post_id'] : null,
            externalReference: (string) ($payload['external_reference'] ?? ''),
            attemptRef: (string) ($payload['attempt_ref'] ?? PublishAttemptRefs::newAttemptRef()),
            idempotencyKey: isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null,
            title: (string) ($payload['title'] ?? ''),
            content: (string) ($payload['content'] ?? ''),
            status: (string) ($payload['status'] ?? 'publish'),
            projectId: (int) ($payload['project_id'] ?? 0),
            taskId: (int) ($payload['task_id'] ?? 0),
            actorUserId: isset($payload['actor_user_id']) ? (int) $payload['actor_user_id'] : null,
        );

        $result = $this->publisher->publish($articlePublishPayload);

        return [
            'success' => $result->success,
            'wp_post_id' => $result->wpPostId,
            'message' => $result->message,
            'already_published' => $result->alreadyPublished,
            'external_reference' => $result->externalReference,
            'delivery_requested' => $result->deliveryRequested,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(array $payload): array
    {
        unset($payload);

        return [
            'success' => false,
            'code' => 'publisher.operation_unsupported',
            'message' => 'WordPress publisher update not implemented.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function delete(array $payload): array
    {
        unset($payload);

        return [
            'success' => false,
            'code' => 'publisher.operation_unsupported',
            'message' => 'WordPress publisher delete not implemented.',
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function find(array $query): ?array
    {
        $siteId = (int) ($query['site_id'] ?? 0);
        $externalReference = (string) ($query['external_reference'] ?? '');

        if ($externalReference === '') {
            return null;
        }

        $wpPostId = $this->publisher->findByExternalReference($siteId, $externalReference);

        if ($wpPostId === null) {
            return null;
        }

        return [
            'wp_post_id' => $wpPostId,
            'external_reference' => $externalReference,
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array
    {
        if (! class_exists(WordPressPublisher::class)) {
            return [
                'ok' => false,
                'message' => 'WordPressPublisher class missing.',
            ];
        }

        try {
            if (Schema::connection('omi_seo_ai')->hasTable('seo_sites')) {
                return [
                    'ok' => true,
                    'message' => 'WordPress publisher ready (site table present).',
                ];
            }
        } catch (Throwable) {
            // fallback
        }

        return [
            'ok' => true,
            'message' => 'WordPress publisher ready.',
        ];
    }
}
