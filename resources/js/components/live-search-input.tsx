import { Loader2, Search, X } from 'lucide-react';
import { useRef } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Props = {
    value: string;
    onChange: (value: string) => void;
    /** X button or Escape. */
    onClear: () => void;
    /** Enter key. The form never reloads the page. */
    onSubmit: () => void;
    loading?: boolean;
    placeholder?: string;
    className?: string;
};

/** Search box for list pages. Full width on phones, capped from `sm` up. Use it with `useLiveSearch`. */
export default function LiveSearchInput({ value, onChange, onClear, onSubmit, loading = false, placeholder = 'Search', className }: Props) {
    const formRef = useRef<HTMLFormElement>(null);

    const clearAndRefocus = () => {
        onClear();
        formRef.current?.querySelector('input')?.focus();
    };

    return (
        <form
            ref={formRef}
            role="search"
            onSubmit={(e) => {
                e.preventDefault();
                onSubmit();
            }}
            className={cn('relative w-full sm:max-w-sm', className)}
        >
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                type="text"
                inputMode="search"
                enterKeyHint="search"
                autoComplete="off"
                aria-label={placeholder}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Escape' && value !== '') {
                        e.preventDefault();
                        onClear();
                    }
                }}
                placeholder={placeholder}
                className="h-10 pr-16 pl-9"
            />
            <div className="absolute top-1/2 right-1 flex -translate-y-1/2 items-center">
                {loading && <Loader2 className="mr-1 size-4 animate-spin text-muted-foreground" aria-label="Searching" />}
                {value !== '' && (
                    <button
                        type="button"
                        onClick={clearAndRefocus}
                        aria-label="Clear search"
                        className="flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <X className="size-4" />
                    </button>
                )}
            </div>
        </form>
    );
}