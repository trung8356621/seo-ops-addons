/**
 * Gen comment — AI texts only. No link random at Gen time.
 */

import { generateSampleComments } from '../api';
import { makeId } from './storage';

export const DEFAULT_SEED_QUANTITY = 3;

/**
 * @param {{
 *   topic: Record<string, unknown>,
 *   userId: number|string,
 *   userDisplayName?: string,
 *   quantity?: number,
 * }} opts
 * @returns {Promise<{ batch: Record<string, unknown>, outputs: Array<Record<string, unknown>>, texts: string[] }>}
 */
export async function generateSeedBatch(opts) {
    const requested = Math.max(1, Math.min(12, Number(opts.quantity) || DEFAULT_SEED_QUANTITY));
    const topic = opts.topic;
    const topicId = String(topic.id ?? topic.localId);
    const now = new Date().toISOString();

    const data = await generateSampleComments({
        full_text: String(topic.full_text || ''),
        social_url: String(topic.social_url || ''),
        count: requested,
        platform: null,
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
