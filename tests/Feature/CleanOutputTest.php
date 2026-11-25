<?php

use Statamic\Facades\Entry;

const CLEAN_OUTPUT_ODESLI_APPLE = 'https://geo.music.apple.com/de/album/_/1491803543?i=1491803545&mt=1&app=itunes&ls=1&at=1000lHKX&ct=api_http&itscg=30200&itsct=odsl_m';

it('prints a line per changed song and a count of checked and changed entries', function () {
    config(['smartlinks.cleanup.on_save' => false]);
    $this->makeSong('Alt', [CLEAN_OUTPUT_ODESLI_APPLE]);
    $this->makeSong('Sauber', ['https://www.deezer.com/track/1']);
    config(['smartlinks.cleanup.on_save' => true]);

    $this->artisan('smartlinks:clean')
        ->expectsOutputToContain('Alt: 1 URL(s)')
        ->expectsOutputToContain('Checked 2 entries, 1 changed.')
        ->assertSuccessful();
});

it('counts a song whose saveQuietly() returned false as failed, and exits with 1', function () {
    // saveQuietly() turns off the events that cancel a save, so a listener cannot make
    // it return false: the entry is faked to return false, as a refused save does.
    $entry = Mockery::mock(Statamic\Entries\Entry::class);
    $entry->shouldReceive('has')->with('streaming_links')->andReturn(true);
    $entry->shouldReceive('get')->with('streaming_links')->andReturn([['url' => CLEAN_OUTPUT_ODESLI_APPLE]]);
    $entry->shouldReceive('set')->andReturnSelf();
    $entry->shouldReceive('saveQuietly')->once()->andReturn(false);
    $entry->shouldReceive('value')->with('title')->andReturn('Alt');

    $query = Mockery::mock();
    $query->shouldReceive('whereIn')->andReturnSelf();
    $query->shouldReceive('get')->andReturn(collect([$entry]));
    Entry::shouldReceive('query')->andReturn($query);

    $this->artisan('smartlinks:clean')
        ->expectsOutputToContain('Alt: not saved')
        ->expectsOutputToContain('1 song(s) could not be saved.')
        ->assertExitCode(1);
});
