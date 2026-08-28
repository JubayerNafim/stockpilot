import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { apiGet } from '../lib/api';
import { useAuth } from '../lib/auth';
import { money, number } from '../lib/format';
import { Badge, Card, PageHeader, Spinner, StatCard, Table } from '../components/ui';

interface DashboardData {
    asset_value: number;
    low_stock: Array<{ id: number; name: string; quantity_on_hand: number; low_stock_threshold: number }>;
    active_warnings: number;
    orders_by_status: Array<{ status: string; count: number }>;
    recent_orders: Array<{ id: number; woocommerce_number: string; customer_name: string; status: string; reservation_state: string; total: number; order_created_at: string }>;
    store_health: { connected: number; total: number };
}

const stateTone = (s: string) => {
    switch (s) {
        case 'confirmed': return 'green';
        case 'reserved': return 'blue';
        case 'returned': return 'purple';
        case 'released': return 'gray';
        case 'partial': return 'amber';
        default: return 'gray';
    }
};

export default function Dashboard() {
    const { user } = useAuth();
    const { data, isLoading } = useQuery({ queryKey: ['dashboard'], queryFn: () => apiGet<DashboardData>('/dashboard') });

    const currency = user?.organization?.currency ?? 'BDT';

    return (
        <div>
            <PageHeader title="Dashboard" subtitle="A live view of your inventory value and order health" />
            {isLoading || !data ? (
                <Spinner className="text-indigo-600" />
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <StatCard label="Asset value" value={money(data.asset_value, currency)} sub="Σ on-hand × cost" />
                        <StatCard
                            label="Low stock items"
                            value={data.low_stock.length}
                            tone="red"
                            sub={`${data.active_warnings} active warning${data.active_warnings === 1 ? '' : 's'}`}
                        />
                        <StatCard label="Orders" value={data.orders_by_status.reduce((a, r) => a + r.count, 0)} sub="All time (synced)" />
                        <StatCard
                            label="Stores connected"
                            value={`${data.store_health.connected}/${data.store_health.total}`}
                            sub="WooCommerce"
                        />
                    </div>

                    <div className="mt-6 grid gap-4 lg:grid-cols-2">
                        <Card title="Low stock">
                            {data.low_stock.length === 0 ? (
                                <p className="text-sm text-gray-500">Nothing is below its warning threshold. 🎉</p>
                            ) : (
                                <div className="space-y-2">
                                    {data.low_stock.map((item) => (
                                        <div key={item.id} className="flex items-center justify-between text-sm">
                                            <Link to={`/items`} className="font-medium text-gray-800 hover:text-indigo-600">
                                                {item.name}
                                            </Link>
                                            <span className="text-gray-500">
                                                {number(item.quantity_on_hand)} / threshold {number(item.low_stock_threshold)}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Card>

                        <Card title="Orders by status">
                            {data.orders_by_status.length === 0 ? (
                                <p className="text-sm text-gray-500">No orders synced yet.</p>
                            ) : (
                                <div className="space-y-2">
                                    {data.orders_by_status.map((r) => (
                                        <div key={r.status} className="flex items-center justify-between text-sm">
                                            <Badge tone="gray">{r.status}</Badge>
                                            <span className="font-medium">{r.count}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Card>
                    </div>

                    <div className="mt-6">
                        <Card title="Recent orders">
                            <Table
                                head={['Order', 'Customer', 'Status', 'Reservation', 'Total']}
                                empty={
                                    data.recent_orders.length === 0 ? (
                                        <p className="p-4 text-sm text-gray-500">Orders synced from WooCommerce will appear here.</p>
                                    ) : undefined
                                }
                            >
                                {data.recent_orders.map((o) => (
                                    <tr key={o.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-2 font-medium text-gray-800">#{o.woocommerce_number}</td>
                                        <td className="px-4 py-2">{o.customer_name ?? '—'}</td>
                                        <td className="px-4 py-2"><Badge tone="gray">{o.status}</Badge></td>
                                        <td className="px-4 py-2"><Badge tone={stateTone(o.reservation_state) as 'green'}>{o.reservation_state}</Badge></td>
                                        <td className="px-4 py-2">{money(o.total, currency)}</td>
                                    </tr>
                                ))}
                            </Table>
                        </Card>
                    </div>
                </>
            )}
        </div>
    );
}
