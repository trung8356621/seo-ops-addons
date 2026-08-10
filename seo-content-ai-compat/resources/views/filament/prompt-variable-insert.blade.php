<script>
(function () {
    const slots = new Map();
    let activePath = null;

    function getWireModelPath(el) {
        if (!el?.attributes) {
            return null;
        }

        for (const attr of el.attributes) {
            if (attr.name === 'wire:model' || attr.name.startsWith('wire:model.')) {
                return attr.value;
            }
        }

        return null;
    }

    function isPromptContentTextarea(el) {
        return Boolean(el?.tagName === 'TEXTAREA' && el.dataset?.promptContent === '1');
    }

    function freezeSelection(el) {
        if (!isPromptContentTextarea(el)) {
            return;
        }

        const path = getWireModelPath(el);
        if (!path) {
            return;
        }

        activePath = path;
        slots.set(path, {
            start: el.selectionStart ?? el.value.length,
            end: el.selectionEnd ?? el.selectionStart ?? el.value.length,
        });
    }

    function textareaForHintClick(target) {
        const wrap = target.closest('.fi-fo-field-wrp');
        if (!wrap) {
            return null;
        }

        return wrap.querySelector('textarea[data-prompt-content]');
    }

    function isInsertVariableHintClick(target) {
        const action = target.closest('.fi-fo-field-wrp-hint-action');
        if (!action) {
            return false;
        }

        return /chèn biến/i.test(action.textContent || '');
    }

    function trackFromEvent(event) {
        if (isPromptContentTextarea(event.target)) {
            freezeSelection(event.target);
        }
    }

    document.addEventListener('focusin', trackFromEvent, true);
    document.addEventListener('click', trackFromEvent, true);
    document.addEventListener('keyup', trackFromEvent, true);
    document.addEventListener('select', trackFromEvent, true);
    document.addEventListener('input', trackFromEvent, true);

    document.addEventListener(
        'mousedown',
        (event) => {
            const active = document.activeElement;
            if (isPromptContentTextarea(active)) {
                freezeSelection(active);
            }

            if (isInsertVariableHintClick(event.target)) {
                const ta = textareaForHintClick(event.target);
                if (ta) {
                    freezeSelection(ta);
                }
            }
        },
        true,
    );

    function findTextarea(statePath) {
        if (!statePath) {
            return null;
        }

        for (const el of document.querySelectorAll('textarea[data-prompt-content]')) {
            if (getWireModelPath(el) === statePath) {
                return el;
            }
        }

        return null;
    }

    function setAlpineState(el, value) {
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));

        if (typeof Alpine !== 'undefined') {
            const data = Alpine.$data(el);
            if (data && Object.prototype.hasOwnProperty.call(data, 'state')) {
                data.state = value;
            }
        }
    }

    function insertAtCursor(text, statePath) {
        const path = statePath || activePath;
        const el = findTextarea(path);
        if (!el) {
            return false;
        }

        const slot = slots.get(path);
        const start = slot?.start ?? el.value.length;
        const end = slot?.end ?? start;
        const value = el.value ?? '';
        const next = value.slice(0, start) + text + value.slice(end);

        setAlpineState(el, next);

        const pos = start + text.length;
        el.focus();
        el.setSelectionRange(pos, pos);
        freezeSelection(el);

        return true;
    }

    function registerLivewire() {
        if (typeof Livewire === 'undefined') {
            return;
        }

        Livewire.on('insert-prompt-variable', (payload) => {
            const data = payload?.[0] ?? payload ?? {};
            const variable = data.variable ?? '';
            const statePath = data.statePath ?? null;

            if (!variable) {
                return;
            }

            const run = () => insertAtCursor(variable, statePath);

            requestAnimationFrame(() => {
                requestAnimationFrame(run);
            });
        });
    }

    document.addEventListener('livewire:init', registerLivewire);
    if (typeof Livewire !== 'undefined') {
        registerLivewire();
    }

    window.PromptVarInsert = {
        track: freezeSelection,
        slots,
        get activePath() {
            return activePath;
        },
    };
})();
</script>
