<?php

namespace Modules\CupChart\Providers;

use Illuminate\Support\ServiceProvider;
//use Illuminate\Database\Eloquent\Factory;

class CupChartServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->registerFactories();
        $this->loadMigrationsFrom(module_path('CupChart', 'Database/Migrations'));
        $this->cupparisPublish();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
//        $langPath = resource_path('lang/modules/cupsite');
//
//        if (is_dir($langPath)) {
//            $this->loadTranslationsFrom($langPath, 'cupsite');
//        } else {
//            $this->loadTranslationsFrom(module_path('CupSite', 'Resources/lang'), 'cupsite');
//        }
    }


    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            module_path('CupChart', 'config/cupparis-chart.php') => config_path('cupparis-chart.php'),
        ], 'config');
        $this->mergeConfigFrom(
            module_path('CupChart', 'config/cupparis-chart.php'), 'cupparis-chart.php'
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/cup-chart');

        $sourcePath = module_path('CupChart', 'Resources/views');

        $this->publishes([
            $sourcePath => $viewPath
        ],'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path . '/modules/cup-chart';
        }, \Config::get('view.paths')), [$sourcePath]), 'cup-chart');
    }



    /**
     * Register an additional directory of factories.
     *
     * @return void
     */
    public function registerFactories()
    {
        // TODO: in laravel 12, the factories are not used anymore
        // if (! app()->environment('production') && $this->app->runningInConsole()) {
        //     app(Factory::class)->load(module_path('CupChart', 'Database/factories'));
        // }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

    public function cupparisPublish()
    {
        //Publishing configs
//        $this->publishes([
//            __DIR__ . '/../Config/foorms' => config_path('foorms'),
//        ], 'config');

        //Publishing and overwriting app folders
        $this->publishes([
            __DIR__ . '/../app/Console/Commands' => app_path('Console/Commands'),

        ], 'commands');


        //Publishing and overwriting app folders
        $this->publishes([
            __DIR__ . '/../app/Models' => app_path('Models'),
            __DIR__ . '/../app/Models/Relations' => app_path('Models/Relations'),
            __DIR__ . '/../app/Policies' => app_path('Policies'),
            __DIR__ . '/../app/Foorm' => app_path('Foorm'),
            __DIR__ . '/../app/Services' => app_path('Services'),
            __DIR__ . '/../app/Http/Controllers' => app_path('Http/Controllers'),
        ], 'models');

        $this->publishes([
            __DIR__ . '/../public/cup-chart-module' => public_path('cup-chart-module'),
//            __DIR__ . '/../public/cup_site' => public_path('cup_site'),
//            __DIR__ . '/../public/admin/pages' => public_path('admin/pages'),
        ], 'public');

    }
}
