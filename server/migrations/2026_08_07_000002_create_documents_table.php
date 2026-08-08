<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Compliance documents (insurance policies, registrations, titles, licenses,
     * permits, inspections) attached to any fleet record.
     *
     * One row per document, so renewals stack rather than overwrite: a vehicle
     * accumulates a policy per term and the history stays auditable. `end_date`
     * is nullable because some documents never expire — a title, for instance.
     *
     * The documented item is polymorphic (`subject_type`/`subject_uuid`) and so
     * cannot carry a foreign key, matching the existing `warranties` table.
     * Every other relation is a real constrained FK.
     */
    public function up(): void
    {
        if (Schema::hasTable('documents')) {
            return;
        }

        Schema::create('documents', function (Blueprint $table) {
            $table->increments('id');
            $table->uuid('uuid')->index()->unique();
            $table->string('public_id')->nullable()->index();
            $table->string('_key')->nullable()->index();

            $table->foreignUuid('company_uuid')->constrained('companies', 'uuid')->cascadeOnDelete();
            // The scan/PDF. Nulled rather than cascaded so deleting a file never
            // silently destroys the record that it existed.
            $table->foreignUuid('file_uuid')->nullable()->constrained('files', 'uuid')->nullOnDelete();
            $table->foreignUuid('vendor_uuid')->nullable()->constrained('vendors', 'uuid')->nullOnDelete();
            $table->foreignUuid('created_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();
            $table->foreignUuid('updated_by_uuid')->nullable()->constrained('users', 'uuid')->nullOnDelete();

            // Documented item — vehicle, driver, equipment, …
            $table->string('subject_type')->nullable();
            $table->uuid('subject_uuid')->nullable();

            // insurance | registration | title | license | permit | inspection
            $table->string('type')->index();
            $table->string('document_number')->nullable()->index();
            // Insurer, DMV/DVLA, or other issuing authority.
            $table->string('provider')->nullable()->index();
            // Issuing state/country, which matters for titles and registrations.
            $table->string('jurisdiction')->nullable()->index();
            // Finance holder on a title.
            $table->string('lienholder')->nullable();

            $table->date('issued_at')->nullable()->index();
            $table->date('start_date')->nullable()->index();
            // Null means the document does not expire.
            $table->date('end_date')->nullable()->index();

            $table->json('meta')->nullable();
            $table->text('notes')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['subject_type', 'subject_uuid'], 'documents_subject_idx');
            $table->index(['subject_type', 'subject_uuid', 'type'], 'documents_subject_type_idx');
            // Drives the "what is expiring / expired" sweep.
            $table->index(['company_uuid', 'end_date'], 'documents_company_end_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
