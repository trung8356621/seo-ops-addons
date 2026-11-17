import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

describe('managed CTA atomic block contract', () => {
    it('flattenHtmlBodyNodes keeps seo-managed-cta before unwrap', () => {
        const source = readFileSync(join(root, 'utils/editorHtmlUtils.js'), 'utf8');
        assert.match(source, /Managed CTA must stay atomic/);
        assert.match(source, /tag === 'div' && node\.classList\?\.contains\('seo-managed-cta'\)/);
        const atomicPos = source.indexOf("tag === 'div' && node.classList?.contains('seo-managed-cta')");
        const unwrapPos = source.indexOf("tag === 'div' || tag === 'section' || tag === 'article'");
        assert.ok(atomicPos >= 0);
        assert.ok(unwrapPos >= 0);
        assert.ok(atomicPos < unwrapPos);
    });

    it('ctaDetector wrap emits a single managed container with paired shortcode', () => {
        const source = readFileSync(join(root, 'utils/ctaDetector.js'), 'utf8');
        assert.match(source, /class="seo-managed-cta"/);
        assert.match(source, /\[seo_ops_cta style=/);
        assert.match(source, /\[\/seo_ops_cta\]/);
    });
});
