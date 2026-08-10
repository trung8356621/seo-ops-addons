<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Support;

/**
 * Best-practices copy for Settings → Recommendations (admin docs only, no runtime).
 */
final class SeoSettingsRecommendationsContent
{
    /**
     * @return array{general_image: string, typography: string, video: string}
     */
    public static function currentBadge(): array
    {
        return [
            'general_image' => 'Imagen 4',
            'typography' => 'Gemini 3.1 Flash Image Preview',
            'video' => '(Current Default)',
        ];
    }

    /**
     * @return list<array{
     *     id: string,
     *     tone: 'info'|'success'|'warning',
     *     icon: string,
     *     title_key: string,
     *     blocks: list<array<string, mixed>>
     * }>
     */
    public static function cards(): array
    {
        return [
            [
                'id' => 'image_routing',
                'tone' => 'info',
                'icon' => 'heroicon-o-light-bulb',
                'title_key' => 'seo-content-ai::filament.settings_recommendations.cards.image_routing.title',
                'blocks' => [
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.image_routing.recommended'],
                    [
                        'type' => 'pairs',
                        'items' => [
                            ['label_key' => 'seo-content-ai::filament.settings_recommendations.cards.image_routing.hero', 'value' => 'Imagen 4'],
                            ['label_key' => 'seo-content-ai::filament.settings_recommendations.cards.image_routing.gallery', 'value' => 'Imagen 4'],
                            ['label_key' => 'seo-content-ai::filament.settings_recommendations.cards.image_routing.infographic', 'value' => 'Gemini 3.1 Flash Image Preview'],
                        ],
                    ],
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.image_routing.reason_heading'],
                    ['type' => 'paragraph', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.image_routing.reason'],
                ],
            ],
            [
                'id' => 'typography',
                'tone' => 'warning',
                'icon' => 'heroicon-o-exclamation-triangle',
                'title_key' => 'seo-content-ai::filament.settings_recommendations.cards.typography.title',
                'blocks' => [
                    ['type' => 'paragraph', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.typography.intro'],
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.typography.expect_heading'],
                    [
                        'type' => 'bullets',
                        'items' => [
                            'seo-content-ai::filament.settings_recommendations.cards.typography.expect_1',
                            'seo-content-ai::filament.settings_recommendations.cards.typography.expect_2',
                        ],
                    ],
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.typography.suitable_heading'],
                    [
                        'type' => 'bullets',
                        'items' => [
                            'seo-content-ai::filament.settings_recommendations.cards.typography.suitable_1',
                            'seo-content-ai::filament.settings_recommendations.cards.typography.suitable_2',
                        ],
                        'style' => 'success',
                    ],
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.typography.not_heading'],
                    [
                        'type' => 'bullets',
                        'items' => [
                            'seo-content-ai::filament.settings_recommendations.cards.typography.not_1',
                            'seo-content-ai::filament.settings_recommendations.cards.typography.not_2',
                        ],
                        'style' => 'danger',
                    ],
                ],
            ],
            [
                'id' => 'prompt_design',
                'tone' => 'success',
                'icon' => 'heroicon-o-light-bulb',
                'title_key' => 'seo-content-ai::filament.settings_recommendations.cards.prompt_design.title',
                'blocks' => [
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.prompt_design.recommended'],
                    [
                        'type' => 'bullets',
                        'items' => [
                            'seo-content-ai::filament.settings_recommendations.cards.prompt_design.rec_1',
                            'seo-content-ai::filament.settings_recommendations.cards.prompt_design.rec_2',
                            'seo-content-ai::filament.settings_recommendations.cards.prompt_design.rec_3',
                            'seo-content-ai::filament.settings_recommendations.cards.prompt_design.rec_4',
                            'seo-content-ai::filament.settings_recommendations.cards.prompt_design.rec_5',
                        ],
                    ],
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.prompt_design.avoid_heading'],
                    [
                        'type' => 'bullets',
                        'items' => [
                            'seo-content-ai::filament.settings_recommendations.cards.prompt_design.avoid_1',
                            'seo-content-ai::filament.settings_recommendations.cards.prompt_design.avoid_2',
                        ],
                        'style' => 'danger',
                    ],
                ],
            ],
            [
                'id' => 'workflow',
                'tone' => 'info',
                'icon' => 'heroicon-o-light-bulb',
                'title_key' => 'seo-content-ai::filament.settings_recommendations.cards.workflow.title',
                'blocks' => [
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.workflow.current'],
                    [
                        'type' => 'flow',
                        'steps' => [
                            'seo-content-ai::filament.settings_recommendations.cards.workflow.step_1',
                            'seo-content-ai::filament.settings_recommendations.cards.workflow.step_2',
                            'seo-content-ai::filament.settings_recommendations.cards.workflow.step_3',
                            'seo-content-ai::filament.settings_recommendations.cards.workflow.step_4',
                        ],
                    ],
                    ['type' => 'paragraph', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.workflow.note'],
                ],
            ],
            [
                'id' => 'ai_models',
                'tone' => 'info',
                'icon' => 'heroicon-o-light-bulb',
                'title_key' => 'seo-content-ai::filament.settings_recommendations.cards.ai_models.title',
                'blocks' => [
                    ['type' => 'paragraph', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.ai_models.intro'],
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.ai_models.if_heading'],
                    [
                        'type' => 'numbered',
                        'items' => [
                            'seo-content-ai::filament.settings_recommendations.cards.ai_models.step_1',
                            'seo-content-ai::filament.settings_recommendations.cards.ai_models.step_2',
                            'seo-content-ai::filament.settings_recommendations.cards.ai_models.step_3',
                            'seo-content-ai::filament.settings_recommendations.cards.ai_models.step_4',
                        ],
                    ],
                ],
            ],
            [
                'id' => 'experimental',
                'tone' => 'warning',
                'icon' => 'heroicon-o-beaker',
                'title_key' => 'seo-content-ai::filament.settings_recommendations.cards.experimental.title',
                'blocks' => [
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.experimental.feature'],
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.experimental.status_heading'],
                    [
                        'type' => 'status',
                        'label' => '🧪 Experimental',
                        'key' => 'seo-content-ai::filament.settings_recommendations.cards.experimental.status_note',
                    ],
                    ['type' => 'subheading', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.experimental.reason_heading'],
                    ['type' => 'paragraph', 'key' => 'seo-content-ai::filament.settings_recommendations.cards.experimental.reason'],
                ],
            ],
        ];
    }
}
