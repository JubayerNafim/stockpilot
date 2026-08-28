import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { apiGet } from '../lib/api';
import { useAuth } from '../lib/auth';
import { date, money, number } from '../lib/format';
import { Card, EmptyState, PageHeader, Table } from '../components/ui';

interface AssetReport {
    total: number;
    materials_total: number;
    finished_total: number;
    by_category: Array<{ category_id: number; category_name: string; value: number; quantity: number }>;
    by_item: Array<{ id: number; name: string; sku: string | null; category: string | null; quantity_on_hand: number; cost_price: number; value: number }>;
    by_product: Array<{ id: number; name: string; sku: string | null; quantity_on_hand: number; quantity_reserved: number; cost: number; value: number }>;
    snapshots: Array<{ captured_at: string; total_value: number }>;
}

interface Consumption {
    consumption: Array<{ item_id: number; item_name: string; quantity_consumed: number; cost: number; events: number }>;
}

export default function Reports() {
    const { user } = useAuth();
    const currency = user?.organization?.currency ?? 'BDT';
    const [tab, setTab] = useState<'asset' | 'consumption'>('asset');

    const asset = useQuery({ queryKey: ['report-asset'], queryFn: () => apiGet<AssetReport>('/reports/asset-value') });
    const consumption = useQuery({ queryKey: ['report-consumption'], queryFn: () => apiGet<Consumption>('/reports/consumption') });

    return (
        <div>
            <PageHeader title="Reports" subtitle="Asset value and component consumption" />

            <div className="mb-4 flex gap-2">
                {(['asset', 'consumption'] as const).map((t) => (
                    <button
                        key={t}
                        onClick={() => setTab(t)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium ${tab === t ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 ring-1 ring-gray-300 hover:bg-gray-50'}`}
                    >
                        {t === 'asset' ? 'Asset value' : 'Consumption'}
                    </button>
                ))}
            </div>

            {tab === 'asset' && (
                <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <Card title="Total asset value">
                            <div className="text-3xl font-bold text-indigo-700">{money(asset.data?.total ?? 0, currency)}</div>
                            <div className="text-xs text-gray-500">Items + finished goods</div>
                        </Card>
                        <Card title="Raw materials (items)">
                            <div className="text-xl font-semibold">{money(asset.data?.materials_total ?? 0, currency)}</div>
                            <div className="text-xs text-gray-500">Components & supplies</div>
                        </Card>
                        <Card title="Finished goods (products)">
                            <div className="text-xl font-semibold">{money(asset.data?.finished_total ?? 0, currency)}</div>
                            <div className="text-xs text-gray-500">Products with their own stock</div>
                        </Card>
                        {asset.data?.by_category.map((c) => (
                            <Card key={c.category_id} title={c.category_name}>
                                <div className="text-xl font-semibold">{money(c.value, currency)}</div>
                                <div className="text-xs text-gray-500">{number(c.quantity)} units on hand</div>
                            </Card>
                        ))}
                    </div>

                    <Card title="Value by item">
                        <Table
                            head={['Item', 'Category', 'On hand', 'Cost', 'Value']}
                            empty={!asset.data?.by_item?.length ? <EmptyState title="No items" /> : undefined}
                        >
                            {asset.data?.by_item.map((i) => (
                                <tr key={i.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-2 font-medium text-gray-800">{i.name}</td>
                                    <td className="px-4 py-2 text-gray-600">{i.category ?? '—'}</td>
                                    <td className="px-4 py-2">{number(i.quantity_on_hand)}</td>
                                    <td className="px-4 py-2">{money(i.cost_price, currency)}</td>
                                    <td className="px-4 py-2 font-medium">{money(i.value, currency)}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    <Card title="Value by finished goods (products)">
                        <Table
                            head={['Product', 'SKU', 'On hand', 'Reserved', 'Cost', 'Value']}
                            empty={!asset.data?.by_product?.length ? <EmptyState title="No products" hint="Products with their own stock will be valued here" /> : undefined}
                        >
                            {asset.data?.by_product.map((p) => (
                                <tr key={p.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-2 font-medium text-gray-800">{p.name}</td>
                                    <td className="px-4 py-2 text-gray-600">{p.sku ?? '—'}</td>
                                    <td className="px-4 py-2">{number(p.quantity_on_hand)}</td>
                                    <td className="px-4 py-2 text-gray-600">{number(p.quantity_reserved)}</td>
                                    <td className="px-4 py-2">{money(p.cost, currency)}</td>
                                    <td className="px-4 py-2 font-medium">{money(p.value, currency)}</td>
                                </tr>
                            ))}
                        </Table>
                    </Card>

                    {asset.data?.snapshots?.length ? (
                        <Card title="Value history">
                            <Table head={['Date', 'Total value']}>
                                {asset.data.snapshots.map((s, i) => (
                                    <tr key={i}>
                                        <td className="px-4 py-1.5">{date(s.captured_at)}</td>
                                        <td className="px-4 py-1.5">{money(s.total_value, currency)}</td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    ) : null}
                </div>
            )}

            {tab === 'consumption' && (
                <Card title="Components consumed by completed orders">
                    <Table
                        head={['Item', 'Quantity consumed', 'COGS', 'Events']}
                        empty={!consumption.data?.consumption?.length ? <EmptyState title="Nothing consumed yet" hint="Complete some orders to see consumption" /> : undefined}
                    >
                        {consumption.data?.consumption.map((c) => (
                            <tr key={c.item_id} className="hover:bg-gray-50">
                                <td className="px-4 py-2 font-medium text-gray-800">{c.item_name}</td>
                                <td className="px-4 py-2">{number(c.quantity_consumed)}</td>
                                <td className="px-4 py-2">{money(c.cost, currency)}</td>
                                <td className="px-4 py-2">{c.events}</td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            )}
        </div>
    );
}
