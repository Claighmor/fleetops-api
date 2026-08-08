<?php

namespace Fleetbase\FleetOps\Mail;

use Fleetbase\FleetOps\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentRecordExpiryReminder extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The document approaching expiry.
     */
    public Document $document;

    /**
     * How many days before expiry this reminder is firing. Zero means the
     * document has already reached or passed its expiry date.
     */
    public int $offsetDays;

    /**
     * Create a new message instance.
     */
    public function __construct(Document $document, int $offsetDays)
    {
        $this->document   = $document;
        $this->offsetDays = $offsetDays;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $label   = ucfirst($this->document->type);
        $subject = ($this->document->is_expired ? 'Expired: ' : 'Expiring Soon: ') . $label;

        if ($name = $this->document->subject_name) {
            $subject .= ' — ' . $name;
        }

        return new Envelope(subject: $subject);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'fleetops::mail.document-record-expiry-reminder',
            with: [
                'document'   => $this->document,
                'offsetDays' => $this->offsetDays,
                'isExpired'  => $this->document->is_expired,
            ]
        );
    }
}
