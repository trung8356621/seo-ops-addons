/**
 * Built-in modules — composition root import only.
 * Side-effect: registers into builtinModulesRegistry for the runtime singleton.
 * Publishing stays Laravel/Alpine shell (not an editor document module).
 */

import { setBuiltinArticleEditorModules } from '../runtime/builtinModulesRegistry';
import { coreModule } from './core';
import { articleMetaModule } from './article-meta';
import { seoModule } from '@seo-addon/editor/modules/seo/index.js';
import { mediaModule } from '@media-addon/editor/modules/media/index.js';
import { featuredModule } from '@media-addon/editor/modules/featured/index.js';
import { galleryModule } from '@media-addon/editor/modules/gallery/index.js';
import { linksModule } from './links';
import { faqModule } from './faq';
import { ctaContactModule } from './cta-contact';
import { aiModule } from '@ai-prompt-addon/editor/modules/ai/index.js';

/** @type {ReadonlyArray<object>} */
export const BUILTIN_ARTICLE_EDITOR_MODULES = Object.freeze([
    coreModule,
    articleMetaModule,
    seoModule,
    mediaModule,
    featuredModule,
    galleryModule,
    linksModule,
    faqModule,
    ctaContactModule,
    aiModule,
]);

setBuiltinArticleEditorModules(BUILTIN_ARTICLE_EDITOR_MODULES);

export {
    coreModule,
    articleMetaModule,
    seoModule,
    mediaModule,
    featuredModule,
    galleryModule,
    linksModule,
    faqModule,
    ctaContactModule,
    aiModule,
};
