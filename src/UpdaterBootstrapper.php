<?php

namespace AvelPress\Update;

use AvelPress\Update\Contracts\UpdateProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the `updater` key of AvelPress::init() into a registered updater.
 *
 * Everything the updater needs is already known by the application: the plugin
 * file is {plugin_root}/{id}.php, and the version comes from its header. A
 * plugin only declares where to ask, and how to authenticate if the endpoint
 * requires it.
 *
 * The application is taken as a plain object on purpose. This package does not
 * depend on avelpress/avelpress, so plugins that only need the updater can use
 * it alone, and a prefixed build never has to rewrite a framework class name
 * inside this package.
 *
 * @since 1.0.0
 */
final class UpdaterBootstrapper {

	/**
	 * Registers the updater described by the application config.
	 *
	 * @param object               $app    AvelPress application; only getPluginFile() and getId() are used.
	 * @param UpdateProvider|array $config A provider, or HttpUpdateProvider options with an `endpoint`.
	 * @return PluginUpdater|null The registered updater, or null when there is nothing to register.
	 */
	public static function boot( $app, $config ) {
		$provider = self::provider( $config );

		if ( ! $provider ) {
			return null;
		}

		$file = $app->getPluginFile();

		if ( ! file_exists( $file ) ) {
			return null;
		}

		$header = get_file_data( $file, [ 'Version' => 'Version' ] );

		$updater = new PluginUpdater(
			$provider,
			plugin_basename( $file ),
			$app->getId(),
			isset( $header['Version'] ) ? $header['Version'] : ''
		);

		$updater->register();

		return $updater;
	}

	/**
	 * Resolves the provider from the config value.
	 *
	 * @param UpdateProvider|array $config Provider or HttpUpdateProvider options.
	 * @return UpdateProvider|null
	 */
	private static function provider( $config ) {
		if ( $config instanceof UpdateProvider ) {
			return $config;
		}

		if ( is_array( $config ) && ! empty( $config['endpoint'] ) ) {
			return new HttpUpdateProvider( $config['endpoint'], $config );
		}

		return null;
	}
}
