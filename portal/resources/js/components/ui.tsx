import React from 'react';
import { cn } from '../lib/cn';

const tones = {
    green: 'bg-emerald-100 text-emerald-800',
    red: 'bg-red-100 text-red-800',
    amber: 'bg-amber-100 text-amber-800',
    blue: 'bg-blue-100 text-blue-800',
    gray: 'bg-gray-100 text-gray-700',
    purple: 'bg-purple-100 text-purple-800',
};

export function Badge({ tone = 'gray', children }: { tone?: keyof typeof tones; children: React.ReactNode }) {
    return (
        <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', tones[tone])}>
            {children}
        </span>
    );
}

const btnVariants = {
    primary: 'bg-indigo-600 text-white hover:bg-indigo-700',
    secondary: 'bg-white text-gray-700 ring-1 ring-gray-300 hover:bg-gray-50',
    danger: 'bg-red-600 text-white hover:bg-red-700',
    ghost: 'text-gray-600 hover:bg-gray-100',
};

export function Button({
    variant = 'primary',
    size = 'md',
    loading = false,
    className,
    children,
    disabled,
    ...props
}: React.ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: keyof typeof btnVariants;
    size?: 'sm' | 'md';
    loading?: boolean;
}) {
    return (
        <button
            {...props}
            disabled={disabled || loading}
            className={cn(
                'inline-flex items-center justify-center gap-1.5 rounded-md font-medium transition disabled:opacity-50',
                size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-3.5 py-2 text-sm',
                btnVariants[variant],
                className,
            )}
        >
            {loading && <Spinner className="size-3.5" />}
            {children}
        </button>
    );
}

export function Input({
    label,
    className,
    ...props
}: React.InputHTMLAttributes<HTMLInputElement> & { label?: string }) {
    return (
        <label className="block">
            {label && <span className="mb-1 block text-xs font-medium text-gray-600">{label}</span>}
            <input
                {...props}
                className={cn(
                    'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500',
                    className,
                )}
            />
        </label>
    );
}

export function Select({
    label,
    className,
    children,
    ...props
}: React.SelectHTMLAttributes<HTMLSelectElement> & { label?: string }) {
    return (
        <label className="block">
            {label && <span className="mb-1 block text-xs font-medium text-gray-600">{label}</span>}
            <select
                {...props}
                className={cn(
                    'w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500',
                    className,
                )}
            >
                {children}
            </select>
        </label>
    );
}

export function Card({ title, actions, children, className }: {
    title?: React.ReactNode;
    actions?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('rounded-lg border border-gray-200 bg-white shadow-sm', className)}>
            {(title || actions) && (
                <div className="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                    <h3 className="text-sm font-semibold text-gray-800">{title}</h3>
                    {actions}
                </div>
            )}
            <div className="p-4">{children}</div>
        </div>
    );
}

export function StatCard({ label, value, sub, tone = 'blue' }: {
    label: string;
    value: React.ReactNode;
    sub?: React.ReactNode;
    tone?: keyof typeof tones;
}) {
    return (
        <div className="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div className="text-xs font-medium uppercase tracking-wide text-gray-500">{label}</div>
            <div className={cn('mt-1 text-2xl font-bold', tone === 'blue' ? 'text-indigo-700' : tones[tone])}>{value}</div>
            {sub && <div className="mt-1 text-xs text-gray-500">{sub}</div>}
        </div>
    );
}

export function Spinner({ className }: { className?: string }) {
    return (
        <span
            className={cn(
                'inline-block size-4 animate-spin rounded-full border-2 border-current border-t-transparent',
                className,
            )}
        />
    );
}

export function FullPageSpinner() {
    return (
        <div className="flex h-screen items-center justify-center">
            <Spinner className="size-8 text-indigo-600" />
        </div>
    );
}

export function Modal({ open, onClose, title, children, wide = false }: {
    open: boolean;
    onClose: () => void;
    title: React.ReactNode;
    children: React.ReactNode;
    wide?: boolean;
}) {
    if (!open) return null;
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
            <div
                className={cn('w-full rounded-lg bg-white shadow-xl', wide ? 'max-w-2xl' : 'max-w-md')}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3">
                    <h3 className="text-sm font-semibold text-gray-800">{title}</h3>
                    <button onClick={onClose} className="text-gray-400 hover:text-gray-600">✕</button>
                </div>
                <div className="p-5">{children}</div>
            </div>
        </div>
    );
}

export function PageHeader({ title, subtitle, actions }: {
    title: string;
    subtitle?: string;
    actions?: React.ReactNode;
}) {
    return (
        <div className="mb-6 flex items-start justify-between">
            <div>
                <h1 className="text-xl font-bold text-gray-900">{title}</h1>
                {subtitle && <p className="mt-0.5 text-sm text-gray-500">{subtitle}</p>}
            </div>
            {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
    );
}

export function Table({ head, children, empty }: {
    head: React.ReactNode[];
    children: React.ReactNode;
    empty?: React.ReactNode;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table className="min-w-full divide-y divide-gray-200 text-sm">
                <thead className="bg-gray-50">
                    <tr>
                        {head.map((h, i) => (
                            <th key={i} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">{children}</tbody>
            </table>
            {empty}
        </div>
    );
}

export function Pagination({ meta, onPage }: {
    meta: { current_page: number; last_page: number; total: number } | undefined;
    onPage: (page: number) => void;
}) {
    if (!meta || meta.last_page <= 1) return null;
    return (
        <div className="mt-4 flex items-center justify-between text-sm text-gray-600">
            <span>{meta.total} total</span>
            <div className="flex gap-1">
                <Button size="sm" variant="secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
                    ← Prev
                </Button>
                <span className="px-2 py-1">Page {meta.current_page} / {meta.last_page}</span>
                <Button size="sm" variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
                    Next →
                </Button>
            </div>
        </div>
    );
}

export function EmptyState({ title, hint }: { title: string; hint?: string }) {
    return (
        <div className="flex flex-col items-center justify-center py-12 text-center">
            <div className="text-3xl">📦</div>
            <div className="mt-2 text-sm font-medium text-gray-700">{title}</div>
            {hint && <div className="mt-1 text-xs text-gray-400">{hint}</div>}
        </div>
    );
}

export function Flash({ message, tone = 'green' }: { message: string | null; tone?: 'green' | 'red' }) {
    if (!message) return null;
    return (
        <div className={cn('mb-4 rounded-md px-3 py-2 text-sm', tone === 'green' ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800')}>
            {message}
        </div>
    );
}
