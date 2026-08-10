import React from 'react';

/**
 * @param {{
 *   icon: React.ComponentType<{ size?: number; strokeWidth?: number; 'aria-hidden'?: boolean }>,
 *   label: string,
 *   shortcut?: string,
 *   active?: boolean,
 *   disabled?: boolean,
 *   onClick?: () => void,
 *   variant?: 'tool' | 'icon' | 'action-secondary' | 'action-primary' | 'sidebar' | 'fill',
 *   className?: string,
 *   children?: React.ReactNode,
 * }} props
 */
export function MagicEraserToolbarButton({
    icon: Icon,
    label,
    shortcut,
    active = false,
    disabled = false,
    onClick,
    variant = 'tool',
    className = '',
    children,
}) {
    const title = shortcut ? `${label} (${shortcut})` : label;

    return (
        <button
            type="button"
            className={`magic-eraser-tb-btn magic-eraser-tb-btn--${variant} ${active ? 'is-active' : ''} ${className}`.trim()}
            onClick={onClick}
            disabled={disabled}
            title={title}
            aria-label={label}
            aria-pressed={variant === 'tool' ? active : undefined}
        >
            {Icon ? <Icon size={variant === 'sidebar' ? 16 : 18} strokeWidth={2} aria-hidden /> : null}
            {children}
        </button>
    );
}

/**
 * @param {{ groups: import('./magicEraserShortcuts').MAGIC_ERASER_SHORTCUT_GROUPS }} props
 */
export function MagicEraserShortcutsPanel({ groups }) {
    return (
        <div className="magic-eraser-shortcuts">
            <p className="magic-eraser-shortcuts-title">Phím tắt</p>
            <div className="magic-eraser-shortcuts-grid">
                {groups.map((group) => (
                    <section key={group.id} className="magic-eraser-shortcuts-group">
                        <h5 className="magic-eraser-shortcuts-group-label">{group.label}</h5>
                        <ul className="magic-eraser-shortcuts-list">
                            {group.items.map((item, idx) => (
                                <li key={`${group.id}-${idx}`}>
                                    <span className="magic-eraser-shortcuts-keys">
                                        {item.keys.map((key, ki) => (
                                            <React.Fragment key={key}>
                                                {ki > 0 ? (
                                                    <span className="magic-eraser-shortcuts-plus">+</span>
                                                ) : null}
                                                <kbd>{key}</kbd>
                                            </React.Fragment>
                                        ))}
                                    </span>
                                    <span className="magic-eraser-shortcuts-desc">{item.desc}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
            </div>
        </div>
    );
}
