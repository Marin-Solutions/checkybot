<?php

use App\Models\NotificationSetting;
use App\Models\SeoCheck;
use App\Models\SeoSchedule;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;

test('website belongs to user', function () {
    $user = User::factory()->create();
    $website = Website::factory()->create(['created_by' => $user->id]);

    expect($website->user)->toBeInstanceOf(User::class);
    expect($website->user->id)->toBe($user->id);
});

test('website has many seo checks', function () {
    $website = Website::factory()->create();
    SeoCheck::factory()->count(3)->create(['website_id' => $website->id]);

    expect($website->seoChecks)->toHaveCount(3);
    expect($website->seoChecks->first())->toBeInstanceOf(SeoCheck::class);
});

test('website has one latest seo check', function () {
    $website = Website::factory()->create();

    SeoCheck::factory()->create([
        'website_id' => $website->id,
        'created_at' => now()->subDays(2),
    ]);

    $latestCheck = SeoCheck::factory()->create([
        'website_id' => $website->id,
        'created_at' => now(),
    ]);

    expect($website->latestSeoCheck->id)->toBe($latestCheck->id);
});

test('website has one seo schedule', function () {
    $website = Website::factory()->create();
    $schedule = SeoSchedule::factory()->create(['website_id' => $website->id]);

    expect($website->seoSchedule)->toBeInstanceOf(SeoSchedule::class);
    expect($website->seoSchedule->id)->toBe($schedule->id);
});

test('website has many notification channels', function () {
    $website = Website::factory()->create();
    NotificationSetting::factory()
        ->websiteScope()
        ->count(2)
        ->create([
            'website_id' => $website->id,
        ]);

    expect($website->notificationChannels)->toHaveCount(2);
});

test('website has many log history', function () {
    $website = Website::factory()->create();
    WebsiteLogHistory::factory()->count(5)->create(['website_id' => $website->id]);

    expect($website->logHistory)->toHaveCount(5);
});

test('website url is required', function () {
    Website::factory()->create(['url' => null]);
})->throws(\Illuminate\Database\QueryException::class);

test('website can enable uptime check', function () {
    $website = Website::factory()->create(['uptime_check' => false]);

    $website->update(['uptime_check' => true]);

    expect($website->fresh()->uptime_check)->toBeTrue();
});

test('website can set uptime interval', function () {
    $website = Website::factory()->create(['uptime_interval' => '60']);

    expect($website->uptime_interval)->toBe(60);
});

test('website tracks ssl expiry date', function () {
    $expiryDate = now()->addDays(30);
    $website = Website::factory()->create(['ssl_expiry_date' => $expiryDate]);

    expect($website->ssl_expiry_date->format('Y-m-d'))->toBe($expiryDate->format('Y-m-d'));
});

test('website ssl reminder throttle timestamp is not mass assignable', function () {
    $website = new Website;

    $website->fill([
        'ssl_expiry_reminder_sent_at' => now()->addYear(),
    ]);

    expect($website->ssl_expiry_reminder_sent_at)->toBeNull();
});

test('extractHost returns the hostname from a full url', function () {
    expect(Website::extractHost('https://example.com/health?foo=bar'))->toBe('example.com');
});

test('extractHost accepts a bare hostname', function () {
    expect(Website::extractHost('example.com'))->toBe('example.com');
});

test('extractHost accepts a schemeless hostname with path', function () {
    expect(Website::extractHost('example.com/health'))->toBe('example.com');
});

test('extractHost accepts a schemeless single-label hostname with path', function () {
    expect(Website::extractHost('internal-api/health'))->toBe('internal-api');
});

test('extractHost returns null for malformed urls with paths', function () {
    expect(Website::extractHost('bad host/path'))->toBeNull();
});

test('extractHost rejects opaque uris', function () {
    expect(Website::extractHost('mailto:ops@example.com'))->toBeNull();
});

test('extractHost accepts bare ipv6 hosts that begin with letters', function () {
    expect(Website::extractHost('fe80::1'))->toBe('fe80::1');
});

test('extractHost accepts bracketed ipv6 hosts in schemeless urls', function () {
    expect(Website::extractHost('[2001:db8::1]:8443/health'))->toBe('2001:db8::1');
});

test('extractPort returns an explicit port from a full url', function () {
    expect(Website::extractPort('https://example.com:8443/health?foo=bar'))->toBe(8443);
});

test('extractPort returns an explicit port from a schemeless url with path', function () {
    expect(Website::extractPort('example.com:8443/health'))->toBe(8443);
});

test('extractPort falls back to 443 when no port is present', function () {
    expect(Website::extractPort('https://example.com/health'))->toBe(443);
});

test('extractPort preserves default port for bare ipv6 hosts', function () {
    expect(Website::extractPort('2001:db8::1'))->toBe(443);
});

test('extractPort returns an explicit port from a bracketed ipv6 schemeless url', function () {
    expect(Website::extractPort('[2001:db8::1]:8443/health'))->toBe(8443);
});
