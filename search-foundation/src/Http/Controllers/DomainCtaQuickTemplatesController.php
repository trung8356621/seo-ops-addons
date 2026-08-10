<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Http\Controllers;

use Omnichannel\Addons\Seo\Services\DomainCtaEditorService;
use Omnichannel\Addons\Seo\Support\CtaQuickTemplates;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Canonical CTA quick-template settings (Phase 2C) — Laravel SoT, not localStorage.
 */
final class DomainCtaQuickTemplatesController extends Controller
{
    public function __construct(
        private readonly DomainCtaEditorService $ctaEditor,
    ) {}

    public function show(): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessManagerFeatures() || SeoAccessControl::canMutateInSeoPanel(), 403);

        $templates = $this->ctaEditor->quickTemplates();

        return response()->json([
            'success' => true,
            'cta_quick_templates' => $templates,
            'settings_version' => $this->versionToken($templates),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(SeoAccessControl::canAccessManagerFeatures(), 403);

        $payload = $request->input('cta_quick_templates', $request->input('templates', []));
        if (! is_array($payload)) {
            return response()->json([
                'success' => false,
                'error' => 'cta_template_invalid_placeholder',
                'message' => 'Invalid CTA templates payload.',
            ], 422);
        }

        $expectedVersion = $request->input('expected_settings_version');
        $current = $this->ctaEditor->quickTemplates();
        $currentVersion = $this->versionToken($current);
        if ($expectedVersion !== null && $expectedVersion !== '' && (string) $expectedVersion !== $currentVersion) {
            return response()->json([
                'success' => false,
                'error' => 'cta_template_conflict',
                'message' => 'CTA template settings version conflict.',
                'settings_version' => $currentVersion,
                'cta_quick_templates' => $current,
            ], 409);
        }

        foreach ($payload as $type => $row) {
            if (! is_array($row)) {
                continue;
            }
            $templates = is_array($row['templates'] ?? null) ? $row['templates'] : [];
            foreach ($templates as $template) {
                $check = CtaQuickTemplates::validate((string) $template, (string) $type);
                if (! ($check['ok'] ?? false)) {
                    return response()->json([
                        'success' => false,
                        'error' => 'cta_template_invalid_placeholder',
                        'message' => (string) ($check['error'] ?? 'Invalid placeholder'),
                    ], 422);
                }
            }
        }

        $saved = $this->ctaEditor->saveQuickTemplates($payload);

        return response()->json([
            'success' => true,
            'cta_quick_templates' => $saved,
            'settings_version' => $this->versionToken($saved),
        ]);
    }

    /**
     * @param  array<string, mixed>  $templates
     */
    private function versionToken(array $templates): string
    {
        return substr(hash('sha256', (string) json_encode($templates)), 0, 16);
    }
}
