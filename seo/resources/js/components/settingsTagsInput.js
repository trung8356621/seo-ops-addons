/**
 * SettingsTagsInput Alpine Component
 *
 * Provides dedicated frontend tag creation with immediate canonicalization,
 * validation, and deduplication before tags are pushed into visible state.
 */

export function normalizeTag(raw, normalizerType = 'trusted_domain') {
    if (!raw || typeof raw !== 'string') {
        return null;
    }

    if (normalizerType === 'extension') {
        const clean = raw.trim().toLowerCase().replace(/^\.+/, '');
        if (!clean || !/^[a-z0-9]{1,12}$/.test(clean)) {
            return null;
        }
        return clean;
    }

    if (normalizerType === 'phrase') {
        const clean = raw.trim().toLowerCase().replace(/\s+/g, ' ');
        return clean || null;
    }

    // Handles 'trusted_domain' and 'strict_domain'
    let val = raw.trim();
    if (!val || /\s/.test(val)) {
        return null;
    }

    // Reject non-http/https URI schemes (javascript:, mailto:, tel:, etc.)
    const schemeMatch = val.match(/^[a-zA-Z][a-zA-Z0-9+.-]*:/);
    if (schemeMatch) {
        const s = schemeMatch[0].toLowerCase();
        if (s !== 'http:' && s !== 'https:') {
            return null;
        }
    }

    // Non-URL with @ is an email or malformed user string
    if (!val.includes('://') && !val.startsWith('//') && val.includes('@')) {
        return null;
    }

    // Scheme-relative or path-containing inputs
    let parseTarget = val;
    if (parseTarget.startsWith('//')) {
        parseTarget = 'https:' + parseTarget;
    } else if (parseTarget.includes('://') || (parseTarget.includes('/') && !parseTarget.startsWith('*.'))) {
        if (!parseTarget.includes('://')) {
            parseTarget = 'https://' + parseTarget;
        }
    }

    if (parseTarget.includes('://')) {
        try {
            const u = new URL(parseTarget);
            if (u.protocol !== 'http:' && u.protocol !== 'https:') {
                return null;
            }
            val = u.hostname;
        } catch {
            return null;
        }
    }

    val = val.toLowerCase().replace(/\.+$/, '');

    if (normalizerType === 'trusted_domain' && val.startsWith('*.')) {
        const suffix = val.slice(2).replace(/\.+$/, '');
        if (!suffix || /[*\/#?]/.test(suffix)) {
            return null;
        }
        if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/.test(suffix)) {
            return null;
        }
        if (!suffix.includes('.') && suffix.length < 2) {
            return null;
        }
        return '*.' + suffix;
    }

    // Wildcards not allowed in strict mode or malformed wildcard
    if (val.includes('*')) {
        return null;
    }

    // Strip leading www.
    if (val.startsWith('www.')) {
        val = val.slice(4);
    }
    val = val.replace(/\.+$/, '');

    if (!/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/.test(val)) {
        return null;
    }

    return val;
}

export default function settingsTagsInputFormComponent({ state, splitKeys = [], normalizerType = 'trusted_domain' }) {
    return {
        newTag: '',
        state,
        splitKeys,
        normalizerType,

        init: function () {
            if (Array.isArray(this.state)) {
                const cleaned = [];
                for (const t of this.state) {
                    const norm = normalizeTag(t, this.normalizerType);
                    if (norm && !cleaned.includes(norm)) {
                        cleaned.push(norm);
                    }
                }
                this.state = cleaned;
            }
        },

        createTag: function () {
            this.newTag = (this.newTag || '').trim();

            if (this.newTag === '') {
                return;
            }

            const normalized = normalizeTag(this.newTag, this.normalizerType);

            if (!normalized) {
                // Reject invalid tag: do not push into visible tag state
                this.newTag = '';
                return;
            }

            if (this.state.includes(normalized)) {
                // Canonical deduplication
                this.newTag = '';
                return;
            }

            this.state.push(normalized);
            this.newTag = '';
        },

        deleteTag: function (tagToDelete) {
            this.state = this.state.filter((tag) => tag !== tagToDelete);
        },

        reorderTags: function (event) {
            const reordered = this.state.splice(event.oldIndex, 1)[0];
            this.state.splice(event.newIndex, 0, reordered);

            this.state = [...this.state];
        },

        input: {
            ['x-on:blur']: 'createTag()',
            ['x-model']: 'newTag',
            ['x-on:keydown'](event) {
                if (['Enter', ...splitKeys].includes(event.key)) {
                    event.preventDefault();
                    event.stopPropagation();

                    this.createTag();
                }
            },
            ['x-on:paste']() {
                this.$nextTick(() => {
                    if (splitKeys.length === 0) {
                        this.createTag();
                        return;
                    }

                    const pattern = splitKeys
                        .map((key) => key.replace(/[/\-\\^$*+?.()|[\]{}]/g, '\\$&'))
                        .join('|');

                    this.newTag
                        .split(new RegExp(pattern, 'g'))
                        .forEach((tag) => {
                            this.newTag = tag;
                            this.createTag();
                        });
                });
            },
        },
    };
}
