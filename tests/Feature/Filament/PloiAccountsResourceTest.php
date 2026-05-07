<?php

use App\Filament\Resources\PloiAccountsResource\Pages\ListPloiAccounts;
use App\Models\PloiAccounts;
use App\Models\User;
use App\Policies\PloiAccountsPolicy;
use Livewire\Livewire;

test('ploi account list only shows accounts created by the current user', function () {
    $this->createResourcePermissions('PloiAccounts');

    $user = $this->actingAsAdmin();
    $user->givePermissionTo(['ViewAny:PloiAccounts', 'View:PloiAccounts']);

    $ownAccount = PloiAccounts::factory()->create([
        'created_by' => $user->id,
        'label' => 'Production Ploi',
    ]);
    $otherAccount = PloiAccounts::factory()->create([
        'label' => 'Other Team Ploi',
    ]);

    Livewire::test(ListPloiAccounts::class)
        ->assertCanSeeTableRecords([$ownAccount])
        ->assertCanNotSeeTableRecords([$otherAccount])
        ->assertSee('Production Ploi')
        ->assertDontSee('Other Team Ploi');
});

test('ploi account list uses duplicate label space for verification context', function () {
    $this->createResourcePermissions('PloiAccounts');

    $user = $this->actingAsAdmin();
    $user->givePermissionTo(['ViewAny:PloiAccounts', 'View:PloiAccounts']);

    $account = PloiAccounts::factory()->unverified()->create([
        'created_by' => $user->id,
        'label' => 'Production Ploi',
        'error_message' => 'Invalid API token.',
    ]);

    Livewire::test(ListPloiAccounts::class)
        ->assertCanSeeTableRecords([$account])
        ->assertTableColumnExists('label')
        ->assertTableColumnExists('error_message')
        ->assertSee('Production Ploi')
        ->assertSee('Invalid API token.');
});

test('direct ploi account view route cannot open another users account', function () {
    $this->createResourcePermissions('PloiAccounts');

    $user = $this->actingAsAdmin();
    $user->givePermissionTo(['ViewAny:PloiAccounts', 'View:PloiAccounts']);
    $otherAccount = PloiAccounts::factory()->create();

    $response = $this->get(route('filament.admin.resources.ploi-accounts.view', [
        'record' => $otherAccount,
    ]));

    expect($response->status())->toBeIn([403, 404]);
});

test('ploi account policy denies another users account even with account permissions', function () {
    $this->createResourcePermissions('PloiAccounts');

    $user = User::factory()->create();
    $user->givePermissionTo([
        'View:PloiAccounts',
        'Update:PloiAccounts',
        'Delete:PloiAccounts',
        'Restore:PloiAccounts',
        'ForceDelete:PloiAccounts',
        'Replicate:PloiAccounts',
    ]);

    $ownAccount = PloiAccounts::factory()->create([
        'created_by' => $user->id,
    ]);
    $otherAccount = PloiAccounts::factory()->create();
    $policy = new PloiAccountsPolicy;

    expect($policy->view($user, $ownAccount))->toBeTrue()
        ->and($policy->update($user, $ownAccount))->toBeTrue()
        ->and($policy->delete($user, $ownAccount))->toBeTrue()
        ->and($policy->restore($user, $ownAccount))->toBeTrue()
        ->and($policy->forceDelete($user, $ownAccount))->toBeTrue()
        ->and($policy->replicate($user, $ownAccount))->toBeTrue()
        ->and($policy->view($user, $otherAccount))->toBeFalse()
        ->and($policy->update($user, $otherAccount))->toBeFalse()
        ->and($policy->delete($user, $otherAccount))->toBeFalse()
        ->and($policy->restore($user, $otherAccount))->toBeFalse()
        ->and($policy->forceDelete($user, $otherAccount))->toBeFalse()
        ->and($policy->replicate($user, $otherAccount))->toBeFalse();
});
