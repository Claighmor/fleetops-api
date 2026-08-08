<?php

use Fleetbase\FleetOps\Models\Document;
use Illuminate\Support\Carbon;

/**
 * `start_date` and `end_date` are date-only columns, so they cast to midnight.
 * Every comparison must therefore be made against the start of today — using
 * `isPast()`/`now()` would report a document expiring today as already lapsed,
 * when an insurance policy running through today is still valid today.
 */
function makeDocument(?string $startDate, ?string $endDate): Document
{
    $document             = new Document();
    $document->start_date = $startDate;
    $document->end_date   = $endDate;

    return $document;
}

test('a document without an expiry never expires', function () {
    // Titles record ownership and have no term.
    $title = makeDocument(Carbon::today()->subDays(400)->toDateString(), null);

    expect($title->is_expired)->toBeFalse();
    expect($title->days_remaining)->toBeNull();
    expect($title->status)->toBe('active');
});

test('expiry status is derived from date-only comparisons', function () {
    $today = Carbon::today();

    $inTerm = makeDocument($today->copy()->subDays(30)->toDateString(), $today->copy()->addDays(200)->toDateString());
    expect($inTerm->is_expired)->toBeFalse();
    expect($inTerm->status)->toBe('active');

    $soon = makeDocument($today->copy()->subDays(300)->toDateString(), $today->copy()->addDays(10)->toDateString());
    expect($soon->status)->toBe('expiring_soon');

    $lapsed = makeDocument($today->copy()->subDays(400)->toDateString(), $today->copy()->subDay()->toDateString());
    expect($lapsed->is_expired)->toBeTrue();
    expect($lapsed->days_remaining)->toBe(0);
    expect($lapsed->status)->toBe('expired');

    $future = makeDocument($today->copy()->addDays(10)->toDateString(), $today->copy()->addDays(375)->toDateString());
    expect($future->status)->toBe('not_started');
});

test('a document expiring today is still valid today', function () {
    $today = Carbon::today();

    $boundary = makeDocument($today->copy()->subDays(365)->toDateString(), $today->toDateString());

    expect($boundary->is_expired)->toBeFalse();
    expect($boundary->status)->toBe('expiring_soon');
});
