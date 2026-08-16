<?php
/**
 * REST route registrar.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Framework;

use RuntimeException;
use WP_REST_Server;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Collects routes declared with a fluent DSL and flushes them through
 * register_rest_route() on rest_api_init:
 *
 *     $router->prefix('bulk')->withPolicy('AdminPolicy')->group(function ($router) {
 *         $router->post('start', [BulkController::class, 'start']);
 *         $router->get('status', [BulkController::class, 'status']);
 *     });
 *
 * Controllers are resolved from the container, so they declare their own
 * dependencies rather than being handed pre-built instances.
 */
class Router {

    /**
     * The container used to resolve controllers and policies.
     *
     * @var Container
     */
    private $container;

    /**
     * REST namespace, e.g. 'swift-image-optimizer/v1'.
     *
     * @var string
     */
    private $namespace;

    /**
     * Namespace that bare policy names are resolved against.
     *
     * @var string
     */
    private $policyNamespace;

    /**
     * Declared routes.
     *
     * @var Route[]
     */
    private $routes = [];

    /**
     * Stack of active group attributes, innermost last.
     *
     * @var array<int, array{prefix: string, policy: string|null}>
     */
    private $groupStack = [];

    /**
     * Attributes staged by prefix()/withPolicy(), consumed by the next group().
     *
     * @var array{prefix?: string, policy?: string|null}
     */
    private $pending = [];

    /**
     * Bind the router to its container and REST namespaces.
     *
     * @param Container $container       Container for resolving handlers.
     * @param string    $namespace       REST namespace.
     * @param string    $policyNamespace Namespace for bare policy names.
     */
    public function __construct( Container $container, $namespace, $policyNamespace ) {
        $this->container       = $container;
        $this->namespace       = trim($namespace, '/');
        $this->policyNamespace = rtrim($policyNamespace, '\\') . '\\';
    }

    /**
     * Stage a URI prefix for the next group().
     *
     * @param string $prefix URI segment.
     * @return $this
     */
    public function prefix( $prefix ) {
        $this->pending['prefix'] = trim($prefix, '/');

        return $this;
    }

    /**
     * Stage a policy for the next group().
     *
     * @param string $policy Policy class short name or FQCN.
     * @return $this
     */
    public function withPolicy( $policy ) {
        $this->pending['policy'] = $policy;

        return $this;
    }

    /**
     * Run a closure with the staged attributes applied to every route inside.
     *
     * @param callable $callback Receives this router.
     * @return $this
     */
    public function group( callable $callback ) {
        $current = $this->currentAttributes();

        $prefix = isset($this->pending['prefix'])
            ? trim($current['prefix'] . '/' . $this->pending['prefix'], '/')
            : $current['prefix'];

        $policy = array_key_exists('policy', $this->pending)
            ? $this->pending['policy']
            : $current['policy'];

        $this->pending      = [];
        $this->groupStack[] = [ 'prefix' => $prefix, 'policy' => $policy ];

        try {
            $callback($this);
        } finally {
            array_pop($this->groupStack);
        }

        return $this;
    }

    /**
     * Declare a GET route.
     *
     * @param string         $uri    Route URI.
     * @param callable|array $action Handler.
     * @return Route
     */
    public function get( $uri, $action ) {
        return $this->addRoute(WP_REST_Server::READABLE, $uri, $action);
    }

    /**
     * Declare a POST route.
     *
     * @param string         $uri    Route URI.
     * @param callable|array $action Handler.
     * @return Route
     */
    public function post( $uri, $action ) {
        return $this->addRoute(WP_REST_Server::CREATABLE, $uri, $action);
    }

    /**
     * Declare a PUT/PATCH route.
     *
     * @param string         $uri    Route URI.
     * @param callable|array $action Handler.
     * @return Route
     */
    public function put( $uri, $action ) {
        return $this->addRoute(WP_REST_Server::EDITABLE, $uri, $action);
    }

    /**
     * Declare a DELETE route.
     *
     * @param string         $uri    Route URI.
     * @param callable|array $action Handler.
     * @return Route
     */
    public function delete( $uri, $action ) {
        return $this->addRoute(WP_REST_Server::DELETABLE, $uri, $action);
    }

    /**
     * Declare a route answering to several HTTP verbs.
     *
     * @param string[]       $methods HTTP methods.
     * @param string         $uri     Route URI.
     * @param callable|array $action  Handler.
     * @return Route
     */
    public function match( array $methods, $uri, $action ) {
        return $this->addRoute(implode(', ', $methods), $uri, $action);
    }

    /**
     * All declared routes.
     *
     * @return Route[]
     */
    public function getRoutes() {
        return $this->routes;
    }

    /**
     * The REST namespace routes are registered under.
     *
     * @return string
     */
    public function getNamespace() {
        return $this->namespace;
    }

    /**
     * Register every declared route with WordPress.
     *
     * @return void
     */
    public function flush() {
        foreach ($this->routes as $route) {
            $args = [
                'methods'             => $route->getMethod(),
                'callback'            => $this->makeCallback($route),
                'permission_callback' => $this->makePermissionCallback($route),
            ];

            if ($route->getArgs()) {
                $args['args'] = $route->getArgs();
            }

            register_rest_route($this->namespace, '/' . $route->getUri(), $args);
        }
    }

    /**
     * Record a route under the current group attributes.
     *
     * @param string         $method HTTP method.
     * @param string         $uri    Route URI.
     * @param callable|array $action Handler.
     * @return Route
     */
    private function addRoute( $method, $uri, $action ) {
        $attributes = $this->currentAttributes();

        $uri = trim($uri, '/');
        $uri = trim($attributes['prefix'] . '/' . $uri, '/');

        $route = new Route($method, $uri, $action, $attributes['policy']);

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Attributes of the innermost active group.
     *
     * @return array{prefix: string, policy: string|null}
     */
    private function currentAttributes() {
        if ( ! $this->groupStack) {
            return [ 'prefix' => '', 'policy' => null ];
        }

        return $this->groupStack[ count($this->groupStack) - 1 ];
    }

    /**
     * Build the REST callback, resolving the controller lazily so nothing is
     * constructed for routes the current request never reaches.
     *
     * @param Route $route Route being registered.
     * @return callable
     */
    private function makeCallback( Route $route ) {
        $action    = $route->getAction();
        $container = $this->container;

        return static function ( $request ) use ( $action, $container ) {
            if (is_array($action) && is_string($action[0])) {
                $controller = $container->make($action[0]);

                return $controller->{$action[1]}($request);
            }

            return call_user_func($action, $request);
        };
    }

    /**
     * Build the permission callback from the route's policy.
     *
     * A route with no policy is a mistake, not a public endpoint: returning
     * false is the safe reading, and WordPress logs a _doing_it_wrong notice
     * for a missing permission_callback anyway.
     *
     * @param Route $route Route being registered.
     * @return callable
     */
    private function makePermissionCallback( Route $route ) {
        $policy    = $route->getPolicy();
        $namespace = $this->policyNamespace;
        $container = $this->container;

        if ( ! $policy) {
            return static function () {
                return false;
            };
        }

        return static function ( $request ) use ( $policy, $namespace, $container ) {
            $class = false === strpos($policy, '\\') ? $namespace . $policy : $policy;

            if ( ! class_exists($class)) {
                throw new RuntimeException("Policy '{$class}' not found.");
            }

            return $container->make($class)->verifyRequest($request);
        };
    }
}
