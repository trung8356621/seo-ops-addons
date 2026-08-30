@php
    use App\Help\HelpRuntimePayloadBuilder;
    use Omnichannel\Addons\Seo\Support\SeoHelpRegistry;

    $helpCssPath = base_path('addons/content/resources/css/global-help.css');
    $helpCss = is_file($helpCssPath) ? (string) file_get_contents($helpCssPath) : '';
    try {
        $helpPayload = app(HelpRuntimePayloadBuilder::class)->clientPayload(attemptSync: true);
    } catch (\Throwable) {
        $helpPayload = SeoHelpRegistry::clientPayload();
        $helpPayload['topic_by_key'] = [];
        $helpPayload['context_keys'] = [];
        $helpPayload['help_version'] = null;
        $helpPayload['source'] = 'legacy-fallback';
    }
@endphp

@once
    @if ($helpCss !== '')
        <style id="global-help-css" data-help-css>{!! $helpCss !!}</style>
    @endif

    <script>
        window.__SEO_HELP_PAYLOAD__ = @json($helpPayload);
    </script>

    <script>
    (function () {
        if (window.__SEO_GLOBAL_HELP_INLINE_BOOT__) {
            return;
        }
        window.__SEO_GLOBAL_HELP_INLINE_BOOT__ = true;

        const BODY_LOCK = 'global-help-modal-open';
        const OPEN_EVENT = 'seo-global-help:open';
        const CLOSE_EVENT = 'seo-global-help:close';
        const LEGACY_OPEN = 'article-editor:help-open';

        function payload() {
            return window.__SEO_HELP_PAYLOAD__ || { groups: [], contexts: {} };
        }

        function findGroup(groupId) {
            const id = String(groupId || '').trim();
            if (!id) return null;
            return (payload().groups || []).find((g) => g.id === id) || null;
        }

        function findTopic(groupId, topicId) {
            const group = findGroup(groupId);
            if (!group) return null;
            const id = String(topicId || '').trim();
            if (!id) return null;
            return (group.topics || []).find((t) => t.id === id) || null;
        }

        function routeNameMatches(routeName, pattern) {
            if (!routeName || !pattern) return false;
            if (String(pattern).endsWith('*')) {
                const prefix = String(pattern).slice(0, -1);
                return routeName === prefix || routeName.startsWith(prefix);
            }
            return routeName === pattern;
        }

        function matchesContext(context, routeName, fullPath) {
            if (!context) return false;
            const names = Array.isArray(context.routeNames) ? context.routeNames : [];
            for (const pattern of names) {
                if (routeNameMatches(routeName, pattern)) return true;
            }
            const paths = Array.isArray(context.pathPatterns) ? context.pathPatterns : [];
            for (const pattern of paths) {
                try {
                    if (new RegExp(pattern).test(fullPath)) return true;
                } catch (e) {}
            }
            return false;
        }

        function resolveHelpContext() {
            const contexts = payload().contexts || {};
            const routeName = String(
                document.body?.dataset?.helpRouteName
                || window.__SEO_HELP_ROUTE_NAME__
                || ''
            ).trim();
            const path = String(window.location?.pathname || '');
            const search = String(window.location?.search || '');
            const fullPath = path + search;

            if (matchesContext(contexts.syncQueue, routeName, fullPath) && /[?&]tab=queue\b/.test(search)) {
                return contexts.syncQueue;
            }
            if (matchesContext(contexts.categories, routeName, fullPath) && /[?&]tab=categories\b/.test(search)) {
                return contexts.categories;
            }
            if (matchesContext(contexts.articleEditor, routeName, fullPath)) return contexts.articleEditor;
            if (matchesContext(contexts.media, routeName, fullPath)) return contexts.media;
            if (matchesContext(contexts.seo, routeName, fullPath)) return contexts.seo;
            if (matchesContext(contexts.settings, routeName, fullPath)) return contexts.settings;
            if (matchesContext(contexts.articles, routeName, fullPath)) return contexts.articles;
            if (matchesContext(contexts.dashboard, routeName, fullPath)) return contexts.dashboard;
            if (document.body?.classList?.contains('article-editor-page')) return contexts.articleEditor;
            return contexts.system || {
                id: 'system',
                modalTitle: 'Hướng dẫn hệ thống',
                defaultGroupId: 'overview',
                groupIds: ['overview'],
            };
        }

        function groupsForContext(context) {
            const ids = Array.isArray(context?.groupIds) && context.groupIds.length
                ? context.groupIds
                : (payload().contexts?.system?.groupIds || []);
            const groups = [];
            for (const id of ids) {
                const group = findGroup(id);
                if (group) groups.push(group);
            }
            return groups.length ? groups : (payload().groups || []);
        }

        function topicMatchesQuery(topic, q) {
            const hay = [topic.title, topic.summary, topic.content, topic.html]
                .concat(Array.isArray(topic.steps) ? topic.steps : [])
                .concat(Array.isArray(topic.keywords) ? topic.keywords : [])
                .filter(Boolean)
                .join(' ')
                .toLowerCase();
            return hay.includes(q);
        }

        function resolveTopicByContextKey(contextKey) {
            const key = String(contextKey || '').trim();
            if (!key) return null;
            const mapped = (payload().topic_by_key || {})[key];
            if (!mapped || !mapped.groupId) return null;
            const groupId = String(mapped.groupId);
            const topicId = String(mapped.topicId || key);
            const topic = findTopic(groupId, topicId)
                || findTopic(groupId, key)
                || ((findGroup(groupId) && findGroup(groupId).topics) || []).find(function (row) {
                    return row && (row.id === topicId || row.id === key || row.key === key);
                })
                || null;
            if (!topic) return null;
            return { groupId: groupId, topic: topic };
        }

        function navigateHelpTarget(target) {
            if (!target || typeof target !== 'object') return;
            const id = String(target.id || '').trim();
            if (!id) return;

            if (target.type === 'module') {
                if (id === 'publishing') {
                    window.dispatchEvent(new CustomEvent('seo-sidebar-open-publish-tab'));
                    window.dispatchEvent(new CustomEvent('seo-assistant-open-publishing'));
                    return;
                }
                window.dispatchEvent(new CustomEvent('seo-assistant-switch-panel', {
                    detail: { panel: id, module: id },
                }));
                window.setTimeout(function () {
                    const panel = document.querySelector(
                        '[data-seo-assistant-panel="' + id + '"], [data-seo-module="' + id + '"], .seo-assistant-dock'
                    );
                    panel?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
                }, 80);
                return;
            }

            if (target.type === 'widget' && id === 'outline') {
                window.dispatchEvent(new CustomEvent('seo-outline-rail-opened'));
                const rail = document.querySelector('.seo-article-editor-outline-rail, .seo-outline-panel');
                rail?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
                return;
            }

            if (target.type === 'scroll' && id === 'google-preview') {
                const preview = document.querySelector(
                    '.seo-article-editor-google-preview-rail, .seo-google-serp-preview, [data-seo-google-serp-preview]'
                );
                preview?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function boot(Alpine) {
            if (!Alpine) {
                return;
            }

            try {
                const existingStore = Alpine.store('help');
                if (existingStore) {
                    patchInlineHelpStore(existingStore);
                    return;
                }
            } catch (e) {}

            Alpine.store('help', {
                __seoHelpInline: true,
                isOpen: false,
                activeContext: null,
                activeGroupId: null,
                activeTopicId: null,
                contextTopicKey: null,
                search: '',
                mobileView: 'groups',
                triggerEl: null,
                _prevOverflow: '',

                get context() {
                    return this.activeContext || resolveHelpContext();
                },
                get modalTitle() {
                    const group = this.activeGroup;
                    if (group && group.modalTitle) return group.modalTitle;
                    return (this.context && this.context.modalTitle) || 'Hướng dẫn hệ thống';
                },
                get groups() {
                    return groupsForContext(this.context);
                },
                get filteredGroups() {
                    const locked = this.resolveLockedTopic();
                    if (locked) {
                        return this.groups.map(function (group) {
                            if (group.id !== locked.groupId) return group;
                            return Object.assign({}, group, { topics: [locked.topic] });
                        });
                    }
                    const q = String(this.search || '').trim().toLowerCase();
                    if (!q) return this.groups;
                    return this.groups.map(function (group) {
                        const groupHit = String(group.title || '').toLowerCase().includes(q);
                        const topics = (group.topics || []).filter(function (topic) {
                            return topicMatchesQuery(topic, q);
                        });
                        if (groupHit || topics.length) {
                            return Object.assign({}, group, { topics: groupHit ? group.topics : topics });
                        }
                        return null;
                    }).filter(Boolean);
                },
                get activeGroup() {
                    return findGroup(this.activeGroupId) || this.filteredGroups[0] || this.groups[0] || null;
                },
                get activeTopics() {
                    const locked = this.resolveLockedTopic();
                    if (locked && this.activeGroupId === locked.groupId) {
                        return [locked.topic];
                    }
                    const group = this.activeGroup;
                    if (!group) return [];
                    const q = String(this.search || '').trim().toLowerCase();
                    if (!q) return group.topics || [];
                    const filtered = this.filteredGroups.find((g) => g.id === group.id);
                    return (filtered && filtered.topics) || group.topics || [];
                },
                get activeTopic() {
                    const topics = this.activeTopics;
                    if (!topics.length) return null;
                    return findTopic(this.activeGroupId, this.activeTopicId)
                        || topics.find((t) => t.id === this.activeTopicId)
                        || topics[0]
                        || null;
                },

                resolveLockedTopic() {
                    const key = String(this.contextTopicKey || '').trim();
                    if (!key) return null;
                    return resolveTopicByContextKey(key);
                },
                clearContextualLock() {
                    this.contextTopicKey = null;
                },
                onSearchInput() {
                    if (this.contextTopicKey) {
                        this.contextTopicKey = null;
                    }
                    this.ensureSelectionAfterSearch();
                },
                syncContextFromLocation() {
                    this.activeContext = resolveHelpContext();
                    const preferred = (this.activeContext && this.activeContext.defaultGroupId) || 'overview';
                    const groups = this.groups;
                    const group = groups.find((g) => g.id === preferred) || groups[0] || null;
                    this.activeGroupId = group ? group.id : null;
                    this.activeTopicId = group && group.topics && group.topics[0] ? group.topics[0].id : null;
                    this.mobileView = 'groups';
                },
                open(options) {
                    const detail = options && typeof options === 'object' ? options : {};
                    if (detail.trigger instanceof Element) this.triggerEl = detail.trigger;
                    else if (document.activeElement instanceof Element) this.triggerEl = document.activeElement;

                    this.contextTopicKey = null;
                    this.search = '';
                    this.syncContextFromLocation();

                    const contextKey = String(detail.contextKey || detail.topicKey || '').trim();
                    if (contextKey) {
                        const locked = resolveTopicByContextKey(contextKey);
                        if (locked) {
                            this.contextTopicKey = contextKey;
                            this.selectGroup(String(locked.groupId), { resetTopic: false });
                            this.selectTopic(String(locked.topic.id));
                            this.search = String(locked.topic.title || '').trim();
                        } else if (window.__SEO_HELP_DEV__) {
                            console.warn('[Help] Missing topic for context key:', contextKey);
                        }
                    }

                    if (detail.groupId) this.selectGroup(String(detail.groupId), { resetTopic: !detail.topicId });
                    if (detail.topicId) this.selectTopic(String(detail.topicId));
                    if (detail.topic && typeof detail.topic === 'string') this.openLegacyTopicKey(detail.topic);

                    this.isOpen = true;
                    this.lockBody();
                },
                openLegacyTopicKey(topicKey) {
                    const key = String(topicKey || '').trim();
                    if (!key) return;
                    if (key.indexOf('article-editor.') === 0) {
                        const topicId = key.slice('article-editor.'.length);
                        this.selectGroup('article-editor', { resetTopic: false });
                        this.selectTopic(topicId === 'sync-wp' ? 'wordpress-sync' : topicId);
                        return;
                    }
                    const parts = key.split('.');
                    if (parts[0]) this.selectGroup(parts[0], { resetTopic: !parts[1] });
                    if (parts[1]) this.selectTopic(parts[1]);
                },
                close() {
                    this.isOpen = false;
                    this.search = '';
                    this.contextTopicKey = null;
                    this.mobileView = 'groups';
                    this.unlockBody();
                    const trigger = this.triggerEl;
                    this.triggerEl = null;
                    if (trigger instanceof HTMLElement) {
                        window.requestAnimationFrame(function () { trigger.focus && trigger.focus(); });
                    }
                },
                selectGroup(groupId, opts) {
                    const resetTopic = !opts || opts.resetTopic !== false;
                    const groups = this.filteredGroups.length ? this.filteredGroups : this.groups;
                    const group = groups.find((g) => g.id === groupId) || findGroup(groupId);
                    if (!group) return;
                    this.activeGroupId = group.id;
                    if (resetTopic) {
                        this.activeTopicId = group.topics && group.topics[0] ? group.topics[0].id : null;
                    }
                    this.mobileView = 'topics';
                },
                selectTopic(topicId) {
                    this.activeTopicId = String(topicId || '').trim() || null;
                    this.mobileView = 'content';
                },
                toggleTopic(topicId) {
                    const id = String(topicId || '').trim();
                    if (!id || this.activeTopicId === id) return;
                    this.selectTopic(id);
                },
                ensureSelectionAfterSearch() {
                    const groups = this.filteredGroups;
                    if (!groups.length) {
                        this.activeGroupId = null;
                        this.activeTopicId = null;
                        return;
                    }
                    if (!groups.some((g) => g.id === this.activeGroupId)) {
                        this.selectGroup(groups[0].id);
                        return;
                    }
                    const topics = this.activeTopics;
                    if (!topics.some((t) => t.id === this.activeTopicId)) {
                        this.activeTopicId = topics[0] ? topics[0].id : null;
                    }
                },
                mobileBack() {
                    if (this.mobileView === 'content') { this.mobileView = 'topics'; return; }
                    if (this.mobileView === 'topics') this.mobileView = 'groups';
                },
                goToTarget(target) {
                    this.close();
                    window.setTimeout(function () { navigateHelpTarget(target); }, 40);
                },
                lockBody() {
                    if (typeof document === 'undefined') return;
                    this._prevOverflow = document.body.style.overflow || '';
                    document.body.classList.add(BODY_LOCK);
                    document.body.style.overflow = 'hidden';
                },
                unlockBody() {
                    if (typeof document === 'undefined') return;
                    document.body.classList.remove(BODY_LOCK);
                    document.body.style.overflow = this._prevOverflow || '';
                    this._prevOverflow = '';
                },
            });

            function patchInlineHelpStore(store) {
                if (!store || store.__seoHelpContextualPatched) return;
                store.__seoHelpContextualPatched = true;
                store.contextTopicKey = store.contextTopicKey || null;
                const blueprint = {
                    resolveLockedTopic: function () {
                        const key = String(this.contextTopicKey || '').trim();
                        if (!key) return null;
                        return resolveTopicByContextKey(key);
                    },
                    onSearchInput: function () {
                        if (this.contextTopicKey) this.contextTopicKey = null;
                        this.ensureSelectionAfterSearch();
                    },
                    clearContextualLock: function () {
                        this.contextTopicKey = null;
                    },
                };
                store.resolveLockedTopic = blueprint.resolveLockedTopic;
                store.onSearchInput = blueprint.onSearchInput;
                store.clearContextualLock = blueprint.clearContextualLock;

                store.open = function (options) {
                    const detail = options && typeof options === 'object' ? options : {};
                    if (detail.trigger instanceof Element) this.triggerEl = detail.trigger;
                    else if (document.activeElement instanceof Element) this.triggerEl = document.activeElement;

                    this.contextTopicKey = null;
                    this.search = '';
                    this.syncContextFromLocation();

                    const contextKey = String(detail.contextKey || detail.topicKey || '').trim();
                    if (contextKey) {
                        const locked = resolveTopicByContextKey(contextKey);
                        if (locked) {
                            this.contextTopicKey = contextKey;
                            this.selectGroup(String(locked.groupId), { resetTopic: false });
                            this.selectTopic(String(locked.topic.id));
                            this.search = String(locked.topic.title || '').trim();
                        }
                    }

                    if (detail.groupId) this.selectGroup(String(detail.groupId), { resetTopic: !detail.topicId });
                    if (detail.topicId) this.selectTopic(String(detail.topicId));
                    if (detail.topic && typeof detail.topic === 'string') this.openLegacyTopicKey(detail.topic);

                    this.isOpen = true;
                    this.lockBody();
                };

                store.close = function () {
                    this.contextTopicKey = null;
                    this.isOpen = false;
                    this.search = '';
                    this.mobileView = 'groups';
                    this.unlockBody();
                    const trigger = this.triggerEl;
                    this.triggerEl = null;
                    if (trigger instanceof HTMLElement) {
                        window.requestAnimationFrame(function () { trigger.focus && trigger.focus(); });
                    }
                };

                Object.defineProperty(store, 'activeTopics', {
                    configurable: true,
                    enumerable: true,
                    get: function () {
                        const locked = this.resolveLockedTopic();
                        if (locked && this.activeGroupId === locked.groupId) {
                            return [locked.topic];
                        }
                        const group = this.activeGroup;
                        if (!group) return [];
                        const q = String(this.search || '').trim().toLowerCase();
                        if (!q) return group.topics || [];
                        const filtered = this.filteredGroups.find((g) => g.id === group.id);
                        return (filtered && filtered.topics) || group.topics || [];
                    },
                });

                Object.defineProperty(store, 'filteredGroups', {
                    configurable: true,
                    enumerable: true,
                    get: function () {
                        const locked = this.resolveLockedTopic();
                        if (locked) {
                            return this.groups.map(function (group) {
                                if (group.id !== locked.groupId) return group;
                                return Object.assign({}, group, { topics: [locked.topic] });
                            });
                        }
                        const q = String(this.search || '').trim().toLowerCase();
                        if (!q) return this.groups;
                        return this.groups.map(function (group) {
                            const groupHit = String(group.title || '').toLowerCase().includes(q);
                            const topics = (group.topics || []).filter(function (topic) {
                                return topicMatchesQuery(topic, q);
                            });
                            if (groupHit || topics.length) {
                                return Object.assign({}, group, { topics: groupHit ? group.topics : topics });
                            }
                            return null;
                        }).filter(Boolean);
                    },
                });
            }
            const store = Alpine.store('help');
            store.syncContextFromLocation();

            if (!window.__SEO_GLOBAL_HELP_BRIDGE__) {
                window.__SEO_GLOBAL_HELP_BRIDGE__ = true;
                window.addEventListener(OPEN_EVENT, function (event) { store.open(event && event.detail ? event.detail : {}); });
                window.addEventListener(LEGACY_OPEN, function (event) { store.open(event && event.detail ? event.detail : {}); });
                window.addEventListener(CLOSE_EVENT, function () { store.close(); });
                document.addEventListener('livewire:navigated', function () {
                    if (store.isOpen) store.close();
                    store.syncContextFromLocation();
                });
                window.addEventListener('popstate', function () {
                    if (store.isOpen) store.close();
                    store.syncContextFromLocation();
                });
            }
        }

        document.addEventListener('alpine:init', function () {
            boot(window.Alpine);
        });
        if (window.Alpine) {
            boot(window.Alpine);
        }
    })();
    </script>
@endonce
