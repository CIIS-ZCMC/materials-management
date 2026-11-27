import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export type PersonOption = {
    id: number | string;
    employee_no: string;
    name: string;
    position: string | null;
    office: string | null;
};

type Props = {
    id?: string;
    /** Which kind of person to search: the personnel directory role, for example "end-user". */
    role: string;
    value: PersonOption | null;
    onChange: (person: PersonOption | null) => void;
};

/**
 * Step 6.2. Picks ONE person from the personnel directory (GET /personnel/search, active people only).
 * In plain words: you type part of a name or employee number, the page asks the server after a short pause,
 * and you click the person you mean. The server checks the choice again when you save.
 */
export default function PersonPicker({ id, role, value, onChange }: Props) {
    const [term, setTerm] = useState('');
    const [results, setResults] = useState<PersonOption[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        if (value || term.trim().length < 2) {
            setResults([]);

            return;
        }

        if (timer.current) {
            clearTimeout(timer.current);
        }

        const controller = new AbortController();

        timer.current = setTimeout(async () => {
            setLoading(true);
            setFailed(false);

            try {
                const params = new URLSearchParams({ search: term.trim(), role, limit: '8' });
                const response = await fetch(`/personnel/search?${params.toString()}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (!response.ok) {
                    throw new Error('search failed');
                }

                const json = (await response.json()) as { data: PersonOption[] };
                setResults(json.data);
            } catch (error) {
                if ((error as Error).name !== 'AbortError') {
                    setFailed(true);
                    setResults([]);
                }
            } finally {
                setLoading(false);
            }
        }, 250);

        return () => {
            controller.abort();

            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
    }, [term, role, value]);

    if (value) {
        return (
            <div className="flex items-center justify-between gap-3 rounded-md border p-2 text-sm">
                <div>
                    <div className="font-medium">{value.name}</div>
                    <div className="text-muted-foreground">
                        {value.employee_no}
                        {value.position ? `, ${value.position}` : ''}
                    </div>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        onChange(null);
                        setTerm('');
                    }}
                >
                    Change
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <Input id={id} value={term} onChange={(e) => setTerm(e.target.value)} placeholder="Type a name or employee number" autoComplete="off" />
            {loading && <p className="text-sm text-muted-foreground">Searching...</p>}
            {failed && <p className="text-sm text-destructive">The personnel list could not be reached. Try again in a moment.</p>}
            {!loading && !failed && term.trim().length >= 2 && results.length === 0 && (
                <p className="text-sm text-muted-foreground">No active end-user found.</p>
            )}
            {results.length > 0 && (
                <ul className="max-h-56 divide-y overflow-auto rounded-md border">
                    {results.map((person) => (
                        <li key={person.id}>
                            <button
                                type="button"
                                className="w-full px-3 py-2 text-left text-sm hover:bg-muted"
                                onClick={() => {
                                    onChange(person);
                                    setResults([]);
                                }}
                            >
                                <span className="font-medium">{person.name}</span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    {person.employee_no}
                                    {person.position ? `, ${person.position}` : ''}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
