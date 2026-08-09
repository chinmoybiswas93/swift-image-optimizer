<?php
/**
 * Core bindings shared by every other provider.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Providers;

use SwiftImageOptimizer\Database\Database;
use SwiftImageOptimizer\Repositories\SettingsRepository;
use SwiftImageOptimizer\Services\AttachmentConverter;
use SwiftImageOptimizer\Services\Bulk\Runner;
use SwiftImageOptimizer\Services\Engine\EngineFactory;
use SwiftImageOptimizer\Services\Optimizer;
use SwiftImageOptimizer\Services\Rewrite\DatabaseRewriter;
use SwiftImageOptimizer\Support\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Binds the shared Optimizer/Converter/Runner/Rewriter instances every other
 * provider resolves from the container, and wires the plugin-wide hooks that
 * don't belong to any single feature (schema install, settings registration).
 */
class AppServiceProvider extends ServiceProvider {

	/**
	 * {@inheritDoc}
	 */
	public function register() {
		App::singleton( 'rewriter', static fn() => new DatabaseRewriter() );
		App::singleton( 'optimizer', static fn() => new Optimizer() );
		App::singleton(
			'converter',
			static fn() => new AttachmentConverter( App::make( 'optimizer' ), App::make( 'rewriter' ) )
		);
		App::singleton(
			'runner',
			static fn() => new Runner( App::make( 'converter' ), App::make( 'rewriter' ) )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function boot() {
		// Run any pending schema upgrade before anything touches the table.
		add_action( 'init', array( Database::class, 'install' ), 1 );

		( new SettingsRepository() )->register();

		// A settings change can alter which engine is selected.
		add_action( 'update_option_' . SettingsRepository::OPTION, array( EngineFactory::class, 'reset' ) );
	}
}
