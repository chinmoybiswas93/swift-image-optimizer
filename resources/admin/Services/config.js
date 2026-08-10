/**
 * The localized payload PHP hands the bundle.
 *
 * Read once here so the shape is stated in exactly one place, and the pages
 * never touch a global directly.
 */

const config = window.swiftImageOptimizer || {};

export default config;
