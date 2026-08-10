<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Content\Models\ArticleMeta;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\Seo\Support\SeoRuleViolationsResolver;
use Omnichannel\Addons\Seo\Support\SeoScoringRulesRegistry;
use Tests\TestCase;

final class SeoRuleViolationsResolverTest extends TestCase
{
    public function test_reads_new_violations_meta_format(): void
    {
        $article = new SeoArticle(['id' => 1]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => SeoScoringRulesRegistry::META_KEY_VIOLATIONS,
                'meta_value' => json_encode(['h2_missing', 'faq_missing']),
            ]),
        ]));

        $violations = SeoRuleViolationsResolver::forArticle($article);

        $this->assertSame(['h2_missing', 'faq_missing'], $violations);
    }

    public function test_converts_legacy_rank_math_reason_keys(): void
    {
        $article = new SeoArticle(['id' => 2]);
        $article->setRelation('articleMetas', collect([
            new ArticleMeta([
                'meta_key' => 'seo_rank_math_score',
                'meta_value' => json_encode([
                    'score' => 65,
                    'reason_keys' => ['seo.heading', 'seo.faq_schema'],
                ]),
            ]),
        ]));

        $violations = SeoRuleViolationsResolver::forArticle($article);

        $this->assertContains('h2_missing', $violations);
        $this->assertContains('faq_missing', $violations);
    }
}
