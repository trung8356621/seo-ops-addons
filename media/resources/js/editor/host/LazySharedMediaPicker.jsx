import React, { lazy, Suspense, useEffect, useState } from 'react';
import { subscribeMediaPicker } from '@content-addon/editor/runtime/editorMediaPickerStore.js';

const SharedMediaPicker = lazy(() => import('./SharedMediaPicker.jsx').then((mod) => ({
    default: mod.SharedMediaPicker,
})));

/**
 * Keep the heavy picker off the Edit Article initial graph until the user opens it.
 */
export function LazySharedMediaPicker(props) {
    const [openedOnce, setOpenedOnce] = useState(false);

    useEffect(() => subscribeMediaPicker((next) => {
        if (next?.open) {
            setOpenedOnce(true);
        }
    }), []);

    if (!openedOnce) {
        return null;
    }

    return (
        <Suspense fallback={null}>
            <SharedMediaPicker {...props} />
        </Suspense>
    );
}

export default LazySharedMediaPicker;
