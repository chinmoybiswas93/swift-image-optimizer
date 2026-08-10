<?php
/**
 * Dependency container.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Framework;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Resolves bindings by string key, and auto-wires concrete classes that have
 * no explicit binding by reading their constructor type hints.
 *
 * Auto-wiring is what lets a controller declare its own dependencies:
 *
 *     class OptimizeController {
 *         public function __construct(AttachmentConverter $converter) {}
 *     }
 *
 * and be resolved with App::make(OptimizeController::class), instead of every
 * dependency having to be registered as a string key up front.
 */
class Container {

    /**
     * Registered factories, keyed by abstract name.
     *
     * @var array<string, callable>
     */
    private $bindings = [];

    /**
     * Resolved singleton instances, keyed by abstract name.
     *
     * @var array<string, mixed>
     */
    private $instances = [];

    /**
     * Abstract names that resolve once and are cached.
     *
     * @var array<string, true>
     */
    private $shared = [];

    /**
     * Alias => abstract map.
     *
     * @var array<string, string>
     */
    private $aliases = [];

    /**
     * Abstracts currently mid-resolution, used to detect circular dependencies.
     *
     * @var array<string, true>
     */
    private $building = [];

    /**
     * Register a factory binding.
     *
     * @param string   $abstract Binding key.
     * @param callable $concrete Factory returning the concrete instance.
     * @param bool     $shared   Whether to cache the resolved instance.
     * @return void
     */
    public function bind( $abstract, callable $concrete, $shared = false ) {
        $this->bindings[ $abstract ] = $concrete;

        if ($shared) {
            $this->shared[ $abstract ] = true;
        }

        unset($this->instances[ $abstract ]);
    }

    /**
     * Register a factory binding that resolves once and is cached.
     *
     * @param string   $abstract Binding key.
     * @param callable $concrete Factory returning the concrete instance.
     * @return void
     */
    public function singleton( $abstract, callable $concrete ) {
        $this->bind($abstract, $concrete, true);
    }

    /**
     * Register an already-built instance.
     *
     * @param string $abstract Binding key.
     * @param mixed  $instance Instance to store.
     * @return void
     */
    public function instance( $abstract, $instance ) {
        $this->instances[ $abstract ] = $instance;
        $this->shared[ $abstract ]    = true;
    }

    /**
     * Point a short name at an existing binding, so both resolve identically.
     *
     * @param string $abstract Binding key.
     * @param string $alias    Short name.
     * @return void
     */
    public function alias( $abstract, $alias ) {
        $this->aliases[ $alias ] = $abstract;
    }

    /**
     * Resolve a binding, auto-wiring it when nothing is explicitly registered.
     *
     * @param string $abstract Binding key or concrete class name.
     * @return mixed
     *
     * @throws RuntimeException When the abstract cannot be resolved.
     */
    public function make( $abstract ) {
        if (isset($this->aliases[ $abstract ])) {
            $abstract = $this->aliases[ $abstract ];
        }

        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[ $abstract ];
        }

        if (isset($this->bindings[ $abstract ])) {
            $object = call_user_func($this->bindings[ $abstract ], $this);

            if (isset($this->shared[ $abstract ])) {
                $this->instances[ $abstract ] = $object;
            }

            return $object;
        }

        if ( ! class_exists($abstract)) {
            throw new RuntimeException("Nothing bound for '{$abstract}'.");
        }

        return $this->build($abstract);
    }

    /**
     * Whether a binding or resolved instance exists for $abstract.
     *
     * @param string $abstract Binding key.
     * @return bool
     */
    public function bound( $abstract ) {
        if (isset($this->aliases[ $abstract ])) {
            $abstract = $this->aliases[ $abstract ];
        }

        return isset($this->bindings[ $abstract ]) || array_key_exists($abstract, $this->instances);
    }

    /**
     * Instantiate a concrete class, resolving constructor dependencies.
     *
     * @param string $concrete Class name.
     * @return object
     *
     * @throws RuntimeException When the class or a dependency cannot be built.
     */
    private function build( $concrete ) {
        if (isset($this->building[ $concrete ])) {
            $chain = implode(' -> ', array_keys($this->building)) . ' -> ' . $concrete;
            throw new RuntimeException("Circular dependency while resolving: {$chain}");
        }

        $reflector = new ReflectionClass($concrete);

        if ( ! $reflector->isInstantiable()) {
            throw new RuntimeException("Class '{$concrete}' is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        if ( ! $constructor || 0 === $constructor->getNumberOfParameters()) {
            return new $concrete();
        }

        $this->building[ $concrete ] = true;

        try {
            $arguments = [];

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                    $arguments[] = $this->make($type->getName());
                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }

                throw new RuntimeException(
                    "Cannot resolve parameter \${$parameter->getName()} of '{$concrete}'."
                );
            }
        } finally {
            unset($this->building[ $concrete ]);
        }

        return $reflector->newInstanceArgs($arguments);
    }
}
