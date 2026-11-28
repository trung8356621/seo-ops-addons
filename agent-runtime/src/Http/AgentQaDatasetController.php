<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Omnichannel\Addons\AgentRuntime\Projects\SiteDirectory;
use Omnichannel\Addons\AgentRuntime\StructuredRag\DatasetPackageWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AgentQaDatasetController
{
    public function __invoke(Request $request, string $packageId, DatasetPackageWriter $packages, SiteDirectory $sites): BinaryFileResponse|JsonResponse
    {
        $context = $request->attributes->get(AgentQaContext::REQUEST_ATTRIBUTE);
        $manifest = $packages->manifest($packageId);
        if (! $context instanceof AgentQaContext || ! is_array($manifest)) return new JsonResponse(['message' => 'Dataset not found.'], 404);
        $siteRef = (string) ($manifest['site_ref'] ?? '');
        $siteId = preg_match('/^site:([1-9]\d*)$/', $siteRef, $match) === 1 ? (int) $match[1] : 0;
        if ($siteId <= 0 || ! $context->allowsSiteId($siteId) || ! $sites->isSiteVisible($siteId, $context->userId)) {
            return new JsonResponse(['message' => 'Dataset is outside allowed QA scope.'], 403);
        }
        $path = $packages->path($packageId);
        if ($path === null) return new JsonResponse(['message' => 'Dataset not found.'], 404);

        return response()->download($path, 'seo-ops-articles-'.$packageId.'.jsonl', [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
