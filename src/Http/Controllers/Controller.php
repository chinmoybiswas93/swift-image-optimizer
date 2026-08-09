<?php
/**
 * REST endpoints backing the admin UI.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Http\Controllers;

use SwiftImageOptimizer\Database\Database;
use SwiftImageOptimizer\Services\AttachmentConverter;
use SwiftImageOptimizer\Services\Backup\BackupManager;
use SwiftImageOptimizer\Services\Bulk\Runner;
use SwiftImageOptimizer\Services\Bulk\Scanner;
use SwiftImageOptimizer\Services\Diagnostics\EnvironmentReport;
use SwiftImageOptimizer\Services\Engine\EngineFactory;
use SwiftImageOptimizer\Services\Logging\Logger;
use SwiftImageOptimizer\Services\Optimizer;
use SwiftImageOptimizer\Repositories\StatsRepository;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under swift-image-optimizer/v1.
 */
class Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'swift-image-optimizer/v1';

	/**
	 * Converter instance.
	 *
	 * @var AttachmentConverter
	 */
	private $converter;

	/**
	 * Bulk runner instance.
	 *
	 * @var Runner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param AttachmentConverter $converter Converter instance.
	 * @param Runner              $runner    Runner instance.
	 */
	public function __construct( AttachmentConverter $converter, Runner $runner ) {
		$this->converter = $converter;
		$this->runner    = $runner;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Whether the current user may manage optimization.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Whether the current user may optimize a single image.
	 *
	 * @return bool
	 */
	public function can_edit_media() {
		return current_user_can( 'upload_files' ) && current_user_can( 'edit_posts' );
	}

	/**
	 * Register every route.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ids_arg = array(
			'ids' => array(
				'type'              => 'array',
				'required'          => true,
				'items'             => array( 'type' => 'integer' ),
				'sanitize_callback' => static function ( $value ) {
					return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
				},
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/scan',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/stats',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/dry-run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dry_run' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/optimize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'optimize' ),
				'permission_callback' => array( $this, 'can_edit_media' ),
				'args'                => $ids_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'can_edit_media' ),
				'args'                => $ids_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/bulk/(?P<action>start|status|cancel|batch)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'action' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/backups/purge',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'purge_backups' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'lines' => array(
						'type'              => 'integer',
						'default'           => 500,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/logs/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_log' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/logs/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_log' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/cleanup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cleanup' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/requeue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'requeue' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Server and plugin environment report.
	 *
	 * @return WP_REST_Response
	 */
	public function diagnostics() {
		$report = EnvironmentReport::get();

		$report['text']      = EnvironmentReport::as_text();
		$report['retryable'] = Scanner::count_retryable();
		$report['tempFiles'] = Optimizer::count_temp_files();
		$report['log']       = array(
			'enabled' => Logger::is_enabled(),
			'bytes'   => Logger::size(),
		);

		return rest_ensure_response( $report );
	}

	/**
	 * Tail of the diagnostic log.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function logs( $request ) {
		return rest_ensure_response( Logger::tail( (int) $request->get_param( 'lines' ) ) );
	}

	/**
	 * Send the whole log file as a download.
	 *
	 * Streamed rather than loaded into a JSON response: the file can be 10MB,
	 * and base64 inside JSON would make it larger still.
	 *
	 * @return void
	 */
	public function download_log() {
		$file = Logger::file();

		if ( is_wp_error( $file ) || ! file_exists( $file ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="swift-image-optimizer-' . gmdate( 'Ymd-His' ) . '.log"' );
		header( 'Content-Length: ' . filesize( $file ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.readfile_readfile -- Streaming a plain text file the plugin itself wrote; WP_Filesystem would load it entirely into memory.
		readfile( $file );

		exit;
	}

	/**
	 * Delete the log and start a fresh one.
	 *
	 * @return WP_REST_Response
	 */
	public function reset_log() {
		Logger::clear();
		Logger::start_run( 'admin' );
		Logger::mark( 'logging', 'Log cleared from the Troubleshoot screen.' );

		return rest_ensure_response(
			array(
				'cleared' => true,
				'log'     => Logger::tail( 100 ),
			)
		);
	}

	/**
	 * Delete scratch files left behind by interrupted conversions.
	 *
	 * @return WP_REST_Response
	 */
	public function cleanup() {
		$removed = Optimizer::sweep_temp_files();

		Logger::start_run( 'admin' );
		Logger::mark( 'cleanup', $removed . ' abandoned temporary file(s) removed.' );

		return rest_ensure_response(
			array(
				'removed'   => $removed,
				'remaining' => Optimizer::count_temp_files(),
			)
		);
	}

	/**
	 * Clear rows that are worth trying again.
	 *
	 * Deleting the row is what returns the attachment to the pending queue -
	 * Scanner decides what is outstanding by the absence of a terminal row.
	 *
	 * @return WP_REST_Response
	 */
	public function requeue() {
		$removed = Scanner::requeue();

		Logger::start_run( 'admin' );
		Logger::mark( 'requeue', $removed . ' row(s) cleared for another attempt.' );

		return rest_ensure_response(
			array(
				'requeued' => $removed,
				'summary'  => Scanner::summary(),
			)
		);
	}


	/**
	 * Library summary plus environment info.
	 *
	 * @return WP_REST_Response
	 */
	public function scan() {
		$summary = Scanner::summary();

		$engine = EngineFactory::get();

		$summary['engine']       = $engine ? $engine->name() : '';
		$summary['engines']      = EngineFactory::availability();
		$summary['backup_bytes'] = BackupManager::disk_usage();

		return rest_ensure_response( $summary );
	}

	/**
	 * Aggregate savings.
	 *
	 * @return WP_REST_Response
	 */
	public function stats() {
		$stats                 = StatsRepository::get( true );
		$stats['backup_bytes'] = BackupManager::disk_usage();

		return rest_ensure_response( $stats );
	}

	/**
	 * Report what a bulk run would change.
	 *
	 * @return WP_REST_Response
	 */
	public function dry_run() {
		return rest_ensure_response( $this->runner->dry_run() );
	}

	/**
	 * Optimize one or more attachments.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function optimize( $request ) {
		$results = array();

		foreach ( (array) $request->get_param( 'ids' ) as $id ) {
			$result = $this->converter->convert( $id );

			$results[] = is_wp_error( $result )
				? array(
					'id'      => $id,
					'ok'      => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
				: array(
					'id'      => $id,
					'ok'      => true,
					'saved'   => max( 0, $result['original_size'] - $result['optimized_size'] ),
					'percent' => $result['original_size'] > 0
						? round( ( 1 - ( $result['optimized_size'] / $result['original_size'] ) ) * 100, 1 )
						: 0,
				);
		}

		return rest_ensure_response( array( 'results' => $results ) );
	}

	/**
	 * Restore one or more attachments.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function restore( $request ) {
		$results = array();

		foreach ( (array) $request->get_param( 'ids' ) as $id ) {
			$result = $this->converter->restore( $id );

			$results[] = is_wp_error( $result )
				? array(
					'id'      => $id,
					'ok'      => false,
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				)
				: array(
					'id'        => $id,
					'ok'        => true,
					// Non-zero means some references could not be reversed and
					// still point at the full-size image. The restore itself
					// succeeded; saying so plainly beats a silent half-result.
					'ambiguous' => isset( $result['ambiguous'] ) ? (int) $result['ambiguous'] : 0,
				);
		}

		return rest_ensure_response( array( 'results' => $results ) );
	}

	/**
	 * Drive the bulk runner.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return WP_REST_Response|\WP_Error
	 */
	public function bulk( $request ) {
		switch ( $request->get_param( 'action' ) ) {
			case 'start':
				return rest_ensure_response( $this->runner->start() );

			case 'batch':
				$state = $this->runner->process_batch();

				return is_wp_error( $state ) ? $state : rest_ensure_response( $state );

			case 'cancel':
				return rest_ensure_response( $this->runner->cancel() );

			default:
				return rest_ensure_response( $this->runner->state() );
		}
	}

	/**
	 * Delete every stored backup immediately.
	 *
	 * @return WP_REST_Response
	 */
	public function purge_backups() {
		global $wpdb;

		$table = Database::table();

		// Expire everything, then let the existing purge routine do the work.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin-owned table; administrative action.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET backup_expires = %d WHERE backup_expires > 0", 1 ) );

		$removed = 0;

		// The purge is batched, so loop until it stops finding work.
		do {
			$count    = \SwiftImageOptimizer\Hooks\Scheduler\RetentionCron::purge();
			$removed += $count;
		} while ( $count > 0 );

		return rest_ensure_response(
			array(
				'purged'       => $removed,
				'backup_bytes' => BackupManager::disk_usage(),
			)
		);
	}
}
