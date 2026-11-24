import { X } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Person } from '@/components/deliveries/delivery-types';

type Props = {
    label: string;
    // The directory's role name: 'inspector' or 'end-user'.
    role: 'inspector' | 'end-user';
    selected: Person[];
    max: number;
    onChange: (people: Person[]) => void;
    error?: string;
};

// Picks people from the personnel directory (GET /personnel/search, step 5.0). The server checks the role
// again when the delivery is saved; this box only makes choosing easy.
export default function PersonnelPicker({ label, role, selected, max, onChange, error }: Props) {
    const [term, setTerm] = useState('');
    const [open, setOpen] = useState(false);
    const [results, setResults] = useState<Person[]>([]);
    const [loading, setLoading] = useState(false);

    const full = selected.length >= max;

    useEffect(() => {
        if (!open || full) {
            return;
        }

        const controller = new AbortController();

        const timer = setTimeout(async () => {
            setLoading(true);

            try {
                const query = new URLSearchParams({ search: term, role, limit: '10' });
                const response = await fetch(`/personnel/search?${query.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });

                if (response.ok) {
                    const json = (await response.json()) as { data: Person[] };
                    setResults(json.data);
                }
            } catch {
                // Typing quickly cancels the previous request; nothing to show.
            } finally {
                setLoading(false);
            }
        }, 300);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term, role, open, full]);

    const choose = (person: Person) => {
        if (selected.some((p) => String(p.id) === String(person.id))) {
            return;
        }

        onChange([...selected, person]);
        setTerm('');
        setOpen(false);
    };

    const remove = (person: Person) => onChange(selected.filter((p) => String(p.id) !== String(person.id)));

    const available = results.filter((p) => !selected.some((s) => String(s.id) === String(p.id)));

    return (
        <div className="space-y-2">
            <Label>{label}</Label>

            {selected.length > 0 && (
                <ul className="flex flex-wrap gap-2">
                    {selected.map((person) => (
                        <li key={String(person.id)} className="flex items-center gap-1 rounded-md border py-1 pr-1 pl-3 text-sm">
                            <span>
                                {person.name}
                                {person.position ? <span className="text-muted-foreground"> · {person.position}</span> : null}
                            </span>
                            <Button type="button" variant="ghost" size="icon" className="size-6" onClick={() => remove(person)} aria-label={`Remove ${person.name}`}>
                                <X className="size-3.5" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {!full && (
                <div className="space-y-1">
                    <Input
                        value={term}
                        placeholder="Search by name or employee no."
                        onChange={(e) => setTerm(e.target.value)}
                        onFocus={() => setOpen(true)}
                        onBlur={() => setTimeout(() => setOpen(false), 150)}
                        autoComplete="off"
                    />
                    {open && (
                        <div className="max-h-56 w-full overflow-auto rounded-md border bg-popover p-1">
                            {loading && <p className="px-2 py-1.5 text-sm text-muted-foreground">Searching…</p>}
                            {!loading && available.length === 0 && <p className="px-2 py-1.5 text-sm text-muted-foreground">No one found.</p>}
                            {available.map((person) => (
                                <button
                                    type="button"
                                    key={String(person.id)}
                                    className="block w-full rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                                    // onMouseDown fires before the input loses focus, so the click is not lost.
                                    onMouseDown={(e) => {
                                        e.preventDefault();
                                        choose(person);
                                    }}
                                >
                                    <span className="block font-medium">{person.name}</span>
                                    <span className="block text-xs text-muted-foreground">
                                        {person.employee_no}
                                        {person.position ? ` · ${person.position}` : ''}
                                    </span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>
            )}

            <InputError message={error} />
        </div>
    );
}