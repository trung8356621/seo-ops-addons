@php
    use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceDeepLink;
    use Omnichannel\Addons\Content\Services\TeamChatAttachmentService;
    use Omnichannel\Addons\Seo\Support\SeoAccessControl;

    // Orphan Global AI Chat API disabled — Agent Workspace owns AI. Keep empty URLs so dead Alpine helpers do not call route().
    $modelsUrl = '';
    $chatUrl = '';
    $teamMessagesUrl = route('seo.team-messages.index');
    $teamStoreUrl = route('seo.team-messages.store');
    $storageKey = 'seo_global_ai_chat_'.((int) auth()->id());
    $currentUserId = (int) auth()->id();
    $isContentManager = SeoAccessControl::isContentManager();
    // Star tab is Agent Workspace launcher — not in-popup AI runtime.
    $canUseAiChat = ! $isContentManager;
    // Long-lived SSE holds an HTTP worker. On php artisan serve (single-thread) that
    // freezes every Filament navigation (blank publishing-queue / Livewire hang).
    // Local + cli-server always use short JSON poll; SSE only outside local.
    $teamSseEnabled = PHP_SAPI !== 'cli-server' && ! app()->environment('local');
    $agentDeepLink = AgentWorkspaceDeepLink::forCurrentRequest();
    $teamChatConfig = app(TeamChatAttachmentService::class)->clientConfig();
    $mediaImportUrl = route('seo.media.import-url');
    $teamAccept = implode(',', array_map(
        static fn (string $ext): string => '.'.$ext,
        $teamChatConfig['allowed_extensions'],
    ));
    $workspaceChatI18n = [
        'browser_fallback_body' => __('seo-content-ai::filament.workspace_chat.browser_notification_body'),
        'unknown_sender' => __('seo-content-ai::filament.workspace_chat.notify_unknown_sender'),
        'open_agent_workspace' => __('seo-content-ai::filament.agent_workspace.open_workspace'),
        'agent_missing_site' => AgentWorkspaceDeepLink::MISSING_SITE_MESSAGE,
    ];
@endphp

@vite('addons/ai-prompt/resources/css/global-ai-chat.css')

<div
    class="seo-global-chat"
    x-data="{
        openChat: false,
        activeTab: 'team',
        loading: false,
        loadingModels: true,
        message: '',
        imageFile: null,
        imagePreview: '',
        models: [],
        selectedModel: '',
        messages: [],
        teamMessages: [],
        teamLoading: false,
        teamSending: false,
        teamFile: null,
        teamFilePreview: '',
        teamFileIsImage: false,
        teamChatConfig: @js($teamChatConfig),
        canUseAiChat: @js($canUseAiChat),
        agentWorkspaceUrl: @js($agentDeepLink['url']),
        agentWorkspaceError: @js($agentDeepLink['message']),
        agentLaunching: false,
        teamAccept: @js($teamAccept),
        lastTeamMessageId: 0,
        lastReadTeamMessageId: 0,
        teamUnreadCount: 0,
        teamEventSource: null,
        teamSseAfterId: null,
        workspaceChatI18n: @js($workspaceChatI18n),
        currentUserId: @js($currentUserId),
        storageKey: @js($storageKey),
        modelsUrl: @js($modelsUrl),
        chatUrl: @js($chatUrl),
        teamMessagesUrl: @js($teamMessagesUrl),
        teamStoreUrl: @js($teamStoreUrl),
        teamSseEnabled: @js($teamSseEnabled),
        teamPollTimer: null,
        teamPollAfterId: null,
        teamUnreadPollTimer: null,
        workspaceOwnerId: null,
        imageEditorOpen: false,
        imageEditorTarget: null,
        drawColor: '#ef4444',
        drawLineWidth: 4,
        drawIsActive: false,
        drawLastX: 0,
        drawLastY: 0,
        drawHistory: [],
        drawHistoryIndex: -1,
        drawColors: ['#111827', '#ef4444', '#eab308', '#22c55e', '#06b6d4', '#a855f7', '#ffffff'],
        attachmentLightboxOpen: false,
        attachmentLightboxUrl: '',
        attachmentLightboxName: '',
        mediaImportUrl: @js($mediaImportUrl),

        init() {
            this.restore();
            // AI star = Agent Workspace launcher. No in-popup model/AI runtime.
            this.loadingModels = false;
            this.activeTab = 'team';
            this.models = [];
            this.selectedModel = '';

            // Badge-only while panel closed — never open SSE/poll stream on every page.
            this.refreshTeamUnreadOnInit().then(() => {
                this.startTeamUnreadPoll();
            });
            this.requestBrowserNotificationPermission();

            this._onGlobalAiChatImageSelected = (event) => {
                this.applyLibraryImage(event?.detail ?? {});
            };
            window.addEventListener('seo-global-ai-chat-image-selected', this._onGlobalAiChatImageSelected);
        },

        openAgentWorkspace() {
            if (this.agentLaunching) {
                return;
            }

            const url = String(this.agentWorkspaceUrl || '').trim();
            if (! url || /\/seo\/?(\?|$)/.test(url)) {
                const message = this.agentWorkspaceError
                    || this.workspaceChatI18n.agent_missing_site
                    || 'Vui lòng chọn website trước khi mở Agent Workspace.';
                window.alert(message);
                return;
            }

            this.agentLaunching = true;
            this.closePanel();
            window.location.assign(url);
        },

        destroy() {
            this.stopTeamRealtime();
            this.stopTeamUnreadPoll();
            if (this._onGlobalAiChatImageSelected) {
                window.removeEventListener('seo-global-ai-chat-image-selected', this._onGlobalAiChatImageSelected);
            }
        },

        async loadModels() {
            try {
                const response = await fetch(this.modelsUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await response.json();
                this.models = Array.isArray(data.models) ? data.models : [];
                const savedModel = localStorage.getItem(`${this.storageKey}_model`);
                const matched = this.models.find((model) => String(model.id) === String(savedModel));
                this.selectedModel = String(matched?.id ?? this.models[0]?.id ?? '');
            } catch (error) {
                this.models = [];
            } finally {
                this.loadingModels = false;
            }
        },

        restore() {
            try {
                const stored = JSON.parse(localStorage.getItem(this.storageKey) || '[]');
                this.messages = Array.isArray(stored)
                    ? stored.filter((item) => ['user', 'assistant'].includes(item?.role) && item?.content)
                    : [];
            } catch (error) {
                this.messages = [];
            }
        },

        persist() {
            const persistent = this.messages
                .filter((item) => ! item.loading)
                .slice(-30)
                .map(({ role, content, error }) => ({ role, content, error: Boolean(error) }));
            localStorage.setItem(this.storageKey, JSON.stringify(persistent));
        },

        openPanel() {
            this.openChat = true;
            if (this.activeTab === 'team') {
                this.loadTeamMessages();
            } else {
                this.scrollToBottom();
            }
        },

        closePanel() {
            this.openChat = false;
            this.closeAttachmentLightbox();
            // Release stream worker; keep lightweight unread badge poll.
            this.stopTeamRealtime();
            this.startTeamUnreadPoll();
        },

        switchTab(tab) {
            if (tab === 'ai') {
                this.openAgentWorkspace();
                return;
            }

            this.activeTab = 'team';
            this.loadTeamMessages();
        },

        startTeamRealtime(afterId = null) {
            if (! this.teamSseEnabled) {
                this.startTeamPoll(afterId);
                return;
            }

            this.startTeamSse(afterId);
        },

        stopTeamRealtime() {
            this.stopTeamSse();
            this.stopTeamPoll();
        },

        startTeamSse(afterId = null) {
            const resolvedAfterId = afterId !== null
                ? Math.max(0, Number(afterId) || 0)
                : Math.max(0, this.lastTeamMessageId);

            if (this.teamEventSource && this.teamSseAfterId === resolvedAfterId) {
                return;
            }

            this.connectTeamSse(resolvedAfterId);
        },

        startTeamPoll(afterId = null) {
            const resolvedAfterId = afterId !== null
                ? Math.max(0, Number(afterId) || 0)
                : Math.max(0, this.lastTeamMessageId);

            this.stopTeamSse();
            this.teamPollAfterId = resolvedAfterId;
            this.pollTeamMessages(true);

            if (this.teamPollTimer) {
                return;
            }

            this.teamPollTimer = window.setInterval(() => {
                this.pollTeamMessages(false);
            }, 4000);
        },

        stopTeamPoll() {
            if (this.teamPollTimer) {
                window.clearInterval(this.teamPollTimer);
                this.teamPollTimer = null;
            }
            this.teamPollAfterId = null;
        },

        startTeamUnreadPoll() {
            if (this.teamUnreadPollTimer) {
                return;
            }

            this.teamUnreadPollTimer = window.setInterval(() => {
                if (this.openChat) {
                    return;
                }
                this.refreshTeamUnreadOnInit();
            }, 15000);
        },

        stopTeamUnreadPoll() {
            if (this.teamUnreadPollTimer) {
                window.clearInterval(this.teamUnreadPollTimer);
                this.teamUnreadPollTimer = null;
            }
        },

        async pollTeamMessages(isInitial = false) {
            const afterId = Math.max(0, Number(this.teamPollAfterId ?? this.lastTeamMessageId) || 0);

            try {
                const params = new URLSearchParams({
                    poll: '1',
                    after_id: String(afterId),
                });
                const response = await fetch(`${this.teamMessagesUrl}?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await response.json();
                if (! response.ok) {
                    return;
                }

                if (data.config) {
                    this.teamChatConfig = data.config;
                }
                if (typeof data.can_use_ai === 'boolean') {
                    this.canUseAiChat = data.can_use_ai;
                }
                if (Number(data.owner_id) > 0) {
                    this.workspaceOwnerId = Number(data.owner_id);
                }

                const rows = Array.isArray(data.messages) ? data.messages : [];
                if (isInitial && afterId === 0) {
                    this.teamMessages = [];
                }
                if (rows.length > 0) {
                    this.mergeTeamMessages(rows);
                    const maxId = rows.reduce((max, item) => Math.max(max, Number(item?.id) || 0), afterId);
                    this.teamPollAfterId = maxId;
                    this.lastTeamMessageId = Math.max(this.lastTeamMessageId, maxId);
                    this.persistTeamCursor();
                }

                if (data.history_end || isInitial) {
                    this.teamLoading = false;
                    this.markTeamAsRead();
                    this.scrollTeamToBottom();
                }
            } catch (error) {
                console.error(error);
                if (isInitial) {
                    this.teamLoading = false;
                }
            }
        },

        connectTeamSse(afterId) {
            this.stopTeamPoll();
            this.stopTeamSse();
            this.teamSseAfterId = afterId;

            const url = `${this.teamMessagesUrl}?after_id=${afterId}`;
            const source = new EventSource(url);
            this.teamEventSource = source;

            source.addEventListener('history_end', (event) => {
                try {
                    const data = JSON.parse(event.data || '{}');
                    if (data.config) {
                        this.teamChatConfig = data.config;
                    }
                    if (typeof data.can_use_ai === 'boolean') {
                        this.canUseAiChat = data.can_use_ai;
                    }
                    if (Number(data.owner_id) > 0) {
                        this.workspaceOwnerId = Number(data.owner_id);
                    }
                } catch (error) {
                    console.error(error);
                }

                this.teamLoading = false;
                this.markTeamAsRead();
                this.scrollTeamToBottom();
            });

            source.onmessage = (event) => {
                if (! event?.data) {
                    return;
                }

                try {
                    const item = JSON.parse(event.data);
                    this.handleIncomingTeamMessage(item);
                } catch (error) {
                    console.error(error);
                }
            };

            source.onerror = () => {
                if (source.readyState === EventSource.CLOSED) {
                    this.stopTeamSse();
                }
            };
        },

        stopTeamSse() {
            if (this.teamEventSource) {
                this.teamEventSource.close();
                this.teamEventSource = null;
            }

            this.teamSseAfterId = null;
        },

        handleIncomingTeamMessage(item) {
            if (! item || ! Number(item.id)) {
                return;
            }

            if (Number(item.owner_id) > 0) {
                this.workspaceOwnerId = Number(item.owner_id);
            }

            const viewingTeam = this.openChat && this.activeTab === 'team';

            if (viewingTeam) {
                const beforeCount = this.teamMessages.length;
                this.mergeTeamMessages([item]);

                if (this.teamMessages.length > beforeCount) {
                    this.scrollTeamToBottom();
                }

                this.markTeamAsRead();
                return;
            }

            const id = Number(item.id) || 0;
            if (! id || id <= this.lastTeamMessageId) {
                return;
            }

            this.lastTeamMessageId = id;

            if (! item.is_mine) {
                this.teamUnreadCount++;
                this.showTeamBrowserNotification(item);
            }

            this.persistTeamCursor();
        },

        markTeamAsRead() {
            this.teamUnreadCount = 0;
            if (this.lastTeamMessageId > this.lastReadTeamMessageId) {
                this.lastReadTeamMessageId = this.lastTeamMessageId;
                localStorage.setItem(`${this.storageKey}_team_read`, String(this.lastReadTeamMessageId));
            }
        },

        persistTeamCursor() {
            localStorage.setItem(`${this.storageKey}_team_last`, String(this.lastTeamMessageId));
        },

        async refreshTeamUnreadOnInit() {
            this.lastReadTeamMessageId = Number(localStorage.getItem(`${this.storageKey}_team_read`) || 0);
            this.lastTeamMessageId = Number(localStorage.getItem(`${this.storageKey}_team_last`) || 0);

            try {
                const params = new URLSearchParams({
                    unread_summary: '1',
                    since_id: String(this.lastReadTeamMessageId),
                });
                const response = await fetch(`${this.teamMessagesUrl}?${params.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await response.json();
                if (! response.ok) {
                    return;
                }

                this.teamUnreadCount = Math.max(0, Number(data.unread_count) || 0);
                const latestId = Number(data.latest_message_id) || 0;
                if (latestId > this.lastTeamMessageId) {
                    this.lastTeamMessageId = latestId;
                    this.persistTeamCursor();
                }
                if (Number(data.owner_id) > 0) {
                    this.workspaceOwnerId = Number(data.owner_id);
                }
            } catch (error) {
                console.error(error);
            }
        },

        showTeamBrowserNotification(item) {
            if (! item || item.is_mine) {
                return;
            }
            if (this.openChat && this.activeTab === 'team') {
                return;
            }
            if (! ('Notification' in window) || Notification.permission !== 'granted') {
                return;
            }

            const name = String(item.user_name || this.workspaceChatI18n.unknown_sender || 'Thành viên');
            let body = String(item.message || '').trim();
            if (body === '' && item.attachment_name) {
                body = '📎 ' + String(item.attachment_name);
            }
            if (body === '') {
                body = String(this.workspaceChatI18n.browser_fallback_body || 'Tin nhắn mới');
            }

            try {
                new Notification(name, {
                    body,
                    tag: 'seo-workspace-chat-' + String(item.id),
                });
            } catch (error) {
                // ignore
            }
        },

        requestBrowserNotificationPermission() {
            if (! ('Notification' in window) || Notification.permission !== 'default') {
                return;
            }

            Notification.requestPermission().catch(() => {});
        },

        mergeTeamMessages(incoming) {
            if (! Array.isArray(incoming) || incoming.length === 0) {
                return;
            }

            const known = new Set(this.teamMessages.map((item) => Number(item.id)));
            incoming.forEach((item) => {
                const id = Number(item.id);
                if (! id || known.has(id)) {
                    return;
                }
                this.teamMessages.push(item);
                known.add(id);
                if (id > this.lastTeamMessageId) {
                    this.lastTeamMessageId = id;
                    this.persistTeamCursor();
                }
            });

            this.teamMessages.sort((a, b) => Number(a.id) - Number(b.id));
        },

        loadTeamMessages() {
            this.teamLoading = true;
            this.teamMessages = [];
            this.stopTeamRealtime();
            if (! this.teamSseEnabled) {
                this.startTeamPoll(0);
                return;
            }
            this.connectTeamSse(0);
        },

        async sendTeamMessage() {
            const text = this.message.trim();
            if (this.teamSending || (text === '' && ! this.teamFile)) {
                return;
            }

            this.teamSending = true;
            const pendingText = text;
            const pendingFile = this.teamFile;
            this.message = '';
            this.clearTeamFile();
            this.resizeInput();

            try {
                const form = new FormData();
                if (pendingText !== '') {
                    form.append('message', pendingText);
                }
                if (pendingFile) {
                    form.append('file', pendingFile);
                }

                const response = await fetch(this.teamStoreUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    credentials: 'same-origin',
                    body: form,
                });
                const data = await response.json();
                if (! response.ok) {
                    throw new Error(data.message || 'Không gửi được tin nhắn.');
                }

                if (data.message) {
                    this.mergeTeamMessages([data.message]);
                    this.markTeamAsRead();
                    this.scrollTeamToBottom();
                }
            } catch (error) {
                this.message = pendingText;
                if (pendingFile) {
                    this.setTeamFile(pendingFile);
                }
                window.alert(error.message || 'Không gửi được tin nhắn nhóm.');
            } finally {
                this.teamSending = false;
                this.$nextTick(() => this.$refs.messageInput?.focus());
            }
        },

        isAiInvocation(text) {
            const value = String(text || '').trim().toLowerCase();
            return value.startsWith('@ai ') || value === '@ai';
        },

        stripAiPrefix(text) {
            const value = String(text || '').trim();
            if (value.toLowerCase() === '@ai') {
                return '';
            }
            if (value.toLowerCase().startsWith('@ai ')) {
                return value.slice(4).trim();
            }
            return value;
        },

        submitComposer() {
            const text = this.message.trim();

            // @ai in Team composer launches Agent Workspace — never popup AI API.
            if (this.canUseAiChat && this.isAiInvocation(text)) {
                this.openAgentWorkspace();
                return;
            }

            this.sendTeamMessage();
        },

        validateTeamFile(file) {
            if (! file) {
                return true;
            }

            const allowed = Array.isArray(this.teamChatConfig?.allowed_extensions)
                ? this.teamChatConfig.allowed_extensions
                : [];
            const maxBytes = Number(this.teamChatConfig?.max_file_size_bytes || 0);
            const extension = String(file.name || '').split('.').pop()?.toLowerCase() || '';
            const mimeExtension = String(file.type || '').split('/')[1]?.toLowerCase() || '';
            const normalizedMimeExtension = mimeExtension === 'jpeg' ? 'jpg' : mimeExtension;
            const extensionOk = extension !== ''
                ? allowed.includes(extension)
                : (
                    normalizedMimeExtension !== ''
                    && (
                        allowed.includes(normalizedMimeExtension)
                        || (normalizedMimeExtension === 'jpg' && allowed.includes('jpeg'))
                    )
                );

            if (allowed.length > 0 && ! extensionOk) {
                window.alert('Loại tệp không được phép.');
                return false;
            }

            if (maxBytes > 0 && Number(file.size || 0) > maxBytes) {
                window.alert(`Tệp vượt quá ${this.teamChatConfig?.max_file_size_mb || 0} MB.`);
                return false;
            }

            return true;
        },

        setTeamFile(file) {
            if (! file || ! this.validateTeamFile(file)) {
                return;
            }

            this.clearTeamFile();
            this.teamFile = file;
            if (String(file.type || '').startsWith('image/')) {
                this.teamFileIsImage = true;
                this.teamFilePreview = URL.createObjectURL(file);
            } else {
                this.teamFileIsImage = false;
                this.teamFilePreview = '';
            }
        },

        selectTeamFile(event) {
            const file = event.target.files?.[0] ?? null;
            if (! file) {
                return;
            }

            this.setTeamFile(file);
            if (this.$refs.teamFileInput) {
                this.$refs.teamFileInput.value = '';
            }
        },

        clearTeamFile() {
            if (this.teamFilePreview) {
                URL.revokeObjectURL(this.teamFilePreview);
            }
            this.teamFilePreview = '';
            this.teamFileIsImage = false;
            this.teamFile = null;
            if (this.$refs.teamFileInput) {
                this.$refs.teamFileInput.value = '';
            }
            if (this.imageEditorOpen && this.imageEditorTarget === 'team') {
                this.closeImageEditor();
            }
        },

        openImageEditor(target) {
            if (target === 'team' && (! this.teamFileIsImage || ! this.teamFilePreview)) {
                return;
            }
            if (target === 'ai' && ! this.imagePreview) {
                return;
            }

            this.imageEditorTarget = target;
            this.imageEditorOpen = true;
            this.drawColor = '#ef4444';
            this.drawHistory = [];
            this.drawHistoryIndex = -1;
            this.drawIsActive = false;

            this.$nextTick(() => this.initImageEditor());
        },

        closeImageEditor() {
            this.imageEditorOpen = false;
            this.imageEditorTarget = null;
            this.drawIsActive = false;
            this.drawHistory = [];
            this.drawHistoryIndex = -1;
        },

        openAttachmentLightbox(url, name = '') {
            if (! url) {
                return;
            }

            this.attachmentLightboxUrl = String(url);
            this.attachmentLightboxName = String(name || 'Ảnh đính kèm');
            this.attachmentLightboxOpen = true;
        },

        closeAttachmentLightbox() {
            this.attachmentLightboxOpen = false;
            this.attachmentLightboxUrl = '';
            this.attachmentLightboxName = '';
        },

        getEditorSourceUrl() {
            return this.imageEditorTarget === 'ai' ? this.imagePreview : this.teamFilePreview;
        },

        initImageEditor() {
            const stage = this.$refs.editorStage;
            const baseCanvas = this.$refs.editorBaseCanvas;
            const drawCanvas = this.$refs.editorDrawCanvas;
            const sourceUrl = this.getEditorSourceUrl();

            if (! stage || ! baseCanvas || ! drawCanvas || ! sourceUrl) {
                return;
            }

            const image = new Image();
            image.onload = () => {
                const maxWidth = Math.max(280, stage.clientWidth - 24);
                const maxHeight = Math.max(220, stage.clientHeight - 24);
                let width = image.naturalWidth || image.width;
                let height = image.naturalHeight || image.height;

                if (width <= 0 || height <= 0) {
                    return;
                }

                const scale = Math.min(maxWidth / width, maxHeight / height, 1);
                width = Math.max(1, Math.round(width * scale));
                height = Math.max(1, Math.round(height * scale));

                baseCanvas.width = width;
                baseCanvas.height = height;
                drawCanvas.width = width;
                drawCanvas.height = height;

                const baseCtx = baseCanvas.getContext('2d');
                const drawCtx = drawCanvas.getContext('2d');
                if (! baseCtx || ! drawCtx) {
                    return;
                }

                baseCtx.clearRect(0, 0, width, height);
                baseCtx.drawImage(image, 0, 0, width, height);
                drawCtx.clearRect(0, 0, width, height);

                this.saveDrawSnapshot();
            };
            image.onerror = () => {
                window.alert('Không tải được ảnh để chỉnh sửa.');
                this.closeImageEditor();
            };
            image.src = sourceUrl;
        },

        getDrawCanvasPoint(event) {
            const canvas = this.$refs.editorDrawCanvas;
            if (! canvas) {
                return { x: 0, y: 0 };
            }

            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / Math.max(rect.width, 1);
            const scaleY = canvas.height / Math.max(rect.height, 1);

            return {
                x: (event.clientX - rect.left) * scaleX,
                y: (event.clientY - rect.top) * scaleY,
            };
        },

        startDrawStroke(event) {
            if (! this.imageEditorOpen || event.button > 0) {
                return;
            }

            const canvas = this.$refs.editorDrawCanvas;
            if (! canvas) {
                return;
            }

            event.preventDefault();
            canvas.setPointerCapture?.(event.pointerId);
            const point = this.getDrawCanvasPoint(event);
            this.drawIsActive = true;
            this.drawLastX = point.x;
            this.drawLastY = point.y;
        },

        moveDrawStroke(event) {
            if (! this.drawIsActive) {
                return;
            }

            const canvas = this.$refs.editorDrawCanvas;
            const ctx = canvas?.getContext('2d');
            if (! ctx) {
                return;
            }

            event.preventDefault();
            const point = this.getDrawCanvasPoint(event);
            ctx.strokeStyle = this.drawColor;
            ctx.lineWidth = this.drawLineWidth;
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
            ctx.beginPath();
            ctx.moveTo(this.drawLastX, this.drawLastY);
            ctx.lineTo(point.x, point.y);
            ctx.stroke();
            this.drawLastX = point.x;
            this.drawLastY = point.y;
        },

        endDrawStroke(event) {
            if (! this.drawIsActive) {
                return;
            }

            const canvas = this.$refs.editorDrawCanvas;
            canvas?.releasePointerCapture?.(event.pointerId);
            this.drawIsActive = false;
            this.saveDrawSnapshot();
        },

        saveDrawSnapshot() {
            const canvas = this.$refs.editorDrawCanvas;
            const ctx = canvas?.getContext('2d');
            if (! canvas || ! ctx) {
                return;
            }

            const snapshot = ctx.getImageData(0, 0, canvas.width, canvas.height);
            this.drawHistory = this.drawHistory.slice(0, this.drawHistoryIndex + 1);
            this.drawHistory.push(snapshot);
            this.drawHistoryIndex = this.drawHistory.length - 1;
        },

        applyDrawSnapshot(index) {
            const snapshot = this.drawHistory[index];
            const canvas = this.$refs.editorDrawCanvas;
            const ctx = canvas?.getContext('2d');
            if (! snapshot || ! canvas || ! ctx) {
                return;
            }

            ctx.putImageData(snapshot, 0, 0);
        },

        undoDrawStroke() {
            if (this.drawHistoryIndex <= 0) {
                return;
            }

            this.drawHistoryIndex -= 1;
            this.applyDrawSnapshot(this.drawHistoryIndex);
        },

        redoDrawStroke() {
            if (this.drawHistoryIndex >= this.drawHistory.length - 1) {
                return;
            }

            this.drawHistoryIndex += 1;
            this.applyDrawSnapshot(this.drawHistoryIndex);
        },

        saveEditedImage() {
            const baseCanvas = this.$refs.editorBaseCanvas;
            const drawCanvas = this.$refs.editorDrawCanvas;
            if (! baseCanvas || ! drawCanvas) {
                return;
            }

            const merged = document.createElement('canvas');
            merged.width = baseCanvas.width;
            merged.height = baseCanvas.height;
            const ctx = merged.getContext('2d');
            if (! ctx) {
                return;
            }

            ctx.drawImage(baseCanvas, 0, 0);
            ctx.drawImage(drawCanvas, 0, 0);

            merged.toBlob((blob) => {
                if (! blob) {
                    window.alert('Không lưu được ảnh đã vẽ.');
                    return;
                }

                const sourceFile = this.imageEditorTarget === 'ai' ? this.imageFile : this.teamFile;
                const originalName = String(sourceFile?.name || 'image.png');
                const baseName = originalName.replace(/\.[^.]+$/u, '') || 'image';
                const fileName = `${baseName}-annotated.png`;
                const editedFile = new File([blob], fileName, { type: 'image/png' });

                if (this.imageEditorTarget === 'ai') {
                    if (this.imagePreview) {
                        URL.revokeObjectURL(this.imagePreview);
                    }
                    this.imageFile = editedFile;
                    this.imagePreview = URL.createObjectURL(blob);
                } else {
                    if (this.teamFilePreview) {
                        URL.revokeObjectURL(this.teamFilePreview);
                    }
                    this.teamFile = editedFile;
                    this.teamFileIsImage = true;
                    this.teamFilePreview = URL.createObjectURL(blob);
                }

                this.closeImageEditor();
            }, 'image/png', 0.92);
        },

        handlePaste(event) {
            const items = event.clipboardData?.items;
            if (! items) {
                return;
            }

            for (const item of items) {
                if (! String(item.type || '').startsWith('image/')) {
                    continue;
                }

                event.preventDefault();
                const file = item.getAsFile();
                if (! file) {
                    return;
                }

                if (this.activeTab === 'team') {
                    this.setTeamFile(file);
                } else if (this.activeTab === 'ai' && this.canUseAiChat) {
                    this.clearImage();
                    this.imageFile = file;
                    this.imagePreview = URL.createObjectURL(file);
                }

                return;
            }
        },

        selectImage(event) {
            const file = event.target.files?.[0] ?? null;
            this.clearImage();
            if (! file) return;

            this.imageFile = file;
            this.imagePreview = URL.createObjectURL(file);
        },

        clearImage() {
            if (this.imagePreview) URL.revokeObjectURL(this.imagePreview);
            this.imagePreview = '';
            this.imageFile = null;
            if (this.$refs.imageInput) this.$refs.imageInput.value = '';
            if (this.imageEditorOpen && this.imageEditorTarget === 'ai') {
                this.closeImageEditor();
            }
        },

        openMediaLibrary() {
            if (! this.canUseAiChat || this.activeTab !== 'ai' || this.loading) {
                return;
            }

            window.dispatchEvent(new CustomEvent('seo-open-workspace-media-picker', {
                detail: { mode: 'ai-chat' },
            }));
        },

        async resolveLibraryImageUrl(payload) {
            const directUrl = String(payload?.url || '').trim();
            if (directUrl === '') {
                throw new Error('Ảnh không có URL.');
            }

            if (Number(payload?.seoMediaId || 0) > 0) {
                return directUrl;
            }

            const response = await fetch(this.mediaImportUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ url: directUrl }),
            });
            const data = await response.json();
            if (! response.ok || ! data.success || ! data.url) {
                throw new Error(data.message || 'Không import được ảnh từ thư viện.');
            }

            return String(data.url);
        },

        async applyLibraryImage(payload) {
            try {
                const resolvedUrl = await this.resolveLibraryImageUrl(payload);
                const response = await fetch(resolvedUrl, { credentials: 'same-origin' });
                if (! response.ok) {
                    throw new Error('Không tải được file ảnh.');
                }

                const blob = await response.blob();
                if (! String(blob.type || '').startsWith('image/')) {
                    throw new Error('Tệp đã chọn không phải ảnh.');
                }

                const slug = String(payload?.slug || 'library-image').replace(/[^\w.-]+/g, '-') || 'library-image';
                const ext = blob.type === 'image/png'
                    ? 'png'
                    : blob.type === 'image/webp'
                        ? 'webp'
                        : blob.type === 'image/gif'
                            ? 'gif'
                            : 'jpg';

                this.clearImage();
                this.imageFile = new File([blob], `${slug}.${ext}`, { type: blob.type || 'image/jpeg' });
                this.imagePreview = URL.createObjectURL(blob);
            } catch (error) {
                window.alert(error.message || 'Không chọn được ảnh từ thư viện.');
            }
        },

        resizeInput() {
            const input = this.$refs.messageInput;
            if (! input) return;
            input.style.height = 'auto';
            input.style.height = `${Math.min(input.scrollHeight, 96)}px`;
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const area = this.$refs.aiMessages;
                if (area) area.scrollTop = area.scrollHeight;
            });
        },

        scrollTeamToBottom() {
            this.$nextTick(() => {
                const area = this.$refs.teamMessages;
                if (area) area.scrollTop = area.scrollHeight;
            });
        },

        async send() {
            const text = this.message.trim();
            if (this.loading || ! this.selectedModel || (! text && ! this.imageFile)) return;

            const imageFile = this.imageFile;
            const imageUrl = this.imagePreview;
            const history = this.messages
                .filter((item) => ! item.loading && ! item.error)
                .slice(-12)
                .map(({ role, content }) => ({ role, content }));

            this.messages.push({
                role: 'user',
                content: text || 'Hãy phân tích hình ảnh này.',
                image: imageUrl,
            });
            this.message = '';
            this.imageFile = null;
            this.imagePreview = '';
            if (this.$refs.imageInput) this.$refs.imageInput.value = '';
            this.resizeInput();
            this.loading = true;
            this.messages.push({ role: 'assistant', content: '', loading: true });
            this.scrollToBottom();

            const form = new FormData();
            form.append('model', this.selectedModel);
            form.append('message', text);
            history.forEach((item, index) => {
                form.append(`history[${index}][role]`, item.role);
                form.append(`history[${index}][content]`, item.content);
            });
            if (imageFile) form.append('image', imageFile);

            try {
                const response = await fetch(this.chatUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                    credentials: 'same-origin',
                    body: form,
                });
                const data = await response.json();
                if (! response.ok) {
                    const validation = data.errors
                        ? Object.values(data.errors).flat().join(' ')
                        : '';
                    throw new Error(validation || data.message || 'Không gửi được tin nhắn.');
                }

                this.messages.splice(this.messages.length - 1, 1, {
                    role: 'assistant',
                    content: data.answer || 'AI không trả về nội dung.',
                });
            } catch (error) {
                this.messages.splice(this.messages.length - 1, 1, {
                    role: 'assistant',
                    content: error.message || 'Không thể kết nối trợ lý AI.',
                    error: true,
                });
            } finally {
                this.loading = false;
                this.persist();
                this.scrollToBottom();
                this.$nextTick(() => this.$refs.messageInput?.focus());
            }
        },

        clearConversation() {
            this.messages.forEach((item) => {
                if (item.image) URL.revokeObjectURL(item.image);
            });
            this.messages = [];
            localStorage.removeItem(this.storageKey);
        },

        userInitials(name) {
            const parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
            if (parts.length >= 2) {
                return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
            }
            return (parts[0]?.[0] || '?').toUpperCase();
        },

        formatTeamTime(value) {
            if (! value) return '';
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return '';
            return date.toLocaleString('vi-VN', {
                day: '2-digit',
                month: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
            });
        },
    }"
    x-on:keydown.escape.window="closePanel()"
>
    <button
        type="button"
        class="seo-global-chat__launcher"
        x-on:click="openPanel()"
        x-show="! openChat"
        x-transition.opacity
        x-bind:aria-label="teamUnreadCount > 0 ? `Mở chat workspace (${teamUnreadCount} tin chưa đọc)` : 'Mở chat workspace'"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12c0 4.142-4.03 7.5-9 7.5a10.8 10.8 0 0 1-3.75-.658L3 20.25l1.575-3.675A6.9 6.9 0 0 1 3 12c0-4.142 4.03-7.5 9-7.5s9 3.358 9 7.5Z" />
        </svg>
        <span
            class="seo-global-chat__launcher-badge"
            x-show="teamUnreadCount > 0"
            x-text="teamUnreadCount > 99 ? '99+' : String(teamUnreadCount)"
            x-cloak
            aria-hidden="true"
        ></span>
    </button>

    <div
        class="seo-global-chat__backdrop"
        x-show="openChat"
        x-transition.opacity
        x-on:click="closePanel()"
        x-cloak
    ></div>

    <aside
        class="seo-global-chat__sidebar"
        x-bind:class="{ 'is-open': openChat }"
        aria-label="Workspace chat"
    >
        <header class="seo-global-chat__header">
            <div class="seo-global-chat__header-top">
                <div class="seo-global-chat__brand">
                    <span class="seo-global-chat__brand-icon">
                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 2.75c.48 4.91 4.34 8.77 9.25 9.25-4.91.48-8.77 4.34-9.25 9.25-.48-4.91-4.34-8.77-9.25-9.25C7.66 11.52 11.52 7.66 12 2.75Z" />
                        </svg>
                    </span>
                    <div>
                        <h2>Chat workspace</h2>
                        <p>
                            <span>Nhóm nội bộ</span>
                            <span
                                class="seo-global-chat__workspace-id"
                                x-show="workspaceOwnerId"
                                x-text="` · WS #${workspaceOwnerId}`"
                            ></span>
                        </p>
                    </div>
                </div>

                <div class="seo-global-chat__header-actions">
                    @if ($canUseAiChat)
                        <button
                            type="button"
                            class="seo-global-chat__ai-shortcut seo-global-chat__ai-shortcut--icon"
                            x-bind:disabled="agentLaunching"
                            x-on:click="openAgentWorkspace()"
                            title="{{ __('seo-content-ai::filament.agent_workspace.open_workspace') }}"
                            aria-label="{{ __('seo-content-ai::filament.agent_workspace.open_workspace') }}"
                        >
                            <x-seo-content-ai::seo-agent-chat.star-icon />
                        </button>
                    @endif
                    <button
                        type="button"
                        class="seo-global-chat__icon-button"
                        x-on:click="clearConversation()"
                        x-show="false"
                        title="Xóa cuộc trò chuyện AI"
                        aria-label="Xóa cuộc trò chuyện AI"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-1.327L4.772 5.79m14.456 0a48.1 48.1 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.1 48.1 0 0 1 3.478-.397m7.5 0V4.477c0-1.18-.91-2.164-2.09-2.201a51 51 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.7 48.7 0 0 0-7.5 0" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        class="seo-global-chat__icon-button"
                        x-on:click="closePanel()"
                        aria-label="Đóng chat"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>

            @if ($canUseAiChat)
                <div class="seo-global-chat__tabs" role="tablist" aria-label="Chọn loại chat">
                    <button
                        type="button"
                        role="tab"
                        class="seo-global-chat__tab"
                        x-bind:class="{ 'is-active': activeTab === 'team' }"
                        x-bind:aria-selected="activeTab === 'team'"
                        x-on:click="switchTab('team')"
                    >
                        Team
                    </button>
                    <button
                        type="button"
                        role="tab"
                        class="seo-global-chat__tab"
                        x-bind:class="{ 'is-active': false, 'is-launching': agentLaunching }"
                        x-bind:aria-selected="false"
                        x-bind:disabled="agentLaunching"
                        x-on:click="openAgentWorkspace()"
                        title="{{ __('seo-content-ai::filament.agent_workspace.open_workspace') }}"
                        aria-label="{{ __('seo-content-ai::filament.agent_workspace.open_workspace') }}"
                    >
                        <x-seo-content-ai::seo-agent-chat.star-icon />
                    </button>
                </div>
            @endif
        </header>

        <div class="seo-global-chat__model-row" x-show="false" x-cloak>
            <label for="seo-global-chat-model">Model</label>
            <x-select
                id="seo-global-chat-model"
                x-model="selectedModel"
                x-on:change="localStorage.setItem(`${storageKey}_model`, selectedModel)"
                x-bind:disabled="loadingModels || models.length === 0"
            >
                <template x-for="model in models" x-bind:key="model.id">
                    <option x-bind:value="String(model.id)" x-text="model.label"></option>
                </template>
                <option x-show="loadingModels" value="">Đang tải model...</option>
                <option x-show="! loadingModels && models.length === 0" value="">Chưa có model AI active</option>
            </x-select>
        </div>

        <div
            class="seo-global-chat__messages"
            x-ref="teamMessages"
            x-show="activeTab === 'team'"
            x-cloak
        >
            <div class="seo-global-chat__empty" x-show="teamLoading && teamMessages.length === 0">
                <p>Đang tải tin nhắn nhóm...</p>
            </div>

            <div class="seo-global-chat__empty" x-show="! teamLoading && teamMessages.length === 0">
                <span class="seo-global-chat__empty-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 0 1 .865-.501 48.172 48.172 0 0 0 3.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
                    </svg>
                </span>
                <h3>Phòng chat nhóm</h3>
                <p>Trao đổi với owner và staff cùng workspace. Thành viên phải được thêm tại <strong>Team members</strong> (staff có parent trỏ về owner).</p>
            </div>

            <template x-for="item in teamMessages" x-bind:key="item.id">
                <div
                    class="seo-global-chat__team-row"
                    x-bind:class="item.is_mine ? 'is-mine' : 'is-other'"
                >
                    <div class="seo-global-chat__team-avatar" x-show="! item.is_mine">
                        <span x-text="userInitials(item.user_name)"></span>
                    </div>
                    <div class="seo-global-chat__team-bubble-wrap">
                        <div class="seo-global-chat__team-meta">
                            <strong x-text="item.is_mine ? 'Bạn' : item.user_name"></strong>
                            <time x-text="formatTeamTime(item.created_at)"></time>
                        </div>
                        <div
                            class="seo-global-chat__team-bubble"
                            x-bind:class="item.is_mine ? 'is-mine' : 'is-other'"
                        >
                            <template x-if="item.attachment_is_image && item.attachment_url">
                                <button
                                    type="button"
                                    class="seo-global-chat__team-attachment-image-btn"
                                    x-on:click="openAttachmentLightbox(item.attachment_url, item.attachment_name)"
                                    x-bind:aria-label="item.attachment_name || 'Xem ảnh full size'"
                                    title="Xem ảnh full size"
                                >
                                    <img
                                        x-bind:src="item.attachment_url"
                                        x-bind:alt="item.attachment_name || 'Ảnh đính kèm'"
                                        class="seo-global-chat__team-attachment-image"
                                    />
                                </button>
                            </template>
                            <template x-if="item.attachment_url && ! item.attachment_is_image">
                                <a
                                    x-bind:href="item.attachment_url"
                                    class="seo-global-chat__team-attachment-link"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    x-text="item.attachment_name || 'Tệp đính kèm'"
                                ></a>
                            </template>
                            <template x-if="item.message">
                                <span class="seo-global-chat__team-text" x-text="item.message"></span>
                            </template>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div
            class="seo-global-chat__messages"
            x-ref="aiMessages"
            x-show="false"
            x-cloak
            aria-hidden="true"
        >
            <div class="seo-global-chat__empty" x-show="messages.length === 0">
                <span class="seo-global-chat__empty-icon">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2.75c.48 4.91 4.34 8.77 9.25 9.25-4.91.48-8.77 4.34-9.25 9.25-.48-4.91-4.34-8.77-9.25-9.25C7.66 11.52 11.52 7.66 12 2.75Z" />
                    </svg>
                </span>
                <h3>Tôi có thể giúp gì?</h3>
                <p>Hỏi về nội dung, SEO, dữ liệu đang xử lý hoặc gửi một hình ảnh để phân tích.</p>
            </div>

            <template x-for="(item, index) in messages" x-bind:key="index">
                <div>
                    <div class="seo-global-chat__user-row" x-show="item.role === 'user'">
                        <div class="seo-global-chat__user-message">
                            <img x-show="item.image" x-bind:src="item.image" alt="Ảnh đính kèm" />
                            <span x-text="item.content"></span>
                        </div>
                    </div>

                    <div class="seo-global-chat__assistant-row" x-show="item.role === 'assistant'">
                        <span class="seo-global-chat__assistant-icon">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M12 2.75c.48 4.91 4.34 8.77 9.25 9.25-4.91.48-8.77 4.34-9.25 9.25-.48-4.91-4.34-8.77-9.25-9.25C7.66 11.52 11.52 7.66 12 2.75Z" />
                            </svg>
                        </span>
                        <div class="seo-global-chat__assistant-message" x-bind:class="{ 'is-error': item.error }">
                            <div class="seo-global-chat__typing" x-show="item.loading">
                                <span></span><span></span><span></span>
                            </div>
                            <div x-show="! item.loading" x-text="item.content"></div>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <footer class="seo-global-chat__composer">
            <div class="seo-global-chat__image-preview" x-show="activeTab === 'team' && teamFilePreview" x-cloak>
                <button
                    type="button"
                    class="seo-global-chat__image-preview-thumb"
                    x-on:click="openImageEditor('team')"
                    title="Chỉnh sửa ảnh"
                    aria-label="Chỉnh sửa ảnh"
                >
                    <img x-bind:src="teamFilePreview" alt="Xem trước ảnh" />
                    <span class="seo-global-chat__image-preview-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                        </svg>
                    </span>
                </button>
                <button type="button" class="seo-global-chat__image-preview-remove" x-on:click="clearTeamFile()" aria-label="Bỏ tệp">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>
            </div>

            <div class="seo-global-chat__file-chip" x-show="activeTab === 'team' && teamFile && ! teamFileIsImage" x-cloak>
                <span x-text="teamFile?.name || 'Tệp đính kèm'"></span>
                <button type="button" x-on:click="clearTeamFile()" aria-label="Bỏ tệp">×</button>
            </div>

            <div class="seo-global-chat__image-preview" x-show="false" x-cloak>
                <button
                    type="button"
                    class="seo-global-chat__image-preview-thumb"
                    x-on:click="openImageEditor('ai')"
                    title="Chỉnh sửa ảnh"
                    aria-label="Chỉnh sửa ảnh"
                >
                    <img x-bind:src="imagePreview" alt="Xem trước ảnh" />
                    <span class="seo-global-chat__image-preview-badge">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                        </svg>
                    </span>
                </button>
                <button type="button" class="seo-global-chat__image-preview-remove" x-on:click="clearImage()" aria-label="Bỏ ảnh">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>
            </div>

            <div class="seo-global-chat__input-shell">
                <input
                    x-ref="teamFileInput"
                    type="file"
                    x-bind:accept="teamAccept"
                    class="seo-global-chat__file-input"
                    x-on:change="selectTeamFile($event)"
                />
                <input
                    x-ref="imageInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    class="seo-global-chat__file-input"
                    x-on:change="selectImage($event)"
                />
                <button
                    type="button"
                    class="seo-global-chat__attach"
                    x-on:click="$refs.teamFileInput.click()"
                    x-bind:disabled="teamSending || activeTab !== 'team'"
                    x-show="activeTab === 'team'"
                    aria-label="Đính kèm tệp"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l9.724-9.724a3 3 0 0 1 4.243 4.243l-9.193 9.193a1.5 1.5 0 0 1-2.121-2.121l8.662-8.662" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="seo-global-chat__attach"
                    x-on:click="$refs.imageInput.click()"
                    x-bind:disabled="true"
                    x-show="false"
                    aria-label="Đính kèm hình ảnh"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l9.724-9.724a3 3 0 0 1 4.243 4.243l-9.193 9.193a1.5 1.5 0 0 1-2.121-2.121l8.662-8.662" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="seo-global-chat__attach"
                    x-on:click="openMediaLibrary()"
                    x-bind:disabled="true"
                    x-show="false"
                    aria-label="Chọn ảnh từ thư viện"
                    title="Chọn ảnh từ thư viện"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75 5.159 5.159a2.25 2.25 0 0 1 2.013-1.327h9.656a2.25 2.25 0 0 1 2.013 1.327L21.75 15.75M2.25 15.75h19.5M2.25 15.75v2.25A2.25 2.25 0 0 0 4.5 20.25h15a2.25 2.25 0 0 0 2.25-2.25v-2.25M8.25 10.5h.008v.008H8.25V10.5Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm3.75 3.75h.008v.008H12.75V14.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                    </svg>
                </button>

                <textarea
                    x-ref="messageInput"
                    x-model="message"
                    rows="1"
                    x-bind:placeholder="canUseAiChat ? 'Nhắn team... (@ai mở Agent Workspace)' : 'Nhắn team...'"
                    x-on:input="resizeInput()"
                    x-on:paste="handlePaste($event)"
                    x-on:keydown.enter.prevent="if (!$event.shiftKey) submitComposer(); else message += '\n'"
                    x-bind:disabled="teamSending || agentLaunching"
                ></textarea>

                <button
                    type="button"
                    class="seo-global-chat__send"
                    x-on:click="submitComposer()"
                    x-bind:disabled="teamSending || agentLaunching || (!message.trim() && !teamFile)"
                    aria-label="Gửi tin nhắn"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.27 3.125A59.8 59.8 0 0 1 21.485 12 59.8 59.8 0 0 1 3.27 20.875L6 12Zm0 0h7.5" />
                    </svg>
                </button>
            </div>
            <p class="seo-global-chat__hint" x-show="canUseAiChat">
                Team chat đồng bộ qua SSE. Gõ <code>@ai</code> hoặc bấm ngôi sao để mở Agent Workspace. Hỗ trợ Ctrl+V ảnh và đính kèm file.
            </p>
            <p class="seo-global-chat__hint" x-show="! canUseAiChat">
                Team chat đồng bộ qua SSE (Server-Sent Events). Hỗ trợ Ctrl+V ảnh và đính kèm file.
            </p>
        </footer>
    </aside>

    <div
        class="seo-global-chat__image-editor"
        x-show="imageEditorOpen"
        x-cloak
        x-on:keydown.escape.window="imageEditorOpen && closeImageEditor()"
    >
        <header class="seo-global-chat__image-editor-header">
            <button type="button" class="seo-global-chat__image-editor-icon" x-on:click="closeImageEditor()" aria-label="Quay lại">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
            </button>
            <div class="seo-global-chat__image-editor-actions">
                <button
                    type="button"
                    class="seo-global-chat__image-editor-icon"
                    x-on:click="undoDrawStroke()"
                    x-bind:disabled="drawHistoryIndex <= 0"
                    aria-label="Hoàn tác"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                </button>
                <button
                    type="button"
                    class="seo-global-chat__image-editor-icon"
                    x-on:click="redoDrawStroke()"
                    x-bind:disabled="drawHistoryIndex >= drawHistory.length - 1"
                    aria-label="Làm lại"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m15 15 6-6m0 0-6-6m6 6H9a6 6 0 0 0 0 12h3" />
                    </svg>
                </button>
                <button type="button" class="seo-global-chat__image-editor-save" x-on:click="saveEditedImage()">
                    Lưu
                </button>
            </div>
        </header>

        <div class="seo-global-chat__image-editor-stage" x-ref="editorStage">
            <div class="seo-global-chat__image-editor-canvas-wrap">
                <canvas x-ref="editorBaseCanvas" class="seo-global-chat__image-editor-base" aria-hidden="true"></canvas>
                <canvas
                    x-ref="editorDrawCanvas"
                    class="seo-global-chat__image-editor-draw"
                    x-on:pointerdown="startDrawStroke($event)"
                    x-on:pointermove="moveDrawStroke($event)"
                    x-on:pointerup="endDrawStroke($event)"
                    x-on:pointercancel="endDrawStroke($event)"
                    x-on:pointerleave="endDrawStroke($event)"
                ></canvas>
            </div>
        </div>

        <footer class="seo-global-chat__image-editor-footer">
            <div class="seo-global-chat__image-editor-colors" role="list" aria-label="Màu vẽ">
                <template x-for="color in drawColors" x-bind:key="color">
                    <button
                        type="button"
                        role="listitem"
                        class="seo-global-chat__image-editor-color"
                        x-bind:class="{ 'is-active': drawColor === color }"
                        x-bind:style="{ backgroundColor: color }"
                        x-on:click="drawColor = color"
                        x-bind:aria-label="`Chọn màu ${color}`"
                    ></button>
                </template>
            </div>
            <div class="seo-global-chat__image-editor-tool">
                <span class="seo-global-chat__image-editor-tool-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z" />
                    </svg>
                </span>
                <span>Phác họa</span>
            </div>
        </footer>
    </div>

    <div
        class="seo-global-chat__attachment-lightbox"
        x-show="attachmentLightboxOpen"
        x-cloak
        x-on:keydown.escape.window="attachmentLightboxOpen && closeAttachmentLightbox()"
        x-on:click.self="closeAttachmentLightbox()"
        role="dialog"
        aria-modal="true"
        x-bind:aria-label="attachmentLightboxName || 'Xem ảnh'"
    >
        <div class="seo-global-chat__attachment-lightbox-inner">
            <button
                type="button"
                class="seo-global-chat__attachment-lightbox-close"
                x-on:click="closeAttachmentLightbox()"
                aria-label="Đóng"
            >
                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                </svg>
            </button>
            <img
                class="seo-global-chat__attachment-lightbox-image"
                x-bind:src="attachmentLightboxUrl"
                x-bind:alt="attachmentLightboxName"
            />
            <p class="seo-global-chat__attachment-lightbox-caption" x-show="attachmentLightboxName" x-text="attachmentLightboxName"></p>
        </div>
    </div>
</div>
