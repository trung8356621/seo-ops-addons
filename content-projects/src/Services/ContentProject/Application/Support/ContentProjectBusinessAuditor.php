<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ActorContext;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectActionResult;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Business audit tối giản — không lưu prompt/output.
 */
final class ContentProjectBusinessAuditor
{
    public function record(
        ActorContext $actor,
        string $action,
        ContentProjectActionResult $result,
        ?int $itemId = null,
    ): void {
        if (! Schema::connection('omi_seo_ai')->hasTable('seo_content_project_business_audits')) {
            return;
        }

        try {
            DB::connection('omi_seo_ai')->table('seo_content_project_business_audits')->insert([
                'actor_type' => $actor->actorType,
                'actor_id' => $actor->actorId,
                'action' => $action,
                'project_ref' => $result->projectId !== null
                    ? ContentProjectPublicRef::project($result->projectId)
                    : null,
                'item_ref' => $itemId !== null ? ContentProjectPublicRef::item($itemId) : null,
                'result' => $result->success ? 'success' : 'failed',
                'result_code' => $result->code,
                'metadata' => json_encode(array_merge([
                    'affected_count' => count($result->affectedItemIds),
                    'correlation_id' => $actor->correlationId,
                ], is_array($result->metadata) ? $result->metadata : []), JSON_UNESCAPED_UNICODE),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable) {
            // audit never breaks business path
        }
    }
}
