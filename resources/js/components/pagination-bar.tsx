import { Button } from '@/components/ui/button';

/** Shape of the pagination fields the controllers already send. */
export type PaginationMeta = {
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    meta: PaginationMeta;
    /** Called with the prev/next URL. Those URLs already carry ?search=..., so the search is kept. */
    onNavigate: (url: string | null) => void;
    /** Plural noun for the summary text, e.g. 'users', 'purchase orders'. */
    noun?: string;
};

/** "Showing 1-15 of 40" + Previous/Next. Stacks on phones with large touch targets. */
export default function PaginationBar({ meta, onNavigate, noun = 'items' }: Props) {
    return (
        <div className="flex flex-col gap-3 text-sm text-muted-foreground md:flex-row md:items-center md:justify-between">
            <span className="text-center md:text-left">{meta.total === 0 ? `0 ${noun}` : `Showing ${meta.from}–${meta.to} of ${meta.total}`}</span>
            <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-3 md:flex">
                <Button size="sm" variant="outline" className="h-11 md:h-8" disabled={!meta.prev_page_url} onClick={() => onNavigate(meta.prev_page_url)}>
                    Previous
                </Button>
                <span className="text-center">
                    Page {meta.current_page} of {meta.last_page}
                </span>
                <Button size="sm" variant="outline" className="h-11 md:h-8" disabled={!meta.next_page_url} onClick={() => onNavigate(meta.next_page_url)}>
                    Next
                </Button>
            </div>
        </div>
    );
}