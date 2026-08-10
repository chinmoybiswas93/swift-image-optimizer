<?php
/**
 * Static facade over the running Application.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App;

use RuntimeException;
use SwiftImageOptimizer\App\Foundation\Application;
use SwiftImageOptimizer\App\Foundation\Config;
use SwiftImageOptimizer\App\Foundation\Router;
use SwiftImageOptimizer\App\Foundation\View;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Entry point for resolving plugin services:
 *
 *     App::make('optimizer');
 *     App::view()->render('admin.admin_app', $data);
 *     App::config()->get('app.slug');
 */
class App
{
    /**
     * The booted application.
     *
     * @var Application|null
     */
    private static $app;

    /**
     * Store the booted application. Called once, from boot/app.php.
     *
     * @param Application $app The application.
     * @return void
     */
    public static function setInstance(Application $app)
    {
        self::$app = $app;
    }

    /**
     * The booted application.
     *
     * @return Application
     *
     * @throws RuntimeException When called before the plugin has booted.
     */
    public static function getInstance()
    {
        if (null === self::$app) {
            throw new RuntimeException('Swift Image Optimizer has not booted yet.');
        }

        return self::$app;
    }

    /**
     * Resolve a binding.
     *
     * @param string $abstract Binding key or class name.
     * @return mixed
     */
    public static function make($abstract)
    {
        return self::getInstance()->make($abstract);
    }

    /**
     * Register a shared (singleton) binding.
     *
     * @param string   $abstract Binding key.
     * @param callable $concrete Factory returning the concrete instance.
     * @return void
     */
    public static function singleton($abstract, callable $concrete)
    {
        self::getInstance()->singleton($abstract, $concrete);
    }

    /**
     * Register a non-shared binding.
     *
     * @param string   $abstract Binding key.
     * @param callable $concrete Factory returning the concrete instance.
     * @return void
     */
    public static function bind($abstract, callable $concrete)
    {
        self::getInstance()->bind($abstract, $concrete, false);
    }

    /**
     * Register an already-built instance.
     *
     * @param string $abstract Binding key.
     * @param mixed  $instance Instance to store.
     * @return void
     */
    public static function instance($abstract, $instance)
    {
        self::getInstance()->instance($abstract, $instance);
    }

    /**
     * Point a short name at an existing binding.
     *
     * @param string $abstract Binding key.
     * @param string $alias    Short name.
     * @return void
     */
    public static function alias($abstract, $alias)
    {
        self::getInstance()->alias($abstract, $alias);
    }

    /**
     * Whether a binding exists.
     *
     * @param string $abstract Binding key.
     * @return bool
     */
    public static function bound($abstract)
    {
        return self::getInstance()->bound($abstract);
    }

    /**
     * The config repository.
     *
     * @return Config
     */
    public static function config()
    {
        return self::getInstance()->config();
    }

    /**
     * The view renderer.
     *
     * @return View
     */
    public static function view()
    {
        return self::getInstance()->view();
    }

    /**
     * The router.
     *
     * @return Router
     */
    public static function router()
    {
        return self::getInstance()->router();
    }

    /**
     * Absolute path inside the plugin directory.
     *
     * @param string $path Path relative to the plugin root.
     * @return string
     */
    public static function path($path = '')
    {
        return self::getInstance()->path($path);
    }

    /**
     * URL inside the plugin directory.
     *
     * @param string $path Path relative to the plugin root.
     * @return string
     */
    public static function url($path = '')
    {
        return self::getInstance()->url($path);
    }
}
