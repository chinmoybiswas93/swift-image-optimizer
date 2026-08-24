<?php
/**
 * Repairs the mime-type desync a re-fed upload leaves behind.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Upload;

use SwiftImageOptimizer\App\Models\OptimizationLog;
use SwiftImageOptimizer\App\Services\Logging\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repairs attachments left with a stale post_mime_type by a re-fed upload
 * (see Interceptor::already_belongs_to_an_attachment()): the file on disk
 * was genuinely converted to WebP, but bind_attachment() never matched it to
 * a new attachment post, so post_mime_type and the log table both still
 * describe the original JPEG/PNG.
 *
 * Modeled on BackupManager::reconcile()'s pattern - its own query, reads the
 * disk, writes corrections, deletes nothing - but kept as its own class: the
 * hole it closes (a stale mime type) is unrelated to what reconcile() reads
 * or writes (a backup manifest pointer).
 */
class MimeReconciler {

	/**
	 * Repair one page of mismatched attachments.
	 *
	 * @param int $after Only rows with a greater attachment ID.
	 * @param int $limit Page size.
	 * @return array{repaired_mime: int, repaired_log: int, skipped: int, last_id: int}
	 */
	public static function reconcile( $after = 0, $limit = 100 ) {
		$result = array(
			'repaired_mime' => 0,
			'repaired_log'  => 0,
			'skipped'       => 0,
			'last_id'       => (int) $after,
		);

		$rows = OptimizationLog::mimeMismatchPage( (int) $after, (int) $limit );

		if ( empty( $rows ) ) {
			return $result;
		}

		foreach ( $rows as $row ) {
			$attachment_id     = (int) $row['attachment_id'];
			$result['last_id'] = $attachment_id;

			$current = get_attached_file( $attachment_id );

			if ( ! $current || ! file_exists( $current ) ) {
				++$result['skipped'];
				continue;
			}

			// Re-derive the mime from the file itself rather than trusting
			// the postmeta value the query matched on - belt and braces.
			$filetype = wp_check_filetype( $current );

			if ( 'image/webp' !== $filetype['type'] ) {
				++$result['skipped'];
				continue;
			}

			wp_update_post(
				array(
					'ID'             => $attachment_id,
					'post_mime_type' => 'image/webp',
				)
			);

			++$result['repaired_mime'];

			$existing = OptimizationLog::find( $attachment_id );

			/*
			 * Only correct the log row when a prior row with real
			 * original-file data exists to carry forward (typically the
			 * upload-time 'skipped: insufficient-memory' row Interceptor
			 * itself wrote before the re-feed happened). Fabricating
			 * original_size/original_file out of nothing would corrupt the
			 * savings stats worse than leaving the row stale.
			 */
			if ( $existing && (int) $existing['original_size'] > 0 ) {
				OptimizationLog::upsert(
					$attachment_id,
					array(
						'status'         => OptimizationLog::STATUS_OPTIMIZED,
						'original_file'  => $existing['original_file'],
						'original_size'  => $existing['original_size'],
						'original_mime'  => $existing['original_mime'],
						'optimized_file' => wp_basename( $current ),
						'optimized_size' => (int) filesize( $current ),
						'engine'         => $existing['engine'],
						'conversion_ms'  => (int) $existing['conversion_ms'],

						/*
						 * Left empty deliberately: BackupManager::reconcile()
						 * is the single place that rebuilds a manifest from
						 * files on disk, and this row now satisfies exactly
						 * its query (status=optimized, backup_path empty) -
						 * running it afterwards picks this row up for free.
						 */
						'backup_path'    => '',
						'backup_expires' => 0,
						'reason'         => '',
					)
				);

				++$result['repaired_log'];
			}

			Logger::info(
				'upload',
				'Reconciled a stale post_mime_type left by a re-fed upload.',
				$attachment_id,
				array( 'file' => wp_basename( $current ) )
			);
		}

		return $result;
	}
}
