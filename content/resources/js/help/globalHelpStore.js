import { groupsForContext, findHelpGroup, findHelpTopic } from './helpRegistry';
import { resolveHelpContext } from './resolveHelpContext';
import { navigateHelpTarget } from './navigateHelpTarget';
import {
    ARTICLE_EDITOR_HELP_OPEN_EVENT,
    GLOBAL_HELP_CLOSE_EVENT,
    GLOBAL_HELP_OPEN_EVENT,
} from './helpEvents';

const BODY_LOCK_CLASS = 'global-help-modal-open';

/**
 * Register Alpine.store('help') once.
 * @param {import('alpinejs').Alpine} Alpine
 */
export function registerGlobalHelpStore(Alpine) {
    let existing = null;
    try {
        existing = typeof Alpine.store === 'function' ? Alpine.store('help') : null;
    } catch {
        existing = null;
    }
    if (existing && typeof existing.open === 'function') {
        return existing;
    }

    Alpine.store('help', {
        isOpen: false,
        activeContext: null,
        activeGroupId: null,
        activeTopicId: null,
        search: '',
        mobileView: 'groups',
        triggerEl: null,
        _prevOverflow: '',

        get context() {
            return this.activeContext ?? resolveHelpContext();
        },

        get modalTitle() {
            const group = this.activeGroup;
            if (group?.modalTitle) {
                return group.modalTitle;
            }

            return this.context?.modalTitle || 'Hướng dẫn hệ thống';
        },

        get groups() {
            return groupsForContext(this.context);
        },

        get filteredGroups() {
            const q = String(this.search ?? '').trim().toLowerCase();
            if (q === '') {
                return this.groups;
            }

            return this.groups
                .map((group) => {
                    const groupHit = String(group.title ?? '').toLowerCase().includes(q);
                    const topics = (group.topics || []).filter((topic) => topicMatchesQuery(topic, q));
                    if (groupHit || topics.length > 0) {
                        return {
                            ...group,
                            topics: groupHit ? group.topics : topics,
                        };
                    }

                    return null;
                })
                .filter(Boolean);
        },

        get activeGroup() {
            return findHelpGroup(this.activeGroupId) ?? this.filteredGroups[0] ?? this.groups[0] ?? null;
        },

        get activeTopics() {
            const group = this.activeGroup;
            if (!group) {
                return [];
            }

            const q = String(this.search ?? '').trim().toLowerCase();
            if (q === '') {
                return group.topics || [];
            }

            const filtered = this.filteredGroups.find((g) => g.id === group.id);
            return filtered?.topics || group.topics || [];
        },

        get activeTopic() {
            const topics = this.activeTopics;
            if (!topics.length) {
                return null;
            }

            return findHelpTopic(this.activeGroupId, this.activeTopicId)
                ?? topics.find((t) => t.id === this.activeTopicId)
                ?? topics[0]
                ?? null;
        },

        syncContextFromLocation() {
            this.activeContext = resolveHelpContext();
            const preferred = this.activeContext?.defaultGroupId || 'overview';
            const groups = this.groups;
            const group = groups.find((g) => g.id === preferred) ?? groups[0] ?? null;
            this.activeGroupId = group?.id ?? null;
            this.activeTopicId = group?.topics?.[0]?.id ?? null;
            this.mobileView = 'groups';
        },

        open(options = {}) {
            const detail = options && typeof options === 'object' ? options : {};
            if (detail.trigger instanceof Element) {
                this.triggerEl = detail.trigger;
            } else if (document.activeElement instanceof Element) {
                this.triggerEl = document.activeElement;
            }

            this.syncContextFromLocation();

            if (detail.groupId) {
                this.selectGroup(String(detail.groupId), { resetTopic: !detail.topicId });
            }
            if (detail.topicId) {
                this.selectTopic(String(detail.topicId));
            }

            // Legacy article-editor topic keys: article-editor.overview → group + topic
            if (detail.topic && typeof detail.topic === 'string') {
                this.openLegacyTopicKey(detail.topic);
            }

            this.isOpen = true;
            this.lockBody();
        },

        openLegacyTopicKey(topicKey) {
            const key = String(topicKey).trim();
            if (key === '') {
                return;
            }

            if (key.startsWith('article-editor.')) {
                const topicId = key.slice('article-editor.'.length);
                this.selectGroup('article-editor', { resetTopic: false });
                this.selectTopic(topicId === 'sync-wp' ? 'wordpress-sync' : topicId);
                return;
            }

            const [groupId, topicId] = key.split('.');
            if (groupId) {
                this.selectGroup(groupId, { resetTopic: !topicId });
            }
            if (topicId) {
                this.selectTopic(topicId);
            }
        },

        close() {
            this.isOpen = false;
            this.search = '';
            this.mobileView = 'groups';
            this.unlockBody();

            const trigger = this.triggerEl;
            this.triggerEl = null;
            if (trigger instanceof HTMLElement) {
                window.requestAnimationFrame(() => trigger.focus?.());
            }
        },

        selectGroup(groupId, { resetTopic = true } = {}) {
            const groups = this.filteredGroups.length ? this.filteredGroups : this.groups;
            const group = groups.find((g) => g.id === groupId) ?? findHelpGroup(groupId);
            if (!group) {
                return;
            }

            this.activeGroupId = group.id;
            if (resetTopic) {
                this.activeTopicId = group.topics?.[0]?.id ?? null;
            }
            this.mobileView = 'topics';
        },

        selectTopic(topicId) {
            this.activeTopicId = String(topicId ?? '').trim() || null;
            this.mobileView = 'content';
        },

        toggleTopic(topicId) {
            const id = String(topicId ?? '').trim();
            if (id === '') {
                return;
            }
            if (this.activeTopicId === id) {
                // Keep one topic open — re-select same is no-op collapse optional;
                // requirement: only one topic open; clicking open topic keeps it.
                return;
            }
            this.selectTopic(id);
        },

        ensureSelectionAfterSearch() {
            const groups = this.filteredGroups;
            if (!groups.length) {
                this.activeGroupId = null;
                this.activeTopicId = null;
                return;
            }

            const stillVisible = groups.some((g) => g.id === this.activeGroupId);
            if (!stillVisible) {
                this.selectGroup(groups[0].id);
                return;
            }

            const topics = this.activeTopics;
            if (!topics.some((t) => t.id === this.activeTopicId)) {
                this.activeTopicId = topics[0]?.id ?? null;
            }
        },

        mobileBack() {
            if (this.mobileView === 'content') {
                this.mobileView = 'topics';
                return;
            }
            if (this.mobileView === 'topics') {
                this.mobileView = 'groups';
            }
        },

        goToTarget(target) {
            this.close();
            window.setTimeout(() => navigateHelpTarget(target), 40);
        },

        lockBody() {
            if (typeof document === 'undefined') {
                return;
            }
            this._prevOverflow = document.body.style.overflow || '';
            document.body.classList.add(BODY_LOCK_CLASS);
            document.body.style.overflow = 'hidden';
        },

        unlockBody() {
            if (typeof document === 'undefined') {
                return;
            }
            document.body.classList.remove(BODY_LOCK_CLASS);
            document.body.style.overflow = this._prevOverflow || '';
            this._prevOverflow = '';
        },
    });

    return Alpine.store('help');
}

/**
 * @param {import('./helpRegistry').HelpTopic} topic
 * @param {string} q lowercased
 */
function topicMatchesQuery(topic, q) {
    const hay = [
        topic.title,
        topic.summary,
        topic.content,
        ...(Array.isArray(topic.steps) ? topic.steps : []),
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return hay.includes(q);
}

/**
 * Wire window events once (open/close + Livewire navigate).
 * @param {ReturnType<typeof registerGlobalHelpStore>} store
 */
export function installGlobalHelpWindowBridge(store) {
    if (typeof window === 'undefined' || window.__SEO_GLOBAL_HELP_BRIDGE__) {
        return;
    }
    window.__SEO_GLOBAL_HELP_BRIDGE__ = true;

    const onOpen = (event) => {
        store.open(event?.detail ?? {});
    };
    const onClose = () => store.close();
    const onNavigated = () => {
        if (store.isOpen) {
            store.close();
        }
        store.syncContextFromLocation();
    };

    window.addEventListener(GLOBAL_HELP_OPEN_EVENT, onOpen);
    window.addEventListener(ARTICLE_EDITOR_HELP_OPEN_EVENT, onOpen);
    window.addEventListener(GLOBAL_HELP_CLOSE_EVENT, onClose);
    document.addEventListener('livewire:navigated', onNavigated);
    window.addEventListener('popstate', onNavigated);
}
