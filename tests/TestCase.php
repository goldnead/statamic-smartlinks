<?php

namespace Goldnead\Smartlinks\Tests;

use Goldnead\Smartlinks\Fieldtypes\SmartlinkUrl;
use Goldnead\Smartlinks\Resolvers\ItunesClient;
use Goldnead\Smartlinks\ServiceProvider;
use Goldnead\Smartlinks\Tags\Smartlinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Entry;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    /** @var list<callable> Nav::extend() callbacks bootAddon() registered. */
    protected array $navCallbacks = [];

    protected function setUp(): void
    {
        parent::setUp();

        // AddonTestCase swaps Nav for a strict mock; keep the callback.
        Nav::shouldReceive('extend')->andReturnUsing(function ($callback) {
            $this->navCallbacks[] = $callback;
        });

        $provider = $this->app->getProvider(ServiceProvider::class);
        $provider?->bootAddon();

        // Core registers tags, fieldtypes and listeners (src/Listeners) from
        // its booted callback, which Testbench never fires; do what that
        // discovery would.
        $provider?->bootEvents();
        Smartlinks::register();
        ItunesClient::resetPacing();
        SmartlinkUrl::register();

        if (! Collection::find('songs')) {
            Collection::make('songs')->title('Songs')->save();
        }
    }

    /**
     * Registered here, not by a `migrate` call in setUp(): DDL inside
     * RefreshDatabase's transaction commits it implicitly under MySQL.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * The routes as a real site mounts them: web routes inside `web`, CP
     * routes inside core's authenticated CP group.
     */
    protected function defineRoutes($router): void
    {
        $router->middleware('web')->group(__DIR__.'/../routes/web.php');

        $router->middleware(['statamic.cp', 'statamic.cp.authenticated'])
            ->prefix('cp')
            ->name('statamic.cp.')
            ->group(__DIR__.'/../routes/cp.php');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('smartlinks.services.spotify.client_id', null);
        $app['config']->set('smartlinks.services.spotify.client_secret', null);
        $app['config']->set('smartlinks.services.youtube.key', null);
        $app['config']->set('smartlinks.services.tidal.client_id', null);
        $app['config']->set('smartlinks.services.tidal.client_secret', null);
        $app['config']->set('smartlinks.services.itunes.interval_ms', 0);
        // No test may reach a real service by accident.
        Http::preventStrayRequests();
    }

    /**
     * In-memory SQLite by default; DB_DRIVER=mysql runs the identical suite
     * against a real server, the only place the upsert's unique index is
     * really tested.
     *
     * @return array<string, mixed>
     */
    protected function testingConnection(): array
    {
        if (env('DB_DRIVER', 'sqlite') !== 'mysql') {
            return [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'smartlinks_test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
    }

    /**
     * Blueprints are files, not Stache items: PreventsSavingStacheItemsToDisk
     * does not stop them. A test that saves one would leak it into every
     * later test (and into vendor/orchestra), so they go after each test.
     */
    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory(resource_path('blueprints'));

        parent::tearDown();
    }

    /**
     * @param  list<string|array<string, mixed>>  $links
     * @param  array<string, mixed>  $data
     */
    protected function makeSong(string $title, array $links = [], array $data = [], bool $published = true): EntryContract
    {
        $entry = Entry::make()
            ->collection('songs')
            ->slug(str($title)->slug()->toString())
            ->published($published)
            ->data([
                'title' => $title,
                'streaming_links' => array_map(fn ($link) => is_string($link) ? ['url' => $link] : $link, $links),
                ...$data,
            ]);
        $entry->save();

        return $entry;
    }
}
