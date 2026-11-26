import { Plus, X } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// One typed person. Both fields are plain text; position may stay empty.
export type TypedPerson = { name: string; position: string };

export const emptyPerson = (): TypedPerson => ({ name: '', position: '' });

type Props = {
    inspectors: TypedPerson[];
    endUser: TypedPerson;
    onInspectorsChange: (rows: TypedPerson[]) => void;
    onEndUserChange: (person: TypedPerson) => void;
    // options.min_inspectors / options.max_inspectors from the server.
    min: number;
    max: number;
    // The form's error bag. Keys: inspectors, inspectors.N.name, inspectors.N.position, end_user, end_user.name, end_user.position.
    errors: Record<string, string | undefined>;
};

// Step 5.2b: the inspectors and the end-user are typed by hand (no personnel directory).
// The server checks every rule again when the delivery is saved; this component only makes typing easy.
export default function SignatoryFields({ inspectors, endUser, onInspectorsChange, onEndUserChange, min, max, errors }: Props) {
    const canAdd = inspectors.length < max;
    const canRemove = inspectors.length > min;

    // Change one field of one row: copy the list, replace that row, hand the new list back to the form.
    const change = (index: number, field: keyof TypedPerson, value: string) =>
        onInspectorsChange(inspectors.map((row, i) => (i === index ? { ...row, [field]: value } : row)));

    return (
        <div className="space-y-6">
            <fieldset className="space-y-3">
                <legend className="text-sm leading-none font-medium">Inspectors</legend>

                {inspectors.map((row, index) => (
                    // The index is a fine key here: rows have no identity of their own, only their position in the list.
                    <div key={index} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start">
                        <div className="space-y-1.5">
                            <Label htmlFor={`inspector-name-${index}`} className="text-xs text-muted-foreground">
                                Name
                            </Label>
                            <Input
                                id={`inspector-name-${index}`}
                                value={row.name}
                                maxLength={150}
                                autoComplete="off"
                                onChange={(e) => change(index, 'name', e.target.value)}
                            />
                            <InputError message={errors[`inspectors.${index}.name`]} />
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor={`inspector-position-${index}`} className="text-xs text-muted-foreground">
                                Position (optional)
                            </Label>
                            <Input
                                id={`inspector-position-${index}`}
                                value={row.position}
                                maxLength={150}
                                autoComplete="off"
                                onChange={(e) => change(index, 'position', e.target.value)}
                            />
                            <InputError message={errors[`inspectors.${index}.position`]} />
                        </div>

                        {canRemove && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="sm:mt-6"
                                aria-label={`Remove inspector ${index + 1}`}
                                onClick={() => onInspectorsChange(inspectors.filter((_, i) => i !== index))}
                            >
                                <X className="size-4" />
                            </Button>
                        )}
                    </div>
                ))}

                <InputError message={errors.inspectors} />

                <Button type="button" variant="outline" size="sm" disabled={!canAdd} onClick={() => onInspectorsChange([...inspectors, emptyPerson()])}>
                    <Plus className="size-4" /> Add inspector
                </Button>
                {!canAdd && <p className="text-xs text-muted-foreground">At most {max} inspectors.</p>}
            </fieldset>

            <fieldset className="space-y-3">
                <legend className="text-sm leading-none font-medium">End-user</legend>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="end-user-name" className="text-xs text-muted-foreground">
                            Name
                        </Label>
                        <Input
                            id="end-user-name"
                            value={endUser.name}
                            maxLength={150}
                            autoComplete="off"
                            onChange={(e) => onEndUserChange({ ...endUser, name: e.target.value })}
                        />
                        <InputError message={errors['end_user.name']} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="end-user-position" className="text-xs text-muted-foreground">
                            Position (optional)
                        </Label>
                        <Input
                            id="end-user-position"
                            value={endUser.position}
                            maxLength={150}
                            autoComplete="off"
                            onChange={(e) => onEndUserChange({ ...endUser, position: e.target.value })}
                        />
                        <InputError message={errors['end_user.position']} />
                    </div>
                </div>

                <InputError message={errors.end_user} />
            </fieldset>
        </div>
    );
}