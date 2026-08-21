<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use PHPUnit\Framework\TestCase;

/**
 * Contract: editor-sessions acquire payload must be JSON-parsed by Laravel.
 */
final class ArticleEditorSessionAcquireContractTest extends TestCase
{
    public function test_seo_article_api_fetch_sets_json_content_type_for_body(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/seo/resources/js/utils/seoArticleApi.js',
        );

        self::assertStringContainsString("Content-Type': 'application/json'", $source);
        self::assertStringContainsString('isJsonStringBody && !hasContentType', $source);
        self::assertStringContainsString('invalid_client_instance_id', $source);
        self::assertStringContainsString('FormData', $source);
    }

    public function test_session_client_acquire_sends_uuid_and_json_headers(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/utils/editorSessionClient.js',
        );

        self::assertStringContainsString('client_instance_id', $source);
        self::assertStringContainsString('known_document_version', $source);
        self::assertStringContainsString('runExclusiveAcquire', $source);
        self::assertStringContainsString('isUuid', $source);
        self::assertStringContainsString("headers: { 'Content-Type': 'application/json' }", $source);
        // Must not accept arbitrary length>=32 strings as UUID.
        self::assertStringNotContainsString('existing.length >= 32', $source);
    }

    public function test_session_wrapper_uses_generation_guard_and_stable_lease_deps(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/resources/js/article-editor.jsx',
        );

        self::assertStringContainsString('acquireGenerationRef', $source);
        self::assertStringContainsString('leaseTtlSeconds', $source);
        self::assertStringContainsString('leaseRenewLeadSeconds', $source);
        self::assertStringContainsString(
            '[articleId, applyClientState, documentVersion, leaseRenewLeadSeconds, leaseTtlSeconds]',
            $source,
        );
    }

    public function test_backend_invalid_client_instance_id_is_422(): void
    {
        $source = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/ArticleEditorSessionService.php',
        );

        self::assertStringContainsString('invalid_', $source);
        self::assertStringContainsString('must be a UUID', $source);
        self::assertStringContainsString('Str::isUuid', $source);
        self::assertMatchesRegularExpression('/422/', $source);
    }
}
