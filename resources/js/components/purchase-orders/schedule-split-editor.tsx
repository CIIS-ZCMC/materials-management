import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * Step 3.3c: the quantity of each PO line per delivery column (needed to print form 04A).
 *
 * Columns: the first one, "within the delivery term from receipt of the PO" (it exists only when the
 * PO has a delivery term; its heading number is the delivery term itself), then one column per date.
 * Every line must add up exactly to its quantity ordered; zero is allowed.
 *
 * The screen keeps the numbers as text keyed by the line's `key` (a new line has no id yet). The page
 * turns them into positions when it saves. The server checks every rule again (App\Support\ScheduleSplit).
 */

// What the server sends: quantity maps are keyed by the id of the line (po_items.id).
export type ScheduleSplitData = {
    columns: { id: number; date: string; quantities: Record<string, number> }[];
    term_quantities: Record<string, number | null>;
};

export type SplitColumn = {
    id: number | null; // null = a new column (the server matches an identical stored date anyway)
    key: string;
    date: string;
    qty: Record<string, string>; // line key -> quantity typed
};

export type SplitState = {
    on: boolean;
    term: Record<string, string>; // line key -> quantity within the delivery term
    columns: SplitColumn[];
};

export type SplitLine = { key: string; label: string; ordered: number };

export const emptySplit: SplitState = { on: false, term: {}, columns: [] };

let columnCounter = 0;
export const nextColumnKey = () => `col-${++columnCounter}`;

const control =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

/** A typed number as a whole number of 0 or more (anything else counts as 0). */
export const parseQty = (value: string | undefined): number => {
    const n = parseInt(value ?? '', 10);

    return Number.isFinite(n) && n > 0 ? n : 0;
};

/** Starts the screen state from what the server stored. `lines` are the form rows (they carry `id` and `key`). */
export function buildSplit(data: ScheduleSplitData | null | undefined, lines: { id: number | null; key: string }[]): SplitState {
    if (!data) {
        return emptySplit;
    }

    const term: Record<string, string> = {};

    for (const line of lines) {
        term[line.key] = String(line.id === null ? 0 : (data.term_quantities[String(line.id)] ?? 0));
    }

    return {
        on: true,
        term,
        columns: data.columns.map((column) => ({
            id: column.id,
            key: nextColumnKey(),
            date: column.date,
            qty: Object.fromEntries(lines.map((line) => [line.key, String(line.id === null ? 0 : (column.quantities[String(line.id)] ?? 0))])),
        })),
    };
}

/** The first column's quantity of a line (0 when the PO has no delivery term). */
export const termQuantity = (split: SplitState, lineKey: string, termDays: number): number => (termDays > 0 ? parseQty(split.term[lineKey]) : 0);

export const cellQuantity = (column: SplitColumn, lineKey: string): number => parseQty(column.qty[lineKey]);

/** How many units of a line are placed in the columns so far. */
export const allocatedFor = (split: SplitState, lineKey: string, termDays: number): number =>
    termQuantity(split, lineKey, termDays) + split.columns.reduce((sum, column) => sum + cellQuantity(column, lineKey), 0);

/** What still blocks saving, in plain words (empty = fine). The server checks the same rules again. */
export function splitProblems(split: SplitState, lines: SplitLine[], termDays: number, maxColumns: number): string[] {
    if (!split.on) {
        return [];
    }

    const problems: string[] = [];
    const dates = split.columns.map((column) => column.date);

    if (split.columns.length === 0 && termDays === 0) {
        problems.push('Add at least one date, or turn the split off.');
    }

    if (split.columns.length > maxColumns) {
        problems.push(`Use at most ${maxColumns} date columns.`);
    }

    if (dates.some((date) => date === '')) {
        problems.push('Choose a date for every column.');
    }

    if (new Set(dates.filter((date) => date !== '')).size !== dates.filter((date) => date !== '').length) {
        problems.push('A date is repeated.');
    }

    if (lines.some((line) => allocatedFor(split, line.key, termDays) !== line.ordered)) {
        problems.push('Place the full quantity of every line (the numbers must add up).');
    }

    if (split.columns.some((column) => lines.reduce((sum, line) => sum + cellQuantity(column, line.key), 0) < 1)) {
        problems.push('Every date must deliver at least one unit.');
    }

    return problems;
}

type Props = {
    lines: SplitLine[];
    split: SplitState;
    onChange: (next: SplitState) => void;
    termDays: number; // 0 = the PO has no delivery term, so no first column
    maxColumns: number;
    errors: Record<string, string | undefined>;
};

export default function ScheduleSplitEditor({ lines, split, onChange, termDays, maxColumns, errors }: Props) {
    const hasTermColumn = termDays > 0;
    const termHeading = `Within ${termDays} ${termDays === 1 ? 'day' : 'days'} from receipt of the PO`;
    const problems = splitProblems(split, lines, termDays, maxColumns);

    const setColumns = (columns: SplitColumn[]) => onChange({ ...split, columns });

    const setDate = (key: string, date: string) => setColumns(split.columns.map((column) => (column.key === key ? { ...column, date } : column)));

    const setCell = (key: string, lineKey: string, value: string) =>
        setColumns(split.columns.map((column) => (column.key === key ? { ...column, qty: { ...column.qty, [lineKey]: value } } : column)));

    const setTerm = (lineKey: string, value: string) => onChange({ ...split, term: { ...split.term, [lineKey]: value } });

    const addColumn = () => setColumns([...split.columns, { id: null, key: nextColumnKey(), date: '', qty: {} }]);

    const removeColumn = (key: string) => setColumns(split.columns.filter((column) => column.key !== key));

    return (
        <div className="space-y-4">
            <div className="space-y-2">
                <p className="text-sm font-medium">Delivery columns</p>
                <p className="text-sm text-muted-foreground">
                    {hasTermColumn
                        ? `First column: ${termHeading.toLowerCase()} (it follows the delivery term). Then one column per date.`
                        : 'This PO has no delivery term, so there is no "within the delivery term" column. Set a delivery term above to get one.'}
                </p>

                {split.columns.map((column, index) => (
                    <div key={column.key} className="grid gap-1">
                        <div className="flex items-center gap-2">
                            <input
                                type="date"
                                aria-label={`Delivery date ${index + 1}`}
                                className={`${control} max-w-48`}
                                value={column.date}
                                onChange={(e) => setDate(column.key, e.target.value)}
                            />
                            <Button type="button" variant="ghost" size="icon" aria-label={`Remove delivery date ${index + 1}`} onClick={() => removeColumn(column.key)}>
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                        <InputError message={errors[`schedule_columns.${index}.date`]} />
                        <InputError message={errors[`schedule_columns.${index}.quantities`]} />
                    </div>
                ))}

                <Button type="button" variant="outline" size="sm" disabled={split.columns.length >= maxColumns} onClick={addColumn}>
                    <Plus className="size-4" /> Add date
                </Button>
                <p className="text-sm text-muted-foreground">
                    {split.columns.length} of at most {maxColumns} date columns.
                </p>
                <InputError message={errors.schedule_columns} />
            </div>

            <div className="space-y-3">
                {lines.map((line, lineIndex) => {
                    const allocated = allocatedFor(split, line.key, termDays);
                    const difference = line.ordered - allocated;

                    return (
                        <div key={line.key} className="space-y-2 rounded-md border p-3">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <p className="min-w-0 text-sm font-medium break-words">
                                    Line {lineIndex + 1}
                                    {line.label !== '' && `: ${line.label}`}
                                </p>
                                <p className={`text-sm tabular-nums ${difference === 0 ? 'text-muted-foreground' : 'text-destructive'}`}>
                                    {allocated} of {line.ordered} placed
                                    {difference > 0 && ` (${difference} left)`}
                                    {difference < 0 && ` (${-difference} too many)`}
                                </p>
                            </div>

                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                {hasTermColumn && (
                                    <div className="grid gap-1">
                                        <Label htmlFor={`term-${line.key}`} className="text-xs">
                                            {termHeading}
                                        </Label>
                                        <Input
                                            id={`term-${line.key}`}
                                            type="number"
                                            min={0}
                                            step={1}
                                            inputMode="numeric"
                                            value={split.term[line.key] ?? '0'}
                                            onChange={(e) => setTerm(line.key, e.target.value)}
                                        />
                                        <InputError message={errors[`items.${lineIndex}.term_quantity`]} />
                                    </div>
                                )}

                                {split.columns.map((column, columnIndex) => (
                                    <div key={column.key} className="grid gap-1">
                                        <Label htmlFor={`cell-${line.key}-${column.key}`} className="text-xs">
                                            {column.date !== '' ? column.date : 'New date'}
                                        </Label>
                                        <Input
                                            id={`cell-${line.key}-${column.key}`}
                                            type="number"
                                            min={0}
                                            step={1}
                                            inputMode="numeric"
                                            value={column.qty[line.key] ?? '0'}
                                            onChange={(e) => setCell(column.key, line.key, e.target.value)}
                                        />
                                        <InputError message={errors[`schedule_columns.${columnIndex}.quantities.${lineIndex}`]} />
                                    </div>
                                ))}
                            </div>

                            <InputError message={errors[`items.${lineIndex}.quantity_ordered`]} />
                        </div>
                    );
                })}
            </div>

            {problems.length > 0 && <p className="text-sm text-destructive">{problems[0]}</p>}
        </div>
    );
}
