import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Options = {
    /** Plain URL of the list page, e.g. '/admin/users'. */
    url: string;
    /** The search term the server applied (from the page props, e.g. filters.search). */
    initial: string;
    /** Props to refetch on every search or page change. Everything else on the page stays as it is. */
    only: string[];
    /** Debounce in milliseconds. */
    delay?: number;
};

type VisitOptions = { replace: boolean; preserveScroll: boolean };

/**
 * Live, debounced, server-side search for Inertia list pages.
 *
 * - Only the props named in `only` are refetched (partial reload).
 * - The input is never remounted, so focus and caret stay put while typing.
 * - A new request cancels the previous one, so a slow old response can never
 *   overwrite a newer one. `loading` turns off only when the NEWEST request ends.
 * - A new search has no `page` in its URL, so it always lands on page 1.
 * - The URL keeps `?search=...`. The back/forward buttons put the term back in the input.
 */
export function useLiveSearch({ url, initial, only, delay = 350 }: Options) {
    const [value, setValue] = useState(initial);
    const [loading, setLoading] = useState(false);

    // Trimmed term of the last request we sent (or the server applied).
    const lastSent = useRef(initial.trim());
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const cancelToken = useRef<{ cancel: () => void } | null>(null);
    const sequence = useRef(0);
    const inFlight = useRef(false);

    // An array literal changes identity on every render, so depend on its content.
    const onlyKey = only.join(',');

    const clearTimer = useCallback(() => {
        if (timer.current) {
            clearTimeout(timer.current);
            timer.current = null;
        }
    }, []);

    const visit = useCallback(
        (target: string, data: Record<string, string>, opts: VisitOptions) => {
            cancelToken.current?.cancel();
            const id = ++sequence.current;
            inFlight.current = true;
            setLoading(true);

            router.get(target, data, {
                only: onlyKey.split(','),
                preserveState: true,
                preserveScroll: opts.preserveScroll,
                replace: opts.replace,
                onCancelToken: (token) => {
                    cancelToken.current = token;
                },
                onFinish: () => {
                    // A cancelled older request also lands here; ignore it.
                    if (id === sequence.current) {
                        inFlight.current = false;
                        setLoading(false);
                    }
                },
            });
        },
        [onlyKey],
    );

    const run = useCallback(
        (term: string) => {
            lastSent.current = term;
            visit(url, term ? { search: term } : {}, { replace: true, preserveScroll: true });
        },
        [url, visit],
    );

    // Back/forward button: the server value changed without us asking, so follow it.
    // Skipped while our own request is running, so typing is never overwritten.
    useEffect(() => {
        if (inFlight.current) {
            return;
        }

        if (initial.trim() !== lastSent.current) {
            clearTimer();
            lastSent.current = initial.trim();
            setValue(initial);
        }
    }, [initial, clearTimer]);

    // Debounce typing.
    useEffect(() => {
        const term = value.trim();

        if (term === lastSent.current) {
            return;
        }

        timer.current = setTimeout(() => run(term), delay);

        return clearTimer;
    }, [value, delay, run, clearTimer]);

    useEffect(() => clearTimer, [clearTimer]);

    /** Enter key: search right now (no debounce wait, no page reload). */
    const flush = useCallback(() => {
        clearTimer();
        const term = value.trim();

        if (term !== lastSent.current) {
            run(term);
        }
    }, [value, run, clearTimer]);

    /** X button or Escape: reset immediately. */
    const clear = useCallback(() => {
        clearTimer();
        setValue('');

        if (lastSent.current !== '') {
            run('');
        }
    }, [run, clearTimer]);

    /** Previous/Next. The paginator URL already carries ?search=..., so the search is kept. */
    const goTo = useCallback(
        (href: string | null) => {
            if (href) {
                visit(href, {}, { replace: false, preserveScroll: false });
            }
        },
        [visit],
    );

    return { value, setValue, loading, flush, clear, goTo };
}