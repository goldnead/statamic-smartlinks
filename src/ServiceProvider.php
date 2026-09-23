<?php

namespace Goldnead\Smartlinks;

use Goldnead\Smartlinks\Resolvers\ResolverChain;
use Goldnead\Smartlinks\Resolvers\SpotifyClient;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Control Panel bundle. The three values must byte-match `laravel()`
     * in vite.config.js.
     *
     * Untyped on purpose: the parent declares it without a type.
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js'],
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/smartlinks.php', 'smartlinks');

        // By class name, never under a short slug.
        $this->app->singleton(Platforms::class, fn () => new Platforms((array) config('smartlinks.platforms', [])));
        $this->app->singleton(Smartlinks::class);
        $this->app->singleton(SpotifyClient::class);
        $this->app->singleton(Resolvers\DeezerClient::class);
        $this->app->singleton(Resolvers\TidalClient::class);
        $this->app->singleton(Resolvers\ItunesClient::class);
        $this->app->singleton(Resolvers\Identifier::class);
        $this->app->singleton(LinkCleaner::class);
        $this->app->singleton(Suggestions::class);
        $this->app->singleton(LinkStatus::class);
        $this->app->singleton(LinkChecker::class);
        $this->app->singleton(ResolverChain::class);

        // On the resolving translator rather than in boot: nav and permission
        // labels are built before bootAddon() runs.
        $langPath = __DIR__.'/../resources/lang';
        $this->app->resolving('translator', fn ($translator) => $translator->addNamespace('smartlinks', $langPath));

        if ($this->app->resolved('translator')) {
            $this->app['translator']->addNamespace('smartlinks', $langPath);
        }
    }

    public function bootAddon(): void
    {
        $this->bootMigrations()
            ->bootViews()
            ->bootCommands()
            ->bootPermissions()
            ->bootNavigation()
            ->bootPublishables();
    }

    protected function bootMigrations(): self
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        return $this;
    }

    protected function bootViews(): self
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'smartlinks');

        return $this;
    }

    /**
     * Registered by hand: core's command discovery runs after Statamic's boot
     * sequence, which a plain console context never reaches.
     */
    protected function bootCommands(): self
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\Commands\Resolve::class,
                Console\Commands\Prune::class,
                Console\Commands\Clean::class,
                Console\Commands\Check::class,
            ]);
        }

        return $this;
    }

    protected function bootPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('smartlinks', __('smartlinks::cp.nav'), function (): void {
                Permission::register('view smartlinks', function ($permission): void {
                    $permission->children([
                        Permission::make('manage smartlinks')->label(__('smartlinks::cp.permission_manage')),
                    ]);
                })->label(__('smartlinks::cp.permission_view'));
            });
        });

        return $this;
    }

    protected function bootNavigation(): self
    {
        if (! config('smartlinks.cp.enabled', true)) {
            return $this;
        }

        Nav::extend(function ($nav): void {
            $nav->create(__('smartlinks::cp.nav'))
                ->section('Content')
                ->icon('link')
                ->route('smartlinks.index')
                ->can('view smartlinks');
        });

        return $this;
    }

    protected function bootPublishables(): self
    {
        $this->publishes([
            __DIR__.'/../config/smartlinks.php' => config_path('smartlinks.php'),
        ], 'smartlinks-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'smartlinks-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/smartlinks'),
        ], 'smartlinks-views');

        $this->publishes([
            __DIR__.'/../resources/lang' => lang_path('vendor/smartlinks'),
        ], 'smartlinks-translations');

        return $this;
    }
}
