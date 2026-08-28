<x-mail::message>
@if ($hasLowStock)
# ⚠ Low stock warning

**{{ $lowCount }} item(s) are at or below their restock threshold.** Restock them before orders that need them start being marked **partial**.

@if ($lowItems->isNotEmpty())
### Components needing restock

| Item | SKU | On hand | Threshold |
| --- | --- | ---: | ---: |
@foreach ($lowItems as $item)
| {{ $item->name }} | {{ $item->sku }} | {{ number_format($item->quantity_on_hand) }} | {{ number_format($item->low_stock_threshold) }} |
@endforeach
@endif

@if ($lowProducts->isNotEmpty())
### Products needing restock

| Product | SKU | Available | Threshold |
| --- | --- | ---: | ---: |
@foreach ($lowProducts as $product)
| {{ $product->name }} | {{ $product->sku }} | {{ number_format($product->available()) }} | {{ number_format($product->low_stock_threshold) }} |
@endforeach
@endif

@else
# Stock status report

No items are currently low on stock. Here is your current inventory:

@endif

### Components

| Item | SKU | On hand | Reserved | Available | Threshold |
| --- | --- | ---: | ---: | ---: | ---: |
@foreach ($items as $item)
| {{ $item->name }} | {{ $item->sku }} | {{ number_format($item->quantity_on_hand) }} | {{ number_format($item->quantity_reserved ?? 0) }} | {{ number_format($item->quantity_on_hand - ($item->quantity_reserved ?? 0)) }} | {{ number_format($item->low_stock_threshold) }} |
@endforeach

@if ($products->isNotEmpty())
### Products

| Product | SKU | On hand | Reserved | Available | Threshold |
| --- | --- | ---: | ---: | ---: | ---: |
@foreach ($products as $product)
| {{ $product->name }} | {{ $product->sku }} | {{ number_format($product->quantity_on_hand) }} | {{ number_format($product->quantity_reserved ?? 0) }} | {{ number_format($product->available()) }} | {{ number_format($product->low_stock_threshold) }} |
@endforeach
@endif

<x-mail::button :url="url('/')">
Open StockPilot
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
