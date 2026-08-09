<?php
/**
 * Cross-request mutual exclusion.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A lock that two simultaneous requests cannot both acquire.
 *
 * Transients are the obvious choice and the wrong one: get_transient() followed
 * by set_transient() is check-then-set, so two requests arriving together both
 * see "unlocked" and both proceed. On a conversion that means two processes
 * renaming and deleting the same files.
 *
 * add_option() is used instead. The options table has a unique key on
 * option_name, so the insert either succeeds or it does not - the database
 * decides, and only one caller can win. The stored value is the acquisition
 * time, which is what allows a lock orphaned by a fatal error to be broken
 * rather than blocking the image for good.
 */
class Lock {

	/**
	 * How long before an unreleased lock is considered abandoned.
	 */
	const TTL = 300;

	/**
	 * Try to take a lock.
	 *
	 * @param string $name Lock name, already prefixed by the caller.
	 * @param int    $ttl  Seconds before the lock may be broken.
	 * @return bool True when this caller now holds it.
	 */
	public static function acquire( $name, $ttl = self::TTL ) {
		// autoload false: locks are short lived and must never be served from
		// the alloptions cache, which would hide another request's insert.
		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}

		$held_since = (int) get_option( $name, 0 );

		// A lock with no readable timestamp, or one older than its TTL, was
		// left behind by a process that died. Break it rather than leave the
		// image permanently unprocessable.
		if ( $held_since > 0 && ( time() - $held_since ) < $ttl ) {
			return false;
		}

		delete_option( $name );

		return (bool) add_option( $name, time(), '', false );
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function release( $name ) {
		delete_option( $name );
	}

	/**
	 * Whether a lock is currently held and still fresh.
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds before the lock is considered abandoned.
	 * @return bool
	 */
	public static function is_held( $name, $ttl = self::TTL ) {
		$held_since = (int) get_option( $name, 0 );

		return $held_since > 0 && ( time() - $held_since ) < $ttl;
	}
}
