<?php

namespace Fleetbase\FleetOps\Mail;

use Fleetbase\Models\CustomField;
use Fleetbase\Models\CustomFieldValue;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class DocumentExpiryReminder extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The custom field definition that expired, e.g. "Insurance Expiry".
     */
    public CustomField $field;

    /**
     * The stored value carrying the expiry date and its subject record.
     */
    public CustomFieldValue $value;

    /**
     * The parsed expiry date.
     */
    public Carbon $expiresAt;

    /**
     * How many days before the expiry this reminder is firing. Zero means the
     * document has already reached or passed its expiry date.
     */
    public int $offsetDays;

    /**
     * Create a new message instance.
     */
    public function __construct(CustomField $field, CustomFieldValue $value, Carbon $expiresAt, int $offsetDays)
    {
        $this->field      = $field;
        $this->value      = $value;
        $this->expiresAt  = $expiresAt;
        $this->offsetDays = $offsetDays;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $label   = $this->field->label ?? $this->field->name;
        $expired = $this->expiresAt->isPast();
        $subject = ($expired ? 'Expired: ' : 'Expiring Soon: ') . $label;

        if ($name = $this->subjectName()) {
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
            markdown: 'fleetops::mail.document-expiry-reminder',
            with: [
                'field'       => $this->field,
                'subject'     => $this->value->subject,
                'subjectName' => $this->subjectName(),
                'expiresAt'   => $this->expiresAt,
                'offsetDays'  => $this->offsetDays,
                'isExpired'   => $this->expiresAt->isPast(),
            ]
        );
    }

    /**
     * A human label for the record the expiring document belongs to.
     */
    private function subjectName(): ?string
    {
        $subject = $this->value->subject;

        if (!$subject) {
            return null;
        }

        return $subject->name
            ?? $subject->display_name
            ?? $subject->public_id;
    }
}
