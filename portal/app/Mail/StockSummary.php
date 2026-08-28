<?php

namespace App\Mail;

use App\Models\Item;
use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Daily stock digest, sent by the `stock:summary` command (and the
 * cron/stock-summary.php entry point). If anything is low on stock the email is
 * a WARNING that leads with the items needing restock; otherwise it's a plain
 * report of the current stock status of every active item and product.
 */
class StockSummary extends Mailable
{
    public function __construct(
        public Organization $organization,
        public Collection $items,
        public Collection $products,
    ) {
    }

    /** Active items at or below their restock threshold. */
    public function lowItems(): Collection
    {
        return $this->items->filter(fn (Item $item) => $item->isLowStock());
    }

    /** Active products at or below their restock threshold. */
    public function lowProducts(): Collection
    {
        return $this->products->filter(fn (Product $product) => $product->isLowStock());
    }

    public function hasLowStock(): bool
    {
        return $this->lowItems()->isNotEmpty() || $this->lowProducts()->isNotEmpty();
    }

    public function lowCount(): int
    {
        return $this->lowItems()->count() + $this->lowProducts()->count();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->hasLowStock()
                ? 'Low stock warning: '.$this->lowCount().' item(s) need restocking'
                : 'Stock status report — '.$this->organization->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.stock-summary',
            with: [
                'organization' => $this->organization,
                'items' => $this->items,
                'products' => $this->products,
                'hasLowStock' => $this->hasLowStock(),
                'lowItems' => $this->lowItems(),
                'lowProducts' => $this->lowProducts(),
                'lowCount' => $this->lowCount(),
            ],
        );
    }
}
