<?php
/**
 * File-backed diagnostic log.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Services\Logging;

use SwiftImageOptimizer\Api\StoreSettings;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a WordPress debug.log style trail of every destructive step.
 *
 * Deliberately static and dependency free. This is called from inside the
 * upload handler, the cron, WP-CLI and the REST controller, so it cannot rely
 * on the container having been built or on any particular request context.
 *
 * Nothing is ever written to the database. A conversion that goes wrong tends
 * to go wrong on disk, and a log that lives in the same database the rewriter
 * is halfway through modifying is the last place it should live.
 */
class Logger {

	/**
	 * Directory name inside uploads.
	 */
	/**
	 * Log directory, relative to the uploads basedir.
	 *
	 * A subdirectory of the plugin's one folder rather than a sibling of it.
	 */
	const DIRNAME = 'swift-image-optimizer/logs';

	/**
	 * Option holding the per-site filename suffix.
	 */
	const SUFFIX_OPTION = 'swift_image_optimizer_log_suffix';

	/**
	 * Rotate once the file reaches this size.
	 */
	const MAX_BYTES = 10485760;

	/**
	 * Severity levels.
	 */
	const ERROR = 'ERROR';
	const WARN  = 'WARN';
	const INFO  = 'INFO';

	/**
	 * Bookkeeping lines that always write regardless of the enabled flag.
	 *
	 * Used only to mark the log being turned on, off or cleared, so a file
	 * that starts mid-story explains why.
	 */
	const MARK = 'MARK';

	/**
	 * The current run identifier, shared by every line of one operation.
	 *
	 * @var string
	 */
	private static $run_id = '';

	/**
	 * Cached result of the "is the verbose trail enabled" check.
	 *
	 * Read once per request: the settings lookup is cheap but this is called
	 * several times per image, and a bulk batch converts many images.
	 *
	 * @var bool|null
	 */
	private static $verbose = null;

	/**
	 * Start a new run and return its identifier.
	 *
	 * A run is one bulk batch, one REST optimize call or one upload. Grouping
	 * lines under a run is what makes a failure readable after the fact - the
	 * whole story of one image sits together.
	 *
	 * @param string $prefix Short label, for example 'bulk' or 'upload'.
	 * @return string The run identifier.
	 */
	public static function start_run( $prefix = 'run' ) {
		self::$run_id = sanitize_key( $prefix ) . '-' . wp_generate_password( 4, false, false );

		return self::$run_id;
	}

	/**
	 * Adopt an existing run identifier.
	 *
	 * A bulk run spans many requests, so the identifier is persisted in the
	 * progress option and restored at the start of each batch.
	 *
	 * @param string $run_id Previously generated identifier.
	 * @return void
	 */
	public static function resume_run( $run_id ) {
		$run_id = sanitize_text_field( (string) $run_id );

		if ( '' !== $run_id ) {
			self::$run_id = $run_id;
		}
	}

	/**
	 * The identifier lines are currently being tagged with.
	 *
	 * @return string
	 */
	public static function run_id() {
		return self::$run_id;
	}

	/**
	 * Whether the verbose trail is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		if ( null === self::$verbose ) {
			self::$verbose = (bool) StoreSettings::get( 'enable_log' );
		}

		return self::$verbose;
	}

	/**
	 * Forget the cached enabled flag, after a settings change.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$verbose = null;
	}

	/**
	 * React to a settings save.
	 *
	 * Drops the cached flag, then records the transition itself so the log
	 * explains its own gaps - a log that simply stops has no way of saying
	 * whether logging was turned off or the plugin broke.
	 *
	 * @param mixed $old_value Settings before the save.
	 * @param mixed $value     Settings after the save.
	 * @return void
	 */
	public static function settings_changed( $old_value, $value ) {
		self::reset();

		$was_enabled = is_array( $old_value ) && ! empty( $old_value['enable_log'] );
		$is_enabled  = is_array( $value ) && ! empty( $value['enable_log'] );

		if ( $is_enabled && ! $was_enabled ) {
			self::ensure_dir();
			self::start_run( 'admin' );
			self::mark( 'logging', 'Logging enabled.' );
		}

		if ( ! $is_enabled && $was_enabled ) {
			self::start_run( 'admin' );
			self::mark( 'logging', 'Logging disabled. Errors will still be recorded.' );
		}
	}

	/**
	 * Record a step that went as intended.
	 *
	 * Only written when logging is enabled - this is the high volume level.
	 *
	 * @param string $step    Short step name, for example 'encode'.
	 * @param string $message What happened.
	 * @param int    $id      Attachment ID, or 0 when there is not one yet.
	 * @param array  $context Optional extra fields appended as key=value.
	 * @return void
	 */
	public static function info( $step, $message, $id = 0, array $context = array() ) {
		self::write( self::INFO, $step, $message, $id, $context );
	}

	/**
	 * Record something recoverable that the user may need to know about.
	 *
	 * Always written, enabled or not.
	 *
	 * @param string $step    Short step name.
	 * @param string $message What happened.
	 * @param int    $id      Attachment ID.
	 * @param array  $context Optional extra fields.
	 * @return void
	 */
	public static function warn( $step, $message, $id = 0, array $context = array() ) {
		self::write( self::WARN, $step, $message, $id, $context );
	}

	/**
	 * Record a failure.
	 *
	 * Always written, enabled or not. A user who reports a problem should
	 * already have the evidence on disk rather than being asked to reproduce
	 * it with logging switched on.
	 *
	 * @param string $step    Short step name.
	 * @param string $message What happened.
	 * @param int    $id      Attachment ID.
	 * @param array  $context Optional extra fields.
	 * @return void
	 */
	public static function error( $step, $message, $id = 0, array $context = array() ) {
		self::write( self::ERROR, $step, $message, $id, $context );
	}

	/**
	 * Record a bookkeeping line that always writes.
	 *
	 * @param string $step    Short step name.
	 * @param string $message What happened.
	 * @return void
	 */
	public static function mark( $step, $message ) {
		self::write( self::MARK, $step, $message, 0, array() );
	}

	/**
	 * Record a WP_Error at the right level for its code.
	 *
	 * Soft errors are expected outcomes on a real library, so they are
	 * warnings; anything else is a genuine failure.
	 *
	 * @param string    $step  Short step name.
	 * @param \WP_Error $error The error.
	 * @param int       $id    Attachment ID.
	 * @param bool      $soft  Whether the code counts as a skip.
	 * @return void
	 */
	public static function wp_error( $step, $error, $id = 0, $soft = false ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}

		$message = $error->get_error_code() . ': ' . $error->get_error_message();

		if ( $soft ) {
			self::warn( $step, $message, $id );
			return;
		}

		self::error( $step, $message, $id );
	}

	/**
	 * Whether a line at this level should be written at all.
	 *
	 * The single definition of the "errors always, verbose when enabled" rule.
	 *
	 * @param string $level One of the level constants.
	 * @return bool
	 */
	private static function should_write( $level ) {
		if ( self::INFO !== $level ) {
			return true;
		}

		return self::is_enabled();
	}

	/**
	 * Append one line to the log file.
	 *
	 * @param string $level   One of the level constants.
	 * @param string $step    Short step name.
	 * @param string $message What happened.
	 * @param int    $id      Attachment ID.
	 * @param array  $context Optional extra fields.
	 * @return void
	 */
	private static function write( $level, $step, $message, $id = 0, array $context = array() ) {
		if ( ! self::should_write( $level ) ) {
			return;
		}

		$file = self::file();

		if ( is_wp_error( $file ) ) {
			return;
		}

		self::maybe_rotate( $file );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- An appending, locked write is the correct primitive for a log file; WP_Filesystem is not available in the upload, cron and CLI contexts this is called from.
		@file_put_contents( $file, self::format( $level, $step, $message, $id, $context ), FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Logging must never interrupt a conversion; a failed write is silently accepted.
	}

	/**
	 * Build one log line.
	 *
	 * Mirrors the shape of WordPress's own debug.log so the file is instantly
	 * familiar and readable with the same tooling.
	 *
	 * @param string $level   One of the level constants.
	 * @param string $step    Short step name.
	 * @param string $message What happened.
	 * @param int    $id      Attachment ID.
	 * @param array  $context Optional extra fields.
	 * @return string Line, newline terminated.
	 */
	private static function format( $level, $step, $message, $id, array $context ) {
		$stamp  = gmdate( 'd-M-Y H:i:s' ) . ' UTC';
		$run    = self::$run_id ? self::$run_id : '-';
		$target = $id > 0 ? '#' . (int) $id : '-';

		$line = sprintf(
			'[%s] %-5s %-12s %-8s %-10s %s',
			$stamp,
			$level,
			$run,
			$target,
			$step,
			$message
		);

		foreach ( $context as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			}

			if ( is_scalar( $value ) || null === $value ) {
				$line .= ' ' . $key . '=' . $value;
			}
		}

		// Collapse newlines so one event is always exactly one line - engine
		// failures in particular arrive as multi-line stderr.
		return preg_replace( '/\s*\R\s*/', ' ', $line ) . PHP_EOL;
	}

	/**
	 * Rotate the file once it passes the size cap.
	 *
	 * One generation is kept. A bulk run over a large library writes several
	 * lines per image, so an uncapped file is a real way to fill a disk.
	 *
	 * @param string $file Absolute path to the current log file.
	 * @return void
	 */
	private static function maybe_rotate( $file ) {
		if ( ! file_exists( $file ) ) {
			return;
		}

		clearstatcache( true, $file );

		if ( filesize( $file ) < self::MAX_BYTES ) {
			return;
		}

		$previous = $file . '.1';

		if ( file_exists( $previous ) ) {
			wp_delete_file( $previous );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed rotation must not interrupt a conversion; the next write simply appends to the existing file.
		@rename( $file, $previous );
	}

	/**
	 * Absolute path to the current log file, creating the directory if needed.
	 *
	 * @return string|WP_Error
	 */
	public static function file() {
		$dir = self::ensure_dir();

		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		return trailingslashit( $dir ) . 'swift-image-optimizer-' . self::suffix() . '.log';
	}

	/**
	 * Absolute path to the rotated log file.
	 *
	 * @return string|WP_Error
	 */
	public static function previous_file() {
		$file = self::file();

		return is_wp_error( $file ) ? $file : $file . '.1';
	}

	/**
	 * The log directory root.
	 *
	 * @return string
	 */
	public static function root() {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DIRNAME;
	}

	/**
	 * Per-site filename suffix.
	 *
	 * The directory is already blocked by .htaccess, but hosts running nginx
	 * ignore that file entirely. An unguessable filename means the log is not
	 * readable by anyone who simply guesses the path.
	 *
	 * @return string
	 */
	private static function suffix() {
		$suffix = get_option( self::SUFFIX_OPTION, '' );

		if ( ! is_string( $suffix ) || '' === $suffix ) {
			$suffix = wp_generate_password( 12, false, false );
			update_option( self::SUFFIX_OPTION, $suffix, false );
		}

		return $suffix;
	}

	/**
	 * Create the log directory and block direct web access to it.
	 *
	 * Mirrors BackupManager::ensure_root() - same hardening, same reasoning.
	 *
	 * @return string|WP_Error Absolute path to the directory.
	 */
	public static function ensure_dir() {
		$root = self::root();

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'log-dir-failed', __( 'The log directory could not be created.', 'swift-image-optimizer' ) );
		}

		$htaccess = $root . '/.htaccess';

		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a protective file inside the plugin's own directory.
			@file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort hardening; failure is non-fatal.
		}

		$index = $root . '/index.php';

		if ( ! file_exists( $index ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a protective file inside the plugin's own directory.
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best-effort hardening; failure is non-fatal.
		}

		if ( ! wp_is_writable( $root ) ) {
			return new WP_Error( 'log-dir-unwritable', __( 'The log directory is not writable.', 'swift-image-optimizer' ) );
		}

		return $root;
	}

	/**
	 * Read the tail of the log.
	 *
	 * Reads from the end of the file rather than loading it whole, so a 10MB
	 * log costs the same to view as an empty one.
	 *
	 * @param int $lines How many lines to return.
	 * @return array {
	 *     @type string[] $lines    The lines, oldest first.
	 *     @type int      $size     Current file size in bytes.
	 *     @type bool     $rotated  Whether a rotated file also exists.
	 *     @type bool     $enabled  Whether verbose logging is on.
	 * }
	 */
	public static function tail( $lines = 500 ) {
		$lines = max( 1, min( 5000, (int) $lines ) );

		$out = array(
			'lines'   => array(),
			'size'    => 0,
			'rotated' => false,
			'enabled' => self::is_enabled(),
		);

		$file = self::file();

		if ( is_wp_error( $file ) || ! file_exists( $file ) ) {
			return $out;
		}

		clearstatcache( true, $file );

		$size           = (int) filesize( $file );
		$out['size']    = $size;
		$out['rotated'] = file_exists( $file . '.1' );

		if ( 0 === $size ) {
			return $out;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_get_contents_fopen -- Seeking backwards from the end is the point; WP_Filesystem cannot read a partial file.
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Handled by the false check.

		if ( ! $handle ) {
			return $out;
		}

		$chunk  = 8192;
		$buffer = '';
		$offset = $size;

		while ( $offset > 0 && substr_count( $buffer, "\n" ) <= $lines ) {
			$read   = (int) min( $chunk, $offset );
			$offset -= $read;

			fseek( $handle, $offset );
			$buffer = fread( $handle, $read ) . $buffer;
		}

		fclose( $handle );

		$all = preg_split( '/\R/', trim( $buffer ) );

		if ( ! is_array( $all ) ) {
			return $out;
		}

		$out['lines'] = array_slice( $all, -$lines );

		return $out;
	}

	/**
	 * Delete the log and its rotated generation.
	 *
	 * @return bool
	 */
	public static function clear() {
		$file = self::file();

		if ( is_wp_error( $file ) ) {
			return false;
		}

		foreach ( array( $file, $file . '.1' ) as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		return true;
	}

	/**
	 * Current size of the log on disk, both generations.
	 *
	 * @return int Bytes.
	 */
	public static function size() {
		$file = self::file();

		if ( is_wp_error( $file ) ) {
			return 0;
		}

		$total = 0;

		foreach ( array( $file, $file . '.1' ) as $path ) {
			if ( file_exists( $path ) ) {
				clearstatcache( true, $path );
				$total += (int) filesize( $path );
			}
		}

		return $total;
	}
}
