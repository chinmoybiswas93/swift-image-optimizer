<?php
/**
 * Plugin settings: registration, defaults and sanitization.
 *
 * @package SwiftImageOptimizer
 */

namespace SwiftImageOptimizer\App\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for plugin options.
 */
class SettingsRepository {

	/**
	 * Option name holding the settings array.
	 */
	const OPTION = 'swift_image_optimizer_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'auto_optimize'       => 1,
			'quality'             => 82,
			'max_dimension'       => 2560,
			'engine'              => 'auto',
			'backup_retention'    => 30,
			'disable_wp_scaling'  => 0,
			'skip_if_larger'      => 1,
			'convert_png'         => 1,
			'rewrite_urls'        => 1,
			'enable_404_fallback' => 1,
			'backup_uploads'      => 1,
			'enable_log'          => 0,
		);
	}

	/**
	 * Retrieve all settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Retrieve a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Register the setting so it is available over REST.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			'swift_image_optimizer',
			self::OPTION,
			array(
				'type'              => 'object',
				'default'           => self::defaults(),
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'auto_optimize'       => array( 'type' => 'integer' ),
							'quality'             => array( 'type' => 'integer' ),
							'max_dimension'       => array( 'type' => 'integer' ),
							'engine'              => array( 'type' => 'string' ),
							'backup_retention'    => array( 'type' => 'integer' ),
							'disable_wp_scaling'  => array( 'type' => 'integer' ),
							'skip_if_larger'      => array( 'type' => 'integer' ),
							'convert_png'         => array( 'type' => 'integer' ),
							'rewrite_urls'        => array( 'type' => 'integer' ),
							'enable_404_fallback' => array( 'type' => 'integer' ),
							'backup_uploads'      => array( 'type' => 'integer' ),
							'enable_log'          => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Sanitize an incoming settings array.
	 *
	 * @param mixed $input Raw settings.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$out = array();

		$out['auto_optimize']       = empty( $input['auto_optimize'] ) ? 0 : 1;
		$out['disable_wp_scaling']  = empty( $input['disable_wp_scaling'] ) ? 0 : 1;
		$out['skip_if_larger']      = empty( $input['skip_if_larger'] ) ? 0 : 1;
		$out['convert_png']         = empty( $input['convert_png'] ) ? 0 : 1;
		$out['rewrite_urls']        = empty( $input['rewrite_urls'] ) ? 0 : 1;
		$out['enable_404_fallback'] = empty( $input['enable_404_fallback'] ) ? 0 : 1;
		$out['backup_uploads']      = empty( $input['backup_uploads'] ) ? 0 : 1;
		$out['enable_log']          = empty( $input['enable_log'] ) ? 0 : 1;

		$quality        = isset( $input['quality'] ) ? (int) $input['quality'] : $defaults['quality'];
		$out['quality'] = max( 1, min( 100, $quality ) );

		$max_dimension        = isset( $input['max_dimension'] ) ? (int) $input['max_dimension'] : $defaults['max_dimension'];
		$out['max_dimension'] = $max_dimension > 0 ? max( 100, min( 10000, $max_dimension ) ) : 0;

		$engine        = isset( $input['engine'] ) ? sanitize_key( $input['engine'] ) : 'auto';
		$out['engine'] = in_array( $engine, array( 'auto', 'imagick', 'cwebp', 'gd' ), true ) ? $engine : 'auto';

		$retention                = isset( $input['backup_retention'] ) ? (int) $input['backup_retention'] : $defaults['backup_retention'];
		$out['backup_retention']  = in_array( $retention, array( 0, 7, 30, 90 ), true ) ? $retention : 30;

		return $out;
	}
}
