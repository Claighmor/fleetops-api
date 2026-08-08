<?php

use Fleetbase\FleetOps\Console\Commands\SendDocumentReminders;
use Fleetbase\Models\CustomField;
use Illuminate\Support\Carbon;

/**
 * An offset's reminder window opens at `expiry - offset` and never closes, so a
 * document inside 7 days has every larger offset open too. Only the most urgent
 * open offset may fire, otherwise the first run against back-dated documents
 * sends one email per configured offset.
 */
test('only the most urgent open reminder offset fires', function () {
    $command = new SendDocumentReminders();
    $today   = Carbon::today();
    $offsets = [30, 14, 7];

    // Nothing open yet.
    expect($command->dueOffset($offsets, $today->copy()->addDays(60)))->toBeNull();
    expect($command->dueOffset($offsets, $today->copy()->addDays(31)))->toBeNull();

    // Each offset takes over as the expiry approaches.
    expect($command->dueOffset($offsets, $today->copy()->addDays(30)))->toBe(30);
    expect($command->dueOffset($offsets, $today->copy()->addDays(20)))->toBe(30);
    expect($command->dueOffset($offsets, $today->copy()->addDays(14)))->toBe(14);
    expect($command->dueOffset($offsets, $today->copy()->addDays(7)))->toBe(7);
    expect($command->dueOffset($offsets, $today->copy()))->toBe(7);

    // A long-expired document yields a single offset, not one per configured offset.
    expect($command->dueOffset($offsets, $today->copy()->subDays(400)))->toBe(7);

    // A zero offset means "on the day it expires".
    expect($command->dueOffset([0], $today->copy()))->toBe(0);
    expect($command->dueOffset([0], $today->copy()->addDay()))->toBeNull();

    // Opting out is the default.
    expect($command->dueOffset([], $today->copy()))->toBeNull();
});

test('reminder offsets are normalised and junk is discarded', function () {
    $command = new SendDocumentReminders();
    $field   = new CustomField();

    $field->meta = ['reminder_offsets' => [7, 30, '14', 14, -5, 'x', null]];
    expect($command->reminderOffsets($field))->toBe([7, 14, 30]);

    $field->meta = ['reminder_offsets' => []];
    expect($command->reminderOffsets($field))->toBe([]);

    $field->meta = ['reminder_offsets' => 'nope'];
    expect($command->reminderOffsets($field))->toBe([]);

    $field->meta = [];
    expect($command->reminderOffsets($field))->toBe([]);

    $field->meta = null;
    expect($command->reminderOffsets($field))->toBe([]);
});
