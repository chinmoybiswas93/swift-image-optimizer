<?php
/**
 * Plugin bootstrap.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer;

use SwiftImageOptimizer\Providers\AdminServiceProvider;
use SwiftImageOptimizer\Providers\AppServiceProvider;
use SwiftImageOptimizer\Providers\BackupServiceProvider;
use SwiftImageOptimizer\Providers\CliServiceProvider;
use SwiftImageOptimizer\Providers\LoggingServiceProvider;
use SwiftImageOptimizer\Providers\PluginBootstrapper;
use SwiftImageOptimizer\Providers\RestServiceProvider;
use SwiftImageOptimizer\Providers\RewriteServiceProvider;
use SwiftImageOptimizer\Providers\UploadServiceProvider;
use SwiftImageOptimizer\Support\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots every provider. AppServiceProvider must register first - it binds
 * the shared Optimizer/Converter/Runner/Rewriter instances every other
 * provider resolves via App::make().
 */
class Plugin {

	/**
	 * Constructor. Boots every provider.
	 */
	public function __construct() {
		PluginBootstrapper::create()
			->providers(
				array(
					AppServiceProvider::class,
					LoggingServiceProvider::class,
					UploadServiceProvider::class,
					BackupServiceProvider::class,
					RewriteServiceProvider::class,
					RestServiceProvider::class,
					AdminServiceProvider::class,
					CliServiceProvider::class,
				)
			)
			->boot();
	}

	/**
	 * Shared optimizer instance.
	 *
	 * @return \SwiftImageOptimizer\Services\Optimizer
	 */
	public function optimizer() {
		return App::make( 'optimizer' );
	}

	/**
	 * Shared converter instance.
	 *
	 * @return \SwiftImageOptimizer\Services\AttachmentConverter
	 */
	public function converter() {
		return App::make( 'converter' );
	}

	/**
	 * Shared bulk runner.
	 *
	 * @return \SwiftImageOptimizer\Services\Bulk\Runner
	 */
	public function runner() {
		return App::make( 'runner' );
	}
}
