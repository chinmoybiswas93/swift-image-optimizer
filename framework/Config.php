<?php
/**
 * Configuration repository.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Foundation;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Holds every config/*.php file, keyed by filename, and exposes them through
 * dot notation: config/app.php returning ['slug' => 'x'] is App::config()->get('app.slug').
 */
class Config
{
    /**
     * Loaded config, keyed by file basename.
     *
     * @var array<string, mixed>
     */
    private $items = [];

    /**
     * @param array<string, mixed> $items Config arrays keyed by basename.
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Load every PHP file in a directory as a config namespace.
     *
     * @param string $directory Absolute path to the config directory.
     * @return static
     */
    public static function fromDirectory($directory)
    {
        $items = [];

        foreach ((array) glob(rtrim($directory, '/') . '/*.php') as $file) {
            $value = require $file;

            if (is_array($value)) {
                $items[basename($file, '.php')] = $value;
            }
        }

        return new static($items);
    }

    /**
     * Read a value by dot-notated key.
     *
     * @param string $key     Dot-notated key, e.g. 'app.slug'.
     * @param mixed  $default Returned when the key is absent.
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Override a value at runtime. Does not write back to disk.
     *
     * @param string $key   Dot-notated key.
     * @param mixed  $value Value to set.
     * @return void
     */
    public function set($key, $value)
    {
        $segments = explode('.', $key);
        $target   = &$this->items;

        while (count($segments) > 1) {
            $segment = array_shift($segments);

            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target[array_shift($segments)] = $value;
    }

    /**
     * Whether a key exists.
     *
     * @param string $key Dot-notated key.
     * @return bool
     */
    public function has($key)
    {
        $sentinel = new \stdClass();

        return $this->get($key, $sentinel) !== $sentinel;
    }
}
