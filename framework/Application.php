<?php
/**
 * Plugin application kernel.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Framework;

use SwiftImageOptimizer\App\App;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Boots the plugin: resolves paths, loads config/, binds the core components,
 * requires the hook manifests, and arranges for routes to register on
 * rest_api_init.
 *
 * Replaces the previous Plugin + PluginBootstrapper + ServiceProvider trio.
 * Where that design put registration inside eight provider classes, this puts
 * it in one readable manifest (app/Hooks/actions.php) and one bindings file
 * (boot/bindings.php).
 */
class Application extends Container {

    /**
     * Root namespace of the app/ PSR-4 tree. Bare policy and controller names
     * declared in route files are resolved against it.
     */
    const APP_NAMESPACE = 'SwiftImageOptimizer\\App';

    /**
     * Absolute path to the plugin directory, with trailing slash.
     *
     * @var string
     */
    private $basePath;

    /**
     * Plugin directory URL, with trailing slash.
     *
     * @var string
     */
    private $baseUrl;

    /**
     * Absolute path to the main plugin file.
     *
     * @var string
     */
    private $pluginFile;

    /**
     * Anchor the application to the main plugin file.
     *
     * @param string $file Absolute path to the main plugin file.
     */
    public function __construct( $file ) {
        $this->pluginFile = $file;
        $this->basePath   = plugin_dir_path($file);
        $this->baseUrl    = plugin_dir_url($file);

        $this->instance(self::class, $this);
        $this->alias(self::class, 'app');

        $this->loadConfig();
        $this->bindCoreComponents();

        // Order matters below: the facade has to point at this instance before
        // bindings.php runs, and the service bindings have to exist before the
        // hook manifests resolve anything out of the container.
        App::setInstance($this);

        $this->loadBindings();
        $this->requireCommonFiles();
        $this->addRestApiInitAction();
    }

    /**
     * Absolute path inside the plugin directory.
     *
     * @param string $path Path relative to the plugin root.
     * @return string
     */
    public function path( $path = '' ) {
        return $this->basePath . ltrim($path, '/');
    }

    /**
     * URL inside the plugin directory.
     *
     * @param string $path Path relative to the plugin root.
     * @return string
     */
    public function url( $path = '' ) {
        return $this->baseUrl . ltrim($path, '/');
    }

    /**
     * Absolute path to the main plugin file.
     *
     * @return string
     */
    public function pluginFile() {
        return $this->pluginFile;
    }

    /**
     * The config repository.
     *
     * @return Config
     */
    public function config() {
        return $this->make('config');
    }

    /**
     * The view renderer.
     *
     * @return View
     */
    public function view() {
        return $this->make('view');
    }

    /**
     * The router.
     *
     * @return Router
     */
    public function router() {
        return $this->make('router');
    }

    /**
     * Load every config/*.php file into the container.
     *
     * @return void
     */
    private function loadConfig() {
        $this->instance('config', Config::fromDirectory($this->path('config')));
    }

    /**
     * Bind the components the rest of the plugin resolves by name.
     *
     * @return void
     */
    private function bindCoreComponents() {
        $this->singleton('view', function () {
            return new View($this->path('app/Views'));
        });

        $this->singleton('router', function () {
            $config = $this->config();

            $namespace = $config->get('app.rest_namespace') . '/' . $config->get('app.rest_version');

            return new Router($this, $namespace, self::APP_NAMESPACE . '\\Http\\Policies');
        });
    }

    /**
     * Register the shared service bindings from boot/bindings.php.
     *
     * @return void
     */
    private function loadBindings() {
        $path = $this->path('boot/bindings.php');

        if (is_readable($path)) {
            require_once $path;
        }
    }

    /**
     * Require the hook manifests. These are the plugin's registration surface:
     * every add_action/add_filter that isn't owned by a single feature class
     * lives in one of them.
     *
     * @return void
     */
    private function requireCommonFiles() {
        foreach ([ 'actions', 'filters' ] as $file) {
            $path = $this->path('app/Hooks/' . $file . '.php');

            if (is_readable($path)) {
                $app = $this; // Made available to the required file.

                require_once $path;
            }
        }
    }

    /**
     * Register REST routes when WordPress builds the REST server.
     *
     * @return void
     */
    private function addRestApiInitAction() {
        add_action('rest_api_init', function () {
            $routes = $this->path('app/Http/Routes/routes.php');

            if ( ! is_readable($routes)) {
                return;
            }

            $router = $this->router();

            require $routes;

            $router->flush();
        });
    }
}
