<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\FleetOps\Mail\DocumentExpiryReminder;
use Fleetbase\FleetOps\Mail\DocumentRecordExpiryReminder;
use Fleetbase\FleetOps\Models\Document;
use Fleetbase\Models\Company;
use Fleetbase\Models\CustomField;
use Fleetbase\Models\CustomFieldValue;
use Fleetbase\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Sends expiry reminder emails for date-type custom field values.
 *
 * Fleet compliance documents (insurance policies, vehicle registrations, driver
 * licenses) are modelled as custom fields on the subject record — a `date-picker`
 * field for the expiry plus a `file-upload` field for the scan. This command is
 * the alerting half: it walks every date-type custom field that has opted in via
 * `meta.reminder_offsets` and emails the company when an expiry is approaching.
 *
 * Opt-in is per field definition, so a "Purchase Date" field never fires:
 *
 *     custom_fields.meta = { "reminder_offsets": [30, 14, 7] }
 *
 * Runs daily. Sends are recorded in `custom_field_reminders` keyed by
 * (value, offset, expiry-date) so a reminder fires exactly once per cycle and
 * re-arms when the document is renewed.
 */
class SendDocumentReminders extends Command
{
    /**
     * Custom field types whose stored value is a date we can expire on.
     *
     * @var array<string>
     */
    private const DATE_FIELD_TYPES = ['date-picker', 'date-time-input'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:send-document-reminders
                            {--sandbox : Run against the sandbox database connection}
                            {--dry-run : Log which emails would be sent without actually sending}
                            {--email= : Send every reminder to this address instead of the resolved recipient}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminder emails for custom field expiry dates that are approaching (insurance, registration, licenses)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        date_default_timezone_set('UTC');
        $sandbox        = (bool) $this->option('sandbox');
        $dryRun         = (bool) $this->option('dry-run');
        $conn           = $sandbox ? 'sandbox' : 'mysql';
        $emailOverride  = $this->option('email');

        $this->info('Sending document expiry reminders' . ($dryRun ? ' [DRY RUN]' : '') . ' at ' . Carbon::now()->toDateTimeString());

        // Load every date-type custom field definition, then keep only those that
        // opted into reminders. The definition set is small (tens of rows per
        // company), so filtering the JSON `meta` column in PHP avoids driver
        // specific JSON predicates entirely.
        $fields = CustomField::on($conn)
            ->withoutGlobalScopes()
            ->whereIn('type', self::DATE_FIELD_TYPES)
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (CustomField $field) => !empty($this->reminderOffsets($field)))
            ->keyBy('uuid');

        if ($fields->isEmpty()) {
            $this->info('No custom fields have reminder_offsets configured. Nothing to do.');

            return self::SUCCESS;
        }

        $this->line('Watching ' . $fields->count() . ' custom field(s) for expiry.');

        $values = CustomFieldValue::on($conn)
            ->withoutGlobalScopes()
            ->whereIn('custom_field_uuid', $fields->keys()->all())
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->whereNull('deleted_at')
            ->with(['subject'])
            ->get();

        $sent       = 0;
        $recipients = [];

        foreach ($values as $value) {
            $field     = $fields->get($value->custom_field_uuid);
            $expiresAt = $this->parseDate($value->value);

            if (!$field || !$expiresAt) {
                continue;
            }

            $offsetDays = $this->dueOffset($this->reminderOffsets($field), $expiresAt);
            if ($offsetDays === null) {
                continue;
            }

            $dueDateSnapshot = $expiresAt->toDateString();

            $alreadySent = DB::connection($conn)
                ->table('custom_field_reminders')
                ->where('custom_field_value_uuid', $value->uuid)
                ->where('offset_days', $offsetDays)
                ->where('due_date_snapshot', $dueDateSnapshot)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            if ($emailOverride) {
                $email = $emailOverride;
            } else {
                // Cache per company, including misses, so a company without a
                // resolvable recipient isn't looked up once per value.
                if (!array_key_exists($value->company_uuid, $recipients)) {
                    $recipients[$value->company_uuid] = $this->resolveRecipient($value->company_uuid, $conn);
                }
                $email = $recipients[$value->company_uuid];
            }

            if (!$email) {
                $this->line("  → Skipped {$field->label}: no recipient resolved for company {$value->company_uuid}.");
                continue;
            }

            $subjectName = $this->subjectName($value);
            $this->line("Sending reminder: {$field->label} on {$subjectName} — {$offsetDays} day(s) before {$dueDateSnapshot} → {$email}");

            if (!$dryRun) {
                Mail::to($email)->send(new DocumentExpiryReminder($field, $value, $expiresAt, $offsetDays));

                DB::connection($conn)->table('custom_field_reminders')->insert([
                    'custom_field_value_uuid' => $value->uuid,
                    'offset_days'             => $offsetDays,
                    'due_date_snapshot'       => $dueDateSnapshot,
                    'sent_at'                 => Carbon::now(),
                ]);
            }

            $sent++;
        }

        $sent += $this->sweepDocuments($conn, $dryRun, $emailOverride, $recipients);

        $this->info("Sent {$sent} reminder(s)" . ($dryRun ? ' (dry run — no emails sent)' : '.'));

        return self::SUCCESS;
    }

    /**
     * Sweep the `documents` table — the first-class home for compliance
     * documents, where renewals stack as separate rows.
     *
     * Offsets come from the document's own `meta.reminder_offsets` when set,
     * otherwise the fleetops config default. Documents without an `end_date`
     * (titles, for instance) never expire and are skipped by the query.
     *
     * @param array<string, string|null> $recipients cache shared with the custom field sweep
     */
    private function sweepDocuments(string $conn, bool $dryRun, ?string $emailOverride, array &$recipients): int
    {
        $defaultOffsets = config('fleetops.document_reminders.offsets', [30, 14, 7]);
        $sent           = 0;

        $documents = Document::on($conn)
            ->withoutGlobalScopes()
            ->whereNotNull('end_date')
            ->whereNull('deleted_at')
            ->with(['subject'])
            ->get();

        if ($documents->isEmpty()) {
            return 0;
        }

        $this->line('Checking ' . $documents->count() . ' document(s) for expiry.');

        foreach ($documents as $document) {
            $offsets = $this->normalizeOffsets(data_get($document->meta, 'reminder_offsets') ?: $defaultOffsets);
            $expires = $document->end_date instanceof Carbon
                ? $document->end_date->copy()->startOfDay()
                : $this->parseDate((string) $document->end_date);

            if (!$expires) {
                continue;
            }

            $offsetDays = $this->dueOffset($offsets, $expires);
            if ($offsetDays === null) {
                continue;
            }

            $dueDateSnapshot = $expires->toDateString();

            $alreadySent = DB::connection($conn)
                ->table('document_reminders')
                ->where('document_uuid', $document->uuid)
                ->where('offset_days', $offsetDays)
                ->where('due_date_snapshot', $dueDateSnapshot)
                ->exists();

            if ($alreadySent) {
                continue;
            }

            if ($emailOverride) {
                $email = $emailOverride;
            } else {
                if (!array_key_exists($document->company_uuid, $recipients)) {
                    $recipients[$document->company_uuid] = $this->resolveRecipient($document->company_uuid, $conn);
                }
                $email = $recipients[$document->company_uuid];
            }

            if (!$email) {
                $this->line("  → Skipped document {$document->public_id}: no recipient resolved for company {$document->company_uuid}.");
                continue;
            }

            $this->line("Sending reminder: {$document->type} {$document->document_number} on {$document->subject_name} — {$offsetDays} day(s) before {$dueDateSnapshot} → {$email}");

            if (!$dryRun) {
                Mail::to($email)->send(new DocumentRecordExpiryReminder($document, $offsetDays));

                DB::connection($conn)->table('document_reminders')->insert([
                    'document_uuid'     => $document->uuid,
                    'offset_days'       => $offsetDays,
                    'due_date_snapshot' => $dueDateSnapshot,
                    'sent_at'           => Carbon::now(),
                ]);
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * The configured reminder offsets for a field, as a clean list of positive day counts.
     *
     * @return array<int>
     */
    public function reminderOffsets(CustomField $field): array
    {
        return $this->normalizeOffsets(data_get($field->meta, 'reminder_offsets'));
    }

    /**
     * Coerce a configured offset list into clean, sorted, positive day counts.
     *
     * @return array<int>
     */
    public function normalizeOffsets($offsets): array
    {
        if (!is_array($offsets)) {
            return [];
        }

        return collect($offsets)
            ->filter(fn ($offset) => is_numeric($offset))
            ->map(fn ($offset) => (int) $offset)
            ->filter(fn (int $offset) => $offset >= 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Pick which offset should fire today, or null if none should.
     *
     * An offset's window opens at `expiry - offset` and never closes, so once a
     * document is inside 7 days every larger offset is open too. Firing all of
     * them would blast one email per offset the first time the command sees a
     * back-dated document. Instead only the most urgent open offset fires; the
     * wider ones still fire on their own day as the expiry approaches, because
     * each is deduped separately.
     *
     * @param array<int> $offsets
     */
    public function dueOffset(array $offsets, Carbon $expiresAt): ?int
    {
        $today = Carbon::today();

        $open = array_filter($offsets, fn (int $offset) => $today->gte($expiresAt->copy()->subDays($offset)));

        return empty($open) ? null : min($open);
    }

    /**
     * Parse a stored custom field value into a date, or null if it isn't one.
     */
    private function parseDate(?string $value): ?Carbon
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve who hears about a company's expiring documents.
     *
     * Prefers an explicit company setting, otherwise falls back to the company
     * owner. The setting key mirrors what `Setting::lookupFromCompany` builds,
     * looked up directly so this works outside a session.
     */
    private function resolveRecipient(?string $companyUuid, string $conn): ?string
    {
        if (!$companyUuid) {
            return null;
        }

        $setting = Setting::on($conn)
            ->withoutGlobalScopes()
            ->where('key', 'company.' . $companyUuid . '.fleet-ops.document-reminder-recipient')
            ->first();

        $configured = data_get($setting, 'value');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $company = Company::on($conn)->withoutGlobalScopes()->where('uuid', $companyUuid)->with('owner')->first();

        return $company?->owner?->email;
    }

    /**
     * A human label for the record the expiring document belongs to.
     */
    private function subjectName(CustomFieldValue $value): string
    {
        $subject = $value->subject;

        if (!$subject) {
            return 'unknown record';
        }

        return $subject->name
            ?? $subject->display_name
            ?? $subject->public_id
            ?? 'unknown record';
    }
}
