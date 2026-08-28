import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { apiGet, apiPost, apiPut, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { Button, Card, Flash, Input, PageHeader } from '../components/ui';

interface SettingsData {
    organization: { name: string; currency: string; timezone: string; low_stock_email: string | null };
    settings: Record<string, unknown>;
}

type StatusMap = { reserve: string; confirm: string; release: string; return: string };

const DEFAULT_STATUS_MAP: StatusMap = {
    reserve: 'pending, pending-payment, on-hold, processing, confirmed',
    confirm: 'completed',
    release: 'cancelled, failed, trash',
    return: 'refunded, returned, returned-with-charge',
};

const STATUS_MAP_LABELS: { key: keyof StatusMap; label: string; hint: string }[] = [
    { key: 'reserve', label: 'Reserve stock', hint: 'Lock components when an order reaches these statuses' },
    { key: 'confirm', label: 'Deduct stock', hint: 'Permanently deduct components on these statuses (e.g. completed)' },
    { key: 'release', label: 'Release reservation', hint: 'Free reserved stock back on these statuses (e.g. cancelled)' },
    { key: 'return', label: 'Add stock back', hint: 'Restock components on these statuses (e.g. refunded)' },
];

export default function Settings() {
    const qc = useQueryClient();
    const { refresh } = useAuth();
    const [form, setForm] = useState({ name: '', currency: '', timezone: '', low_stock_email: '' });
    const [cooldown, setCooldown] = useState('24');
    const [allowNegative, setAllowNegative] = useState(false);
    const [statusMap, setStatusMap] = useState<StatusMap>(DEFAULT_STATUS_MAP);
    const [flash, setFlash] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    // TanStack Query v5 ignores onSuccess on useQuery, so we populate the form
    // from the fetched data via an effect.
    const { data, isLoading } = useQuery({
        queryKey: ['settings'],
        queryFn: () => apiGet<SettingsData>('/settings'),
    });

    useEffect(() => {
        if (!data) return;

        setForm({
            name: data.organization.name,
            currency: data.organization.currency,
            timezone: data.organization.timezone,
            low_stock_email: data.organization.low_stock_email ?? '',
        });
        setCooldown(String((data.settings.warning_cooldown_hours as number) ?? 24));
        setAllowNegative(Boolean(data.settings.allow_negative_stock));

        if (data.settings.status_map) {
            const m = data.settings.status_map as Record<string, string[]>;
            setStatusMap({
                reserve: (m.reserve ?? []).join(', '),
                confirm: (m.confirm ?? []).join(', '),
                release: (m.release ?? []).join(', '),
                return: (m.return ?? []).join(', '),
            });
        }
    }, [data]);

    const save = useMutation({
        mutationFn: () =>
            apiPut('/settings', {
                ...form,
                low_stock_email: form.low_stock_email || null,
                warning_cooldown_hours: Number(cooldown),
                allow_negative_stock: allowNegative,
                status_map: Object.fromEntries(
                    Object.entries(statusMap).map(([k, v]) => [
                        k,
                        v.split(',').map((s) => s.trim().toLowerCase()).filter(Boolean),
                    ]),
                ),
            }),
        onSuccess: async () => {
            qc.invalidateQueries({ queryKey: ['settings'] });
            await refresh(); // update name/currency/timezone in the sidebar & dashboard
            setFlash('Settings saved.');
        },
        onError: (e) => setError(errMsg(e)),
    });

    const testMail = useMutation({
        mutationFn: () => apiPost<{ sent_to: string }>('/settings/test-email'),
        onSuccess: (r) => setFlash(`Test email sent to ${r.sent_to}. Check your inbox / spam.`),
        onError: (e) => setError(errMsg(e)),
    });

    if (isLoading || !data) return null;

    return (
        <div>
            <PageHeader title="Settings" subtitle="Company details and warning behaviour" />

            <Flash message={flash} />
            <Flash message={error} tone="red" />

            <div className="max-w-2xl space-y-4">
                <Card title="Company">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Input label="Company name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                        <Input label="Currency" maxLength={3} value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value.toUpperCase() })} />
                    </div>
                    <div className="mt-3">
                        <Input label="Timezone" value={form.timezone} onChange={(e) => setForm({ ...form, timezone: e.target.value })} />
                    </div>
                </Card>

                <Card title="Order status → inventory actions">
                    <p className="mb-3 text-sm text-gray-600">
                        Map your WooCommerce statuses to what happens to inventory. Statuses are comma-separated.
                        Add any custom status your store uses (e.g. <code>confirmed</code>).
                    </p>
                    <div className="space-y-3">
                        {STATUS_MAP_LABELS.map(({ key, label, hint }) => (
                            <div key={key}>
                                <Input
                                    label={label}
                                    value={statusMap[key]}
                                    onChange={(e) => setStatusMap({ ...statusMap, [key]: e.target.value })}
                                />
                                <span className="mt-0.5 block text-xs text-gray-400">{hint}</span>
                            </div>
                        ))}
                    </div>
                </Card>

                <Card title="Low-stock warnings">
                    <p className="mb-3 text-sm text-gray-600">
                        When any item drops to or below its threshold, an email is sent to the address below via the SMTP server configured in <code>.env</code> (e.g. Gmail SMTP). Warnings clear when stock recovers.
                    </p>
                    <div className="space-y-3">
                        <Input
                            label="Email to notify"
                            type="email"
                            placeholder="you@example.com"
                            value={form.low_stock_email}
                            onChange={(e) => setForm({ ...form, low_stock_email: e.target.value })}
                        />
                        <Input
                            label="Cooldown between re-alerts (hours)"
                            type="number"
                            min="0"
                            value={cooldown}
                            onChange={(e) => setCooldown(e.target.value)}
                        />
                        <label className="flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" checked={allowNegative} onChange={(e) => setAllowNegative(e.target.checked)} />
                            Allow negative stock (do not clamp deductions at zero)
                        </label>
                        <div className="flex items-center gap-2 border-t border-gray-100 pt-3">
                            <Button size="sm" variant="secondary" loading={testMail.isPending} onClick={() => testMail.mutate()}>
                                Send test email
                            </Button>
                            <span className="text-xs text-gray-500">
                                Verifies SMTP delivery to the email above. Check spam too.
                            </span>
                        </div>
                    </div>
                </Card>

                <Button onClick={() => save.mutate()} loading={save.isPending}>Save settings</Button>
            </div>
        </div>
    );
}
