/**
 * Phase 6A — runtime context builder.
 * Modules must not read window/Alpine/Livewire/localStorage SoT directly.
 */

/**
 * @param {object} [input]
 * @returns {object}
 */
export function buildEditorRuntimeContext(input = {}) {
    const article = input.article && typeof input.article === 'object' ? input.article : {};
    const workflow = input.workflow && typeof input.workflow === 'object' ? input.workflow : {};
    const session = input.session && typeof input.session === 'object' ? input.session : {};
    const document = input.document && typeof input.document === 'object' ? input.document : {};
    const snapshots = input.snapshots && typeof input.snapshots === 'object' ? input.snapshots : {};
    const policy = input.policy && typeof input.policy === 'object' ? input.policy : {};
    const services = input.services && typeof input.services === 'object' ? input.services : {};

    return Object.freeze({
        article: Object.freeze({
            id: article.id ?? null,
            type: article.type ?? null,
            documentVersion: article.documentVersion ?? null,
            editorDocumentHash: article.editorDocumentHash ?? null,
        }),
        workflow: Object.freeze({
            belongsToContentProject: Boolean(workflow.belongsToContentProject),
            contentProjectStatus: workflow.contentProjectStatus ?? null,
            archived: Boolean(workflow.archived),
            manualWpSyncAllowed: Boolean(workflow.manualWpSyncAllowed),
            returnUrl: workflow.returnUrl ?? null,
        }),
        session: Object.freeze({
            id: session.id ?? null,
            status: session.status ?? null,
            writable: session.writable !== false,
            conflict: Boolean(session.conflict),
        }),
        permissions: Object.freeze(input.permissions && typeof input.permissions === 'object'
            ? { ...input.permissions }
            : {}),
        capabilities: Object.freeze(input.capabilities && typeof input.capabilities === 'object'
            ? { ...input.capabilities }
            : {}),
        document: Object.freeze({
            editorRegistry: document.editorRegistry ?? null,
            documentModel: document.documentModel ?? null,
            selectors: document.selectors ?? null,
            insertionContext: document.insertionContext ?? null,
            commandExecutor: document.commandExecutor ?? null,
        }),
        snapshots: Object.freeze({
            media: snapshots.media ?? null,
            faq: snapshots.faq ?? null,
            analysis: snapshots.analysis ?? null,
        }),
        policy: Object.freeze({
            analysis: policy.analysis ?? null,
            media: policy.media ?? null,
        }),
        services: Object.freeze({
            apiClient: services.apiClient ?? null,
            sessionClient: services.sessionClient ?? null,
            notifications: services.notifications ?? null,
            i18n: services.i18n ?? null,
        }),
        flags: Object.freeze(input.flags && typeof input.flags === 'object'
            ? { ...input.flags }
            : {}),
    });
}

/**
 * Session/presentation writable for mutation UI (command layer still guards).
 * @param {object} context
 * @returns {boolean}
 */
export function isRuntimeContextWritable(context) {
    if (!context) return false;
    if (context.workflow?.archived) return false;
    if (context.session?.conflict) return false;
    if (context.session?.writable === false) return false;
    const status = String(context.session?.status ?? '');
    if ([
        'acquiring',
        'revoked',
        'read_only',
        'locked',
        'expired',
        'taken_over',
        'conflict',
        'closing',
        'released',
        'network_degraded',
    ].includes(status)) {
        return false;
    }
    return status === 'active' || status === '';
}
