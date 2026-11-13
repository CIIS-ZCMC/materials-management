/** "1234.5" -> "PHP 1,234.50". The server sends money as plain strings with 2 decimals. */
export function formatPhp(amount: string | number): string {
    return `PHP ${Number(amount).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/** Shows an em dash for an empty value. */
export function orDash(value: string | number | null | undefined): string {
    return value === null || value === undefined || value === '' ? '—' : String(value);
}