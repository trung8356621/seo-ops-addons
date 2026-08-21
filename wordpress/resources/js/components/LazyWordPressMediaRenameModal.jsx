import React, { lazy, Suspense, useEffect, useState } from 'react';

const WordPressMediaRenameModal = lazy(() => import('./WordPressMediaRenameModal.jsx'));

/**
 * Load the WP rename modal only after the first explicit open event.
 */
export default function LazyWordPressMediaRenameModal() {
    const [openedOnce, setOpenedOnce] = useState(false);

    useEffect(() => {
        const onOpen = () => setOpenedOnce(true);
        window.addEventListener('seo-wordpress-media-rename-open', onOpen);
        return () => window.removeEventListener('seo-wordpress-media-rename-open', onOpen);
    }, []);

    if (!openedOnce) {
        return null;
    }

    return (
        <Suspense fallback={null}>
            <WordPressMediaRenameModal />
        </Suspense>
    );
}
