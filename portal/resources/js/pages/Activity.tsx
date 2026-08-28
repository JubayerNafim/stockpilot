import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { apiGet } from '../lib/api';
import { dateTime, number } from '../lib/format';
import { Badge, EmptyState, Input, PageHeader, Pagination, Select, Table } from '../components/ui';

interface Movement {
    id: number;
    entity_type: 'item' | 'product';
    entity_id: number;
    entity_name: string;
    entity_sku: string | null;
    movement_type: string;
    qty_delta: number;
    reserved_delta: number;
    on_hand_after: number;
    reserved_after: number;
    ref_type: string | null;
    ref_id: number | null;
    ref_label: string | null;
    ref_order_status: string | null;
    reason: string | null;
    created_by: string | null;
    created_at: string;
}

const moveMeta: Record<string, { label: string; tone: 'green' | 'blue' | 'amber' | 'gray' | 'purple' }> = {
    reserve: { label: 'Reserved', tone: 'blue' },
    confirm: { label: 'Confirmed', tone: 'green' },
    release: { label: 'Released', tone: 'gray' },
    return: { label: 'Returned', tone: 'purple' },
    adjustment: { label: 'Adjustment', tone: 'amber' },
    initial: { label: 'Initial', tone: 'gray' },
    purchase_in: { label: 'Purchase', tone: 'gray' },
    sync_fix: { label: 'Sync fix', tone: 'gray' },
};

function Delta({ value }: { value: number }) {
    if (value === 0) return <span className="text-gray-400">0</span>;
    const positive = value > 0;
    return (
        <span className={`font-medium ${positive ? 'text-emerald-600' : 'text-red-600'}`}>
            {positive ? '+' : ''}{number(value)}
        </span>
    );
}

export default function Activity() {
    const [page, setPage] = useState(1);
    const [entity, setEntity] = useState('');
    const [movementType, setMovementType] = useState('');
    const [refType, setRefType] = useState('');
    const [q, setQ] = useState('');
    const [qInput, setQInput] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['movements', page, entity, movementType, refType, q],
        queryFn: () =>
            apiGet<{ data: Movement[]; current_page: number; last_page: number; total: number }>('/movements', {
                page, per_page: 50,
                entity: entity || undefined,
                movement_type: movementType || undefined,
                ref_type: refType || undefined,
                q: q || undefined,
            }),
    });

    return (
        <div>
            <PageHeader
                title="Activity Log"
                subtitle="Every stock movement — reserve, confirm, release, return, adjustment — for items and products, and the order or reason that caused it"
            />

            <div className="mb-4 flex flex-wrap items-end gap-3">
                <Input
                    label="Search item / product"
                    value={qInput}
                    onChange={(e) => setQInput(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { setQ(qInput); setPage(1); } }}
                    placeholder="Name or SKU"
                    className="max-w-[220px]"
                />
                <Select label="Type" value={entity} onChange={(e) => { setEntity(e.target.value); setPage(1); }} className="max-w-[150px]">
                    <option value="">All entities</option>
                    <option value="item">Items</option>
                    <option value="product">Products</option>
                </Select>
                <Select label="Movement" value={movementType} onChange={(e) => { setMovementType(e.target.value); setPage(1); }} className="max-w-[150px]">
                    <option value="">All movements</option>
                    {Object.entries(moveMeta).map(([k, m]) => <option key={k} value={k}>{m.label}</option>)}
                </Select>
                <Select label="Source" value={refType} onChange={(e) => { setRefType(e.target.value); setPage(1); }} className="max-w-[150px]">
                    <option value="">All sources</option>
                    <option value="order">Orders</option>
                    <option value="adjustment">Adjustments</option>
                    <option value="seed">Initial stock</option>
                </Select>
            </div>

            <Table
                head={['Date / time', 'Entity', 'Movement', 'Qty Δ', 'Reserved Δ', 'On hand', 'Reference', 'By']}
                empty={!isLoading && !data?.data?.length ? (
                    <EmptyState title="No movements found" hint="Adjust stock or sync orders and their activity will appear here" />
                ) : undefined}
            >
                {data?.data.map((m) => {
                    const meta = moveMeta[m.movement_type] ?? { label: m.movement_type, tone: 'gray' as const };
                    return (
                        <tr key={m.id} className="hover:bg-gray-50">
                            <td className="whitespace-nowrap px-4 py-2 text-xs text-gray-500">{dateTime(m.created_at)}</td>
                            <td className="px-4 py-2">
                                <div className="font-medium text-gray-800">{m.entity_name}</div>
                                <div className="text-xs text-gray-400">
                                    <Badge tone={m.entity_type === 'product' ? 'blue' : 'gray'}>{m.entity_type}</Badge>
                                    {m.entity_sku && <span className="ml-1">{m.entity_sku}</span>}
                                </div>
                            </td>
                            <td className="px-4 py-2"><Badge tone={meta.tone}>{meta.label}</Badge></td>
                            <td className="px-4 py-2"><Delta value={m.qty_delta} /></td>
                            <td className="px-4 py-2"><Delta value={m.reserved_delta} /></td>
                            <td className="px-4 py-2">
                                <div>{number(m.on_hand_after)}</div>
                                {m.reserved_after > 0 && <div className="text-xs text-gray-400">{number(m.reserved_after)} reserved</div>}
                            </td>
                            <td className="px-4 py-2">
                                {m.ref_type === 'order' && m.ref_label ? (
                                    <div>
                                        <Link to={`/orders?order=${m.ref_id}`} className="font-medium text-indigo-600 hover:underline">
                                            {m.ref_label}
                                        </Link>
                                        {m.ref_order_status && <Badge tone="gray">{m.ref_order_status}</Badge>}
                                    </div>
                                ) : (
                                    <span className="text-sm text-gray-600">{m.reason ?? m.ref_label ?? '—'}</span>
                                )}
                            </td>
                            <td className="px-4 py-2 text-xs text-gray-500">{m.created_by ?? '—'}</td>
                        </tr>
                    );
                })}
            </Table>

            <Pagination meta={data as never} onPage={setPage} />
        </div>
    );
}
