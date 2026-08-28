export function money(value: number | string | null | undefined, currency = 'BDT'): string {
    const n = Number(value ?? 0);
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format(n);
}

export function number(value: number | string | null | undefined, digits = 4): string {
    const n = Number(value ?? 0);
    return new Intl.NumberFormat(undefined, {
        maximumFractionDigits: digits,
    }).format(n);
}

export function date(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

export function dateTime(value: string | null | undefined): string {
    if (!value) return '—';
    const d = new Date(value);
    return d.toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
