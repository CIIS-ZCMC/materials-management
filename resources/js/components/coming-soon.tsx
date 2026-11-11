import { Head } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

type ComingSoonProps = {
    title: string;
    description: string;
    icon: LucideIcon;
    step: string;
};

// Temporary page body, replaced when the real screen is built.
// Brand colors: green #0f8a3e / #0b5a2b, yellow #f5e400.
export function ComingSoon({ title, description, icon: Icon, step }: ComingSoonProps) {
    return (
        <>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col p-4">
                <div className="flex flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-[#0f8a3e]/40 p-8 text-center">
                    <div className="mb-4 flex size-14 items-center justify-center rounded-full bg-[#0f8a3e]/10 text-[#0f8a3e] dark:bg-[#f5e400]/10 dark:text-[#f5e400]">
                        <Icon className="size-7" />
                    </div>
                    <h1 className="text-xl font-semibold text-[#0b5a2b] dark:text-foreground">
                        {title}
                    </h1>
                    <p className="mt-2 max-w-md text-sm text-muted-foreground">
                        {description}
                    </p>
                    <span className="mt-4 rounded-full border border-[#0f8a3e]/30 bg-[#f5e400]/30 px-3 py-1 text-xs font-medium text-[#0b5a2b] dark:text-[#f5e400]">
                        Planned: step {step}
                    </span>
                </div>
            </div>
        </>
    );
}