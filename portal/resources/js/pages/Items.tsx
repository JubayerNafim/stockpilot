import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { apiDelete, apiGet, apiPost, apiPut, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { money, number } from '../lib/format';
import {
    Badge, Button, Card, EmptyState, Flash, Input, Modal, PageHeader, Pagination, Select, Table,
} from '../components/ui';

interface Item {
    id: number;
    name: string;
    sku: string | null;
    unit: string;
    category_id: number | null;
    category_name: string | null;
    cost_price: number;
    quantity_on_hand: number;
    quantity_reserved: number;
    available: number;
    low_stock_threshold: number;
    is_low_stock: boolean;
    asset_value: number;
    is_active: boolean;
}

interface Category { id: number; name: string }

const emptyForm = {
    name: '', sku: '', category_id: '', unit: 'pcs', cost_price: '0', quantity_on_hand: '0', low_stock_threshold: '0',
};

export default function Items() {
    const { user } = useAuth();
    const qc = useQueryClient();
    const currency = user?.organization?.currency ?? 'BDT';

    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState('');
    const [lowOnly, setLowOnly] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Item | null>(null);
    const [form, setForm] = useState(emptyForm);
    const [adjusting, setAdjusting] = useState<Item | null>(null);
    const [adj, setAdj] = useState({ type: 'add', quantity: '1', reason: '' });
    const [flash, setFlash] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['items', page, search, category, lowOnly],
        queryFn: () =>
            apiGet<{ data: Item[]; current_page: number; last_page: number; total: number }>('/items', {
                page, per_page: 25, search: search || undefined, category_id: category || undefined, low_stock_only: lowOnly || undefined,
            }),
    });

    const { data: categories } = useQuery({ queryKey: ['categories'], queryFn: () => apiGet<{ categories: Category[] }>('/categories') });

    const save = useMutation({
        mutationFn: (body: Record<string, unknown>) =>
            editing ? apiPut(`/items/${editing.id}`, body) : apiPost('/items', body),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['items'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setFormOpen(false);
            setFlash(editing ? 'Item updated.' : 'Item created.');
            setEditing(null);
            setForm(emptyForm);
        },
        onError: (e) => setError(errMsg(e)),
    });

    const remove = useMutation({
        mutationFn: (id: number) => apiDelete(`/items/${id}`),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash('Item deleted.');
        },
    });

    const adjust = useMutation({
        mutationFn: (body: { type: string; quantity: string; reason: string }) =>
            apiPost(`/items/${adjusting!.id}/adjust`, body),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['items'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setAdjusting(null);
            setFlash('Stock adjusted.');
        },
        onError: (e) => setError(errMsg(e)),
    });

    const openNew = () => {
        setEditing(null);
        setForm(emptyForm);
        setFormOpen(true);
    };

    const openEdit = (item: Item) => {
        setEditing(item);
        setForm({
            name: item.name,
            sku: item.sku ?? '',
            category_id: item.category_id ? String(item.category_id) : '',
            unit: item.unit,
            cost_price: String(item.cost_price),
            quantity_on_hand: String(item.quantity_on_hand),
            low_stock_threshold: String(item.low_stock_threshold),
        });
        setFormOpen(true);
    };

    const catOptions = useMemo(
        () => categories?.categories ?? [],
        [categories],
    );

    const submit = () => {
        setError(null);
        save.mutate({
            name: form.name,
            sku: form.sku || null,
            category_id: form.category_id || null,
            unit: form.unit,
            cost_price: Number(form.cost_price),
            quantity_on_hand: Number(form.quantity_on_hand),
            low_stock_threshold: Number(form.low_stock_threshold),
        });
    };

    return (
        <div>
            <PageHeader
                title="Items"
                subtitle="Components you stock — cost, quantity and warning thresholds"
                actions={<Button onClick={openNew}>+ Add item</Button>}
            />

            <Flash message={flash} />

            <div className="mb-4 flex flex-wrap items-end gap-3">
                <Input label="Search" placeholder="Name or SKU…" value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} className="max-w-xs" />
                <Select label="Category" value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }} className="max-w-[160px]">
                    <option value="">All</option>
                    {catOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </Select>
                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" checked={lowOnly} onChange={(e) => { setLowOnly(e.target.checked); setPage(1); }} />
                    Low stock only
                </label>
            </div>

            <Table
                head={['Name', 'Category', 'On hand', 'Reserved', 'Available', 'Cost', 'Value', 'Status', '']}
                empty={!isLoading && !data?.data?.length ? <EmptyState title="No items yet" hint="Add your first component, e.g. Thank-you card" /> : undefined}
            >
                {data?.data?.map((item) => (
                    <tr key={item.id} className="hover:bg-gray-50">
                        <td className="px-4 py-2">
                            <div className="font-medium text-gray-800">{item.name}</div>
                            {item.sku && <div className="text-xs text-gray-400">{item.sku}</div>}
                        </td>
                        <td className="px-4 py-2 text-gray-600">{item.category_name ?? '—'}</td>
                        <td className={`px-4 py-2 font-medium ${item.quantity_on_hand < 0 ? 'text-red-600' : ''}`}>{number(item.quantity_on_hand)} {item.unit}</td>
                        <td className="px-4 py-2 text-gray-500">{number(item.quantity_reserved)}</td>
                        <td className={`px-4 py-2 ${item.available < 0 ? 'font-medium text-red-600' : ''}`}>{number(item.available)}</td>
                        <td className="px-4 py-2">{money(item.cost_price, currency)}</td>
                        <td className="px-4 py-2 text-gray-700">{money(item.asset_value, currency)}</td>
                        <td className="px-4 py-2">{item.is_low_stock ? <Badge tone="red">Low</Badge> : <Badge tone="green">OK</Badge>}</td>
                        <td className="px-4 py-2 text-right whitespace-nowrap">
                            <Button size="sm" variant="ghost" onClick={() => { setAdjusting(item); setAdj({ type: 'add', quantity: '1', reason: '' }); setError(null); }}>Adjust</Button>
                            <Button size="sm" variant="ghost" onClick={() => openEdit(item)}>Edit</Button>
                            {user?.role === 'admin' && (
                                <Button size="sm" variant="ghost" onClick={() => { if (confirm(`Delete ${item.name}?`)) remove.mutate(item.id); }}>🗑</Button>
                            )}
                        </td>
                    </tr>
                ))}
            </Table>

            <Pagination meta={data as never} onPage={setPage} />

            <Modal open={formOpen} onClose={() => setFormOpen(false)} title={editing ? 'Edit item' : 'Add item'}>
                <div className="space-y-3">
                    <Flash message={error} tone="red" />
                    <Input label="Name *" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="SKU" value={form.sku} onChange={(e) => setForm({ ...form, sku: e.target.value })} />
                        <Select label="Unit" value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })}>
                            {['pcs', 'box', 'set', 'roll', 'kg', 'piece'].map((u) => <option key={u}>{u}</option>)}
                        </Select>
                    </div>
                    <Select label="Category" value={form.category_id} onChange={(e) => setForm({ ...form, category_id: e.target.value })}>
                        <option value="">—</option>
                        {catOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                    </Select>
                    <div className="grid grid-cols-2 gap-3">
                        <Input label="Cost price *" type="number" step="0.01" value={form.cost_price} onChange={(e) => setForm({ ...form, cost_price: e.target.value })} />
                        <Input label="Starting quantity" type="number" step="0.0001" value={form.quantity_on_hand} disabled={!!editing} onChange={(e) => setForm({ ...form, quantity_on_hand: e.target.value })} />
                    </div>
                    <Input label="Low-stock warning threshold" type="number" step="0.0001" value={form.low_stock_threshold} onChange={(e) => setForm({ ...form, low_stock_threshold: e.target.value })} />
                    <Button className="w-full" loading={save.isPending} onClick={submit}>
                        {editing ? 'Save changes' : 'Create item'}
                    </Button>
                </div>
            </Modal>

            <Modal open={!!adjusting} onClose={() => setAdjusting(null)} title={`Adjust stock — ${adjusting?.name}`}>
                <div className="space-y-3">
                    <Flash message={error} tone="red" />
                    <Select label="Action" value={adj.type} onChange={(e) => setAdj({ ...adj, type: e.target.value })}>
                        <option value="add">Add stock</option>
                        <option value="remove">Remove stock</option>
                    </Select>
                    <Input label="Quantity *" type="number" step="0.0001" min="0" value={adj.quantity} onChange={(e) => setAdj({ ...adj, quantity: e.target.value })} />
                    <Input label="Reason" value={adj.reason} onChange={(e) => setAdj({ ...adj, reason: e.target.value })} />
                    <Button className="w-full" loading={adjust.isPending} onClick={() => adjust.mutate(adj)}>
                        Apply
                    </Button>
                </div>
            </Modal>
        </div>
    );
}
