<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tracks which expiry reminders have already gone out for a document, so the
     * daily sweep is idempotent.
     *
     * `due_date_snapshot` holds the expiry at time of sending. Because renewals
     * create a new `documents` row rather than mutating the old one, this mostly
     * guards against re-sending within a cycle; it also re-arms correctly if an
     * expiry date is corrected in place.
     *
     * Unlike the custom field equivalent this can carry a real foreign key, so
     * reminder history is deleted along with its document.
     */
    public function up(): void
    {
        if (Schema::hasTable('document_reminders')) {
            return;
        }

        Schema::create('document_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_uuid')->constrained('documents', 'uuid')->cascadeOnDelete();
            $table->unsignedSmallInteger('offset_days')
                ->comment('Which offset fired, e.g. 30, 14 or 7');
            $table->date('due_date_snapshot')
                ->comment('The expiry date at time of sending');
            $table->timestamp('sent_at')->useCurrent();

            $table->unique(['document_uuid', 'offset_days', 'due_date_snapshot'], 'unique_document_reminder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_reminders');
    }
};
