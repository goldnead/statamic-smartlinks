<?php

use Illuminate\Support\Facades\Log;
use Statamic\Facades\Role;
use Statamic\Facades\User;

function limitViewer(): Statamic\Contracts\Auth\User
{
    $role = Role::make('smartlinks-limit-viewer')->permissions(['access cp', 'view smartlinks']);
    $role->save();

    $user = User::make()->email(uniqid().'@example.com')->assignRole($role);
    $user->save();

    return $user;
}

it('does not mark the listing truncated while every song fits', function () {
    $this->makeSong('Eins', ['https://www.deezer.com/track/1']);
    $this->makeSong('Zwei', ['https://www.deezer.com/track/2']);
    Log::spy();

    $this->actingAs(limitViewer())->getJson('/cp/smartlinks/listing')
        ->assertOk()
        ->assertJsonPath('meta.truncated', false)
        ->assertJsonPath('meta.total', 2);

    Log::shouldNotHaveReceived('warning');
});

it('does not mark the listing truncated when exactly the limit exists', function () {
    config(['smartlinks.cp.limit' => 2]);
    $this->makeSong('Eins', ['https://www.deezer.com/track/1']);
    $this->makeSong('Zwei', ['https://www.deezer.com/track/2']);

    $this->actingAs(limitViewer())->getJson('/cp/smartlinks/listing')
        ->assertOk()
        ->assertJsonPath('meta.truncated', false)
        ->assertJsonPath('meta.total', 2);
});

it('marks the listing truncated and logs once when more songs exist than the limit', function () {
    config(['smartlinks.cp.limit' => 2]);
    $this->makeSong('Eins', ['https://www.deezer.com/track/1']);
    $this->makeSong('Zwei', ['https://www.deezer.com/track/2']);
    $this->makeSong('Drei', ['https://www.deezer.com/track/3']);
    Log::spy();

    $this->actingAs(limitViewer())->getJson('/cp/smartlinks/listing')
        ->assertOk()
        ->assertJsonPath('meta.truncated', true)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonCount(2, 'data');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'cut at 2'))->once();
});
