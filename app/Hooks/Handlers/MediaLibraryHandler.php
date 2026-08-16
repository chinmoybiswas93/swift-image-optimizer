<?php
/**
 * Media Library integration.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Hooks\Handlers;

use SwiftImageOptimizer\App\App;
use SwiftImageOptimizer\App\Services\AttachmentConverter;
use SwiftImageOptimizer\App\Services\Backup\BackupManager;
use SwiftImageOptimizer\App\Services\Bulk\Scanner;
use SwiftImageOptimizer\App\Models\OptimizationLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the optimization column, row actions and bulk action to upload.php.
 */
class MediaLibraryHandler {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'add_row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'expose_to_js' ), 10, 2 );
	}

	/**
	 * Add the Optimization column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['swift_image_optimizer'] = __( 'Optimization', 'swift-image-optimizer' );

		return $columns;
	}

	/**
	 * Render the column contents.
	 *
	 * @param string $column        Column key.
	 * @param int    $attachment_id Attachment ID.
	 * @return void
	 */
	public function render_column( $column, $attachment_id ) {
		if ( 'swift_image_optimizer' !== $column ) {
			return;
		}

		App::view()->render( 'admin.parts.media-column', $this->column_state( $attachment_id ) );
	}

	/**
	 * Work out what the column should say for an attachment.
	 *
	 * Split from the rendering so the cell's markup lives in a template and
	 * this stays a pure mapping from row to state.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{state: string, primary: string, detail: string}
	 */
	private function column_state( $attachment_id ) {
		$row = OptimizationLog::find( $attachment_id );

		if ( ! $row ) {
			$mime = get_post_mime_type( $attachment_id );

			if ( ! in_array( $mime, Scanner::mime_types(), true ) ) {
				return array( 'state' => 'none', 'primary' => '', 'detail' => '' );
			}

			return array(
				'state'   => 'pending',
				'primary' => __( 'Not optimized', 'swift-image-optimizer' ),
				'detail'  => '',
			);
		}

		/*
		 * Verified against the disk rather than taken from the column. A row
		 * outliving the file it describes is what showed a whole library as
		 * processed after a restore, with nothing left to click.
		 */
		if ( OptimizationLog::STATUS_OPTIMIZED === $row['status']
			&& ! AttachmentConverter::optimized_output_exists( $attachment_id, $row ) ) {
			return array(
				'state'   => 'pending',
				'primary' => __( 'Not optimized', 'swift-image-optimizer' ),
				'detail'  => __( 'A previous result was recorded but its file is missing.', 'swift-image-optimizer' ),
			);
		}

		switch ( $row['status'] ) {
			case OptimizationLog::STATUS_OPTIMIZED:
				$saved   = max( 0, (int) $row['original_size'] - (int) $row['optimized_size'] );
				$percent = $row['original_size'] > 0 ? round( ( $saved / $row['original_size'] ) * 100, 1 ) : 0;

				return array(
					'state'   => 'optimized',
					'primary' => sprintf(
						/* translators: %s: percentage saved. */
						__( '%s%% smaller', 'swift-image-optimizer' ),
						number_format_i18n( $percent, 1 )
					),
					'detail'  => sprintf(
						/* translators: 1: original size, 2: optimized size. */
						__( '%1$s to %2$s', 'swift-image-optimizer' ),
						size_format( (int) $row['original_size'] ),
						size_format( (int) $row['optimized_size'] )
					),
				);

			case OptimizationLog::STATUS_SKIPPED:
				return array(
					'state'   => 'skipped',
					'primary' => $this->reason_label( $row['reason'] ),
					'detail'  => '',
				);

			case OptimizationLog::STATUS_FAILED:
				return array(
					'state'   => 'failed',
					'primary' => __( 'Failed', 'swift-image-optimizer' ),
					'detail'  => $this->reason_label( $row['reason'] ),
				);

			case OptimizationLog::STATUS_RESTORED:
				return array(
					'state'   => 'restored',
					'primary' => __( 'Restored to original', 'swift-image-optimizer' ),
					'detail'  => '',
				);
		}

		return array( 'state' => 'none', 'primary' => '', 'detail' => '' );
	}

	/**
	 * Turn an internal reason code into something readable.
	 *
	 * @param string $reason Reason code.
	 * @return string
	 */
	private function reason_label( $reason ) {
		$labels = array(
			'skipped-larger'      => __( 'Already efficient, WebP would be larger', 'swift-image-optimizer' ),
			'insufficient-memory' => __( 'Too large for the available memory', 'swift-image-optimizer' ),
			'unsupported-format'  => __( 'Format not supported', 'swift-image-optimizer' ),
			'already-webp'        => __( 'Already WebP', 'swift-image-optimizer' ),
			'png-disabled'        => __( 'PNG conversion is off', 'swift-image-optimizer' ),
			'no-engine'           => __( 'No conversion engine available', 'swift-image-optimizer' ),
			'missing-file'        => __( 'File missing on disk', 'swift-image-optimizer' ),
			'backup-copy-failed'  => __( 'Backup could not be written', 'swift-image-optimizer' ),
			'insufficient-disk'   => __( 'Not enough free disk space to back up safely', 'swift-image-optimizer' ),
			'animated-image'      => __( 'Animated, so it was left as it is', 'swift-image-optimizer' ),
			'engine-unsupported'  => __( 'No engine here can convert it correctly', 'swift-image-optimizer' ),
		);

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : ucfirst( str_replace( '-', ' ', (string) $reason ) );
	}

	/**
	 * Add Optimize and Restore links to each row.
	 *
	 * @param array    $actions Existing actions.
	 * @param \WP_Post $post    Attachment.
	 * @return array
	 */
	public function add_row_actions( $actions, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$row  = OptimizationLog::find( $post->ID );
		$mime = get_post_mime_type( $post->ID );

		if ( $row && OptimizationLog::STATUS_OPTIMIZED === $row['status']
			&& AttachmentConverter::optimized_output_exists( $post->ID, $row ) ) {
			if ( BackupManager::manifest_is_intact( $row['backup_path'] ) ) {
				$actions['swift_restore'] = sprintf(
					'<a href="%s" class="sio-row-action sio-row-action--restore" data-action="restore" data-id="%d">%s</a>',
					esc_url( $this->action_url( 'restore', $post->ID ) ),
					(int) $post->ID,
					esc_html__( 'Restore original', 'swift-image-optimizer' )
				);
			}

			return $actions;
		}

		if ( ! in_array( $mime, Scanner::mime_types(), true ) ) {
			return $actions;
		}

		$actions['swift_optimize'] = sprintf(
			'<a href="%s" class="sio-row-action sio-row-action--optimize" data-action="optimize" data-id="%d">%s</a>',
			esc_url( $this->action_url( 'optimize', $post->ID ) ),
			(int) $post->ID,
			esc_html__( 'Optimize', 'swift-image-optimizer' )
		);

		return $actions;
	}

	/**
	 * Build a nonced action URL.
	 *
	 * @param string $action Either optimize or restore.
	 * @param int    $id     Attachment ID.
	 * @return string
	 */
	private function action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'swift_image_optimizer_action' => $action,
					'attachment_id'                => (int) $id,
				),
				admin_url( 'upload.php' )
			),
			'swift_image_optimizer_' . $action . '_' . $id
		);
	}

	/**
	 * Add the bulk dropdown entries.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function add_bulk_actions( $actions ) {
		$actions['swift_image_optimizer_optimize'] = __( 'Optimize images', 'swift-image-optimizer' );
		$actions['swift_image_optimizer_restore']  = __( 'Restore originals', 'swift-image-optimizer' );

		return $actions;
	}

	/**
	 * Run the selected bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action key.
	 * @param array  $ids      Selected attachment IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect, $action, $ids ) {
		$converter = App::make( 'converter' );

		if ( 'swift_image_optimizer_optimize' === $action ) {
			$done = 0;

			foreach ( (array) $ids as $id ) {
				if ( current_user_can( 'edit_post', $id ) && ! is_wp_error( $converter->convert( $id ) ) ) {
					++$done;
				}
			}

			return add_query_arg( 'swift_image_optimizer_optimized', $done, $redirect );
		}

		if ( 'swift_image_optimizer_restore' === $action ) {
			$done = 0;

			foreach ( (array) $ids as $id ) {
				if ( current_user_can( 'edit_post', $id ) && ! is_wp_error( $converter->restore( $id ) ) ) {
					++$done;
				}
			}

			return add_query_arg( 'swift_image_optimizer_restored', $done, $redirect );
		}

		return $redirect;
	}

	/**
	 * Render an admin notice through the shared partial.
	 *
	 * @param string $type    One of error, warning, success, info.
	 * @param string $message Notice body.
	 * @return void
	 */
	private function notice( $type, $message ) {
		App::view()->render(
			'admin.parts.notice',
			array(
				'type'    => $type,
				'message' => $message,
			)
		);
	}

	/**
	 * Handle a single-row action and show the result.
	 *
	 * @return void
	 */
	public function bulk_notice() {
		$screen = get_current_screen();

		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		$this->maybe_handle_row_action();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a count set by our own redirect.
		$optimized = isset( $_GET['swift_image_optimizer_optimized'] ) ? absint( $_GET['swift_image_optimizer_optimized'] ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display of a count set by our own redirect.
		$restored = isset( $_GET['swift_image_optimizer_restored'] ) ? absint( $_GET['swift_image_optimizer_restored'] ) : null;

		if ( null !== $optimized ) {
			$this->notice(
				'success',
				sprintf(
					/* translators: %d: number of images. */
					_n( '%d image optimized.', '%d images optimized.', $optimized, 'swift-image-optimizer' ),
					$optimized
				)
			);
		}

		if ( null !== $restored ) {
			$this->notice(
				'success',
				sprintf(
					/* translators: %d: number of images. */
					_n( '%d image restored.', '%d images restored.', $restored, 'swift-image-optimizer' ),
					$restored
				)
			);
		}
	}

	/**
	 * Process a nonced single-row optimize or restore link.
	 *
	 * @return void
	 */
	private function maybe_handle_row_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified immediately below.
		$action = isset( $_GET['swift_image_optimizer_action'] ) ? sanitize_key( wp_unslash( $_GET['swift_image_optimizer_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce is verified immediately below.
		$id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;

		if ( ! $action || ! $id || ! in_array( $action, array( 'optimize', 'restore' ), true ) ) {
			return;
		}

		check_admin_referer( 'swift_image_optimizer_' . $action . '_' . $id );

		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'You are not allowed to modify this image.', 'swift-image-optimizer' ) );
		}

		$converter = App::make( 'converter' );
		$result    = 'optimize' === $action ? $converter->convert( $id ) : $converter->restore( $id );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );

			return;
		}

		$this->notice(
			'success',
			'optimize' === $action
				? __( 'Image optimized.', 'swift-image-optimizer' )
				: __( 'Original restored.', 'swift-image-optimizer' )
		);
	}

	/**
	 * Expose the log row to the media modal.
	 *
	 * @param array    $response   Attachment data for JS.
	 * @param \WP_Post $attachment Attachment.
	 * @return array
	 */
	public function expose_to_js( $response, $attachment ) {
		$mime        = get_post_mime_type( $attachment->ID );
		$convertible = in_array( $mime, Scanner::mime_types(), true );
		$row         = OptimizationLog::find( $attachment->ID );

		// Nothing to say about a PDF that has never been touched.
		if ( ! $row && ! $convertible ) {
			return $response;
		}

		$editable = current_user_can( 'edit_post', $attachment->ID ) && current_user_can( 'upload_files' );
		$status   = $row ? $row['status'] : '';

		/*
		 * A row can claim `optimized` while the file it describes is gone -
		 * typically after the database and the uploads directory were restored
		 * from different points in time. Treat that as not optimized, so the
		 * modal offers Optimize instead of reporting a result that no longer
		 * exists.
		 */
		$stale = $row
			&& OptimizationLog::STATUS_OPTIMIZED === $status
			&& ! AttachmentConverter::optimized_output_exists( $attachment->ID, $row );

		if ( $stale ) {
			$status   = '';
			$original = 0;
			$current  = 0;
		}
		$original = $row ? (int) $row['original_size'] : 0;
		$current  = $row ? (int) $row['optimized_size'] : 0;
		$saved    = max( 0, $original - $current );

		$response['swiftImageOptimizer'] = array(
			'status'        => $status,
			// Offer the button whenever the format is convertible and we have
			// not already converted it. A previous skip or failure is still
			// worth retrying — settings or the engine may have changed since.
			'canOptimize'   => $editable && $convertible && OptimizationLog::STATUS_OPTIMIZED !== $status,
			// Verified against the disk, not just the column: a manifest can
			// outlive the files it names, and a Restore that fails is worse
			// than one that was never offered.
			'canRestore'    => $editable && $row && BackupManager::manifest_is_intact( $row['backup_path'] ),
			'originalSize'  => $original,
			'optimizedSize' => $current,
			'savedBytes'    => $saved,
			'percent'       => $original > 0 ? round( ( $saved / $original ) * 100, 1 ) : 0.0,
			'originalMime'  => $row && $row['original_mime'] ? $row['original_mime'] : $mime,
			'currentMime'   => $mime,
			'engine'        => $row && $row['engine'] ? $row['engine'] : '',
			'conversionMs'  => $row && isset( $row['conversion_ms'] ) ? (int) $row['conversion_ms'] : 0,
			'reason'        => $row ? $this->reason_label( $row['reason'] ) : '',
		);

		return $response;
	}
}
