import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { apiDelete, apiGet, apiPost, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { dateTime } from '../lib/format';
import { Badge, Button, Card, Flash, Input, Modal, PageHeader, Table } from '../components/ui';

interface Store {
    id: number;
    name: string;
    store_url: string;
    plugin_version: string | null;
    is_active: boolean;
    last_seen_at: string | null;
    last_sync_at: string | null;
    last_error: string | null;
    sync_count: number;
}

export default function Stores() {
    const { user } = useAuth();
    const qc = useQueryClient();
    const [createOpen, setCreateOpen] = useState(false);
    const [form, setForm] = useState({ name: '', store_url: '' });
    const [flash, setFlash] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [credentials, setCredentials] = useState<{ api_key: string; api_secret: string } | null>(null);

    const { data } = useQuery({ queryKey: ['stores'], queryFn: () => apiGet<{ stores: Store[] }>('/stores') });

    const create = useMutation({
        mutationFn: () => apiPost<{ api_key: string; api_secret: string }>('/stores', form),
        onSuccess: (r) => {
            qc.invalidateQueries({ queryKey: ['stores'] });
            setCredentials({ api_key: r.api_key, api_secret: r.api_secret });
            setCreateOpen(false);
            setForm({ name: '', store_url: '' });
        },
        onError: (e) => setError(errMsg(e)),
    });

    const rotate = useMutation({
        mutationFn: (id: number) => apiPost<{ api_key: string; api_secret: string }>(`/stores/${id}/rotate-key`),
        onSuccess: (r) => setCredentials({ api_key: r.api_key, api_secret: r.api_secret }),
        onError: (e) => setFlash(errMsg(e)),
    });

    const revoke = useMutation({
        mutationFn: (id: number) => apiDelete(`/stores/${id}`),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['stores'] }); setFlash('Store disconnected.'); },
        onError: (e) => setFlash(errMsg(e)),
    });

    return (
        <div>
            <PageHeader
                title="Store connections"
                subtitle="Connect your WooCommerce stores so the plugin can push orders and products"
                actions={user?.role === 'admin' ? <Button onClick={() => setCreateOpen(true)}>+ Connect store</Button> : undefined}
            />

            <Flash message={flash} />
            <Flash message={error} tone="red" />

            <Table
                head={['Store', 'Status', 'Plugin', 'Last seen', 'Last sync', 'Syncs', '']}
                empty={!data?.stores?.length ? undefined : undefined}
            >
                {data?.stores.map((s) => (
                    <tr key={s.id} className="hover:bg-gray-50">
                        <td className="px-4 py-2">
                            <div className="font-medium text-gray-800">{s.name}</div>
                            <div className="text-xs text-gray-400">{s.store_url}</div>
                        </td>
                        <td className="px-4 py-2">{s.is_active ? <Badge tone="green">connected</Badge> : <Badge tone="red">revoked</Badge>}</td>
                        <td className="px-4 py-2 text-gray-600">{s.plugin_version ?? '—'}</td>
                        <td className="px-4 py-2 text-gray-600">{dateTime(s.last_seen_at)}</td>
                        <td className="px-4 py-2 text-gray-600">{dateTime(s.last_sync_at)}</td>
                        <td className="px-4 py-2">{s.sync_count}</td>
                        <td className="px-4 py-2 text-right whitespace-nowrap">
                            {user?.role === 'admin' && (
                                <>
                                    <Button size="sm" variant="ghost" onClick={() => rotate.mutate(s.id)}>Rotate key</Button>
                                    <Button size="sm" variant="ghost" onClick={() => { if (confirm(`Disconnect ${s.name}?`)) revoke.mutate(s.id); }}>Revoke</Button>
                                </>
                            )}
                        </td>
                    </tr>
                ))}
            </Table>

            <div className="mt-6">
                <Card title="How to connect">
                    <ol className="list-decimal space-y-1 pl-5 text-sm text-gray-600">
                        <li>Create a store above to generate an <b>API key</b> and <b>secret</b>.</li>
                        <li>Install the <b>StockPilot for WooCommerce</b> plugin on your store.</li>
                        <li>Enter this portal's URL plus the key &amp; secret in the plugin settings.</li>
                        <li>Press <b>Test connection</b> — the portal will show it here as connected.</li>
                    </ol>
                </Card>
            </div>

            <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Connect a WooCommerce store">
                <div className="space-y-3">
                    <Input label="Store name *" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    <Input label="Store URL *" placeholder="https://yourshop.com" value={form.store_url} onChange={(e) => setForm({ ...form, store_url: e.target.value })} />
                    <Button className="w-full" loading={create.isPending} onClick={() => create.mutate()}>Generate credentials</Button>
                </div>
            </Modal>

            <Modal open={!!credentials} onClose={() => setCredentials(null)} title="Store credentials — save these now" wide>
                <div className="space-y-3">
                    <p className="text-sm text-amber-700 bg-amber-50 rounded-md px-3 py-2">
                        The secret is shown only once. Paste the key &amp; secret into the WordPress plugin settings.
                    </p>
                    <div>
                        <div className="mb-1 text-xs font-medium text-gray-600">API key</div>
                        <code className="block break-all rounded bg-gray-100 px-3 py-2 text-sm">{credentials?.api_key}</code>
                    </div>
                    <div>
                        <div className="mb-1 text-xs font-medium text-gray-600">API secret</div>
                        <code className="block break-all rounded bg-gray-100 px-3 py-2 text-sm">{credentials?.api_secret}</code>
                    </div>
                    <Button className="w-full" variant="secondary" onClick={() => setCredentials(null)}>I've saved it</Button>
                </div>
            </Modal>
        </div>
    );
}
