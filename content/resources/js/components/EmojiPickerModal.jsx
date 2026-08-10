import React, { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { Search, X } from 'lucide-react';
import { filterEmojiCategories } from '../data/emojiCatalog';
import { t } from '../utils/i18n';

/**
 * @param {{ open: boolean, onClose: () => void, onSelect: (emoji: string) => void }} props
 */
export default function EmojiPickerModal({ open, onClose, onSelect }) {
    const [query, setQuery] = useState('');

    const categories = useMemo(() => filterEmojiCategories(query), [query]);
    const hasResults = categories.some((c) => c.items.length > 0);

    if (!open) {
        return null;
    }

    const handlePick = (emoji) => {
        onSelect(emoji);
        setQuery('');
    };

    return createPortal(
        <div
            className="seo-emoji-modal-backdrop"
            role="dialog"
            aria-modal="true"
            aria-label={t('emoji_choose')}
            onMouseDown={(e) => {
                if (e.target === e.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className="seo-emoji-modal">
                <div className="seo-emoji-modal__head">
                    <h3>{t('toolbar_insert_emoji')}</h3>
                    <button type="button" className="seo-emoji-modal__close" onClick={onClose} aria-label={t('magic_close')}>
                        <X size={18} />
                    </button>
                </div>
                <div className="seo-emoji-modal__search">
                    <Search size={16} aria-hidden />
                    <input
                        type="search"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder={t('emoji_search_placeholder')}
                    />
                </div>
                <div className="seo-emoji-modal__body">
                    {!hasResults ? (
                        <p className="seo-emoji-modal__empty">{t('emoji_no_results')}</p>
                    ) : (
                        categories.map((cat) => (
                            <section key={cat.id} className="seo-emoji-modal__section">
                                <h4 className="seo-emoji-modal__section-title">{cat.label}</h4>
                                <div className="seo-emoji-modal__grid">
                                    {cat.items.map((item) => (
                                        <button
                                            key={`${cat.id}-${item.emoji}`}
                                            type="button"
                                            className="seo-emoji-modal__item"
                                            title={item.label}
                                            onMouseDown={(e) => {
                                                e.preventDefault();
                                                handlePick(item.emoji);
                                            }}
                                        >
                                            <span className="seo-emoji-modal__glyph" aria-hidden>
                                                {item.emoji}
                                            </span>
                                            <span className="seo-emoji-modal__label">{item.label}</span>
                                        </button>
                                    ))}
                                </div>
                            </section>
                        ))
                    )}
                </div>
            </div>
        </div>,
        document.body,
    );
}
