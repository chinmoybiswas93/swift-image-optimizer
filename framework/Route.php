<?php
/**
 * A single declared route.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Foundation;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Value object produced by the Router's verb methods. The fluent setters exist
 * so a route can be refined after declaration:
 *
 *     $router->post('/', [Controller::class, 'store'])
 *            ->args(StoreRequest::args())
 *            ->withPolicy('AdminPolicy');
 */
class Route
{
    /**
     * HTTP method(s), as a WP_REST_Server constant or comma-separated list.
     *
     * @var string
     */
    private $method;

    /**
     * Route URI relative to the REST namespace, without a leading slash.
     *
     * @var string
     */
    private $uri;

    /**
     * [ControllerClass, 'method'] pair, or any callable.
     *
     * @var callable|array
     */
    private $action;

    /**
     * Policy class short name, resolved against the policy namespace.
     *
     * @var string|null
     */
    private $policy;

    /**
     * WordPress REST args schema.
     *
     * @var array<string, mixed>
     */
    private $args = [];

    /**
     * @param string         $method HTTP method.
     * @param string         $uri    Route URI.
     * @param callable|array $action Handler.
     * @param string|null    $policy Policy short name.
     */
    public function __construct($method, $uri, $action, $policy = null)
    {
        $this->method = $method;
        $this->uri    = $uri;
        $this->action = $action;
        $this->policy = $policy;
    }

    /**
     * Attach a WordPress REST args schema.
     *
     * @param array<string, mixed> $args Args schema.
     * @return $this
     */
    public function args(array $args)
    {
        $this->args = $args;

        return $this;
    }

    /**
     * Override the policy for this route.
     *
     * @param string $policy Policy class short name.
     * @return $this
     */
    public function withPolicy($policy)
    {
        $this->policy = $policy;

        return $this;
    }

    /**
     * HTTP method.
     *
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * Route URI.
     *
     * @return string
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Handler.
     *
     * @return callable|array
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Policy short name, if any.
     *
     * @return string|null
     */
    public function getPolicy()
    {
        return $this->policy;
    }

    /**
     * Args schema.
     *
     * @return array<string, mixed>
     */
    public function getArgs()
    {
        return $this->args;
    }
}
