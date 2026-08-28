{{-- Shared clipboard helper: Clipboard API + textarea fallback (HTTP / non-secure). --}}
@once
    <script>
        (() => {
            if (window.omiCopyText) {
                return;
            }

            window.omiCopyText = async (text) => {
                const value = String(text ?? '');
                if (value === '') {
                    return false;
                }

                try {
                    if (navigator.clipboard?.writeText) {
                        await navigator.clipboard.writeText(value);
                        return true;
                    }
                } catch (_error) {
                    // fall through to execCommand
                }

                try {
                    const ta = document.createElement('textarea');
                    ta.value = value;
                    ta.setAttribute('readonly', '');
                    ta.style.position = 'fixed';
                    ta.style.top = '-1000px';
                    ta.style.left = '-1000px';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    ta.setSelectionRange(0, value.length);
                    const ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    return Boolean(ok);
                } catch (_error) {
                    return false;
                }
            };
        })();
    </script>
@endonce
