<?php
/**
 * Plain-PHP view renderer.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Framework;

use RuntimeException;

if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Renders templates from app/Views. Dot and slash notation both work, and the
 * .php extension is optional, so these are equivalent:
 *
 *     App::view()->render('admin.admin_app', $data);
 *     App::view()->render('admin/admin_app.php', $data);
 *
 * Deliberately not a template engine - templates are PHP, escaped with the
 * usual WordPress helpers.
 */
class View {

    /**
     * Absolute path to the views root, with trailing slash.
     *
     * @var string
     */
    private $root;

    /**
     * Data shared with every view rendered through this instance.
     *
     * @var array<string, mixed>
     */
    private $shared = [];

    /**
     * Point the renderer at the views directory.
     *
     * @param string $root Absolute path to the views directory.
     */
    public function __construct( $root ) {
        $this->root = rtrim($root, '/') . '/';
    }

    /**
     * Share data with every subsequent view.
     *
     * @param array<string, mixed> $data Shared variables.
     * @return void
     */
    public function share( array $data ) {
        $this->shared = array_merge($this->shared, $data);
    }

    /**
     * Render a view straight to output.
     *
     * @param string               $view View path.
     * @param array<string, mixed> $data Variables extracted into the template.
     * @return void
     */
    public function render( $view, array $data = [] ) {
        // The template is trusted plugin code that echoes its own escaped
        // markup, so it is emitted verbatim rather than re-escaped here.
        echo $this->make($view, $data); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Render a view and return it as a string.
     *
     * @param string               $view View path.
     * @param array<string, mixed> $data Variables extracted into the template.
     * @return string
     *
     * @throws RuntimeException When the template does not exist.
     */
    public function make( $view, array $data = [] ) {
        $path = $this->resolve($view);

        if ( ! $path) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing; the view name comes from a render() call site, never request data.
            throw new RuntimeException("View '{$view}' not found in {$this->root}");
        }

        // Extracted so templates can use $title rather than $data['title'].
        // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
        extract(array_merge($this->shared, $data), EXTR_SKIP);

        ob_start();

        include $path;

        return (string) ob_get_clean();
    }

    /**
     * Whether a view exists.
     *
     * @param string $view View path.
     * @return bool
     */
    public function exists( $view ) {
        return (bool) $this->resolve($view);
    }

    /**
     * Turn a view name into an absolute path inside the views root.
     *
     * @param string $view View path in dot or slash notation.
     * @return string|null Absolute path, or null when absent or out of bounds.
     */
    private function resolve( $view ) {
        $relative = str_replace('.php', '', (string) $view);
        $relative = str_replace('.', '/', $relative);
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        $path = $this->root . $relative . '.php';

        // A view name is never user input today, but resolving through
        // realpath keeps a future caller from walking out of the root.
        $real = realpath($path);
        $root = realpath($this->root);

        if ( ! $real || ! $root || 0 !== strpos($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
