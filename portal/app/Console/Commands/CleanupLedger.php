<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemMovement;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Remove the phantom "duplicate confirm" ledger rows left behind by the
 * re-confirmation bug, and restore the on-hand/reserved balances they wrongly
 * reduced.
 *
 * An order may legitimately confirm an entity ONCE per shipment. A second
 * `confirm` row for the same (order, entity) with NO intervening `return` of
 * that entity's units is provably a phantom over-deduction (the engine used to
 * re-confirm old orders on replayed/stale events and re-deduct the same goods).
 * Only those unambiguous rows are removed — returns and ambiguous
 * confirm→return→confirm cycles are left untouched.
 *
 * Safe by design:
 *   - dry-run by default: reports proposed changes, writes nothing.
 *   - `--apply` backs up every deleted row + the affected stock balances to a
 *     JSON file under storage/app/ledger-cleanup/ before writing anything.
 *   - the balance fix is exact: on_hand_new = on_hand_current - Σ(phantom qty_delta).
 */
class CleanupLedger extends Command
{
    protected $signature = 'ledger:cleanup
        {--apply : Actually delete the phantom rows and fix balances (default is a dry-run report)}
        {--item= : Restrict to one item id}
        {--product= : Restrict to one product id}';

    protected $description = 'Remove duplicate-confirm phantom rows from sp_item_movements and restore stock balances';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $onlyItem = $this->option('item') !== null ? (int) $this->option('item') : null;
        $onlyProduct = $this->option('product') !== null ? (int) $this->option('product') : null;

        $this->info($apply
            ? 'Analyzing ledger and applying cleanup…'
            : 'Analyzing ledger (dry-run — nothing will be written). Add --apply to actually clean up.');

        // Only order-driven confirm/return rows matter for this scan.
        $rows = ItemMovement::whereIn('movement_type', ['confirm', 'return'])
            ->where('ref_type', 'order')
            ->when($onlyItem, fn ($q) => $q->where('item_id', $onlyItem))
            ->when($onlyProduct, fn ($q) => $q->where('product_id', $onlyProduct))
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->line('No order confirm/return movements found.');

            return self::SUCCESS;
        }

        // Group rows per (entity, order) so each order's confirm/return sequence
        // for one entity is replayed in isolation.
        $groups = [];
        foreach ($rows as $row) {
            $entityKey = $row->item_id !== null ? 'item:'.$row->item_id : 'product:'.$row->product_id;
            $groups[$entityKey][$row->ref_id][] = $row;
        }

        // Find the phantom rows: a confirm is a duplicate when the order has
        // already confirmed this entity and not yet reversed it with a return.
        $phantom = collect();
        foreach ($groups as $entityKey => $byOrder) {
            foreach ($byOrder as $rowsForOrder) {
                $outstanding = 0.0;

                foreach ($rowsForOrder as $row) {
                    if ($row->movement_type === 'return') {
                        $outstanding = max(0.0, $outstanding - (float) $row->qty_delta);

                        continue;
                    }

                    $magnitude = (float) -$row->qty_delta;

                    if ($magnitude <= 0) {
                        continue;
                    }

                    if ($outstanding >= $magnitude - 0.0001) {
                        $phantom->push($row);
                    } else {
                        $outstanding += $magnitude;
                    }
                }
            }
        }

        if ($phantom->isEmpty()) {
            $this->line('No phantom duplicate-confirm rows found. Your ledger looks clean.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Entity', 'Order', 'Movement', 'Qty delta', 'Reserved delta', 'Row id'],
            $phantom->map(fn ($row) => [
                $this->entityName($row),
                '#'.$row->ref_id,
                $row->movement_type,
                $row->qty_delta,
                $row->reserved_delta,
                $row->id,
            ]),
        );

        // Per-entity correction totals.
        $corrections = $phantom->groupBy(fn ($row) => $row->item_id !== null ? 'item:'.$row->item_id : 'product:'.$row->product_id)
            ->map(fn ($rows) => [
                'qty_delta' => round((float) $rows->sum('qty_delta'), 4),
                'reserved_delta' => round((float) $rows->sum('reserved_delta'), 4),
            ]);

        $this->newLine();
        $this->table(
            ['Entity', 'Type', 'Current on-hand', 'Phantom Δ', 'Corrected on-hand', 'Current reserved', 'Corrected reserved'],
            $corrections->map(function ($c, $key) {
                $entity = $this->entity($key);

                return [
                    $entity?->name ?? $key,
                    str_starts_with($key, 'item:') ? 'item' : 'product',
                    number_format((float) $entity->quantity_on_hand, 2),
                    number_format($c['qty_delta'], 2),
                    number_format(round((float) $entity->quantity_on_hand - $c['qty_delta'], 4), 2),
                    number_format((float) $entity->quantity_reserved, 2),
                    number_format(round((float) $entity->quantity_reserved - $c['reserved_delta'], 4), 2),
                ];
            }),
        );

        $this->line('Total phantom rows: '.$phantom->count().'.');

        if (! $apply) {
            $this->newLine();
            $this->warn('Dry-run only. Run with --apply to back up, delete these rows, and fix the balances.');

            return self::SUCCESS;
        }

        // --apply: back up first, then delete and correct.
        $backupDir = 'ledger-cleanup';
        $backupPath = $backupDir.'/cleanup-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'created_at' => now()->toDateTimeString(),
            'deleted_rows' => $phantom->map->only(['id', 'item_id', 'product_id', 'movement_type', 'qty_delta', 'reserved_delta', 'on_hand_after', 'reserved_after', 'ref_type', 'ref_id', 'reason'])->all(),
            'entities_before' => $corrections->map(function ($c, $key) {
                $entity = $this->entity($key);

                return [
                    'key' => $key,
                    'quantity_on_hand' => $entity?->quantity_on_hand,
                    'quantity_reserved' => $entity?->quantity_reserved,
                ];
            })->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        ItemMovement::whereIn('id', $phantom->pluck('id'))->delete();

        foreach ($corrections as $key => $c) {
            $entity = $this->entity($key);

            if (! $entity) {
                continue;
            }

            $entity->quantity_on_hand = round((float) $entity->quantity_on_hand - $c['qty_delta'], 4);
            $entity->quantity_reserved = round((float) $entity->quantity_reserved - $c['reserved_delta'], 4);
            $entity->save();
        }

        $this->newLine();
        $this->info('Deleted '.$phantom->count().' phantom row(s) and corrected '.$corrections->count().' entity balance(s).');
        $this->info('Backup written to: storage/app/'.$backupPath);

        return self::SUCCESS;
    }

    /** @param  ItemMovement  $row */
    private function entityName($row): string
    {
        if ($row->item_id !== null) {
            return Item::find($row->item_id)?->name ?? 'Item #'.$row->item_id;
        }

        return Product::find($row->product_id)?->name ?? 'Product #'.$row->product_id;
    }

    /** @return Item|Product|null */
    private function entity(string $key): mixed
    {
        [$type, $id] = explode(':', $key);

        return $type === 'item' ? Item::find((int) $id) : Product::find((int) $id);
    }
}
