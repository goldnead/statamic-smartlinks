<?php

use Goldnead\Smartlinks\LinkStatus;
use Goldnead\Smartlinks\Suggestions;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Entry;
use Statamic\Facades\Role;
use Statamic\Facades\User;

function stateUser(array $permissions): Statamic\Contracts\Auth\User
{
    $role = Role::make('smartlinks-state-'.md5(implode(',', $permissions)))->permissions(['access cp', ...$permissions]);
    $role->save();

    $user = User::make()->email(uniqid().'@example.com')->assignRole($role);
    $user->save();

    return $user;
}

function filterParam(string $state): string
{
    return base64_encode(json_encode(['link_state' => ['state' => $state]]));
}

beforeEach(function () {
    $this->dead = $this->makeSong('Tot', ['https://play.napster.com/track/tra.1', 'https://www.deezer.com/track/1']);
    $this->suggested = $this->makeSong('Vorschlag', ['https://www.deezer.com/track/2']);
    $this->fine = $this->makeSong('Gut', ['https://www.deezer.com/track/3']);

    app(LinkStatus::class)->record((string) $this->dead->id(), 'https://play.napster.com/track/tra.1', LinkStatus::DEAD, null);
    app(Suggestions::class)->add((string) $this->suggested->id(), 'youtube', 'https://www.youtube.com/watch?v=yG4VfxlXbIc');
});

it('shows per song how many links are dead and how many suggestions wait, with the filter on the page', function () {
    $user = stateUser(['view smartlinks', 'manage smartlinks']);

    $this->actingAs($user)->get('/cp/smartlinks')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filters.0.handle', 'link_state')
        ->where('filters.0.title', 'Link state'));

    $rows = collect($this->actingAs($user)->getJson('/cp/smartlinks/listing?sort=title&order=asc')->json('data'))->keyBy('title');

    expect($rows['Tot']['dead'])->toBe(1)
        ->and($rows['Tot']['links'])->toBe(1)
        ->and($rows['Vorschlag']['suggestions'])->toBe(1)
        ->and($rows['Vorschlag']['suggestion_items'][0]['label'])->toBe('YouTube')
        ->and($rows['Vorschlag']['suggestion_items'][0]['accept_url'])->toEndWith('/accept')
        ->and($rows['Gut']['dead'])->toBe(0);
});

it('filters to songs with dead links or open suggestions, with core\'s badge', function () {
    $user = stateUser(['view smartlinks']);

    $this->actingAs($user)->getJson('/cp/smartlinks/listing?filters='.filterParam('dead'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Tot')
        ->assertJsonPath('meta.activeFilterBadges.link_state', 'Dead links');

    $this->actingAs($user)->getJson('/cp/smartlinks/listing?filters='.filterParam('suggested'))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.title', 'Vorschlag');
});

it('accepts a suggestion into the links, and rejects one for good', function () {
    $user = stateUser(['view smartlinks', 'manage smartlinks']);
    $id = app(Suggestions::class)->pending((string) $this->suggested->id())[0]->id;

    $this->actingAs($user)->post("/cp/smartlinks/suggestions/{$id}/accept")
        ->assertRedirect('/cp/smartlinks')
        ->assertSessionHas('success', 'YouTube link added.');

    expect(Entry::find($this->suggested->id())->get('streaming_links')[1])
        ->toBe(['url' => 'https://www.youtube.com/watch?v=yG4VfxlXbIc', 'platform' => 'youtube'])
        ->and(app(Suggestions::class)->find($id)->status)->toBe(Suggestions::ACCEPTED);

    // Accepting twice does nothing.
    $this->actingAs($user)->post("/cp/smartlinks/suggestions/{$id}/accept")->assertNotFound();

    app(Suggestions::class)->add((string) $this->fine->id(), 'youtube', 'https://www.youtube.com/watch?v=AAAAAAAAAAA');
    $other = app(Suggestions::class)->pending((string) $this->fine->id())[0]->id;
    $this->actingAs($user)->post("/cp/smartlinks/suggestions/{$other}/reject")->assertRedirect('/cp/smartlinks');

    expect(app(Suggestions::class)->find($other)->status)->toBe(Suggestions::REJECTED)
        ->and(Entry::find($this->fine->id())->get('streaming_links'))->toHaveCount(1);
});

it('needs manage smartlinks to act on suggestions, and hides the actions without it', function () {
    $viewer = stateUser(['view smartlinks']);
    $id = app(Suggestions::class)->pending((string) $this->suggested->id())[0]->id;

    $response = $this->actingAs($viewer)->post("/cp/smartlinks/suggestions/{$id}/accept");

    expect($response->status())->toBeIn([302, 403])
        ->and(app(Suggestions::class)->find($id)->status)->toBe(Suggestions::PENDING)
        ->and(Entry::find($this->suggested->id())->get('streaming_links'))->toHaveCount(1);

    $row = collect($this->actingAs($viewer)->getJson('/cp/smartlinks/listing')->json('data'))->firstWhere('title', 'Vorschlag');
    expect($row['suggestion_items'][0]['accept_url'])->toBeNull();
});
