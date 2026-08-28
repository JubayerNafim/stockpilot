import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { apiDelete, apiGet, apiPost, apiPut, errMsg } from '../lib/api';
import { useAuth } from '../lib/auth';
import { money } from '../lib/format';
import {
    Badge, Button, EmptyState, Flash, Input, Modal, PageHeader, Pagination, Select, Table,
} from '../components/ui';

interface Product {
    id: number;
    name: string;
    sku: string | null;
    source: 'woo' | 'manual';
    store_name: string | null;
    price: number;
    cost: number | null;
    quantity_on_hand: number;
    quantity_reserved: number;
    available: number;
    low_stock_threshold: number;
    is_low_stock: boolean;
    stock_value: number;
    categories: Array<{ id: number; name: string }>;
    has_recipe: boolean;
    material_cost: number;
    is_active: boolean;
}

interface BomLine {
    id: number;
    component_type: 'item' | 'product';
    item_id: number | null;
    component_product_id: number | null;
    item_name: string | null;
    item_sku: string | null;
    item_unit: string;
    item_cost: number;
    item_available: number;
    product_name: string | null;
    product_sku: string | null;
    product_cost: number | null;
    product_available: number | null;
    quantity: number;
    line_cost: number;
}

type BomLineInput = { type: 'item' | 'product'; id: number; quantity: string };

interface ItemOption { id: number; name: string; available: number; cost_price: number; unit: string }

export default function Products() {
    const { user } = useAuth();
    const qc = useQueryClient();
    const currency = user?.organization?.currency ?? 'BDT';

    const [page, setPage] = useState(1);
    const [noRecipe, setNoRecipe] = useState(false);
    const [flash, setFlash] = useState<string | null>(null);
    const [recipeFor, setRecipeFor] = useState<Product | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [createForm, setCreateForm] = useState({ name: '', sku: '', price: '0' });

    const { data, isLoading } = useQuery({
        queryKey: ['products', page, noRecipe],
        queryFn: () =>
            apiGet<{ data: Product[]; current_page: number; last_page: number; total: number }>('/products', {
                page, per_page: 25, no_recipe: noRecipe || undefined,
            }),
    });

    const { data: itemData } = useQuery({ queryKey: ['items-for-bom'], queryFn: () => apiGet<{ data: ItemOption[] }>('/items', { per_page: 200 }) });

    const create = useMutation({
        mutationFn: () => apiPost('/products', createForm),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['products'] }); setCreateOpen(false); setFlash('Product created.'); setCreateForm({ name: '', sku: '', price: '0' }); },
        onError: (e) => setFlash(errMsg(e)),
    });

    const remove = useMutation({
        mutationFn: (id: number) => apiDelete(`/products/${id}`),
        onSuccess: () => { qc.invalidateQueries({ queryKey: ['products'] }); setFlash('Product deleted.'); },
    });

    return (
        <div>
            <PageHeader
                title="Products & Recipes"
                subtitle="Sellable products (synced from WooCommerce) and the components each one consumes"
                actions={
                    user?.role !== 'staff' ? <Button onClick={() => setCreateOpen(true)}>+ Manual product</Button> : undefined
                }
            />

            <Flash message={flash} />

            <div className="mb-4 flex items-end gap-3">
                <label className="flex items-center gap-2 text-sm text-gray-600">
                    <input type="checkbox" checked={noRecipe} onChange={(e) => { setNoRecipe(e.target.checked); setPage(1); }} />
                    No recipe yet
                </label>
                <span className="text-xs text-gray-400">
                    {data?.total ?? 0} products · attach a recipe to each so orders deduct inventory automatically
                </span>
            </div>

            <Table
                head={['Product', 'Price', 'Cost', 'On hand', 'Reserved', 'Available', 'Low', 'Recipe', '']}
                empty={!isLoading && !data?.data?.length ? <EmptyState title="No products yet" hint="Products appear here automatically once the WooCommerce plugin connects" /> : undefined}
            >
                {data?.data?.map((p) => (
                    <tr key={p.id} className="hover:bg-gray-50">
                        <td className="px-4 py-2">
                            <div className="font-medium text-gray-800">{p.name}</div>
                            {p.sku && <div className="text-xs text-gray-400">{p.sku}</div>}
                            {p.categories?.length > 0 && (
                                <div className="mt-0.5 flex gap-1">
                                    {p.categories.slice(0, 3).map((c) => <Badge key={c.id} tone="gray">{c.name}</Badge>)}
                                </div>
                            )}
                        </td>
                        <td className="px-4 py-2">{money(p.price, currency)}</td>
                        <td className="px-4 py-2">{p.cost != null ? money(p.cost, currency) : '—'}</td>
                        <td className={`px-4 py-2 font-medium ${p.quantity_on_hand < 0 ? 'text-red-600' : ''}`}>{p.quantity_on_hand}</td>
                        <td className="px-4 py-2 text-gray-500">{p.quantity_reserved}</td>
                        <td className={`px-4 py-2 font-medium ${p.available < 0 ? 'text-red-600' : ''}`}>{p.available}</td>
                        <td className="px-4 py-2">{p.is_low_stock ? <Badge tone="red">Low</Badge> : <Badge tone="green">OK</Badge>}</td>
                        <td className="px-4 py-2">{p.has_recipe ? <Badge tone="green">Recipe</Badge> : <Badge tone="amber">No recipe</Badge>}</td>
                        <td className="px-4 py-2 text-right whitespace-nowrap">
                            <Button size="sm" variant="ghost" onClick={() => setRecipeFor(p)}>Recipe</Button>
                            {user?.role === 'admin' && (
                                <Button size="sm" variant="ghost" onClick={() => { if (confirm(`Delete ${p.name}?`)) remove.mutate(p.id); }}>🗑</Button>
                            )}
                        </td>
                    </tr>
                ))}
            </Table>

            <Pagination meta={data as never} onPage={setPage} />

            <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Create manual product">
                <div className="space-y-3">
                    <Input label="Name *" value={createForm.name} onChange={(e) => setCreateForm({ ...createForm, name: e.target.value })} />
                    <Input label="SKU" value={createForm.sku} onChange={(e) => setCreateForm({ ...createForm, sku: e.target.value })} />
                    <Input label="Price" type="number" step="0.01" value={createForm.price} onChange={(e) => setCreateForm({ ...createForm, price: e.target.value })} />
                    <Button className="w-full" loading={create.isPending} onClick={() => create.mutate()}>Create</Button>
                </div>
            </Modal>

            {recipeFor && <RecipeEditor product={recipeFor} onClose={() => setRecipeFor(null)} items={itemData?.data ?? []} currency={currency} />}
        </div>
    );
}

function RecipeEditor({ product, onClose, items, currency }: {
    product: Product;
    onClose: () => void;
    items: ItemOption[];
    currency: string;
}) {
    const qc = useQueryClient();
    const [lines, setLines] = useState<BomLineInput[] | null>(null);
    const [flash, setFlash] = useState<string | null>(null);
    const [stock, setStock] = useState({
        cost: product.cost != null ? String(product.cost) : '',
        onHand: String(product.quantity_on_hand),
        threshold: String(product.low_stock_threshold),
    });

    const { data } = useQuery({
        queryKey: ['product-bom', product.id],
        queryFn: () => apiGet<{ bom: BomLine[] }>(`/products/${product.id}/bom`),
    });

    // Products eligible to be components (excluding this one — a product can't
    // be a component of itself).
    const { data: productData } = useQuery({
        queryKey: ['products-for-bom'],
        queryFn: () => apiGet<{ data: Product[] }>('/products', { per_page: 200 }),
    });
    const productOptions = (productData?.data ?? []).filter((p) => p.id !== product.id);

    const saveProductInfo = useMutation({
        mutationFn: () =>
            apiPut(`/products/${product.id}`, {
                cost: stock.cost ? Number(stock.cost) : null,
                quantity_on_hand: Number(stock.onHand),
                low_stock_threshold: Number(stock.threshold),
            }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['products'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setFlash('Product cost / stock / warning saved.');
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const adjustStock = useMutation({
        mutationFn: (type: 'add' | 'remove') =>
            apiPost<{ product: Product }>(`/products/${product.id}/adjust`, { type, quantity: '1' }),
        onSuccess: (r) => {
            qc.invalidateQueries({ queryKey: ['products'] });
            qc.invalidateQueries({ queryKey: ['dashboard'] });
            setStock((s) => ({ ...s, onHand: String(r.product.quantity_on_hand) }));
            setFlash('Stock adjusted.');
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    useEffect(() => {
        if (data && lines === null) {
            setLines(data.bom.map((l) => ({
                type: l.component_type,
                id: l.component_type === 'product' ? (l.component_product_id ?? 0) : (l.item_id ?? 0),
                quantity: String(l.quantity),
            })));
        }
    }, [data, lines]);

    const save = useMutation({
        mutationFn: () => apiPut(`/products/${product.id}/bom`, { lines: lines ?? [] }),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['products'] });
            qc.invalidateQueries({ queryKey: ['product-bom', product.id] });
            setFlash('Recipe saved.');
        },
        onError: (e) => setFlash(errMsg(e)),
    });

    const used = new Set((lines ?? []).map((l) => `${l.type}:${l.id}`));

    const addLine = () => {
        const freeItem = items.find((i) => !used.has(`item:${i.id}`));
        const freeProduct = productOptions.find((p) => !used.has(`product:${p.id}`));
        const type = freeItem ? 'item' : 'product';
        const id = freeItem ? freeItem.id : (freeProduct?.id ?? 0);
        if (!id) return;
        setLines([...(lines ?? []), { type, id, quantity: '1' }]);
    };

    const setLineType = (index: number, type: 'item' | 'product') => {
        setLines((prev) => {
            const cur = prev ?? [];
            const usedElsewhere = new Set(cur.map((l, i) => (i === index ? '' : `${l.type}:${l.id}`)));
            const freeItem = type === 'item' ? items.find((i) => !usedElsewhere.has(`item:${i.id}`)) : undefined;
            const freeProduct = type === 'product' ? productOptions.find((p) => !usedElsewhere.has(`product:${p.id}`)) : undefined;
            const id = type === 'item' ? (freeItem?.id ?? 0) : (freeProduct?.id ?? 0);
            return cur.map((l, i) => (i === index ? { type, id, quantity: l.quantity } : l));
        });
    };

    const setLineId = (index: number, id: number) => {
        setLines((prev) => (prev ?? []).map((l, i) => (i === index ? { ...l, id: Number(id) } : l)));
    };

    const setQty = (index: number, quantity: string) => {
        setLines((prev) => (prev ?? []).map((l, i) => (i === index ? { ...l, quantity } : l)));
    };

    const removeLine = (index: number) => setLines((prev) => (prev ?? []).filter((_, i) => i !== index));

    const lineInfo = (line: BomLineInput): { name: string; cost: number; available: number | null } => {
        if (line.type === 'product') {
            const p = productOptions.find((x) => x.id === line.id);
            return { name: p?.name ?? 'Unknown product', cost: p?.cost ?? 0, available: p?.available ?? null };
        }
        const it = items.find((x) => x.id === line.id);
        return { name: it?.name ?? 'Unknown item', cost: it?.cost_price ?? 0, available: it?.available ?? null };
    };

    return (
        <Modal open onClose={onClose} title={`Recipe — ${product.name}`} wide>
            <div className="space-y-3">
                <Flash message={flash} />

                <div className="rounded-md border border-gray-200 bg-gray-50 p-3">
                    <div className="mb-2 flex items-center justify-between">
                        <span className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Finished goods (manual)
                        </span>
                        <span className="text-[11px] text-gray-500">
                            This product's own stock is deducted when it is ordered
                        </span>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <Input
                            label="Cost per unit"
                            type="number"
                            step="0.01"
                            min="0"
                            value={stock.cost}
                            onChange={(e) => setStock({ ...stock, cost: e.target.value })}
                        />
                        <div>
                            <span className="mb-1 block text-xs font-medium text-gray-600">Units on hand</span>
                            <div className="flex items-center gap-1">
                                <Button size="sm" variant="secondary" onClick={() => adjustStock.mutate('remove')}>−1</Button>
                                <span className={`flex-1 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-center text-sm font-medium ${Number(stock.onHand) < 0 ? 'text-red-600' : ''}`}>
                                    {stock.onHand}
                                </span>
                                <Button size="sm" variant="secondary" onClick={() => adjustStock.mutate('add')}>+1</Button>
                            </div>
                        </div>
                        <Input
                            label="Low-stock warning threshold"
                            type="number"
                            step="0.0001"
                            min="0"
                            value={stock.threshold}
                            onChange={(e) => setStock({ ...stock, threshold: e.target.value })}
                        />
                    </div>
                    <div className="mt-2 flex flex-wrap items-center gap-4 text-xs text-gray-600">
                        <span>Reserved: <b className="text-gray-700">{product.quantity_reserved}</b></span>
                        <span>
                            Available:{' '}
                            <b className={Number(stock.onHand) - product.quantity_reserved < 0 ? 'text-red-600' : 'text-gray-700'}>
                                {Number(stock.onHand) - product.quantity_reserved}
                            </b>
                        </span>
                        {Number(stock.onHand) - product.quantity_reserved < 0 && (
                            <span className="text-red-600">
                                ⚠ Short by {Math.abs(Number(stock.onHand) - product.quantity_reserved)} after reservations
                            </span>
                        )}
                    </div>
                    <div className="mt-2 flex items-center justify-between">
                        <span className="text-xs text-gray-500">
                            {stock.cost ? `Value: ${money(Number(stock.cost) * Number(stock.onHand), currency)}` : 'Set a cost to see value'}
                        </span>
                        <Button size="sm" loading={saveProductInfo.isPending} onClick={() => saveProductInfo.mutate()}>
                            Save cost / stock / warning
                        </Button>
                    </div>
                </div>

                <p className="text-xs text-gray-500">
                    When an order for this product arrives, the system consumes this product's own stock plus the
                    components below. Add an <b>item</b> for raw materials, or a <b>product</b> to consume another
                    product (e.g. a belt or money bag) along with its own recipe.
                </p>

                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left text-xs uppercase text-gray-500">
                            <th className="py-1 w-24">Type</th>
                            <th className="py-1">Component</th>
                            <th className="py-1">Qty per unit</th>
                            <th className="py-1">Cost/line</th>
                            <th className="py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        {(lines ?? []).map((line, index) => {
                            const info = lineInfo(line);
                            return (
                                <tr key={index} className="border-b border-gray-100">
                                    <td className="py-2">
                                        <Select value={line.type} onChange={(e) => setLineType(index, e.target.value as 'item' | 'product')}>
                                            <option value="item">Item</option>
                                            <option value="product">Product</option>
                                        </Select>
                                    </td>
                                    <td className="py-2">
                                        {line.type === 'item' ? (
                                            <Select value={String(line.id)} onChange={(e) => setLineId(index, Number(e.target.value))}>
                                                {items.filter((i) => i.id === line.id || !used.has(`item:${i.id}`)).map((i) => (
                                                    <option key={i.id} value={String(i.id)}>{i.name} ({i.available} avail)</option>
                                                ))}
                                            </Select>
                                        ) : (
                                            <Select value={String(line.id)} onChange={(e) => setLineId(index, Number(e.target.value))}>
                                                {productOptions.filter((p) => p.id === line.id || !used.has(`product:${p.id}`)).map((p) => (
                                                    <option key={p.id} value={String(p.id)}>{p.name} ({p.available} avail)</option>
                                                ))}
                                            </Select>
                                        )}
                                    </td>
                                    <td className="py-2 w-32">
                                        <Input type="number" step="0.0001" min="0" value={line.quantity} onChange={(e) => setQty(index, e.target.value)} />
                                    </td>
                                    <td className="py-2 text-gray-600">{money(Number(line.quantity) * info.cost, currency)}</td>
                                    <td className="py-2 text-right">
                                        <Button size="sm" variant="ghost" onClick={() => removeLine(index)}>✕</Button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>

                <div className="flex items-center justify-between">
                    <Button size="sm" variant="secondary" onClick={addLine} disabled={used.size >= items.length + productOptions.length}>
                        + Add component
                    </Button>
                    <div className="text-sm text-gray-700">
                        Material cost / unit:{' '}
                        <span className="font-semibold">{money((lines ?? []).reduce((sum, l) => sum + Number(l.quantity) * lineInfo(l).cost, 0), currency)}</span>
                    </div>
                </div>

                <Button className="w-full" loading={save.isPending} onClick={() => save.mutate()}>Save recipe</Button>
            </div>
        </Modal>
    );
}
