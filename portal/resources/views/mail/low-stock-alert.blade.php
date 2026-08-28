<x-mail::message>
# Low stock alert

The item **{{ $itemName }}** has dropped to or below its warning threshold.

- Current stock on hand: **{{ $onHand }}**
- Warning threshold: **{{ $threshold }}**

Restock this item before you run out — orders that need it will be marked **partial** once stock is fully reserved.

<x-mail::button :url="url('/items')">
Open StockPilot
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
