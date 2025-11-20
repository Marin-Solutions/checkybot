<?php

use App\Enums\NavigationIcon;

test('it has all expected cases', function () {
    $cases = NavigationIcon::cases();

    expect($cases)->toHaveCount(11);
});

test('it contains all expected case instances', function () {
    $cases = NavigationIcon::cases();

    expect($cases)->toContain(NavigationIcon::Key);
    expect($cases)->toContain(NavigationIcon::GlobeAlt);
    expect($cases)->toContain(NavigationIcon::MagnifyingGlass);
    expect($cases)->toContain(NavigationIcon::ServerStack);
    expect($cases)->toContain(NavigationIcon::ArrowPathRoundedSquare);
    expect($cases)->toContain(NavigationIcon::BellAlert);
    expect($cases)->toContain(NavigationIcon::Cog);
    expect($cases)->toContain(NavigationIcon::RectangleStack);
    expect($cases)->toContain(NavigationIcon::CloudArrowUp);
    expect($cases)->toContain(NavigationIcon::InboxStack);
    expect($cases)->toContain(NavigationIcon::Users);
});

test('it has correct heroicon values for all cases', function () {
    expect(NavigationIcon::Key->value)->toBe('heroicon-o-key');
    expect(NavigationIcon::GlobeAlt->value)->toBe('heroicon-o-globe-alt');
    expect(NavigationIcon::MagnifyingGlass->value)->toBe('heroicon-o-magnifying-glass');
    expect(NavigationIcon::ServerStack->value)->toBe('heroicon-o-server-stack');
    expect(NavigationIcon::ArrowPathRoundedSquare->value)->toBe('heroicon-o-arrow-path-rounded-square');
    expect(NavigationIcon::BellAlert->value)->toBe('heroicon-o-bell-alert');
    expect(NavigationIcon::Cog->value)->toBe('heroicon-o-cog');
    expect(NavigationIcon::RectangleStack->value)->toBe('heroicon-o-rectangle-stack');
    expect(NavigationIcon::CloudArrowUp->value)->toBe('heroicon-o-cloud-arrow-up');
    expect(NavigationIcon::InboxStack->value)->toBe('heroicon-o-inbox-stack');
    expect(NavigationIcon::Users->value)->toBe('heroicon-o-users');
});

test('all icons follow heroicon naming convention', function () {
    foreach (NavigationIcon::cases() as $icon) {
        expect($icon->value)->toStartWith('heroicon-o-');
    }
});

test('it can be serialized to string', function () {
    expect((string) NavigationIcon::Key->value)->toBe('heroicon-o-key');
    expect((string) NavigationIcon::GlobeAlt->value)->toBe('heroicon-o-globe-alt');
    expect((string) NavigationIcon::ServerStack->value)->toBe('heroicon-o-server-stack');
});

test('it can be instantiated from value', function () {
    expect(NavigationIcon::from('heroicon-o-key'))->toBe(NavigationIcon::Key);
    expect(NavigationIcon::from('heroicon-o-globe-alt'))->toBe(NavigationIcon::GlobeAlt);
    expect(NavigationIcon::from('heroicon-o-magnifying-glass'))->toBe(NavigationIcon::MagnifyingGlass);
    expect(NavigationIcon::from('heroicon-o-server-stack'))->toBe(NavigationIcon::ServerStack);
    expect(NavigationIcon::from('heroicon-o-bell-alert'))->toBe(NavigationIcon::BellAlert);
});

test('it returns null for invalid value with try from', function () {
    expect(NavigationIcon::tryFrom('invalid-icon'))->toBeNull();
    expect(NavigationIcon::tryFrom('heroicon-o-nonexistent'))->toBeNull();
});

test('it can be compared with equality', function () {
    $key1 = NavigationIcon::Key;
    $key2 = NavigationIcon::Key;
    $globe = NavigationIcon::GlobeAlt;

    expect($key1 === $key2)->toBeTrue();
    expect($key1 === $globe)->toBeFalse();
});
