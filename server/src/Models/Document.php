<?php

namespace Fleetbase\FleetOps\Models;

use Fleetbase\Casts\Json;
use Fleetbase\Models\File;
use Fleetbase\Models\Model;
use Fleetbase\Models\User;
use Fleetbase\Traits\HasApiModelBehavior;
use Fleetbase\Traits\HasMetaAttributes;
use Fleetbase\Traits\HasPublicId;
use Fleetbase\Traits\HasUuid;
use Fleetbase\Traits\Searchable;
use Fleetbase\Traits\TracksApiCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A compliance document attached to a fleet record.
 *
 * Covers insurance policies, registrations, titles, licenses, permits and
 * inspections for vehicles, drivers and equipment. One row per document, so a
 * renewal is a new record and the prior term stays on file — which is what makes
 * "prove we were covered on this date" answerable.
 */
class Document extends Model
{
    use HasUuid;
    use HasPublicId;
    use TracksApiCredential;
    use HasApiModelBehavior;
    use HasMetaAttributes;
    use Searchable;
    use LogsActivity;

    /**
     * Documents that cover a term and therefore expire.
     */
    public const TYPE_INSURANCE = 'insurance';
    public const TYPE_REGISTRATION = 'registration';
    public const TYPE_LICENSE = 'license';
    public const TYPE_PERMIT = 'permit';
    public const TYPE_INSPECTION = 'inspection';

    /**
     * Ownership documentation. A title does not expire, so `end_date` is
     * expected to be null and no reminders fire for it.
     */
    public const TYPE_TITLE = 'title';

    /**
     * All recognised document types.
     *
     * @var array<string>
     */
    public const TYPES = [
        self::TYPE_INSURANCE,
        self::TYPE_REGISTRATION,
        self::TYPE_TITLE,
        self::TYPE_LICENSE,
        self::TYPE_PERMIT,
        self::TYPE_INSPECTION,
    ];

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'documents';

    /**
     * The type of public Id to generate.
     *
     * @var string
     */
    protected $publicIdType = 'document';

    /**
     * The attributes that can be queried.
     *
     * @var array
     */
    protected $searchableColumns = ['document_number', 'provider', 'jurisdiction', 'lienholder', 'public_id'];

    /**
     * The attributes that can be used for filtering.
     *
     * @var array
     */
    protected $filterParams = ['type', 'provider', 'jurisdiction', 'subject_type', 'subject_uuid', 'start_date', 'end_date'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'company_uuid',
        'file_uuid',
        'vendor_uuid',
        'created_by_uuid',
        'updated_by_uuid',
        'subject_type',
        'subject_uuid',
        'type',
        'document_number',
        'provider',
        'jurisdiction',
        'lienholder',
        'issued_at',
        'start_date',
        'end_date',
        'meta',
        'notes',
    ];

    /**
     * Dynamic attributes that are appended to object.
     *
     * @var array
     */
    protected $appends = [
        'subject_name',
        'is_expired',
        'days_remaining',
        'status',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['subject', 'vendor'];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'issued_at'  => 'date',
        'start_date' => 'date',
        'end_date'   => 'date',
        'meta'       => Json::class,
    ];

    /**
     * The name of the subject to log.
     *
     * @var string
     */
    protected static $logName = 'document';

    /**
     * Get the activity log options for the model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logOnlyDirty();
    }

    /**
     * The record this document belongs to — a vehicle, driver or equipment.
     */
    public function subject(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'subject_type', 'subject_uuid');
    }

    /**
     * The uploaded scan or PDF.
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_uuid', 'uuid');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_uuid', 'uuid');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_uuid', 'uuid');
    }

    /**
     * Name of the record this document covers.
     */
    public function getSubjectNameAttribute(): ?string
    {
        if ($this->subject) {
            return $this->subject->name ?? $this->subject->display_name ?? $this->subject->public_id ?? null;
        }

        return null;
    }

    /**
     * Whether the document has passed its expiry. Documents without an expiry
     * (titles) are never expired.
     *
     * These are date-only columns, so every comparison is made against the start
     * of today. Using `isPast()` would treat a document expiring today — which is
     * still valid today — as already lapsed, because the cast lands on midnight.
     */
    public function getIsExpiredAttribute(): bool
    {
        return (bool) ($this->end_date && $this->end_date->lt(now()->startOfDay()));
    }

    /**
     * Days until expiry, or null when the document does not expire.
     */
    public function getDaysRemainingAttribute(): ?int
    {
        if (!$this->end_date) {
            return null;
        }

        if ($this->is_expired) {
            return 0;
        }

        return now()->startOfDay()->diffInDays($this->end_date, false);
    }

    /**
     * Lifecycle status derived from the dates, never stored — a stored status
     * goes stale the moment a date passes.
     */
    public function getStatusAttribute(): string
    {
        if ($this->start_date && $this->start_date->isFuture()) {
            return 'not_started';
        }

        if (!$this->end_date) {
            return 'active';
        }

        if ($this->is_expired) {
            return 'expired';
        }

        if ($this->days_remaining !== null && $this->days_remaining <= 30) {
            return 'expiring_soon';
        }

        return 'active';
    }

    /**
     * Documents currently in force.
     */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->startOfDay();

        return $query->where(function ($q) use ($today) {
            $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
        })->where(function ($q) use ($today) {
            $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
        });
    }

    /**
     * Documents past their expiry. A document expiring today is still valid.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('end_date')->where('end_date', '<', now()->startOfDay());
    }

    /**
     * Documents expiring within the given window, today included.
     */
    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->startOfDay()->addDays($days)]);
    }

    /**
     * Restrict to a single document type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * The document that currently governs a subject for a given type — the one
     * with the furthest expiry, since renewals stack as separate rows.
     */
    public function scopeCurrentFor(Builder $query, string $subjectType, string $subjectUuid, string $type): Builder
    {
        return $query->where('subject_type', $subjectType)
            ->where('subject_uuid', $subjectUuid)
            ->where('type', $type)
            ->orderByRaw('(end_date IS NULL) DESC')
            ->orderBy('end_date', 'desc');
    }
}
