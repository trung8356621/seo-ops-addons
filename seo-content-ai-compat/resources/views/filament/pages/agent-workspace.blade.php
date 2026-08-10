<x-filament-panels::page>
    @vite([
        'addons/ai-prompt/resources/css/global-ai-chat.css',
        'addons/agent/resources/css/agent-workspace.css',
        'addons/agent/resources/js/agent/command-catalog.js',
    ])
    {{-- Fallback if Vite asset chưa build: hydrate từ PHP catalog (cùng schema). --}}
    <script>
        (function () {
            if (window.AgentCommandCatalog && window.AgentCommandCatalogApi) {
                return;
            }
            var rows = @json(\Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandCatalog::toFrontendCatalog());
            window.AgentCommandCatalog = rows;
            window.AgentCommandCatalogApi = {
                storageKey: 'agent.command-catalog.v1',
                all: function () { return window.AgentCommandCatalog || []; },
                filter: function (query) {
                    var q = String(query || '').trim().toLowerCase();
                    var list = window.AgentCommandCatalog || [];
                    if (q === '' || q === '/') return list.slice();
                    var needle = q.replace(/^\//, '');
                    return list.filter(function (row) {
                        var name = String(row.name || '').toLowerCase().replace(/^\//, '');
                        var desc = String(row.description || '').toLowerCase();
                        return name.indexOf(needle) === 0 || name.indexOf(needle) !== -1 || desc.indexOf(needle) !== -1;
                    });
                },
                find: function (name) {
                    var n = String(name || '').trim().toLowerCase();
                    if (n && n.charAt(0) !== '/') n = '/' + n;
                    return (window.AgentCommandCatalog || []).find(function (row) {
                        return String(row.name || '').toLowerCase() === n;
                    }) || null;
                },
                persist: function () {}
            };
        })();
    </script>

    @php
        $siteName = $workspaceContext['site_name'] ?? null;
        $bootOk = is_array($workspaceContext) && ($workspaceContext['site_ref'] ?? null);
        $subtitle = $siteName
            ? ('Site: '.$siteName.($workspaceContext['project_ref'] ?? null ? ' · Project context' : ''))
            : __('seo-content-ai::filament.agent_workspace.empty_hint');
    @endphp

    <div
        class="seo-agent-workspace"
        wire:key="seo-agent-workspace-root"
        x-data="seoAgentWorkspace"
        x-on:keydown.escape.window="onEscape()"
        x-on:agent-focus-composer.window="focusComposer()"
        x-on:agent-cli-template-ready.window="onCliTemplateReady()"
    >
        @unless ($bootOk)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                {{ \Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceDeepLink::MISSING_SITE_MESSAGE }}
            </div>
        @else
            @if ($contextNotice !== '')
                <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                    {{ $contextNotice }}
                </div>
            @endif

            @if (($composerError ?? '') !== '')
                <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100" wire:key="agent-composer-error">
                    {{ $composerError }}
                </div>
            @endif

            <div class="seo-agent-workspace__toolbar">
                <div class="ml-auto flex flex-wrap gap-2">
                    <x-filament::button type="button" size="sm" color="gray" wire:click="createConversation" wire:loading.attr="disabled" wire:target="createConversation">
                        {{ __('seo-content-ai::filament.agent_workspace.new_chat') }}
                    </x-filament::button>
                </div>
            </div>

            @if (($activePanel ?? 'chat') === 'chat')
            <div class="seo-agent-workspace__grid">
                <section class="seo-global-chat seo-agent-workspace-chat">
                    <x-seo-content-ai::seo-agent-chat.header
                        :title="__('seo-content-ai::filament.agent_workspace.title')"
                        :subtitle="$subtitle"
                    />

                    <div
                        class="seo-global-chat__messages seo-agent-workspace-chat__messages"
                        x-ref="chatMessages"
                        wire:loading.class="opacity-60"
                        wire:target="sendMessage,submitComposer,submitClarification,saveProposedPlan"
                        @if ($this->hasActiveExecutionPoll())
                            wire:poll.5s="pollActiveExecutions"
                        @endif
                    >
                        @if (count($messages) === 0)
                            <x-seo-content-ai::seo-agent-chat.empty-state
                                :title="__('seo-content-ai::filament.agent_workspace.empty_title')"
                                :description="__('seo-content-ai::filament.agent_workspace.empty_hint')"
                            />
                            {{-- Template cards: value + static wire:click selectTemplate($event.currentTarget.value). --}}
                            <div class="seo-agent-workspace__template-grid">
                                @foreach ($suggestedActions as $card)
                                    <x-seo-content-ai::agent-workspace.action-button
                                        action="selectTemplate"
                                        :value="$card['key']"
                                        wire:key="agent-template-{{ $card['key'] }}"
                                        class="seo-agent-workspace__template-card"
                                        wire:loading.attr="disabled"
                                        wire:target="selectTemplate"
                                    >
                                        <div class="seo-agent-workspace__template-title">{{ $card['title'] }}</div>
                                        <div class="seo-agent-workspace__template-desc">{{ $card['description'] }}</div>
                                    </x-seo-content-ai::agent-workspace.action-button>
                                @endforeach
                            </div>
                        @endif

                        @foreach ($messages as $message)
                            <div wire:key="agent-msg-{{ $message['public_ref'] ?? ($loop->index) }}">
                                <x-seo-content-ai::seo-agent-chat.message
                                    :role="$message['role'] ?? 'assistant'"
                                    :content="$message['content'] ?? ''"
                                    :message-type="$message['message_type'] ?? 'text'"
                                >
                                    <?php if (($message['role'] ?? '') !== 'user'): ?>
                                        @include('seo-content-ai::filament.pages.partials.agent-message-structured', ['message' => $message])
                                    <?php endif; ?>
                                </x-seo-content-ai::seo-agent-chat.message>
                            </div>
                        @endforeach

                        @if ($composerSubmitting ?? false)
                            <div class="seo-agent-workspace__pending" wire:key="agent-pending">
                                <div class="seo-global-chat__typing" aria-live="polite">
                                    <span></span><span></span><span></span>
                                </div>
                                <span class="text-xs text-gray-500">Đang xử lý…</span>
                            </div>
                        @endif
                    </div>

                    <div class="seo-agent-workspace-chat__composer-wrap relative">
                        <div
                            class="seo-agent-workspace__palette"
                            role="listbox"
                            aria-label="Slash commands"
                            x-ref="paletteRoot"
                            x-show="paletteOpen"
                            x-cloak
                        >
                            <div class="seo-agent-workspace__palette-head">Commands</div>
                            <template x-for="(row, idx) in filteredCommands" :key="row.name">
                                <div>
                                    <div
                                        class="seo-agent-workspace__palette-group"
                                        x-show="idx === 0 || (filteredCommands[idx - 1] && filteredCommands[idx - 1].group !== row.group)"
                                        x-text="row.group || 'Other'"
                                    ></div>
                                    <button
                                        type="button"
                                        role="option"
                                        class="seo-agent-workspace__palette-item"
                                        x-bind:value="row.name"
                                        x-bind:data-index="idx"
                                        x-bind:class="paletteIndex === idx && 'is-active'"
                                        x-on:mouseenter="paletteIndex = idx; updateHelpFromRow(row)"
                                        x-on:click="selectPaletteRow(row)"
                                    >
                                        <div class="seo-agent-workspace__palette-main">
                                            <div class="seo-agent-workspace__palette-cmd" x-text="row.name"></div>
                                            <div class="seo-agent-workspace__palette-desc" x-text="row.description"></div>
                                        </div>
                                    </button>
                                </div>
                            </template>
                            <div
                                class="seo-agent-workspace__palette-empty"
                                x-show="filteredCommands.length === 0"
                                x-cloak
                            >Không tìm thấy lệnh.</div>
                        </div>

                        <div
                            class="seo-agent-workspace__cli-help"
                            x-show="cliHelp && cliHelp.example"
                            x-cloak
                        >
                            <div class="seo-agent-workspace__cli-help-example">
                                <span>Example:</span>
                                <code x-text="cliHelp.example"></code>
                            </div>
                        </div>

                        <div
                            class="seo-agent-workspace__arg-suggest"
                            x-show="argSuggestions.length > 0 || argLoading"
                            x-cloak
                        >
                            <div class="seo-agent-workspace__arg-suggest-loading" x-show="argLoading" x-cloak>Đang tải…</div>
                            <template x-for="suggest in argSuggestions" :key="suggest.value">
                                <button
                                    type="button"
                                    class="seo-agent-workspace__arg-suggest-item"
                                    x-bind:data-value="suggest.value"
                                    x-on:click="applyArgSuggestion($el.dataset.value)"
                                    x-text="suggest.label"
                                ></button>
                            </template>
                        </div>

                        <x-seo-content-ai::seo-agent-chat.composer
                            :placeholder="__('seo-content-ai::filament.agent_workspace.composer_placeholder')"
                        >
                            <x-slot:below>
                                <div class="seo-agent-workspace__cli-global-keys">Tab: biến tiếp theo · Shift+Tab: biến trước</div>
                                <x-seo-content-ai::seo-agent-chat.disclaimer />
                            </x-slot:below>
                        </x-seo-content-ai::seo-agent-chat.composer>
                    </div>
                </section>
            </div>
            @endif
            {{-- No skill/modal/drawer UI in chat-only UX --}}
        @endunless
    </div>

    <script>
        (function () {
            if (window.__seoAgentWorkspaceAlpineRegistered) {
                return;
            }
            window.__seoAgentWorkspaceAlpineRegistered = true;

            var register = function () {
                if (! window.Alpine || typeof window.Alpine.data !== 'function') {
                    return;
                }
                if (window.__seoAgentWorkspaceDataRegistered) {
                    return;
                }
                window.__seoAgentWorkspaceDataRegistered = true;

                window.Alpine.data('seoAgentWorkspace', function () {
                    return {
                        conversationsOpen: false,
                        contextOpen: false,
                        paletteOpen: false,
                        paletteIndex: 0,
                        placeholderIndex: 0,
                        filteredCommands: [],
                        cliHelp: null,
                        skillBrowserOpen: false,
                        composer: '',
                        composerSubmitting: false,
                        argSuggestions: [],
                        argLoading: false,
                        _composerSyncTimer: null,
                        _argSuggestTimer: null,
                        _argCache: { project: null, member: null },
                        _argRequestToken: 0,
                        _pendingClientRequestId: null,
                        _restoreFocusAfterMorph: false,
                        stickToBottom: true,
                        _chatObserver: null,

                        init: function () {
                            var self = this;
                            this.composer = this.$wire.composerText || '';
                            this.refreshLocalPalette();
                            this.initChatAutoScroll();
                            this.$watch('$wire.composerText', function (value) {
                                if (self.composerSubmitting) {
                                    return;
                                }
                                if (typeof value === 'string' && value !== self.composer) {
                                    self.composer = value;
                                    self.refreshLocalPalette();
                                }
                            });
                            if (window.Livewire && typeof window.Livewire.hook === 'function' && ! window.__seoAgentFocusHook) {
                                window.__seoAgentFocusHook = true;
                                window.Livewire.hook('morph.updated', function () {
                                    var root = document.querySelector('.seo-agent-workspace');
                                    if (! root || ! root.__x) {
                                        return;
                                    }
                                    // Restore CLI focus after Livewire morph — not while user selects confirmation controls.
                                    var active = document.activeElement;
                                    if (active && active.closest && active.closest('[wire\\:click*="answerConversation"]')) {
                                        return;
                                    }
                                    var sel = window.getSelection && window.getSelection();
                                    if (sel && String(sel) !== '' && active && active.id !== 'seo-agent-composer-input') {
                                        return;
                                    }
                                    var el = document.getElementById('seo-agent-composer-input');
                                    if (! el || el.disabled) {
                                        return;
                                    }
                                    if (document.activeElement === el) {
                                        return;
                                    }
                                    // Only restore when we just submitted or server asked for focus.
                                    if (! root.querySelector('[x-data]') ) {
                                        return;
                                    }
                                });
                            }
                        },

                        initChatAutoScroll: function () {
                            var self = this;
                            this.$nextTick(function () {
                                var el = self.$refs.chatMessages;
                                if (! el) {
                                    return;
                                }
                                el.addEventListener('scroll', function () {
                                    var distance = el.scrollHeight - el.scrollTop - el.clientHeight;
                                    self.stickToBottom = distance < 80;
                                }, { passive: true });
                                if (self._chatObserver) {
                                    self._chatObserver.disconnect();
                                }
                                self._chatObserver = new MutationObserver(function () {
                                    self.scrollChatToBottom(false);
                                });
                                self._chatObserver.observe(el, {
                                    childList: true,
                                    subtree: true,
                                    characterData: true,
                                });
                                self.scrollChatToBottom(true);
                            });
                        },

                        scrollChatToBottom: function (force) {
                            var self = this;
                            if (! force && ! this.stickToBottom) {
                                return;
                            }
                            this.$nextTick(function () {
                                var el = self.$refs.chatMessages;
                                if (! el) {
                                    return;
                                }
                                el.scrollTop = el.scrollHeight;
                                requestAnimationFrame(function () {
                                    if (force || self.stickToBottom) {
                                        el.scrollTop = el.scrollHeight;
                                    }
                                });
                            });
                        },

                        catalogApi: function () {
                            return window.AgentCommandCatalogApi || null;
                        },

                        refreshLocalPalette: function () {
                            var text = String(this.composer || '').trimStart();
                            if (! text.startsWith('/')) {
                                this.paletteOpen = false;
                                this.filteredCommands = [];
                                return;
                            }
                            var spaceIdx = text.indexOf(' ');
                            var token = spaceIdx === -1 ? text : text.slice(0, spaceIdx);
                            var api = this.catalogApi();
                            var rows;
                            if (api) {
                                rows = api.filter(token);
                            } else {
                                var needle = token.replace(/^\//, '').toLowerCase();
                                rows = (window.AgentCommandCatalog || []).filter(function (row) {
                                    var name = String(row.name || '').toLowerCase().replace(/^\//, '');
                                    return needle === '' || name.indexOf(needle) !== -1;
                                });
                            }
                            this.filteredCommands = rows;
                            var groupOrder = ['Core', 'Site', 'Project', 'Member', 'Keyword', 'Audit', 'Publishing', 'Operation'];
                            this.filteredCommands.sort(function (a, b) {
                                var ga = groupOrder.indexOf(String(a.group || ''));
                                var gb = groupOrder.indexOf(String(b.group || ''));
                                if (ga < 0) ga = 99;
                                if (gb < 0) gb = 99;
                                if (ga !== gb) return ga - gb;
                                return String(a.name || '').localeCompare(String(b.name || ''));
                            });
                            // Command palette only while editing command token — no network.
                            this.paletteOpen = spaceIdx === -1;
                            // Reset highlight when filter set changes size / empty.
                            if (this.paletteIndex >= this.filteredCommands.length || this.paletteIndex < 0) {
                                this.paletteIndex = 0;
                            }
                            if (this.paletteOpen && this.filteredCommands[this.paletteIndex]) {
                                this.updateHelpFromRow(this.filteredCommands[this.paletteIndex]);
                            }
                        },

                        closePalette: function () {
                            this.paletteOpen = false;
                        },

                        updateHelpFromRow: function (row) {
                            if (! row) {
                                return;
                            }
                            this.cliHelp = {
                                example: row.example || '',
                            };
                        },

                        movePalette: function (delta) {
                            var self = this;
                            var items = this.filteredCommands || [];
                            if (! items.length) {
                                return;
                            }
                            this.paletteIndex = (this.paletteIndex + delta + items.length) % items.length;
                            this.updateHelpFromRow(items[this.paletteIndex]);
                            this.$nextTick(function () {
                                var root = self.$refs.paletteRoot;
                                if (! root) {
                                    return;
                                }
                                var el = root.querySelector('[data-index="' + self.paletteIndex + '"]');
                                if (el && typeof el.scrollIntoView === 'function') {
                                    el.scrollIntoView({ block: 'nearest' });
                                }
                            });
                        },

                        selectPaletteRow: function (row) {
                            if (! row || ! row.name) {
                                return;
                            }
                            var template = row.template || row.name;
                            this.composer = template;
                            this.paletteOpen = false;
                            this.placeholderIndex = 0;
                            this.updateHelpFromRow(row);
                            this.argSuggestions = [];
                            // Sync once after select — not on every keystroke for "/".
                            this.$wire.set('composerText', this.composer);
                            this.$nextTick(function () {
                                this.focusFirstPlaceholder();
                                this.scheduleArgSuggest();
                            }.bind(this));
                        },

                        selectPaletteElement: function (element) {
                            var command = element && element.value ? String(element.value) : '';
                            var api = this.catalogApi();
                            var row = api ? api.find(command) : null;
                            if (row) {
                                this.selectPaletteRow(row);
                                return;
                            }
                            if (command !== '') {
                                this.closePalette();
                                this.$wire.selectCommand(String(command));
                            }
                        },

                        selectBrowserElement: function (element) {
                            this.closeSkillBrowser();
                            this.selectPaletteElement(element);
                        },

                        selectPalette: function () {
                            var row = (this.filteredCommands || [])[this.paletteIndex];
                            if (row) {
                                this.selectPaletteRow(row);
                            }
                        },

                        openSkillBrowser: function () {
                            this.skillBrowserOpen = true;
                            this.$wire.openSkillBrowser();
                        },

                        closeSkillBrowser: function () {
                            this.skillBrowserOpen = false;
                        },

                        onEscape: function () {
                            if (this.skillBrowserOpen) {
                                this.closeSkillBrowser();
                                return;
                            }
                            if (this.paletteOpen) {
                                this.closePalette();
                                return;
                            }
                            this.argSuggestions = [];
                            this.cliHelp = null;
                        },

                        focusComposer: function (opts) {
                            var options = opts || {};
                            var force = !!options.force;
                            var self = this;
                            var restore = function () {
                                var active = document.activeElement;
                                if (! force) {
                                    if (active && active.closest && active.closest('[wire\\:click*="answerConversation"]')) {
                                        return;
                                    }
                                    var sel = window.getSelection && window.getSelection();
                                    if (sel && String(sel) !== '' && active && active.id !== 'seo-agent-composer-input') {
                                        return;
                                    }
                                }
                                var el = document.getElementById('seo-agent-composer-input');
                                if (! el || el.disabled) {
                                    return;
                                }
                                el.focus({ preventScroll: true });
                                if (typeof options.caret === 'number') {
                                    var pos = Math.max(0, Math.min(options.caret, el.value.length));
                                    el.setSelectionRange(pos, pos);
                                }
                            };
                            this.$nextTick(function () {
                                restore();
                                requestAnimationFrame(function () {
                                    restore();
                                    setTimeout(restore, 0);
                                });
                            });
                        },

                        onCliTemplateReady: function () {
                            var self = this;
                            this.placeholderIndex = 0;
                            this.$nextTick(function () {
                                self.composer = self.$wire.composerText || self.composer;
                                self.paletteOpen = false;
                                self.focusFirstPlaceholder();
                                self.scheduleArgSuggest();
                            });
                        },

                        findPlaceholders: function (text) {
                            var matches = [];
                            var re = /(-{1,2}[a-z0-9-]+)=""|(-{1,2}[a-z])=""/gi;
                            var m;
                            while ((m = re.exec(text)) !== null) {
                                var start = m.index + m[0].length - 2;
                                var end = m.index + m[0].length - 1;
                                matches.push({ start: start, end: end });
                            }
                            return matches;
                        },

                        focusFirstPlaceholder: function () {
                            var ph = this.findPlaceholders(this.composer);
                            if (! ph.length) {
                                return;
                            }
                            this.selectPlaceholder(0);
                        },

                        selectPlaceholder: function (idx) {
                            var el = document.getElementById('seo-agent-composer-input');
                            if (! el) {
                                return;
                            }
                            var ph = this.findPlaceholders(this.composer);
                            if (! ph.length) {
                                el.focus();
                                var end = this.composer.length;
                                el.setSelectionRange(end, end);
                                return;
                            }
                            var p = ph[idx % ph.length];
                            this.placeholderIndex = idx % ph.length;
                            el.focus();
                            el.setSelectionRange(p.start, p.end);
                            this.scheduleArgSuggest();
                        },

                        applyArgSuggestion: function (value) {
                            var el = document.getElementById('seo-agent-composer-input');
                            if (! el) {
                                return;
                            }
                            var text = this.composer;
                            var cursor = el.selectionStart || text.length;
                            var before = text.slice(0, cursor);
                            var after = text.slice(cursor);
                            var replaced = before.replace(/((?:--project-id|-p|--member-id|--member)=)([^"\s]*)$/i, '$1' + value);
                            if (replaced === before) {
                                return;
                            }
                            this.composer = replaced + after;
                            this.argSuggestions = [];
                            this.argLoading = false;
                            this.$wire.set('composerText', this.composer);
                            this.$nextTick(function () {
                                this.focusComposer();
                            }.bind(this));
                        },

                        detectArgSuggest: function (text, cursor) {
                            var before = text.slice(0, cursor);
                            var projectMatch = before.match(/(?:--project-id|-p)=("?)([^"\s]*)$/i);
                            if (projectMatch) {
                                return { type: 'project', query: projectMatch[2] || '' };
                            }
                            var memberMatch = before.match(/(?:--member-id|--member)=("?)([^"\s]*)$/i);
                            if (memberMatch) {
                                return { type: 'member', query: memberMatch[2] || '' };
                            }
                            return null;
                        },

                        filterCachedArgs: function (rows, query) {
                            var q = String(query || '').toLowerCase();
                            if (! q) {
                                return rows.slice();
                            }
                            return rows.filter(function (row) {
                                var label = String(row.label || '').toLowerCase();
                                var value = String(row.value || '').toLowerCase();
                                return label.indexOf(q) !== -1 || value.indexOf(q) !== -1;
                            });
                        },

                        scheduleArgSuggest: function () {
                            var self = this;
                            var el = document.getElementById('seo-agent-composer-input');
                            if (! el) {
                                return;
                            }
                            clearTimeout(this._argSuggestTimer);
                            this._argSuggestTimer = setTimeout(function () {
                                var ctx = self.detectArgSuggest(self.composer, el.selectionStart || 0);
                                if (! ctx) {
                                    self.argSuggestions = [];
                                    self.argLoading = false;
                                    return;
                                }
                                var cached = self._argCache[ctx.type];
                                if (Array.isArray(cached)) {
                                    self.argSuggestions = self.filterCachedArgs(cached, ctx.query);
                                    self.argLoading = false;
                                    return;
                                }
                                var token = ++self._argRequestToken;
                                self.argLoading = true;
                                self.$wire.getCliArgumentSuggestions(String(ctx.type), String(ctx.query || '')).then(function () {
                                    if (token !== self._argRequestToken) {
                                        return;
                                    }
                                    var rows = self.$wire.cliArgumentSuggestions || [];
                                    self._argCache[ctx.type] = Array.isArray(rows) ? rows.slice() : [];
                                    self.argSuggestions = self.filterCachedArgs(self._argCache[ctx.type], ctx.query);
                                    self.argLoading = false;
                                }).catch(function () {
                                    if (token !== self._argRequestToken) {
                                        return;
                                    }
                                    self.argSuggestions = [];
                                    self.argLoading = false;
                                });
                            }, 120);
                        },

                        onComposerInput: function (event) {
                            var el = event.target;
                            el.style.height = 'auto';
                            el.style.height = Math.min(el.scrollHeight, 160) + 'px';
                            this.paletteIndex = 0;
                            // Filter locally — no Livewire request for "/".
                            this.refreshLocalPalette();
                            this.scheduleArgSuggest();
                        },

                        onComposerArrow: function (delta) {
                            if (this.paletteOpen) {
                                this.movePalette(delta);
                            }
                        },

                        onComposerTab: function (event) {
                            if (this.paletteOpen && (this.filteredCommands || []).length) {
                                event.preventDefault();
                                this.selectPalette();
                                return;
                            }
                            var ph = this.findPlaceholders(this.composer);
                            if (ph.length) {
                                event.preventDefault();
                                if (event.shiftKey) {
                                    this.selectPlaceholder(this.placeholderIndex - 1 + ph.length);
                                } else if (this.placeholderIndex + 1 < ph.length) {
                                    this.selectPlaceholder(this.placeholderIndex + 1);
                                } else {
                                    // Past last placeholder: caret to end.
                                    event.preventDefault();
                                    var el = document.getElementById('seo-agent-composer-input');
                                    if (el) {
                                        var end = this.composer.length;
                                        el.focus();
                                        el.setSelectionRange(end, end);
                                    }
                                    this.placeholderIndex = ph.length;
                                }
                            }
                        },

                        onComposerEnter: function (event) {
                            if (event.shiftKey) {
                                return;
                            }
                            event.preventDefault();
                            event.stopPropagation();
                            if (this.composerSubmitting || this.$wire.composerSubmitting) {
                                return;
                            }
                            if (this.paletteOpen && (this.filteredCommands || []).length) {
                                this.selectPalette();
                                return;
                            }
                            this.submitAgentComposer();
                        },

                        submitAgentComposer: function () {
                            var self = this;
                            if (this.composerSubmitting || this.$wire.composerSubmitting) {
                                return;
                            }
                            var message = String(this.composer || '').trim();
                            if (message === '') {
                                return;
                            }
                            this.composerSubmitting = true;
                            this.stickToBottom = true;
                            this.scrollChatToBottom(true);
                            this.paletteOpen = false;
                            this.argSuggestions = [];
                            this._pendingClientRequestId = 'cr_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 10);
                            this.$wire.sendMessage(message, this._pendingClientRequestId).then(function () {
                                self.composer = '';
                                self.cliHelp = null;
                                self.stickToBottom = true;
                                self.scrollChatToBottom(true);
                                self.focusComposer({ force: true, caret: 0 });
                            }).catch(function () {
                                self.focusComposer({ force: true });
                            }).finally(function () {
                                self.composerSubmitting = false;
                                self._pendingClientRequestId = null;
                                self.stickToBottom = true;
                                self.scrollChatToBottom(true);
                                self.focusComposer({ force: true });
                            });
                        }
                    };
                });
            };

            if (window.Alpine) {
                register();
            }
            document.addEventListener('alpine:init', register);
        })();
    </script>
</x-filament-panels::page>
