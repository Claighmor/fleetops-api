<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Tracks which expiry reminders have already been sent for a custom field
     * value, so the daily command is idempotent.
     *
     * Mirrors `maintenance_schedule_reminders`. The `due_date_snapshot` column
     * holds the expiry date at the time of sending — when a document is renewed
     * and the date moves forward, the snapshot changes and the reminders re-arm
     * for the new cycle.
     */
    public function up(): void
    {
        if (!Schema::hasTable('custom_field_reminders')) {
            Schema::create('custom_field_reminders', function (Blueprint $table) {
                $table->id();
                $table->string('custom_field_value_uuid', 191)->index();
                $table->unsignedSmallInteger('offset_days')
                    ->comment('Which offset fired, e.g. 30, 14 or 7');
                $table->date('due_date_snapshot')
                    ->comment('The expiry date at time of sending; advances on renewal so reminders re-fire');
                $table->timestamp('sent_at')->useCurrent();

                $table->unique(['custom_field_value_uuid', 'offset_days', 'due_date_snapshot'], 'unique_custom_field_reminder');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_reminders');
    }
};
