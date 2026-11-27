/**
 * Step 6.2. A visible reminder that the property rule is not final yet. It shows while config/mms.php
 * "property.provisional" is true, and disappears when MMS has confirmed the rule and someone sets it to false.
 */
export default function ProvisionalNotice({ what = 'The PAR / ICS rule is' }: { what?: string }) {
    return (
        <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-300">
            <span className="font-medium">Provisional.</span> {what} not final yet. It is waiting for MMS to confirm. Every decision keeps the
            rule it was made with, so nothing is rewritten when the rule changes.
        </div>
    );
}
