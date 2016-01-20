<?php

namespace Lehter\Monitor;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Raven_ErrorHandler;

class LehterMonitorServiceProvider extends ServiceProvider
{
    protected $defer = false;

    protected $listenerRegistered = false;

    public function boot()
    {
        if ( ! $this->listenerRegistered)
        {
            $this->registerListener();
        }
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $app = $this->app;

        $this->app['raven.client'] = $this->app->share(function ($app)
        {
            $config = $app['config']->get('services.lehtermonitor', []);

            $dsn = $app['config']->get('services.lehtermonitor.dsn');

            if ( ! $dsn)
            {
                throw new InvalidArgumentException('Lehter DSN not configured');
            }

            // Use async by default.
            if (empty($config['curl_method']))
            {
                $config['curl_method'] = 'async';
            }

            return new LehterClient($dsn, array_except($config, ['dsn']));
        });

        //dd($this->app['raven.client']);

        $this->app['raven.handler'] = $this->app->share(function ($app)
        {
            $level = $app['config']->get('services.lehtermonitor.level', 'debug');

            return new LehterLogHandler($app['raven.client'], $app, $level);
        });

        if (isset($this->app['log']))
        {
            $this->registerListener();
        }

        // Register the fatal error handler.
        register_shutdown_function(function () use ($app)
        {
            if (isset($app['raven.client']))
            {
                (new Raven_ErrorHandler($app['raven.client']))->registerShutdownFunction();
            }
        });
    }

    protected function registerListener()
    {
        $app = $this->app;

        $this->app['log']->listen(function ($level, $message, $context) use ($app)
        {
            $app['raven.handler']->log($level, $message, $context);
        });

        $this->listenerRegistered = true;
    }

}
