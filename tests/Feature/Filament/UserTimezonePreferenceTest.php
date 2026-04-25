<?php

use App\Filament\MyProfile\PersonalInfoWithTimezone;
use App\Models\ApiKey;
use App\Models\User;
use App\Support\UserTimezone;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

test('users table has nullable timezone column', function () {
    expect(Schema::hasColumn('users', 'timezone'))->toBeTrue();
});

test('user model accepts timezone as fillable', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Berlin']);

    expect($user->fresh()->timezone)->toBe('Europe/Berlin');
});

test('user model defaults timezone to null when not provided', function () {
    $user = User::factory()->create();

    expect($user->fresh()->timezone)->toBeNull();
});

test('UserTimezone helper returns null for guests', function () {
    Auth::logout();

    expect(UserTimezone::current())->toBeNull();
    expect(UserTimezone::currentOrAppDefault())->toBe(config('app.timezone', 'UTC'));
});

test('UserTimezone helper returns null when user has no timezone set', function () {
    $user = User::factory()->create(['timezone' => null]);
    Auth::login($user);

    expect(UserTimezone::current())->toBeNull();
});

test('UserTimezone helper returns the user timezone when set', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);
    Auth::login($user);

    expect(UserTimezone::current())->toBe('Asia/Tokyo');
    expect(UserTimezone::currentOrAppDefault())->toBe('Asia/Tokyo');
});

test('UserTimezone helper rejects invalid timezone identifiers', function () {
    $user = User::factory()->create();
    $user->forceFill(['timezone' => 'Mars/Olympus_Mons'])->save();
    Auth::login($user);

    expect(UserTimezone::current())->toBeNull();
});

test('UserTimezone exposes a list of selectable identifiers with UTC offsets', function () {
    $options = UserTimezone::options();

    expect($options)->toBeArray()
        ->and($options)->toHaveKey('UTC')
        ->and($options['UTC'])->toBe('UTC (UTC+00:00)')
        ->and($options)->toHaveKey('Europe/Berlin')
        ->and($options['Europe/Berlin'])->toMatch('/^Europe\/Berlin \(UTC[+-]\d{2}:\d{2}\)$/');
});

test('UserFactory inTimezone state sets the timezone preference', function () {
    $user = User::factory()->inTimezone('Europe/Berlin')->create();

    expect($user->fresh()->timezone)->toBe('Europe/Berlin');
});

test('TextColumn dateTimeInUserZone macro renders the timestamp in the user timezone', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);
    Auth::login($user);

    $timestamp = \Carbon\Carbon::parse('2026-04-25 12:00:00', 'UTC');
    $expected = $timestamp->copy()->setTimezone('Asia/Tokyo')->translatedFormat('M j, Y H:i:s');

    $column = TextColumn::make('created_at')->dateTimeInUserZone('M j, Y H:i:s');

    $rendered = $column->formatState($timestamp);

    expect($rendered)->toBe($expected);
});

test('TextColumn dateTimeInUserZone macro renders in UTC when guest', function () {
    Auth::logout();

    $timestamp = \Carbon\Carbon::parse('2026-04-25 12:00:00', 'UTC');
    $expected = $timestamp->copy()->setTimezone(config('app.timezone', 'UTC'))->translatedFormat('M j, Y H:i:s');

    $column = TextColumn::make('created_at')->dateTimeInUserZone('M j, Y H:i:s');

    $rendered = $column->formatState($timestamp);

    expect($rendered)->toBe($expected);
});

test('TextColumn sinceInUserZone macro produces a relative timestamp', function () {
    $user = User::factory()->create(['timezone' => 'Asia/Tokyo']);
    Auth::login($user);

    $column = TextColumn::make('created_at')->sinceInUserZone();

    $rendered = $column->formatState(\Carbon\Carbon::now()->subMinutes(5));

    expect($rendered)->toContain('minute');
});

/**
 * Build a TextEntry with a real Schema container so closure-based formatters
 * (which need $component->getContainer() during evaluation) can run outside
 * of a real Filament page.
 */
function buildContainedTextEntry(callable $factory): TextEntry
{
    $entry = $factory();

    $schema = \Filament\Schemas\Schema::make()
        ->components([$entry]);

    $entry->container($schema);

    return $entry;
}

test('TextEntry dateTimeInUserZone macro renders the timestamp in the user timezone', function () {
    $user = User::factory()->inTimezone('Asia/Tokyo')->create();
    Auth::login($user);

    $timestamp = \Carbon\Carbon::parse('2026-04-25 12:00:00', 'UTC');
    $expected = $timestamp->copy()->setTimezone('Asia/Tokyo')->translatedFormat('M j, Y H:i:s');

    $entry = buildContainedTextEntry(
        fn (): TextEntry => TextEntry::make('created_at')->dateTimeInUserZone('M j, Y H:i:s'),
    );

    $rendered = $entry->formatState($timestamp);

    expect($rendered)->toBe($expected);
});

test('TextEntry dateTimeInUserZone macro falls back to app timezone when guest', function () {
    Auth::logout();

    $timestamp = \Carbon\Carbon::parse('2026-04-25 12:00:00', 'UTC');
    $expected = $timestamp->copy()->setTimezone(config('app.timezone', 'UTC'))->translatedFormat('M j, Y H:i:s');

    $entry = buildContainedTextEntry(
        fn (): TextEntry => TextEntry::make('created_at')->dateTimeInUserZone('M j, Y H:i:s'),
    );

    $rendered = $entry->formatState($timestamp);

    expect($rendered)->toBe($expected);
});

test('TextEntry sinceInUserZone macro produces a relative timestamp', function () {
    $user = User::factory()->inTimezone('Asia/Tokyo')->create();
    Auth::login($user);

    $entry = buildContainedTextEntry(
        fn (): TextEntry => TextEntry::make('created_at')->sinceInUserZone(),
    );

    $rendered = $entry->formatState(\Carbon\Carbon::now()->subMinutes(5));

    expect($rendered)->toContain('minute');
});

test('PersonalInfoWithTimezone form renders the timezone select', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(PersonalInfoWithTimezone::class)
        ->assertSuccessful()
        ->assertFormFieldExists('timezone');
});

test('PersonalInfoWithTimezone form persists the chosen timezone', function () {
    $user = $this->actingAsSuperAdmin();

    Livewire::test(PersonalInfoWithTimezone::class)
        ->fillForm([
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'Europe/Berlin',
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    expect($user->fresh()->timezone)->toBe('Europe/Berlin');
});

test('PersonalInfoWithTimezone form rejects an unknown timezone', function () {
    $user = $this->actingAsSuperAdmin();

    Livewire::test(PersonalInfoWithTimezone::class)
        ->fillForm([
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'Mars/Olympus_Mons',
        ])
        ->call('submit')
        ->assertHasFormErrors(['timezone']);

    expect($user->fresh()->timezone)->toBeNull();
});

test('PersonalInfoWithTimezone form allows clearing the timezone back to default', function () {
    $user = $this->actingAsSuperAdmin();
    $user->update(['timezone' => 'Asia/Tokyo']);

    Livewire::test(PersonalInfoWithTimezone::class)
        ->fillForm([
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => null,
        ])
        ->call('submit')
        ->assertHasNoFormErrors();

    expect($user->fresh()->timezone)->toBeNull();
});

test('Filament list pages still render when the viewer has a timezone preference', function () {
    $user = $this->actingAsSuperAdmin();
    $user->update(['timezone' => 'Europe/Berlin']);

    ApiKey::factory()->count(2)->create(['user_id' => $user->id]);

    Livewire::test(\App\Filament\Resources\ApiKeyResource\Pages\ListApiKeys::class)
        ->assertSuccessful();
});
