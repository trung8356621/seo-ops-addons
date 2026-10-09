<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

/**
 * Hard catalog of modules and operations. Weights live in settings, not here.
 */
final class SemanticOperationRegistry
{
    public const SEO_AUDIT_PATH = '/seo/content-projects/seo-audit';

    /** @return array<string, array{label: string, operations: array<string, array<string, mixed>>}> */
    public static function modules(): array
    {
        return [
            'keywords' => self::module('Keywords', [
                'keywords.landscape' => self::define('READ', 'keywords.landscape'),
                'keywords.relationship' => self::define('READ', 'keywords.relationship'),
            ]),
            'articles' => self::module('Articles', [
                'articles.inventory' => self::define('READ', 'articles.inventory'),
            ]),
            'seo_audit' => self::module('SEO Audit', [
                'seo_audit.worst_articles' => self::define('READ', 'seo_audit.worst_articles'),
                'seo_audit.focus_keyword_guidance' => self::define('READ', null, self::focusKeywordGuidance()),
                'seo_audit.site_improve' => self::define('IMPROVE', 'seo_audit.worst_articles', null, true),
                'seo_audit.draft_action' => self::define('READ', null, 'Dùng nút Đưa vào Draft trên danh sách bài đã chọn. Agent không tạo bài mới từ câu này.'),
                'seo_audit.publish' => self::define('IMPROVE', 'seo_audit.publish'),
            ]),
            'gsc' => self::module('GSC', [
                'gsc.performance' => self::define('READ', 'gsc.performance'),
                'gsc.cross_read' => self::define('READ', 'gsc.performance', null, true, ['keywords.landscape']),
            ]),
            'content_projects' => self::module('Content Project', [
                'content_projects.read' => self::define('READ', 'content_projects.read'),
            ]),
            'links' => self::module('Links', [
                'links.internal' => self::define('READ', 'links.internal'),
                'links.external' => self::define('READ', 'links.external'),
            ]),
        ];
    }

    /** @return list<string> */
    public static function moduleKeys(): array
    {
        return array_keys(self::modules());
    }

    /** @return array<string, mixed>|null */
    public static function operation(string $ref): ?array
    {
        foreach (self::modules() as $module) {
            if (isset($module['operations'][$ref])) {
                return $module['operations'][$ref];
            }
        }

        return null;
    }

    public static function knownOperation(string $ref): bool
    {
        return self::operation($ref) !== null;
    }

    public static function knownModule(string $ref): bool
    {
        return isset(self::modules()[$ref]);
    }

    /** @param array<string, array<string, mixed>> $operations */
    private static function module(string $label, array $operations): array
    {
        return ['label' => $label, 'operations' => $operations];
    }

    /** @param list<string> $secondary */
    private static function define(string $family, ?string $capability, ?string $guidance = null, bool $answerModel = false, array $secondary = []): array
    {
        return [
            'family' => $family,
            'capability' => $capability,
            'guidance' => $guidance,
            'answer_model' => $answerModel,
            'secondary' => $secondary,
        ];
    }

    private static function focusKeywordGuidance(): string
    {
        return 'Các bài thiếu Focus Keyword xem và sửa trong SEO Audit: '.self::SEO_AUDIT_PATH.'. Agent không tự tạo Draft cho câu này.';
    }
}
