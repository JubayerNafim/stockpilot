<?php

namespace App\Mail;

use App\Models\StockWarning;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Low-stock alert. Sent synchronously (not queued) so it works on shared
 * hosting without a queue worker.
 */
class LowStockAlert extends Mailable
{
    public function __construct(public StockWarning $warning)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Low stock alert: '.$this->warning->entityLabel(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.low-stock-alert',
            with: [
                'itemName' => $this->warning->entityLabel(),
                'onHand' => $this->warning->entityOnHand(),
                'threshold' => $this->warning->entityThreshold(),
            ],
        );
    }
}
