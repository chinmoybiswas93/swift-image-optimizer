<?php
/**
 * Container bindings.
 *
 * The shared service instances the rest of the plugin resolves by name. These
 * were previously AppServiceProvider::register().
 *
 * @package SwiftImageOptimizer
 */

use SwiftImageOptimizer\Api\StoreSettings;
use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Services\AttachmentConverter;
use SwiftImageOptimizer\App\Services\Bulk\Runner;
use SwiftImageOptimizer\App\Services\Optimizer;
use SwiftImageOptimizer\App\Services\Rewrite\DatabaseRewriter;

if (!defined('ABSPATH')) {
    exit;
}

App::singleton('rewriter', static function () {
    return new DatabaseRewriter();
});

App::singleton('optimizer', static function () {
    return new Optimizer();
});

App::singleton('converter', static function () {
    return new AttachmentConverter(App::make('optimizer'), App::make('rewriter'));
});

App::singleton('runner', static function () {
    return new Runner(App::make('converter'), App::make('rewriter'));
});

App::singleton('settings', static function () {
    return new StoreSettings();
});

// The concrete class names resolve to the same shared instances, so anything
// resolved by constructor type hint gets the singleton rather than a new one.
App::alias('rewriter', DatabaseRewriter::class);
App::alias('optimizer', Optimizer::class);
App::alias('converter', AttachmentConverter::class);
App::alias('runner', Runner::class);
App::alias('settings', StoreSettings::class);
