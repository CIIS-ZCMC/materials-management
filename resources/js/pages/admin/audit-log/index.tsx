import { Head, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import LiveSearchInput from '@/components/live-search-input';
import PaginationBar from '@/components/pagination-bar';
import type { PaginationMeta } from '@/components/pagination-bar';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Change = { label: string; old: string | null; new: string | null };

type LogRow = {
    id: number;
    event: string;
    event_label: string;
    description: string;
    when: string | null;
    causer: { id: number; name: string; employee_no: string | null } | null;
    subject: { type: string; label: string } | null;
    changes: Change[];
    reason: string | null;
    ip: string | null;
};

type Filters = {
    causer: string;
    from: string;
    to: string;
    subject_type: string;
    search: string;
    sort: string; // '' = newest first, 'oldest'
};

type Props = {
    logs: PaginationMeta & { data: LogRow[] };
    filters: Filters;
    causers: { id: number; label: string }[];
    subjectTypes: { value: string; label: string }[];
    timezone: string;
};

const URL = '/admin/audit-log';
const ONLY = ['logs', 'filters'];

const selectClass =
    'border-input bg-background h-9 w-full rounded-md border px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function badgeVariant(event: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (['login_failed', 'login_blocked', 'deactivated', 'session_ended'].includes(event)) {
        return 'destructive';
    }

    if (['created', 'activated'].includes(event)) {
        return 'default';
    }

    if (['login', 'logout'].includes(event)) {
        return 'outline';
    }

    return 'secondary';
}

function ChangeList({ changes, reason }: { changes: Change[]; reason: string | null }) {
    if (changes.length === 0 && !reason) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <ul className="space-y-1 text-sm break-words">
            {changes.map((c, i) => (
                <li key={`${c.label}-${i}`}>
                    <span className="text-muted-foreground">{c.label}: </span>
                    {c.old !== null && c.new !== null ? (
                        <>
                            <span className="text-muted-foreground line-through">{c.old}</span>
                            <span className="mx-1">→</span>
                            <span className="font-medium">{c.new}</span>
                        </>
                    ) : c.new !== null ? (
                        <span className="font-medium">{c.new}</span>
                    ) : (
                        <span className="text-muted-foreground line-through">{c.old}</span>
                    )}
                </li>
            ))}
            {reason && (
                <li>
                    <span className="text-muted-foreground">Reason: </span>
                    {reason}
                </li>
            )}
        </ul>
    );
}

export default function AuditLogIndex({ logs, filters: serverFilters, causers, subjectTypes, timezone }: Props) {
    const [filters, setFilters] = useState<Filters>(serverFilters);
    const [loading, setLoading] = useState(false);

    const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const inFlight = useRef(false);

    // Sends the filters to the server. Empty ones are left out of the URL.
    // A new visit cancels the one still running, so the list always matches the last filters.
    const visit = (next: Filters) => {
        const query = Object.fromEntries(Object.entries(next).filter(([, value]) => value !== ''));

        router.get(URL, query, {
            only: ONLY,
            preserveState: true,
            preserveScroll: true,
            replace: true, // filter changes do not fill the Back button
            onStart: () => {
                inFlight.current = true;
                setLoading(true);
            },
            onFinish: () => {
                inFlight.current = false;
                setLoading(false);
            },
        });
    };

    const change = (key: keyof Filters, value: string, debounce = false) => {
        const next = { ...filters, [key]: value };
        setFilters(next);

        clearTimeout(timer.current);
        timer.current = undefined;

        if (debounce) {
            timer.current = setTimeout(() => {
                timer.current = undefined;
                visit(next);
            }, 300);
        } else {
            visit(next);
        }
    };

    const flush = () => {
        clearTimeout(timer.current);
        timer.current = undefined;
        visit(filters);
    };

    const clearAll = () => {
        const empty: Filters = { causer: '', from: '', to: '', subject_type: '', search: '', sort: '' };
        clearTimeout(timer.current);
        timer.current = undefined;
        setFilters(empty);
        visit(empty);
    };

    // Previous / Next keep the filters (the server builds the URLs with them) and push history.
    const goTo = (url: string | null) => {
        if (!url) {
            return;
        }

        router.get(
            url,
            {},
            {
                only: ONLY,
                preserveState: true,
                preserveScroll: true,
                onStart: () => {
                    inFlight.current = true;
                    setLoading(true);
                },
                onFinish: () => {
                    inFlight.current = false;
                    setLoading(false);
                },
            },
        );
    };

    // Browser Back/Forward: copy the filters from the server into the inputs,
    // but never while the person is typing or a request is running.
    useEffect(() => {
        if (inFlight.current || timer.current) {
            return;
        }

        setFilters(serverFilters);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serverFilters.causer, serverFilters.from, serverFilters.to, serverFilters.subject_type, serverFilters.search, serverFilters.sort]);

    useEffect(() => () => clearTimeout(timer.current), []);

    const hasFilters = Object.values(filters).some((value) => value !== '');

    const columns: ListColumn<LogRow>[] = [
        {
            key: 'when',
            header: `When (${timezone})`,
            card: 'summary',
            className: 'whitespace-nowrap',
            cell: (l) => l.when ?? '—',
        },
        {
            key: 'event',
            header: 'Action',
            card: 'badge',
            cell: (l) => <Badge variant={badgeVariant(l.event)}>{l.event_label}</Badge>,
        },
        {
            key: 'description',
            header: 'Description',
            card: 'title',
            className: 'break-words',
            cell: (l) => l.description,
        },
        {
            key: 'causer',
            header: 'By',
            card: 'summary',
            cell: (l) => (l.causer ? `${l.causer.name}${l.causer.employee_no ? ` (${l.causer.employee_no})` : ''}` : 'Not logged in'),
        },
        {
            key: 'subject',
            header: 'Subject',
            className: 'break-words',
            cell: (l) =>
                l.subject ? (
                    <>
                        <span className="text-muted-foreground">{l.subject.type}: </span>
                        {l.subject.label}
                    </>
                ) : (
                    '—'
                ),
        },
        {
            key: 'changes',
            header: 'What changed',
            className: 'min-w-[14rem]',
            cell: (l) => <ChangeList changes={l.changes} reason={l.reason} />,
        },
        {
            key: 'ip',
            header: 'IP',
            className: 'font-mono text-xs',
            cell: (l) => l.ip ?? '—',
        },
    ];

    return (
        <>
            <Head title="Audit Log" />

            <div className="space-y-4 p-4 md:space-y-6">
                <Heading title="Audit Log" description="Who did what, and when. Newest first. Times are shown in Asia/Manila." />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <div className="space-y-1.5 sm:col-span-2 lg:col-span-2">
                        <Label>Search</Label>
                        <div className="[&_input]:h-9">
                            <LiveSearchInput
                                value={filters.search}
                                onChange={(value: string) => change('search', value, true)}
                                onClear={() => change('search', '')}
                                onSubmit={flush}
                                loading={loading}
                                placeholder="Search subject or description"
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="audit-subject-type">Subject type</Label>
                        <select
                            id="audit-subject-type"
                            className={selectClass}
                            value={filters.subject_type}
                            onChange={(e) => change('subject_type', e.target.value)}
                        >
                            <option value="">All types</option>
                            {subjectTypes.map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="audit-causer">By user</Label>
                        <select id="audit-causer" className={selectClass} value={filters.causer} onChange={(e) => change('causer', e.target.value)}>
                            <option value="">Everyone</option>
                            <option value="none">Not logged in (failed logins)</option>
                            {causers.map((u) => (
                                <option key={u.id} value={String(u.id)}>
                                    {u.label}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="audit-from">From</Label>
                        <Input id="audit-from" type="date" value={filters.from} max={filters.to || undefined} onChange={(e) => change('from', e.target.value)} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="audit-to">To</Label>
                        <Input id="audit-to" type="date" value={filters.to} min={filters.from || undefined} onChange={(e) => change('to', e.target.value)} />
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="w-full space-y-1.5 sm:w-48">
                        <Label htmlFor="audit-sort">Sort by time</Label>
                        <select id="audit-sort" className={selectClass} value={filters.sort} onChange={(e) => change('sort', e.target.value)}>
                            <option value="">Newest first</option>
                            <option value="oldest">Oldest first</option>
                        </select>
                    </div>

                    {hasFilters && (
                        <Button type="button" variant="ghost" size="sm" onClick={clearAll}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <ResponsiveList
                    columns={columns}
                    rows={logs.data}
                    rowKey={(l) => l.id}
                    loading={loading}
                    emptyMessage="No activity found."
                />

                <PaginationBar meta={logs} onNavigate={goTo} noun="entries" />
            </div>
        </>
    );
}

AuditLogIndex.layout = {
    breadcrumbs: [{ title: 'Audit Log', href: '/admin/audit-log' }],
};