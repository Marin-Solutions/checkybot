<?php

use App\Enums\NotificationChannelTypesEnum;
use App\Enums\NotificationScopesEnum;
use App\Enums\WebsiteServicesEnum;
use App\Filament\Resources\WebsiteResource\Pages\CreateWebsite;
use App\Filament\Resources\WebsiteResource\Pages\EditWebsite;
use App\Filament\Resources\WebsiteResource\Pages\ListWebsites;
use App\Filament\Resources\WebsiteResource\Pages\ViewWebsite;
use App\Filament\Resources\WebsiteResource\RelationManagers\LogHistoryRelationManager;
use App\Filament\Resources\WebsiteResource\RelationManagers\NotificationSettingsRelationManager;
use App\Filament\Resources\WebsiteResource\RelationManagers\OutboundLinksRelationManager;
use App\Models\NotificationChannels;
use App\Models\NotificationSetting;
use App\Models\OutboundLink;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteLogHistory;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Dns\Dns;

test('super admin can render list page', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(ListWebsites::class)
        ->assertSuccessful();
});

test('super admin can see websites in table', function () {
    $user = $this->actingAsSuperAdmin();
    $websites = Website::factory()->count(3)->create(['created_by' => $user->id]);

    Livewire::test(ListWebsites::class)
        ->assertCanSeeTableRecords($websites);
});

test('super admin can search websites', function () {
    $user = $this->actingAsSuperAdmin();
    $website1 = Website::factory()->create(['name' => 'Test Site One', 'created_by' => $user->id]);
    $website2 = Website::factory()->create(['name' => 'Another Site', 'created_by' => $user->id]);

    Livewire::test(ListWebsites::class)
        ->searchTable('Test Site')
        ->assertCanSeeTableRecords([$website1])
        ->assertCanNotSeeTableRecords([$website2]);
});

test('super admin can render create page', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(CreateWebsite::class)
        ->assertSuccessful();
});

test('super admin can create website', function () {
    $user = $this->actingAsSuperAdmin();

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    Http::fake([
        'https://example.com' => Http::response('OK', 200),
    ]);

    Livewire::test(CreateWebsite::class)
        ->fillForm([
            'name' => 'Test Website',
            'url' => 'https://example.com',
            'description' => 'Test description',
            'uptime_check' => true,
            'uptime_interval' => 60,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('websites', [
        'name' => 'Test Website',
        'url' => 'https://example.com',
        'created_by' => $user->id,
    ]);
});

test('create website requires url', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(CreateWebsite::class)
        ->fillForm([
            'name' => 'Test Website',
            'url' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['url' => 'required']);
});

test('super admin can render edit page', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->assertSuccessful();
});

test('super admin can update website', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'name' => 'Old Name',
        'created_by' => $user->id,
        'url' => 'https://example.com',
    ]);

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    Http::fake([
        'https://example.com' => Http::response('OK', 200),
    ]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('websites', [
        'id' => $website->id,
        'name' => 'New Name',
    ]);
});

test('website edit page exposes website notification management', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Website Notifications');

    expect(\App\Filament\Resources\WebsiteResource::getRelations())
        ->toContain(NotificationSettingsRelationManager::class);
});

test('website notification relation manager only shows alerts for the current website', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);
    $otherWebsite = Website::factory()->create(['created_by' => $user->id]);

    $visibleSetting = NotificationSetting::factory()->websiteScope()->email()->create([
        'user_id' => $user->id,
        'website_id' => $website->id,
    ]);
    $hiddenSetting = NotificationSetting::factory()->websiteScope()->email()->create([
        'user_id' => $user->id,
        'website_id' => $otherWebsite->id,
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => EditWebsite::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visibleSetting])
        ->assertCanNotSeeTableRecords([$hiddenSetting]);
});

test('super admin can create website-scoped email notification from website page', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => EditWebsite::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::WEBSITE_CHECK->value,
            'channel_type' => NotificationChannelTypesEnum::MAIL->value,
            'address' => 'ops@example.com',
            'flag_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('notification_settings', [
        'user_id' => $user->id,
        'website_id' => $website->id,
        'scope' => NotificationScopesEnum::WEBSITE->value,
        'inspection' => WebsiteServicesEnum::WEBSITE_CHECK->value,
        'channel_type' => NotificationChannelTypesEnum::MAIL->value,
        'address' => 'ops@example.com',
        'flag_active' => true,
    ]);
});

test('super admin can create website-scoped webhook notification from website page', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);
    $channel = NotificationChannels::factory()->create([
        'created_by' => $user->id,
        'title' => 'Ops Hook',
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => EditWebsite::class,
    ])
        ->callTableAction('create', data: [
            'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
            'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
            'notification_channel_id' => $channel->id,
            'flag_active' => true,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('notification_settings', [
        'user_id' => $user->id,
        'website_id' => $website->id,
        'scope' => NotificationScopesEnum::WEBSITE->value,
        'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
        'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
        'notification_channel_id' => $channel->id,
        'address' => null,
        'flag_active' => true,
    ]);
});

test('super admin can update website-scoped webhook notification from website page', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);
    $oldChannel = NotificationChannels::factory()->create(['created_by' => $user->id, 'title' => 'Old Hook']);
    $newChannel = NotificationChannels::factory()->create(['created_by' => $user->id, 'title' => 'Primary Hook']);

    $setting = NotificationSetting::factory()->websiteScope()->webhook()->create([
        'user_id' => $user->id,
        'website_id' => $website->id,
        'notification_channel_id' => $oldChannel->id,
        'inspection' => WebsiteServicesEnum::WEBSITE_CHECK->value,
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => EditWebsite::class,
    ])
        ->callTableAction('edit', $setting, data: [
            'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
            'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
            'notification_channel_id' => $newChannel->id,
            'flag_active' => false,
        ])
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseHas('notification_settings', [
        'id' => $setting->id,
        'user_id' => $user->id,
        'website_id' => $website->id,
        'scope' => NotificationScopesEnum::WEBSITE->value,
        'inspection' => WebsiteServicesEnum::ALL_CHECK->value,
        'channel_type' => NotificationChannelTypesEnum::WEBHOOK->value,
        'notification_channel_id' => $newChannel->id,
        'address' => null,
        'flag_active' => false,
    ]);
});

test('super admin can delete website-scoped notification from website page', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    $setting = NotificationSetting::factory()->websiteScope()->email()->create([
        'user_id' => $user->id,
        'website_id' => $website->id,
        'inspection' => WebsiteServicesEnum::WEBSITE_CHECK->value,
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => EditWebsite::class,
    ])
        ->callTableAction('delete', $setting)
        ->assertHasNoTableActionErrors();

    $this->assertDatabaseMissing('notification_settings', [
        'id' => $setting->id,
    ]);
});

test('super admin can create website with warning state when target checks fail', function () {
    $user = $this->actingAsSuperAdmin();

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('broken.example', 'A')
        ->andReturn([]);

    Http::fake([
        'https://broken.example' => Http::response('Not Found', 404),
    ]);

    Livewire::test(CreateWebsite::class)
        ->fillForm([
            'name' => 'Broken Website',
            'url' => 'https://broken.example',
            'description' => 'Broken target',
            'uptime_check' => true,
            'uptime_interval' => 60,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('websites', [
        'name' => 'Broken Website',
        'url' => 'https://broken.example',
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);

    expect(Website::where('url', 'https://broken.example')->first()?->status_summary)
        ->toContain('The domain did not resolve during setup.')
        ->toContain('HTTP 404');
});

test('super admin can update website and keep save flow when target checks fail', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'name' => 'Healthy Website',
        'created_by' => $user->id,
        'url' => 'https://example.com',
        'current_status' => 'healthy',
        'status_summary' => 'Heartbeat received successfully.',
    ]);

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('broken.example', 'A')
        ->andReturn([]);

    Http::fake([
        'https://broken.example' => Http::response('Service Unavailable', 503),
    ]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['url' => 'https://broken.example'])
        ->call('save')
        ->assertHasNoFormErrors();

    $website->refresh();

    expect($website->url)->toBe('https://broken.example')
        ->and($website->current_status)->toBe('warning')
        ->and($website->status_summary)->toContain('The domain did not resolve during setup.')
        ->and($website->status_summary)->toContain('HTTP 503');
});

test('super admin clears setup warning state on healthy website edit', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'name' => 'Warning Website',
        'created_by' => $user->id,
        'url' => 'https://broken.example',
        'current_status' => 'warning',
        'status_summary' => 'The target could not be reached during setup.',
    ]);

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldReceive('getRecords')
        ->with('example.com', 'A')
        ->andReturn([['ip' => '192.0.2.1']]);

    Http::fake([
        'https://example.com' => Http::response('OK', 200),
    ]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['url' => 'https://example.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    $website->refresh();

    expect($website->url)->toBe('https://example.com')
        ->and($website->current_status)->toBeNull()
        ->and($website->status_summary)->toBeNull();
});

test('super admin preserves health state when editing without changing url', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'name' => 'Original Name',
        'created_by' => $user->id,
        'url' => 'https://broken.example',
        'current_status' => 'danger',
        'status_summary' => 'Website returned HTTP 500.',
    ]);

    $dnsMock = $this->mock(Dns::class);
    $dnsMock->shouldNotReceive('getRecords');

    Http::fake();

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->fillForm(['name' => 'Renamed Website'])
        ->call('save')
        ->assertHasNoFormErrors();

    $website->refresh();

    expect($website->name)->toBe('Renamed Website')
        ->and($website->url)->toBe('https://broken.example')
        ->and($website->current_status)->toBe('danger')
        ->and($website->status_summary)->toBe('Website returned HTTP 500.');
});

test('super admin can delete website', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    Livewire::test(EditWebsite::class, ['record' => $website->id])
        ->callAction('delete');

    $this->assertSoftDeleted('websites', [
        'id' => $website->id,
    ]);
});

test('super admin can filter to archived websites', function () {
    $user = $this->actingAsSuperAdmin();

    $activeWebsite = Website::factory()->create(['created_by' => $user->id]);
    $archivedWebsite = Website::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_name' => 'archived-website',
    ]);

    $archivedWebsite->delete();

    Livewire::test(ListWebsites::class)
        ->filterTable('trashed', 'only')
        ->assertCanSeeTableRecords([$archivedWebsite]);
});

test('regular user cannot access website resource', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(ListWebsites::class)
        ->assertForbidden();
});

test('super admin can filter websites by current status', function () {
    $user = $this->actingAsSuperAdmin();

    $healthy = Website::factory()->create(['created_by' => $user->id, 'current_status' => 'healthy']);
    $warning = Website::factory()->create(['created_by' => $user->id, 'current_status' => 'warning']);
    $danger = Website::factory()->create(['created_by' => $user->id, 'current_status' => 'danger']);
    $unknownNull = Website::factory()->create(['created_by' => $user->id, 'current_status' => null]);
    $unknownLiteral = Website::factory()->create(['created_by' => $user->id, 'current_status' => 'unknown']);

    Livewire::test(ListWebsites::class)
        ->filterTable('current_status', 'danger')
        ->assertCanSeeTableRecords([$danger])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $unknownNull, $unknownLiteral]);

    Livewire::test(ListWebsites::class)
        ->filterTable('current_status', 'warning')
        ->assertCanSeeTableRecords([$warning])
        ->assertCanNotSeeTableRecords([$healthy, $danger, $unknownNull, $unknownLiteral]);

    Livewire::test(ListWebsites::class)
        ->filterTable('current_status', 'healthy')
        ->assertCanSeeTableRecords([$healthy])
        ->assertCanNotSeeTableRecords([$warning, $danger, $unknownNull, $unknownLiteral]);

    Livewire::test(ListWebsites::class)
        ->filterTable('current_status', 'unknown')
        ->assertCanSeeTableRecords([$unknownNull, $unknownLiteral])
        ->assertCanNotSeeTableRecords([$healthy, $warning, $danger]);
});

test('super admin can filter websites to only failing', function () {
    $user = $this->actingAsSuperAdmin();

    $healthy = Website::factory()->create(['created_by' => $user->id, 'current_status' => 'healthy']);
    $warning = Website::factory()->create(['created_by' => $user->id, 'current_status' => 'warning']);
    $danger = Website::factory()->create(['created_by' => $user->id, 'current_status' => 'danger']);
    $unknown = Website::factory()->create(['created_by' => $user->id, 'current_status' => null]);
    $pausedWarning = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
        'uptime_check' => false,
    ]);
    $pausedDanger = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
        'uptime_check' => false,
    ]);

    Livewire::test(ListWebsites::class)
        ->filterTable('only_failing', true)
        ->assertCanSeeTableRecords([$warning, $danger])
        ->assertCanNotSeeTableRecords([$healthy, $unknown, $pausedWarning, $pausedDanger]);
});

test('super admin can bulk disable uptime checks on websites', function () {
    $user = $this->actingAsSuperAdmin();

    $websites = Website::factory()->count(3)->create([
        'created_by' => $user->id,
        'uptime_check' => true,
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableBulkAction('disableUptimeCheck', $websites);

    foreach ($websites as $website) {
        expect($website->refresh()->uptime_check)->toBeFalse();
    }
});

test('super admin can bulk enable uptime checks on websites', function () {
    $user = $this->actingAsSuperAdmin();

    $websites = Website::factory()->count(3)->create([
        'created_by' => $user->id,
        'uptime_check' => false,
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableBulkAction('enableUptimeCheck', $websites);

    foreach ($websites as $website) {
        expect($website->refresh()->uptime_check)->toBeTrue();
    }
});

test('bulk disable on already disabled websites notifies that nothing changed', function () {
    $user = $this->actingAsSuperAdmin();

    $websites = Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'uptime_check' => false,
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableBulkAction('disableUptimeCheck', $websites)
        ->assertNotified('Nothing to disable');

    foreach ($websites as $website) {
        expect($website->refresh()->uptime_check)->toBeFalse();
    }
});

test('bulk enable on already enabled websites notifies that nothing changed', function () {
    $user = $this->actingAsSuperAdmin();

    $websites = Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'uptime_check' => true,
    ]);

    Livewire::test(ListWebsites::class)
        ->callTableBulkAction('enableUptimeCheck', $websites)
        ->assertNotified('Nothing to enable');

    foreach ($websites as $website) {
        expect($website->refresh()->uptime_check)->toBeTrue();
    }
});

test('super admin can toggle uptime_check inline from the websites table', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => true,
    ]);

    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'uptime_check', $website->getKey(), false)
        ->assertNotified();

    expect($website->refresh()->uptime_check)->toBeFalse();

    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'uptime_check', $website->getKey(), true)
        ->assertNotified();

    expect($website->refresh()->uptime_check)->toBeTrue();
});

test('super admin can toggle ssl_check and outbound_check inline from the websites table', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'ssl_check' => true,
        'outbound_check' => true,
    ]);

    // Disable both
    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'ssl_check', $website->getKey(), false)
        ->assertNotified();
    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'outbound_check', $website->getKey(), false)
        ->assertNotified();

    $website->refresh();
    expect($website->ssl_check)->toBeFalse()
        ->and($website->outbound_check)->toBeFalse();

    // Re-enable both
    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'ssl_check', $website->getKey(), true)
        ->assertNotified();
    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'outbound_check', $website->getKey(), true)
        ->assertNotified();

    $website->refresh();
    expect($website->ssl_check)->toBeTrue()
        ->and($website->outbound_check)->toBeTrue();
});

test('user without Update:Website permission cannot toggle website columns inline', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo(['ViewAny:Website', 'View:Website']);
    $this->actingAs($user);

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => true,
        'ssl_check' => true,
        'outbound_check' => true,
    ]);

    // Each toggle column should report itself as disabled for this user, and
    // any attempted Livewire toggle should be a no-op. We deliberately do not
    // call assertForbidden() here: Filament's updateTableColumnState
    // short-circuits to null when the column is disabled, before
    // beforeStateUpdated ever runs, so the Livewire response is 200, not 403.
    // disabled() is the real gate; assertForbidden() would actually fail.
    $page = Livewire::test(ListWebsites::class);

    foreach (['uptime_check', 'ssl_check', 'outbound_check'] as $column) {
        $page->assertTableColumnExists(
            $column,
            fn (\Filament\Tables\Columns\ToggleColumn $col): bool => $col->isDisabled(),
            $website,
        );
    }

    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'uptime_check', $website->getKey(), false);
    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'ssl_check', $website->getKey(), false);
    Livewire::test(ListWebsites::class)
        ->call('updateTableColumnState', 'outbound_check', $website->getKey(), false);

    $website->refresh();
    expect($website->uptime_check)->toBeTrue()
        ->and($website->ssl_check)->toBeTrue()
        ->and($website->outbound_check)->toBeTrue();
});

test('user without Update:Website permission cannot see website bulk actions', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo('ViewAny:Website');
    $this->actingAs($user);

    Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'uptime_check' => true,
    ]);

    Livewire::test(ListWebsites::class)
        ->assertTableBulkActionHidden('enableUptimeCheck')
        ->assertTableBulkActionHidden('disableUptimeCheck');
});

test('soft-deleted websites are not counted in bulk uptime disable notification', function () {
    $user = $this->actingAsSuperAdmin();

    $active = Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'uptime_check' => true,
    ]);

    $trashed = Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => true,
    ]);
    $trashed->delete();

    $selection = $active->toBase()->concat([$trashed]);

    Livewire::test(ListWebsites::class)
        ->callTableBulkAction('disableUptimeCheck', $selection)
        ->assertNotified('2 websites disabled');

    foreach ($active as $website) {
        expect($website->refresh()->uptime_check)->toBeFalse();
    }

    expect(Website::withTrashed()->find($trashed->id)->uptime_check)->toBeTrue();
});

test('soft-deleted websites are not counted in bulk uptime enable notification', function () {
    $user = $this->actingAsSuperAdmin();

    $active = Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'uptime_check' => false,
    ]);

    $trashed = Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => false,
    ]);
    $trashed->delete();

    $selection = $active->toBase()->concat([$trashed]);

    Livewire::test(ListWebsites::class)
        ->callTableBulkAction('enableUptimeCheck', $selection)
        ->assertNotified('2 websites enabled');

    foreach ($active as $website) {
        expect($website->refresh()->uptime_check)->toBeTrue();
    }

    expect(Website::withTrashed()->find($trashed->id)->uptime_check)->toBeFalse();
});

test('super admin can render view page with infolist sections', function () {
    $user = $this->actingAsSuperAdmin();
    $lastHeartbeatAt = \Illuminate\Support\Carbon::parse('2026-04-24 12:00:00');
    $detectedStaleAt = \Illuminate\Support\Carbon::parse('2026-04-24 12:08:00');

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_interval' => '5m',
        'current_status' => 'danger',
        'status_summary' => 'Website returned HTTP 500.',
        'ssl_check' => true,
        'ssl_expiry_date' => now()->addDays(45)->toDateString(),
        'last_heartbeat_at' => $lastHeartbeatAt,
        'stale_at' => $detectedStaleAt,
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Heartbeat & Freshness')
        ->assertSee('Expected Stale Threshold')
        ->assertSee($lastHeartbeatAt->copy()->addMinutes(5)->toDayDateTimeString())
        ->assertSee('Detected Stale At')
        ->assertSee($detectedStaleAt->toDayDateTimeString())
        ->assertSee('Uptime Monitoring')
        ->assertSee('SSL Certificate')
        ->assertSee('Website returned HTTP 500.')
        ->assertSee('Danger');
});

test('view page does not blame package interval when expected stale threshold lacks heartbeat', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_interval' => '5m',
        'last_heartbeat_at' => null,
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Expected Stale Threshold')
        ->assertSee('Never')
        ->assertDontSee('Cannot parse package interval 5m');
});

test('view page keeps expected stale threshold quiet when package interval is blank', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_interval' => null,
        'last_heartbeat_at' => \Illuminate\Support\Carbon::parse('2026-04-24 12:00:00'),
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Expected Stale Threshold')
        ->assertDontSee('Cannot parse package interval');
});

test('view page explains invalid package interval for expected stale threshold', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'source' => 'package',
        'package_interval' => 'xyz',
        'last_heartbeat_at' => \Illuminate\Support\Carbon::parse('2026-04-24 12:00:00'),
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Expected Stale Threshold')
        ->assertSee('Cannot parse package interval xyz');
});

test('view page run now action persists a real heartbeat and surfaces evidence', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://example.com',
        'uptime_check' => true,
        'current_status' => null,
        'status_summary' => null,
        'last_heartbeat_at' => null,
    ]);

    Http::fake([
        'https://example.com' => Http::response('OK', 200),
    ]);

    expect($website->logHistory()->count())->toBe(0);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->callAction('run_now')
        ->assertNotified('On-demand check succeeded');

    $website->refresh();

    expect($website->logHistory()->count())->toBe(1)
        ->and($website->logHistory()->first()->status)->toBe('healthy')
        ->and($website->current_status)->toBeNull()
        ->and($website->last_heartbeat_at)->toBeNull()
        ->and($website->status_summary)->toBeNull();
});

test('view page run now action surfaces failure when target returns server error', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://broken.example',
        'uptime_check' => true,
        'current_status' => 'healthy',
        'status_summary' => 'Heartbeat received successfully.',
    ]);

    Http::fake([
        'https://broken.example' => Http::response('boom', 500),
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->callAction('run_now')
        ->assertNotified('On-demand check failed');

    $website->refresh();

    expect($website->logHistory()->count())->toBe(1)
        ->and($website->logHistory()->first()->http_status_code)->toBe(500)
        ->and($website->logHistory()->first()->status)->toBe('danger')
        ->and($website->current_status)->toBe('healthy')
        ->and($website->status_summary)->toBe('Heartbeat received successfully.');
});

test('view page hides run now action when uptime check is disabled', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => false,
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertActionHidden('run_now');
});

test('view page hides run now action for users without update permission', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');
    $user->givePermissionTo(['ViewAny:Website', 'View:Website']);
    $this->actingAs($user);

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => true,
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertActionHidden('run_now');
});

test('view page run now action does not fire user-facing health alerts and preserves the live status baseline', function () {
    $user = $this->actingAsSuperAdmin();

    $website = Website::factory()->create([
        'created_by' => $user->id,
        'url' => 'https://broken.example',
        'uptime_check' => true,
        'current_status' => 'healthy',
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);

    $heartbeatBefore = $website->last_heartbeat_at;

    Http::fake([
        'https://broken.example' => Http::response('boom', 500),
    ]);

    $notificationService = Mockery::mock(\App\Services\HealthEventNotificationService::class);
    $notificationService->shouldNotReceive('notifyWebsite');
    $this->app->instance(\App\Services\HealthEventNotificationService::class, $notificationService);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->callAction('run_now')
        ->assertNotified('On-demand check failed');

    $website->refresh();

    expect($website->current_status)->toBe('healthy')
        ->and($website->last_heartbeat_at?->equalTo($heartbeatBefore))->toBeTrue()
        ->and($website->logHistory()->latest('id')->first()->status)->toBe('danger');
});

test('view page renders recent failures when non-healthy logs exist', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'status' => 'danger',
        'http_status_code' => 503,
        'speed' => 4200,
        'summary' => 'Origin returned HTTP 503 Service Unavailable.',
        'created_at' => now()->subMinutes(10),
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Recent Failures')
        ->assertSee('Origin returned HTTP 503 Service Unavailable.')
        ->assertSee('503');
});

test('view page surfaces transport error evidence for failed uptime logs', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    WebsiteLogHistory::factory()->transportError('dns')->create([
        'website_id' => $website->id,
        'summary' => 'Website heartbeat failed before an HTTP response: DNS lookup failed.',
        'transport_error_message' => 'cURL error 6: Could not resolve host: missing.example',
        'transport_error_code' => 6,
        'created_at' => now()->subMinutes(10),
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Last Transport Error')
        ->assertSee('DNS failure')
        ->assertSee('code 6')
        ->assertSee('Could not resolve host');
});

test('view page hides recent failures when no failing logs exist', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'status' => 'healthy',
        'http_status_code' => 200,
        'speed' => 180,
        'summary' => 'Heartbeat received successfully.',
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertDontSee('Recent Failures');
});

test('view page reports SSL cert expiring today as expiring, not expired', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'ssl_check' => true,
        'ssl_expiry_date' => now()->toDateString(),
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Expires today')
        ->assertDontSee('Expired ');
});

test('view page reports SSL cert expired yesterday as expired', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create([
        'created_by' => $user->id,
        'ssl_check' => true,
        'ssl_expiry_date' => now()->subDay()->toDateString(),
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertSee('Expired yesterday');
});

test('view page excludes failures older than 7 days from the recent failures panel', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'status' => 'danger',
        'http_status_code' => 500,
        'speed' => 1500,
        'summary' => 'Stale incident from last month.',
        'created_at' => now()->subDays(30),
    ]);

    Livewire::test(ViewWebsite::class, ['record' => $website->id])
        ->assertSuccessful()
        ->assertDontSee('Recent Failures')
        ->assertDontSee('Most recent non-healthy monitor runs from the last 7 days.');
});

test('log history relation manager renders on view page', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    $log = WebsiteLogHistory::factory()->create([
        'website_id' => $website->id,
        'status' => 'warning',
        'http_status_code' => 429,
        'speed' => 920,
        'summary' => 'Origin throttled the request.',
    ]);

    Livewire::test(LogHistoryRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => ViewWebsite::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$log]);
});

test('log history relation manager renders transport error evidence', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    $log = WebsiteLogHistory::factory()->transportError('tls')->create([
        'website_id' => $website->id,
        'transport_error_message' => 'cURL error 60: SSL certificate problem.',
        'transport_error_code' => 60,
    ]);

    Livewire::test(LogHistoryRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => ViewWebsite::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$log])
        ->assertSee('TLS/SSL failure')
        ->assertSee('SSL certificate problem');
});

test('outbound links relation manager is registered on website resource', function () {
    expect(\App\Filament\Resources\WebsiteResource::getRelations())
        ->toContain(OutboundLinksRelationManager::class);
});

test('outbound links relation manager only shows links for the current website', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);
    $otherWebsite = Website::factory()->create(['created_by' => $user->id]);

    $visibleLink = OutboundLink::factory()->create([
        'website_id' => $website->id,
        'outgoing_url' => 'https://partner.example/visible',
        'http_status_code' => 200,
    ]);
    $hiddenLink = OutboundLink::factory()->create([
        'website_id' => $otherWebsite->id,
        'outgoing_url' => 'https://partner.example/hidden',
        'http_status_code' => 200,
    ]);

    Livewire::test(OutboundLinksRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => ViewWebsite::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visibleLink])
        ->assertCanNotSeeTableRecords([$hiddenLink]);
});

test('outbound links relation manager filters broken links by status code range', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    $healthy = OutboundLink::factory()->create([
        'website_id' => $website->id,
        'outgoing_url' => 'https://partner.example/ok',
        'http_status_code' => 200,
    ]);
    $notFound = OutboundLink::factory()->create([
        'website_id' => $website->id,
        'outgoing_url' => 'https://partner.example/missing',
        'http_status_code' => 404,
    ]);
    $serverError = OutboundLink::factory()->create([
        'website_id' => $website->id,
        'outgoing_url' => 'https://partner.example/down',
        'http_status_code' => 503,
    ]);

    Livewire::test(OutboundLinksRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => ViewWebsite::class,
    ])
        ->filterTable('http_status_code', 'broken')
        ->assertCanSeeTableRecords([$notFound, $serverError])
        ->assertCanNotSeeTableRecords([$healthy]);
});

test('outbound links relation manager filters by exact status code', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    $notFound = OutboundLink::factory()->create([
        'website_id' => $website->id,
        'outgoing_url' => 'https://partner.example/missing',
        'http_status_code' => 404,
    ]);
    $serverError = OutboundLink::factory()->create([
        'website_id' => $website->id,
        'outgoing_url' => 'https://partner.example/down',
        'http_status_code' => 500,
    ]);

    Livewire::test(OutboundLinksRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => ViewWebsite::class,
    ])
        ->filterTable('http_status_code', '404')
        ->assertCanSeeTableRecords([$notFound])
        ->assertCanNotSeeTableRecords([$serverError]);
});

test('outbound links relation manager filters to rows with no response recorded', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);

    $missingResponse = OutboundLink::factory()->create([
        'website_id' => $website->id,
        'outgoing_url' => 'https://partner.example/no-response',
        'http_status_code' => null,
    ]);
    $healthy = OutboundLink::factory()->create([
        'website_id' => $website->id,
        'outgoing_url' => 'https://partner.example/ok',
        'http_status_code' => 200,
    ]);

    Livewire::test(OutboundLinksRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => ViewWebsite::class,
    ])
        ->filterTable('http_status_code', 'unknown')
        ->assertCanSeeTableRecords([$missingResponse])
        ->assertCanNotSeeTableRecords([$healthy]);
});

test('notification relation manager renders on view page and is website scoped', function () {
    $user = $this->actingAsSuperAdmin();
    $website = Website::factory()->create(['created_by' => $user->id]);
    $otherWebsite = Website::factory()->create(['created_by' => $user->id]);

    $visibleSetting = NotificationSetting::factory()->websiteScope()->email()->create([
        'user_id' => $user->id,
        'website_id' => $website->id,
    ]);
    $hiddenSetting = NotificationSetting::factory()->websiteScope()->email()->create([
        'user_id' => $user->id,
        'website_id' => $otherWebsite->id,
    ]);

    Livewire::test(NotificationSettingsRelationManager::class, [
        'ownerRecord' => $website,
        'pageClass' => ViewWebsite::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visibleSetting])
        ->assertCanNotSeeTableRecords([$hiddenSetting]);
});

test('website navigation badge shows plain total when everything is healthy', function () {
    $user = $this->actingAsSuperAdmin();
    Website::factory()->count(3)->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);

    expect(\App\Filament\Resources\WebsiteResource::getNavigationBadge())->toBe('3')
        ->and(\App\Filament\Resources\WebsiteResource::getNavigationBadgeColor())->toBeNull();
});

test('website navigation badge highlights unhealthy count in danger color', function () {
    $user = $this->actingAsSuperAdmin();
    Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);
    Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    expect(\App\Filament\Resources\WebsiteResource::getNavigationBadge())->toBe('2/4')
        ->and(\App\Filament\Resources\WebsiteResource::getNavigationBadgeColor())->toBe('danger');
});

test('website navigation badge is scoped to the current user', function () {
    $user = $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();

    Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    Website::factory()->create([
        'created_by' => $otherUser->id,
        'current_status' => 'danger',
    ]);

    expect(\App\Filament\Resources\WebsiteResource::getNavigationBadge())->toBe('1')
        ->and(\App\Filament\Resources\WebsiteResource::getNavigationBadgeColor())->toBeNull();
});

test('website navigation badge caches counts between badge and color lookups', function () {
    $user = $this->actingAsSuperAdmin();
    Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    \App\Filament\Resources\WebsiteResource::flushUnhealthyNavigationBadgeCache();

    $queries = 0;
    \DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'websites')) {
            $queries++;
        }
    });

    \App\Filament\Resources\WebsiteResource::getNavigationBadge();
    \App\Filament\Resources\WebsiteResource::getNavigationBadgeColor();

    // Exactly 2 website queries: count(*) for total, and count(*) with whereIn
    // for unhealthy. The color lookup reuses the cached result.
    expect($queries)->toBe(2);
});

test('website navigation badge excludes soft-deleted records', function () {
    $user = $this->actingAsSuperAdmin();

    Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);

    $trashed = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    $trashed->delete();

    \App\Filament\Resources\WebsiteResource::flushUnhealthyNavigationBadgeCache();

    expect(\App\Filament\Resources\WebsiteResource::getNavigationBadge())->toBe('1')
        ->and(\App\Filament\Resources\WebsiteResource::getNavigationBadgeColor())->toBeNull();
});

test('website list shows all four health status tabs', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(ListWebsites::class)
        ->assertSee('All')
        ->assertSee('Failing')
        ->assertSee('Disabled')
        ->assertSee('Recently Recovered');
});

test('website list failing tab only shows warning and danger websites', function () {
    $user = $this->actingAsSuperAdmin();

    $healthy = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    $warning = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);
    $danger = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    Livewire::test(ListWebsites::class)
        ->set('activeTab', 'failing')
        ->assertCanSeeTableRecords([$warning, $danger])
        ->assertCanNotSeeTableRecords([$healthy]);
});

test('website list disabled tab only shows websites with uptime check off', function () {
    $user = $this->actingAsSuperAdmin();

    $enabled = Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => true,
    ]);
    $disabled = Website::factory()->create([
        'created_by' => $user->id,
        'uptime_check' => false,
    ]);

    Livewire::test(ListWebsites::class)
        ->set('activeTab', 'disabled')
        ->assertCanSeeTableRecords([$disabled])
        ->assertCanNotSeeTableRecords([$enabled]);
});

test('website list recently recovered tab requires healthy status with prior failure in window', function () {
    $user = $this->actingAsSuperAdmin();

    $recovered = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $recovered->id,
        'status' => 'danger',
        'created_at' => now()->subHours(2),
    ]);

    $stillHealthy = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $stillHealthy->id,
        'status' => 'healthy',
        'created_at' => now()->subHours(2),
    ]);

    $stillFailing = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $stillFailing->id,
        'status' => 'danger',
        'created_at' => now()->subMinutes(10),
    ]);

    $oldRecovery = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $oldRecovery->id,
        'status' => 'danger',
        'created_at' => now()->subDays(3),
    ]);

    Livewire::test(ListWebsites::class)
        ->set('activeTab', 'recently_recovered')
        ->assertCanSeeTableRecords([$recovered])
        ->assertCanNotSeeTableRecords([$stillHealthy, $stillFailing, $oldRecovery]);
});

test('website list all tab shows every visible website regardless of health', function () {
    $user = $this->actingAsSuperAdmin();

    $healthy = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'uptime_check' => true,
    ]);
    $warning = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
        'uptime_check' => true,
    ]);
    $disabled = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'uptime_check' => false,
    ]);

    Livewire::test(ListWebsites::class)
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$healthy, $warning, $disabled]);
});

test('website list tab badges report accurate per-tab counts', function () {
    $user = $this->actingAsSuperAdmin();

    Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'warning',
    ]);
    Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'uptime_check' => false,
    ]);

    $recovered = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'healthy',
        'uptime_check' => true,
    ]);
    WebsiteLogHistory::factory()->create([
        'website_id' => $recovered->id,
        'status' => 'danger',
        'created_at' => now()->subHours(2),
    ]);

    $page = Livewire::test(ListWebsites::class)->instance();

    expect(invade($page)->resolveTabCounts())->toMatchArray([
        'failing' => 3,
        'disabled' => 2,
        'recently_recovered' => 1,
    ]);
});

test('website list tab badges scope counts to the current user', function () {
    $user = $this->actingAsSuperAdmin();
    $otherUser = User::factory()->create();

    Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    Website::factory()->count(5)->create([
        'created_by' => $otherUser->id,
        'current_status' => 'danger',
    ]);

    $page = Livewire::test(ListWebsites::class)->instance();

    expect(invade($page)->resolveTabCounts()['failing'])->toBe(2);
});

test('website list tab badges exclude soft-deleted websites', function () {
    $user = $this->actingAsSuperAdmin();

    Website::factory()->count(2)->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    $trashed = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    $trashed->delete();

    $page = Livewire::test(ListWebsites::class)->instance();

    expect(invade($page)->resolveTabCounts()['failing'])->toBe(2);
});

test('website list failing tab excludes soft-deleted websites', function () {
    $user = $this->actingAsSuperAdmin();

    $visible = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);

    $trashed = Website::factory()->create([
        'created_by' => $user->id,
        'current_status' => 'danger',
    ]);
    $trashed->delete();

    Livewire::test(ListWebsites::class)
        ->set('activeTab', 'failing')
        ->assertCanSeeTableRecords([$visible])
        ->assertCanNotSeeTableRecords([$trashed]);
});

test('website list shows empty state with create CTA when no websites exist', function () {
    $this->actingAsSuperAdmin();

    Livewire::test(ListWebsites::class)
        ->assertSee('No websites monitored yet')
        ->assertSee('Add your first website to start tracking uptime, SSL expiry, outbound links, and SEO health.')
        ->assertSee('Add website');
});
