import { useCallback, useEffect, useRef } from 'react';

export function useDebouncedCallback(callback, delayMs) {
    const callbackRef = useRef(callback);
    const timerRef = useRef(null);

    useEffect(() => {
        callbackRef.current = callback;
    }, [callback]);

    const cancel = useCallback(() => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    }, []);

    const debounced = useCallback(
        (...args) => {
            cancel();
            timerRef.current = setTimeout(() => {
                callbackRef.current(...args);
            }, delayMs);
        },
        [cancel, delayMs],
    );

    useEffect(() => cancel, [cancel]);

    return { debounced, cancel };
}
