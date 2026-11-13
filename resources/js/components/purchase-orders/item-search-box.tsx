import { Loader2, Search } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';

export type CatalogHit = {
    id: number;
    name: string;
    category_id: number;
    category: string;
    unit_id: number;
    unit: string;
};

type Props = {
    onSelect: (hit: CatalogHit) => void;
    placeholder?: string;
};

// The server never returns more than this many rows.
const PAGE_SIZE = 10;

// OPEN QUESTION (waiting for the owner's answer): show "Showing the first 10, type to narrow"
// under the list when the server returned a full page. Switch to true to turn it on.
const SHOW_MORE_HINT = false;

/**
 * Item picker for the catalog.
 *  - Click or focus the box: the first 10 active items show at once (no typing needed).
 *  - Type: after 250 ms the server filters (names starting with the text first, then containing it).
 *  - Arrow Up / Down move the highlight, Enter picks it, Escape closes. Enter never submits the form.
 * The box clears itself after a choice; the form shows the chosen item under the box.
 */
export default function ItemSearchBox({ onSelect, placeholder = 'Click to browse, or type to search the catalog…' }: Props) {
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<CatalogHit[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [loaded, setLoaded] = useState(false);
    const [failed, setFailed] = useState(false);
    const [active, setActive] = useState(-1); // index of the highlighted row, -1 = none
    const box = useRef<HTMLDivElement>(null);
    const listId = useId();

    const term = query.trim();
    const showList = open && (loaded || loading);

    // Ask the server whenever the list is open and the text changes.
    // An empty box asks at once; typing waits 250 ms. Each new change cancels the older request.
    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();

        const timer = window.setTimeout(
            async () => {
                setLoading(true);
                setFailed(false);

                try {
                    const response = await fetch(`/purchase-orders/item-search?q=${encodeURIComponent(term)}`, {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    });

                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    const json = (await response.json()) as { items: CatalogHit[] };
                    setHits(json.items);
                    setActive(json.items.length > 0 ? 0 : -1);
                    setLoaded(true);
                } catch (error) {
                    if ((error as Error).name !== 'AbortError') {
                        setHits([]);
                        setActive(-1);
                        setFailed(true);
                        setLoaded(true);
                    }
                } finally {
                    if (!controller.signal.aborted) {
                        setLoading(false);
                    }
                }
            },
            term === '' ? 0 : 250,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, term]);

    // Close the list when you click somewhere else.
    useEffect(() => {
        const close = (event: MouseEvent) => {
            if (box.current && !box.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, []);

    // Keep the highlighted row visible when you move with the arrow keys.
    useEffect(() => {
        if (open && active >= 0) {
            document.getElementById(`${listId}-opt-${active}`)?.scrollIntoView({ block: 'nearest' });
        }
    }, [active, open, listId]);

    const pick = (hit: CatalogHit) => {
        onSelect(hit);
        setQuery('');
        setHits([]);
        setLoaded(false);
        setActive(-1);
        setOpen(false);
    };

    const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();

            if (!open) {
                setOpen(true);
            } else if (hits.length > 0) {
                setActive((a) => Math.min(a + 1, hits.length - 1));
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();

            if (open && hits.length > 0) {
                setActive((a) => Math.max(a - 1, 0));
            }
        } else if (e.key === 'Enter') {
            // Enter must never submit the whole PO form from inside the box.
            e.preventDefault();

            if (open && active >= 0 && hits[active]) {
                pick(hits[active]);
            }
        } else if (e.key === 'Escape') {
            if (open) {
                e.preventDefault();
                setOpen(false);
            }
        } else if (e.key === 'Tab') {
            setOpen(false);
        }
    };

    return (
        <div ref={box} className="relative">
            <div className="relative">
                <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                <Input
                    value={query}
                    onChange={(e) => {
                        setQuery(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onClick={() => setOpen(true)}
                    onKeyDown={onKeyDown}
                    placeholder={placeholder}
                    autoComplete="off"
                    role="combobox"
                    aria-label="Search the item catalog"
                    aria-expanded={showList}
                    aria-controls={listId}
                    aria-autocomplete="list"
                    aria-activedescendant={showList && active >= 0 ? `${listId}-opt-${active}` : undefined}
                    className="pr-8 pl-8"
                />
                {open && loading && <Loader2 className="absolute top-2.5 right-2.5 size-4 animate-spin text-muted-foreground" />}
            </div>

            {showList && (
                <div className="absolute z-20 mt-1 w-full rounded-md border bg-popover text-sm shadow-md">
                    <ul id={listId} role="listbox" className="max-h-64 overflow-y-auto overflow-x-hidden p-1">
                        {!loaded ? (
                            <li className="px-2 py-1.5 text-muted-foreground">Loading…</li>
                        ) : failed ? (
                            <li className="px-2 py-1.5 text-destructive">Could not load the catalog. Try again.</li>
                        ) : hits.length === 0 ? (
                            <li className="px-2 py-1.5 text-muted-foreground">No active item matches.</li>
                        ) : (
                            hits.map((hit, index) => (
                                <li key={hit.id} role="presentation">
                                    <button
                                        id={`${listId}-opt-${index}`}
                                        type="button"
                                        role="option"
                                        aria-selected={index === active}
                                        className={`flex w-full flex-col rounded-sm px-2 py-1.5 text-left ${index === active ? 'bg-accent' : ''}`}
                                        onMouseEnter={() => setActive(index)}
                                        onClick={() => pick(hit)}
                                    >
                                        <span className="font-medium break-words">{hit.name}</span>
                                        <span className="text-xs break-words text-muted-foreground">
                                            {hit.category} · {hit.unit}
                                        </span>
                                    </button>
                                </li>
                            ))
                        )}
                    </ul>

                    {SHOW_MORE_HINT && loaded && !failed && hits.length >= PAGE_SIZE && (
                        <p className="border-t px-3 py-1.5 text-xs text-muted-foreground">Showing the first {PAGE_SIZE}, type to narrow.</p>
                    )}
                </div>
            )}
        </div>
    );
}