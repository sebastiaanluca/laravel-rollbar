<?php namespace Jenssegers\Rollbar;

use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Rollbar;
use RollbarNotifier;

class RollbarServiceProvider extends ServiceProvider
{
    
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;
    
    
    
    /**
     * Is this a Laravel or a Lumen application?
     *
     * @return bool
     */
    protected function isLaravel()
    {
        return is_a($this->app, '\Illuminate\Foundation\Application', true);
    }
    
    
    
    /**
     * Register the Rollbar notifier.
     */
    protected function registerNotifier()
    {
        $this->app['RollbarNotifier'] = $this->app->share(function ($app) {
            // Default configuration.
            $defaults = [
                'environment' => $app->environment(),
                'root'        => base_path(),
            ];
            
            $config = array_merge($defaults, $app['config']->get('services.rollbar', []));
            
            $config['access_token'] = getenv('ROLLBAR_TOKEN') ?: $app['config']->get('services.rollbar.access_token');
            
            if (empty($config['access_token'])) {
                throw new InvalidArgumentException('Rollbar access token not configured');
            }
            
            Rollbar::$instance = $rollbar = new RollbarNotifier($config);
            
            return $rollbar;
        });
    }
    
    /**
     * Register the Rollbar handler.
     */
    protected function registerHandler()
    {
        if ($this->isLaravel()) {
            $this->app['Jenssegers\Rollbar\RollbarLogHandler'] = $this->app->share(function ($app) {
                $level = getenv('ROLLBAR_LEVEL') ?: $app['config']->get('services.rollbar.level', 'debug');
                
                return new RollbarLogHandler($app['RollbarNotifier'], $app, $level);
            });
        } else {
            $this->app['jenssegers.rollbar.handler'] = app('Monolog\Handler\RollbarHandler', [$this->app['RollbarNotifier']]);
        }
    }
    
    /**
     * Handle shutdown of the application.
     */
    protected function handleShutdown()
    {
        $app = $this->app;
        
        // Register the fatal error handler.
        register_shutdown_function(function () use ($app) {
            if (isset($app['RollbarNotifier'])) {
                $app->make('RollbarNotifier');
                Rollbar::report_fatal_error();
            }
        });
        
        // If the Rollbar client was resolved, then there is a possibility that there
        // are unsent error messages in the internal queue, so let's flush them.
        register_shutdown_function(function () use ($app) {
            if (isset($app['RollbarNotifier'])) {
                $app['RollbarNotifier']->flush();
            }
        });
    }
    
    
    
    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        if ($this->isLaravel()) {
            $app = $this->app;
            
            // Listen to log messages.
            $this->app['log']->listen(function ($level, $message, $context) use ($app) {
                $app['Jenssegers\Rollbar\RollbarLogHandler']->log($level, $message, $context);
            });
        } else {
            $this->app['log']->pushHandler($this->app['jenssegers.rollbar.handler']);
        }
    }
    
    /**
     * Register the service provider.
     */
    public function register()
    {
        // Don't register rollbar if it is not configured.
        if (! getenv('ROLLBAR_TOKEN') and ! $this->app['config']->get('services.rollbar')) {
            return;
        }
        
        $this->registerNotifier();
        $this->registerHandler();
        $this->handleShutdown();
    }
    
}
