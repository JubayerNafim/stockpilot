import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { apiDelete, apiGet, apiPost, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { dateTime, money, number } from '../lib/format';
import { Badge, Button, EmptyState, Flash, Modal, PageHeader, Pagination, Select, Spinner, Table } from '../components/ui';

interface OrderSummary {
    id: number;
    woocommerce_id: number;
    number: string;
    status: string;
    reservation_state: string;
    customer_name: string | null;
    total: number;
    refund_total: number;
    store_name: string | null;
    sync_status: string;
    order_created_at: string;
    reserved_at: string | null;
    confirmed_at: string | null;
}

interface OrderDetail extends OrderSummary {
    items: Array<{ id: number; product_name: string; sku: string | null; quantity: number; price: number; line_total: number }>;
    components: Array<{ id: number; item_id: number | null; component_product_id: number | null; item: { name: string; unit: string } | null; component_product: { name: string } | null; quantity_per_unit: number; order_quantity: number; total_quantity: number; reserved_quantity: number; confirmed_quantity: number; returned_quantity: number; status: string }>;
    status_events: Array<{ id: number; status_from: string | null; status_to: string; occurred_at: string }>;
}

type BulkAction = 'reprocess' | 'recompute_dry' | 'recompute_apply' | 'delete';

const stateTone: Record<string, 'green' | 'blue' | 'purple' | 'gray' | 'amber'> = {
    confirmed: 'green', reserved: 'blue', returned: 'purple', released: 'gray', partial: 'amber', none: 'gray',
};

const compTone: Record<string, 'green' | 'blue' | 'purple' | 'gray' | 'amber'> = {
    confirmed: 'green', reserved: 'blue', returned: 'purple', released: 'gray', partial: 'amber', pending: 'gray',
};

export default function Orders() {
    const { user } = useAuth();
    const currency = user?.organization?.currency ?? 'BDT';
    const qc = useQueryClient();
    const [searchParams] = useSearchParams();

    // Deep-link support: /orders?order=<id> (used by the Activity Log) auto-opens that order.
    useEffect(() => {
        const raw = searchParams.get('order');
        const id = raw ? Number(raw) : NaN;
        if (Number.isFinite(id) && id > 0) loadDetail(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchParams]);

    const [page, setPage] = useState(1);
    const [status, setStatus] = useState('');
    const [reservation, setReservation] = useState('');
    const [mismatch, setMismatch] = useState(false);
    const [unmapped, setUnmapped] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [detail, setDetail] = useState<OrderDetail | null>(null);
    const [flash, setFlash] = useState<string | null>(null);

    const { data, isLoading } = useQuery({
        queryKey: ['orders', page, status, reservation, mismatch, unmapped],
        queryFn: () =>
            apiGet<{ data: OrderSummary[]; current_page: number; last_page: number; total: number }>('/orders', {
                page, per_page: 25, status: status || undefined, reservation_state: reservation || undefined, mismatch: mismatch || undefined, unmapped: unmapped || undefined,
            }),
    });

    const loadDetail = async (id: number) => {
        const r = await apiGet<{ order: OrderDetail; items: OrderDetail['items']; components: OrderDetail['components']; status_events: OrderDetail['status_events'] }>(`/orders/${id}`);
        setDetail({ ...r.order, items: r.items, components: r.components, status_events: r.status_events });
    };

    const recompute = useMutation({
        mutationFn: (apply: boolean) => apiPost(`/orders/${detail!.id}/recompute`, { apply }),
        onSuccess: (r: { apply: boolean; drift_count: number; reconciliation_count: number }) => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setFlash(r.apply ? `Reconciled ${r.reconciliation_count} item cache(s).` : `Dry run: ${r.drift_count} recipe drift(s), ${r.reconciliation_count} cache diff(s).`);
        },
    });

    const reprocess = useMutation({
        mutationFn: () => apiPost<{ result: { action: string; reservation_state: string } }>(`/orders/${detail!.id}/reprocess`),
        onSuccess: async (r) => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setFlash(`Reprocessed: action=${r.result.action}, reservation state=${r.result.reservation_state}`);
            if (detail) await loadDetail(detail.id);
        },
    });

    const del = useMutation({
        mutationFn: () => apiDelete(`/orders/${detail!.id}`),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash('Order deleted.');
            setDetail(null);
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const bulk = useMutation({
        mutationFn: (action: BulkAction) =>
            apiPost<{ results: Array<{ id: number; number: string; status: string; error?: string }> }>('/orders/bulk', { action, ids: Array.from(selected) }),
        onSuccess: (r, action) => {
            const ok = r.results.filter((x) => x.status === 'ok').length;
            const errs = r.results.filter((x) => x.status === 'error');
            const label = action === 'recompute_dry' ? 'Dry-run' : action === 'recompute_apply' ? 'Reconciliation' : action === 'delete' ? 'Delete' : 'Reprocess';
            qc.invalidateQueries({ queryKey: ['orders'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            qc.invalidateQueries({ queryKey: ['items'] });
            setFlash(`Bulk ${label}: ${ok} ok${errs.length ? `, ${errs.length} failed — ${errs[0].error ?? errs[0].number}` : ''}.`);
            setSelected(new Set());
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const pageIds = (data?.data ?? []).map((o) => o.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selected.has(id));

    const toggleAll = () => {
        setSelected((prev) => {
            const next = new Set(prev);
            pageIds.forEach((id) => (allSelected ? next.delete(id) : next.add(id)));
            return next;
        });
    };

    const toggleSel = (id: number) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id); else next.add(id);
            return next;
        });
    };

    const confirmBulkDelete = () => {
        if (!confirm(`Delete ${selected.size} order(s)? Stock reservations will be released and confirmed stock restocked first.`)) return;
        bulk.mutate('delete');
    };

    return (
        <div>
            <PageHeader title="Orders" subtitle="Synced WooCommerce orders and how they consumed inventory" />

            <Flash message={flash} />

            <div className="mb-4 flex flex-wrap items-end gap-3">
                <Select label="Status" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }} className="max-w-[170px]">
                    <option value="">All statuses</option>
                    {['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed'].map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
                <Select label="Reservation" value={reservation} onChange={(e) => { setReservation(e.target.value); setPage(1); }} className="max-w-[170px]">
                    <option value="">All</option>
                    {['none', 'reserved', 'confirmed', 'released', 'returned', 'partial'].map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" checked={mismatch} onChange={(e) => { setMismatch(e.target.checked); setPage(1); }} />
                    Mismatches only
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" checked={unmapped} onChange={(e) => { setUnmapped(e.target.checked); setPage(1); }} />
                    Unmapped only
                </label>
            </div>

            {selected.size > 0 && (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-md border border-gray-200 bg-gray-50 p-2">
                    <span className="text-xs font-semibold text-gray-600">{selected.size} selected</span>
                    <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'reprocess'} disabled={bulk.isPending} onClick={() => bulk.mutate('reprocess')}>Reprocess</Button>
                    <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'recompute_dry'} disabled={bulk.isPending} onClick={() => bulk.mutate('recompute_dry')}>Dry-run recompute</Button>
                    <Button size="sm" variant="secondary" loading={bulk.isPending && bulk.variables === 'recompute_apply'} disabled={bulk.isPending} onClick={() => { if (confirm(`Apply reconciliation to ${selected.size} order(s)?`)) bulk.mutate('recompute_apply'); }}>Apply reconciliation</Button>
                    {user?.role === 'admin' && (
                        <Button size="sm" variant="danger" loading={bulk.isPending && bulk.variables === 'delete'} disabled={bulk.isPending} onClick={confirmBulkDelete}>Delete</Button>
                    )}
                </div>
            )}

            <Table
                head={[
                    <input key="sel" type="checkbox" checked={allSelected} onChange={toggleAll} aria-label="Select all on page" />,
                    'Order', 'Customer', 'Status', 'Reservation', 'Total', 'Synced', '',
                ]}
                empty={!isLoading && !data?.data?.length ? <EmptyState title="No orders synced yet" hint="Connect your store and place an order — it will appear here" /> : undefined}
            >
                {data?.data?.map((o) => (
                    <tr key={o.id} className="hover:bg-gray-50">
                        <td className="px-2 py-2"><input type="checkbox" checked={selected.has(o.id)} onChange={() => toggleSel(o.id)} /></td>
                        <td className="px-4 py-2">
                            <div className="font-medium text-gray-800">#{o.number}</div>
                            <div className="text-xs text-gray-400">{o.store_name ?? '—'} · {dateTime(o.order_created_at)}</div>
                        </td>
                        <td className="px-4 py-2">{o.customer_name ?? '—'}</td>
                        <td className="px-4 py-2"><Badge tone="gray">{o.status}</Badge></td>
                        <td className="px-4 py-2"><Badge tone={stateTone[o.reservation_state] ?? 'gray'}>{o.reservation_state}</Badge></td>
                        <td className="px-4 py-2">
                            {money(o.total, currency)}
                            {o.refund_total > 0 && <span className="ml-1 text-xs text-red-500">-{money(o.refund_total, currency)}</span>}
                        </td>
                        <td className="px-4 py-2">
                            {o.sync_status === 'mismatch' ? <Badge tone="amber">mismatch</Badge>
                                : o.sync_status === 'unmapped' ? <Badge tone="amber">unmapped</Badge>
                                    : o.sync_status === 'error' ? <Badge tone="red">error</Badge>
                                        : <Badge tone="green">ok</Badge>}
                        </td>
                        <td className="px-4 py-2 text-right"><Button size="sm" variant="ghost" onClick={() => loadDetail(o.id)}>View</Button></td>
                    </tr>
                ))}
            </Table>

            <Pagination meta={data as never} onPage={setPage} />

            <Modal open={!!detail} onClose={() => setDetail(null)} title={detail ? `Order #${detail.number}` : ''} wide>
                {detail ? (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            <Badge tone="gray">status: {detail.status}</Badge>
                            <Badge tone={stateTone[detail.reservation_state] ?? 'gray'}>reservation: {detail.reservation_state}</Badge>
                            {detail.sync_status === 'mismatch' && <Badge tone="amber">mismatch</Badge>}
                            {detail.sync_status === 'unmapped' && <Badge tone="amber">unmapped</Badge>}
                            {detail.sync_status === 'error' && <Badge tone="red">error</Badge>}
                            <span className="text-gray-500">{detail.customer_name ?? '—'}</span>
                            <span className="ml-auto font-semibold">{money(detail.total, currency)}</span>
                        </div>

                        <div>
                            <h4 className="mb-1 text-xs font-semibold uppercase text-gray-500">Ordered items</h4>
                            <Table head={['Product', 'Qty', 'Price', 'Line total']}>
                                {detail.items.map((i) => (
                                    <tr key={i.id}>
                                        <td className="px-3 py-1.5">
                                            <span className="font-medium">{i.product_name}</span>
                                            {i.sku && <span className="ml-1 text-xs text-gray-400">({i.sku})</span>}
                                        </td>
                                        <td className="px-3 py-1.5">{number(i.quantity)}</td>
                                        <td className="px-3 py-1.5">{money(i.price, currency)}</td>
                                        <td className="px-3 py-1.5">{money(i.line_total, currency)}</td>
                                    </tr>
                                ))}
                            </Table>
                        </div>

                        <div>
                            <h4 className="mb-1 text-xs font-semibold uppercase text-gray-500">Components consumed (BOM)</h4>
                            {detail.components.length === 0 ? (
                                <p className="text-sm text-gray-500">No components — no product line mapped to a recipe. Check the product name and fix the order's recipe, then press "Reprocess order".</p>
                            ) : (
                                <Table head={['Component', 'Per unit', 'Order qty', 'Total', 'Reserved', 'Confirmed', 'Returned', 'Status']}>
                                    {detail.components.map((c) => (
                                        <tr key={c.id}>
                                            <td className="px-3 py-1.5 font-medium">
                                                {c.component_product ? (
                                                    <><Badge tone="blue">product</Badge> {c.component_product.name}</>
                                                ) : (
                                                    <>{c.item?.name ?? `Item #${c.item_id}`}</>
                                                )}
                                            </td>
                                            <td className="px-3 py-1.5">{number(c.quantity_per_unit)}</td>
                                            <td className="px-3 py-1.5">{number(c.order_quantity)}</td>
                                            <td className="px-3 py-1.5 font-medium">{number(c.total_quantity)}</td>
                                            <td className="px-3 py-1.5">{number(c.reserved_quantity)}</td>
                                            <td className="px-3 py-1.5">{number(c.confirmed_quantity)}</td>
                                            <td className="px-3 py-1.5">{number(c.returned_quantity)}</td>
                                            <td className="px-3 py-1.5"><Badge tone={compTone[c.status] ?? 'gray'}>{c.status}</Badge></td>
                                        </tr>
                                    ))}
                                </Table>
                            )}
                        </div>

                        <div>
                            <h4 className="mb-1 text-xs font-semibold uppercase text-gray-500">Status history</h4>
                            <div className="flex flex-wrap gap-2 text-xs text-gray-600">
                                {detail.status_events.map((e) => (
                                    <span key={e.id} className="rounded bg-gray-100 px-2 py-1">
                                        {e.status_from ?? '—'} → <b>{e.status_to}</b> · {dateTime(e.occurred_at)}
                                    </span>
                                ))}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-center justify-end gap-2 border-t pt-3">
                            {user?.role === 'admin' && (
                                <Button variant="danger" loading={del.isPending} onClick={() => { if (confirm('Delete this order? Reservations will be released and confirmed stock restocked first.')) del.mutate(); }}>
                                    Delete order
                                </Button>
                            )}
                            <Button variant="secondary" loading={reprocess.isPending} onClick={() => { if (confirm('Re-run this order through the inventory engine? Idempotent — safe for already-processed orders.')) reprocess.mutate(); }}>
                                Reprocess order
                            </Button>
                            <Button variant="secondary" loading={recompute.isPending} onClick={() => recompute.mutate(false)}>Dry-run recompute</Button>
                            <Button variant="danger" loading={recompute.isPending} onClick={() => { if (confirm('Apply cache reconciliation? This fixes item caches against the ledger.')) recompute.mutate(true); }}>
                                Apply reconciliation
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Spinner />
                )}
            </Modal>
        </div>
    );
}
