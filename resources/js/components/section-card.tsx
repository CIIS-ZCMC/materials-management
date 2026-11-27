import type { ReactNode, Ref } from 'react';
import { cn } from '@/lib/utils';

// Shared building blocks so every section of the delivery and document screens looks the same and
// reads as its own block: SectionCard (the container), SubSection (a titled group inside a card),
// Notice (a coloured message box) and Stat (a small number tile).

type CardProps = {
    title: ReactNode;
    description?: ReactNode;
    /** Buttons / badges drawn on the right of the header (they wrap under the title on a phone). */
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    id?: string;
    sectionRef?: Ref<HTMLElement>;
};

export function SectionCard({ title, description, actions, children, className, id, sectionRef }: CardProps) {
    return (
        <section id={id} ref={sectionRef} className={cn('scroll-mt-4 overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm', className)}>
            <header className="flex flex-wrap items-start justify-between gap-3 border-b bg-muted/40 px-4 py-3">
                <div className="min-w-0 flex-1">
                    <h3 className="text-base font-semibold break-words">{title}</h3>
                    {description && <div className="mt-0.5 text-sm text-muted-foreground">{description}</div>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </header>
            <div className="space-y-5 p-4">{children}</div>
        </section>
    );
}

type SubProps = { title: ReactNode; description?: ReactNode; actions?: ReactNode; children: ReactNode; className?: string };

export function SubSection({ title, description, actions, children, className }: SubProps) {
    return (
        <div className={cn('space-y-3', className)}>
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div className="min-w-0">
                    <h4 className="text-sm font-semibold tracking-wide text-foreground">{title}</h4>
                    {description && <p className="text-xs text-muted-foreground">{description}</p>}
                </div>
                {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
            </div>
            {children}
        </div>
    );
}

type Tone = 'info' | 'warning' | 'danger' | 'muted';

const toneClass: Record<Tone, string> = {
    info: 'border-primary/20 bg-primary/5',
    warning: 'border-amber-500/40 bg-amber-500/10',
    danger: 'border-destructive/40 bg-destructive/10',
    muted: 'bg-muted/40',
};

export function Notice({ tone = 'muted', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
    return <div className={cn('rounded-lg border px-3 py-2 text-sm break-words', toneClass[tone], className)}>{children}</div>;
}

export function Stat({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('min-w-0 rounded-lg border bg-muted/30 px-3 py-2', className)}>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-0.5 font-semibold tabular-nums break-words">{children}</p>
        </div>
    );
}