/**
 * Gen comment — AI texts only. No link random at Gen time.
 */

import { generateSampleComments } from '../api';
import { makeId } from './storage';

export const DEFAULT_SEED_QUANTITY = 3;

/**
 * Extract preview metadata for topic URL if present.
 *
 * @param {Record<string, unknown>} topic
 * @param {Record<string, Record<string, unknown>>} [linkPreviewCache]
 * @returns {{ title?: string|null, description?: string|null, domain?: string|null }}
 */
function extractTopicPreviewMeta(topic, linkPreviewCache = {}) {
    const links = Array.isArray(topic.links) ? topic.links : [];
    const firstLink = links[0] || null;
    const url = String(topic.social_url || firstLink?.url || '').trim();

    if (!url) return {};

    const cached = linkPreviewCache[url] || (firstLink?.preview_title ? firstLink : null);
    if (!cached) return {};

    return {
        title: cached.preview_title || cached.title || null,
        description: cached.preview_description || cached.description || null,
        domain: cached.preview_domain || cached.domain || null,
    };
}

/**
 * @param {{
 *   topic: Record<string, unknown>,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   quantity?: number,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 * }} opts
 * @returns {Promise<{ batch: Record<string, unknown>, outputs: Array<Record<string, unknown>>, texts: string[] }>}
 */
export async function generateSeedBatch(opts) {
    const requested = Math.max(1, Math.min(12, Number(opts.quantity) || DEFAULT_SEED_QUANTITY));
    const topic = opts.topic;
    const topicId = String(topic.id ?? topic.localId);
    const now = new Date().toISOString();

    const fullText = String(topic.full_text || topic.content || '').trim();
    const socialUrl = String(topic.social_url || topic.url || '').trim();
    const platform = String(topic.social_platform || topic.platform || 'threads').trim();
    const sourceType = socialUrl && !fullText ? 'url' : 'text';
    const previewMeta = extractTopicPreviewMeta(topic, opts.linkPreviewCache);

    const data = await generateSampleComments({
        source_type: sourceType,
        content: fullText,
        url: socialUrl,
        social: platform || 'threads',
        quantity: requested,
        title: previewMeta.title,
        description: previewMeta.description,
        domain: previewMeta.domain,
        // Legacy parameter fallbacks
        full_text: fullText,
        social_url: socialUrl,
        count: requested,
        platform: platform || null,
    });

    const texts = Array.isArray(data?.comments)
        ? data.comments.map((t) => String(t || '').trim()).filter(Boolean)
        : [];

    const batchId = makeId('sbatch');
    const batch = {
        id: batchId,
        topic_id: topicId,
        user_id: opts.userId,
        requested_quantity: requested,
        generated_quantity: texts.length,
        created_at: now,
    };

    // generated comments — no seed link until Copy
    const outputs = texts.map((content) => ({
        id: makeId('gcom'),
        batch_id: batchId,
        topic_id: topicId,
        user_id: opts.userId,
        content,
        append_link: false,
        selected_seed_link_id: null,
        selected_seed_url: null,
        seed_link_id: null,
        url: null,
        copied_at: null,
        created_at: now,
        updated_at: now,
    }));

    return { batch, outputs, texts };
}

/**
 * Regenerate one comment text (keeps selected link if any).
 *
 * @param {{
 *   output: Record<string, unknown>,
 *   topic: Record<string, unknown>,
 *   linkPreviewCache?: Record<string, Record<string, unknown>>,
 * }} opts
 */
export async function regenerateSeedOutput(opts) {
    const output = opts.output;
    const topic = opts.topic;
    const now = new Date().toISOString();

    const fullText = String(topic.full_text || topic.content || '').trim();
    const socialUrl = String(topic.social_url || topic.url || '').trim();
    const platform = String(topic.social_platform || topic.platform || 'threads').trim();
    const sourceType = socialUrl && !fullText ? 'url' : 'text';
    const previewMeta = extractTopicPreviewMeta(topic, opts.linkPreviewCache);

    const data = await generateSampleComments({
        source_type: sourceType,
        content: fullText,
        url: socialUrl,
        social: platform || 'threads',
        quantity: 1,
        title: previewMeta.title,
        description: previewMeta.description,
        domain: previewMeta.domain,
        // Legacy parameter fallbacks
        full_text: fullText,
        social_url: socialUrl,
        count: 1,
        platform: platform || null,
    });

    const texts = Array.isArray(data?.comments)
        ? data.comments.map((t) => String(t || '').trim()).filter(Boolean)
        : [];
    const content = texts[0] || String(output.content || '');

    return {
        ...output,
        content,
        updated_at: now,
    };
}

export function updateSeedOutputContent(output, content) {
    return {
        ...output,
        content: String(content || ''),
        updated_at: new Date().toISOString(),
    };
}
