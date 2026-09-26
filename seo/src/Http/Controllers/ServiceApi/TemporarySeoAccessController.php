<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers\ServiceApi;

use App\Api\Access\TemporaryServiceAccessContext;
use App\Api\Middleware\ResolveTemporaryServiceAccess;
use App\Api\Services\ServiceApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessCatalog;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessGscComposer;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessKeywordsComposer;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessSiteKnowledgeComposer;
use Throwable;

/**
 * Temporary site-bound SEO Access façade (read-only; no permanent Bearer key).
 */
final class TemporarySeoAccessController
{
    public function __construct(
        private readonly SeoAccessSiteKnowledgeComposer $site,
        private readonly SeoAccessKeywordsComposer $keywords,
        private readonly SeoAccessGscComposer $gsc,
    ) {}

    public function index(Request $request, string $token): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $sitePayload = $this->site->compose($context->siteId);
        $identity = is_array($sitePayload['identity'] ?? null) ? $sitePayload['identity'] : [];

        return $this->jsonData([
            'schema' => SeoAccessCatalog::SCHEMA,
            'site_ref' => $context->siteRef(),
            'site' => [
                'domain' => $identity['domain'] ?? null,
                'title' => $identity['site_title'] ?? null,
            ],
            'resources' => SeoAccessCatalog::resourcesWithHrefs('/api/v1/access/'.$token),
        ], true);
    }

    public function site(Request $request, string $token): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        return $this->jsonData($this->site->compose($context->siteId), true);
    }

    public function keywords(Request $request, string $token): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $query = [
            'page' => $request->query('page'),
            'per_page' => $request->query('per_page'),
            'sort' => $request->query('sort'),
            'direction' => $request->query('direction'),
            'coverage' => $request->query('coverage'),
            'status' => $request->query('status'),
            'has_focus_article' => $request->query('has_focus_article'),
        ];

        return $this->runReadSafely(
            fn (): array => $this->keywords->landscape($context->siteId, $query, $token)
        );
    }

    public function keywordsTopic(Request $request, string $token, string $topicRef): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        return $this->runReadSafely(function () use ($context, $token, $topicRef): array {
            $detail = $this->keywords->topicDetail($context->siteId, $topicRef, $token);
            if ($detail === null) {
                throw new SeoAccessNotFoundException('Topic not found for this site.');
            }

            return $detail;
        });
    }

    public function keywordsQuery(Request $request, string $token): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $request->all();
        if (! is_array($payload)) {
            return ServiceApiError::validationFailed('Request body must be a JSON object.');
        }

        $unknown = array_diff(array_keys($payload), ['keyword_ref', 'keyword_id', 'sections']);
        if ($unknown !== []) {
            return ServiceApiError::validationFailed(
                'Unknown request fields: '.implode(', ', array_values($unknown))
            );
        }

        return $this->runReadSafely(
            fn (): array => $this->keywords->relationship($context->siteId, $payload)
        );
    }

    public function gsc(Request $request, string $token): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $period = $request->query('period');
        $includeRaw = $request->query('include');
        $include = $this->parseIncludeQuery($includeRaw);
        if ($include instanceof JsonResponse) {
            return $include;
        }

        return $this->runReadSafely(
            fn (): array => $this->gsc->compose(
                $context->siteId,
                is_string($period) ? $period : null,
                $include,
            )
        );
    }

    public function gscQuery(Request $request, string $token): JsonResponse
    {
        $context = $this->requireContext($request);
        if ($context instanceof JsonResponse) {
            return $context;
        }

        $payload = $request->all();
        if (! is_array($payload)) {
            return ServiceApiError::validationFailed('Request body must be a JSON object.');
        }

        $unknown = array_diff(array_keys($payload), ['period', 'include']);
        if ($unknown !== []) {
            return ServiceApiError::validationFailed(
                'Unknown request fields: '.implode(', ', array_values($unknown))
            );
        }

        $period = isset($payload['period']) && is_string($payload['period'])
            ? $payload['period']
            : null;
        $include = null;
        if (array_key_exists('include', $payload)) {
            if (! is_array($payload['include'])) {
                return ServiceApiError::validationFailed('include must be an array of section names.');
            }
            $include = array_values(array_filter(
                $payload['include'],
                static fn (mixed $v): bool => is_string($v),
            ));
        }

        return $this->runReadSafely(
            fn (): array => $this->gsc->compose($context->siteId, $period, $include)
        );
    }

    /**
     * @return list<string>|null|JsonResponse
     */
    private function parseIncludeQuery(mixed $includeRaw): array|JsonResponse|null
    {
        if ($includeRaw === null || $includeRaw === '') {
            return null;
        }
        if (is_array($includeRaw)) {
            return array_values(array_filter($includeRaw, static fn (mixed $v): bool => is_string($v)));
        }
        if (! is_string($includeRaw)) {
            return ServiceApiError::validationFailed('Invalid include query.');
        }

        $parts = array_values(array_filter(array_map(
            static fn (string $p): string => strtolower(trim($p)),
            explode(',', $includeRaw),
        ), static fn (string $p): bool => $p !== ''));

        return $parts === [] ? null : $parts;
    }

    private function requireContext(Request $request): TemporaryServiceAccessContext|JsonResponse
    {
        $context = $request->attributes->get(ResolveTemporaryServiceAccess::REQUEST_CONTEXT_KEY);
        if (! $context instanceof TemporaryServiceAccessContext) {
            $context = app()->bound(TemporaryServiceAccessContext::class)
                ? app(TemporaryServiceAccessContext::class)
                : null;
        }

        if (! $context instanceof TemporaryServiceAccessContext) {
            return ServiceApiError::json(
                ServiceApiError::TEMPORARY_ACCESS_INVALID,
                'Temporary access is invalid or expired.',
                401,
            );
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function jsonData(array $data, bool $noStore = false): JsonResponse
    {
        $response = response()->json(['data' => $data]);
        if ($noStore) {
            $response->headers->set('Cache-Control', 'no-store');
        }

        return $response;
    }

    private function runReadSafely(callable $callback): JsonResponse
    {
        try {
            /** @var array<string, mixed> $result */
            $result = $callback();

            return $this->jsonData($result, true);
        } catch (SeoAccessNotFoundException $e) {
            return ServiceApiError::notFound($e->getMessage());
        } catch (InvalidArgumentException $e) {
            return ServiceApiError::validationFailed($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return ServiceApiError::json(
                'service_api_internal_error',
                'Unexpected error while reading SEO access resource.',
                500,
            );
        }
    }
}
