/**
 * Seed content generation — adapts legacy /api/seeding/comments/generate.
 * Creates seed_batches + seed_outputs; attaches links via selectLinksForBatch.
 */

import { generateSampleComments } from '../api';
import { makeId } from './storage';
import { selectLinksForBatch, selectLinkForSingle } from './linkSelector';

export const DEFAULT_SEED_QUANTITY = 3;

/**
 * @param {{
 *   topic: Record<string, unknown>,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   quantity?: number,
 *   seedLinks: Array<Record<string, unknown>>,
 *   existingOutputs: Array<Record<string, unknown>>,
 *   random?: () => number,
 * }} opts
 * @returns {Promise<{ batch: Record<string, unknown>, outputs: Array<Record<string, unknown>>, texts: string[] }>}
 */
export async function generateSeedBatch(opts) {
    const requested = Math.max(1, Math.min(12, Number(opts.quantity) || DEFAULT_SEED_QUANTITY));
    const topic = opts.topic;
    const topicId = String(topic.localId || topic.id);
    const now = new Date().toISOString();

    const data = await generateSampleComments({
        full_text: String(topic.full_text || ''),
        social_url: String(topic.social_url || ''),
        count: requested,
        platform: null,
    });

    // Legacy API contract: { comments: string[] } → seed content texts
    const texts = Array.isArray(data?.comments)
        ? data.comments.map((t) => String(t || '').trim()).filter(Boolean)
        : [];

    const picks = selectLinksForBatch({
        links: opts.seedLinks,
        existingOutputs: opts.existingOutputs,
        quantity: texts.length,
        random: opts.random,
    });

    const batchId = makeId('sbatch');
    const batch = {
        id: batchId,
        topic_id: topicId,
        user_id: opts.userId,
        requested_quantity: requested,
        generated_quantity: texts.length,
        created_at: now,
    };

    const outputs = texts.map((content, index) => {
        const link = picks[index] || null;
        return {
            id: makeId('sout'),
            batch_id: batchId,
            topic_id: topicId,
            user_id: opts.userId,
            content,
            seed_link_id: link ? String(link.id) : null,
            url: link ? String(link.url) : null,
            created_at: now,
            updated_at: now,
        };
    });

    return { batch, outputs, texts };
}

/**
 * Regenerate one output in-place (same id). Default keeps link.
 * @param {{
 *   output: Record<string, unknown>,
 *   topic: Record<string, unknown>,
 *   seedLinks?: Array<Record<string, unknown>>,
 *   existingOutputs?: Array<Record<string, unknown>>,
 *   rerandomLink?: boolean,
 *   random?: () => number,
 * }} opts
 */
export async function regenerateSeedOutput(opts) {
    const output = opts.output;
    const topic = opts.topic;
    const now = new Date().toISOString();

    const data = await generateSampleComments({
        full_text: String(topic.full_text || ''),
        social_url: String(topic.social_url || ''),
        count: 1,
        platform: null,
    });

    const texts = Array.isArray(data?.comments)
        ? data.comments.map((t) => String(t || '').trim()).filter(Boolean)
        : [];
    const content = texts[0] || String(output.content || '');

    let seedLinkId = output.seed_link_id ?? null;
    let url = output.url ?? null;

    if (opts.rerandomLink) {
        const pick = selectLinkForSingle({
            links: opts.seedLinks || [],
            existingOutputs: opts.existingOutputs || [],
            excludeOutputId: String(output.id),
            random: opts.random,
        });
        seedLinkId = pick ? String(pick.id) : null;
        url = pick ? String(pick.url) : null;
    }

    return {
        ...output,
        content,
        seed_link_id: seedLinkId,
        url,
        updated_at: now,
    };
}

/**
 * @param {Record<string, unknown>} output
 * @param {string} content
 */
export function updateSeedOutputContent(output, content) {
    return {
        ...output,
        content: String(content || ''),
        updated_at: new Date().toISOString(),
    };
}
