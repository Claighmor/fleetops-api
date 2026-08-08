<?php

namespace Fleetbase\FleetOps\Console\Commands;

use Fleetbase\Models\Category;
use Fleetbase\Models\Company;
use Fleetbase\Models\CustomField;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provisions the compliance document custom fields (insurance, registration,
 * licenses) on Fleet-Ops records.
 *
 * These fields can also be created by hand at Fleet-Ops → Settings → Custom
 * Fields, but that page only offers a fixed set of subject tabs — Equipment is
 * not among them. Nothing else blocks Equipment: `equipment/form.hbs` and
 * `equipment/details.hbs` already render `custom-field/yield`, which resolves
 * its definitions with `fieldFor: "fleet-ops:equipment"` and
 * `groupedFor: "equipment_custom_field_group"`. Writing rows with those keys is
 * enough to make the fields appear, so this command covers the subjects the
 * settings page cannot reach and makes the whole setup reproducible across
 * environments.
 *
 * Date fields are stamped with `meta.reminder_offsets`, which is what
 * `fleetops:send-document-reminders` reads to decide when to email.
 *
 * Idempotent: re-running matches on (company, for, name) and leaves existing
 * fields alone.
 */
class InstallDocumentFields extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fleetops:install-document-fields
                            {--company= : Restrict to a single company UUID (defaults to every company)}
                            {--subjects=vehicle,equipment : Comma separated subjects, e.g. vehicle,equipment,driver}
                            {--offsets=30,14,7 : Comma separated reminder offsets in days for date fields}
                            {--group=Compliance Documents : Name of the custom field group to create}
                            {--sandbox : Run against the sandbox database connection}
                            {--dry-run : Report what would be created without writing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create insurance, registration and license custom fields on vehicles, equipment and drivers';

    /**
     * The field sets installed per subject.
     *
     * `type` doubles as the rendering `component`, matching how the console's
     * custom field form derives it from the type map.
     *
     * @var array<string, array<int, array{label: string, type: string}>>
     */
    private const FIELD_SETS = [
        'vehicle' => [
            ['label' => 'Insurance Provider', 'type' => 'input'],
            ['label' => 'Insurance Policy Number', 'type' => 'input'],
            ['label' => 'Insurance Expiry', 'type' => 'date-picker'],
            ['label' => 'Insurance Document', 'type' => 'file-upload'],
            ['label' => 'Registration Number', 'type' => 'input'],
            ['label' => 'Registration Expiry', 'type' => 'date-picker'],
            ['label' => 'Registration Document', 'type' => 'file-upload'],
        ],
        'equipment' => [
            ['label' => 'Insurance Provider', 'type' => 'input'],
            ['label' => 'Insurance Policy Number', 'type' => 'input'],
            ['label' => 'Insurance Expiry', 'type' => 'date-picker'],
            ['label' => 'Insurance Document', 'type' => 'file-upload'],
            ['label' => 'Registration Number', 'type' => 'input'],
            ['label' => 'Registration Expiry', 'type' => 'date-picker'],
            ['label' => 'Registration Document', 'type' => 'file-upload'],
        ],
        // Drivers already carry `drivers_license_number` and `license_expiry` as
        // real columns, so those are deliberately not duplicated here.
        'driver' => [
            ['label' => 'License Document', 'type' => 'file-upload'],
            ['label' => 'Medical Certificate Expiry', 'type' => 'date-picker'],
            ['label' => 'Medical Certificate Document', 'type' => 'file-upload'],
        ],
    ];

    /**
     * Field types whose value is a date and therefore reminder eligible.
     *
     * @var array<string>
     */
    private const DATE_FIELD_TYPES = ['date-picker', 'date-time-input'];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $conn      = $this->option('sandbox') ? 'sandbox' : 'mysql';
        $dryRun    = (bool) $this->option('dry-run');
        $groupName = (string) $this->option('group');

        $subjects = $this->parseList($this->option('subjects'));
        $offsets  = array_map('intval', $this->parseList($this->option('offsets')));

        $unknown = array_diff($subjects, array_keys(self::FIELD_SETS));
        if ($unknown) {
            $this->error('Unknown subject(s): ' . implode(', ', $unknown) . '. Supported: ' . implode(', ', array_keys(self::FIELD_SETS)) . '.');

            return self::FAILURE;
        }

        if (!$subjects) {
            $this->error('No subjects given.');

            return self::FAILURE;
        }

        $companies = Company::on($conn)->withoutGlobalScopes()->whereNull('deleted_at');
        if ($companyUuid = $this->option('company')) {
            $companies->where('uuid', $companyUuid);
        }
        $companies = $companies->get();

        if ($companies->isEmpty()) {
            $this->error('No companies matched.');

            return self::FAILURE;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Installing document fields for ' . $companies->count() . ' company(ies): ' . implode(', ', $subjects));

        $created = 0;

        foreach ($companies as $company) {
            foreach ($subjects as $subject) {
                $group = $this->ensureGroup($company, $subject, $groupName, $conn, $dryRun);

                foreach (self::FIELD_SETS[$subject] as $order => $definition) {
                    if ($this->ensureField($company, $subject, $definition, $group, $order, $offsets, $conn, $dryRun)) {
                        $created++;
                    }
                }
            }
        }

        $this->info(($dryRun ? "Would create {$created} field(s)." : "Created {$created} field(s)."));

        return self::SUCCESS;
    }

    /**
     * Find or create the custom field group the console groups these fields under.
     */
    private function ensureGroup(Company $company, string $subject, string $groupName, string $conn, bool $dryRun): ?Category
    {
        // Matches the console's `underscore(model)` group key, e.g. fuel-report -> fuel_report.
        $for = str_replace('-', '_', $subject) . '_custom_field_group';

        $group = Category::on($conn)
            ->withoutGlobalScopes()
            ->where('owner_uuid', $company->uuid)
            ->where('for', $for)
            ->where('name', $groupName)
            ->whereNull('deleted_at')
            ->first();

        if ($group) {
            return $group;
        }

        $this->line("  + group [{$subject}] {$groupName}");

        if ($dryRun) {
            return null;
        }

        return Category::on($conn)->create([
            'company_uuid' => $company->uuid,
            'owner_uuid'   => $company->uuid,
            'owner_type'   => 'company',
            'name'         => $groupName,
            'for'          => $for,
        ]);
    }

    /**
     * Find or create a single custom field definition.
     *
     * Returns true when a field was (or would be) created.
     *
     * @param array{label: string, type: string} $definition
     * @param array<int>                         $offsets
     */
    private function ensureField(Company $company, string $subject, array $definition, ?Category $group, int $order, array $offsets, string $conn, bool $dryRun): bool
    {
        $for  = 'fleet-ops:' . $subject;
        $name = Str::slug($definition['label']);

        $exists = CustomField::on($conn)
            ->withoutGlobalScopes()
            ->where('company_uuid', $company->uuid)
            ->where('for', $for)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            return false;
        }

        $isDateField = in_array($definition['type'], self::DATE_FIELD_TYPES, true);
        $meta        = $isDateField && $offsets ? ['reminder_offsets' => $offsets] : null;

        $this->line("  + field [{$subject}] {$definition['label']} ({$definition['type']})" . ($meta ? ' reminders: ' . implode(',', $offsets) : ''));

        if ($dryRun) {
            return true;
        }

        CustomField::on($conn)->create([
            'company_uuid'  => $company->uuid,
            'category_uuid' => $group?->uuid,
            // Definitions hang off the company; the values hang off the record.
            'subject_uuid'  => $company->uuid,
            'subject_type'  => 'company',
            'for'           => $for,
            'name'          => $name,
            'label'         => $definition['label'],
            'type'          => $definition['type'],
            'component'     => $definition['type'],
            'required'      => false,
            'editable'      => true,
            'order'         => $order,
            'meta'          => $meta,
        ]);

        return true;
    }

    /**
     * Split a comma separated option into a clean list.
     *
     * @return array<string>
     */
    private function parseList(?string $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn ($item) => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
