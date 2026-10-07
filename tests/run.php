<?php
/**
 * Dependency-free test run: php tests/run.php
 *
 * Exits with 1 when any check fails.
 */

require __DIR__ . '/bootstrap.php';

use AvelPress\Update\Contracts\UpdateProvider;
use AvelPress\Update\HttpUpdateProvider;
use AvelPress\Update\PluginUpdater;
use AvelPress\Update\UpdaterBootstrapper;

$failures = 0;
$checks = 0;

function check( $condition, $label ) {
	global $failures, $checks;
	$checks++;
	if ( $condition ) {
		echo "ok   - $label\n";
		return;
	}
	$failures++;
	echo "FAIL - $label\n";
}

class FakeApp {
	public $file;
	public $id;

	public function __construct( $file, $id ) {
		$this->file = $file;
		$this->id = $id;
	}

	public function getPluginFile() {
		return $this->file;
	}

	public function getId() {
		return $this->id;
	}
}

class FixedProvider implements UpdateProvider {
	public $payload;
	public $calls = 0;

	public function __construct( $payload ) {
		$this->payload = $payload;
	}

	public function fetch( $slug ) {
		$this->calls++;
		return $this->payload;
	}
}

function hooks() {
	return array_map( function ( $filter ) {
		return $filter[0];
	}, $GLOBALS['wp_stub']['filters'] );
}

$pluginFile = WP_PLUGIN_DIR . '/acme-plugin/acme-plugin.php';
$app = new FakeApp( $pluginFile, 'acme-plugin' );

// Bootstrapper: endpoint array.
wp_stub_reset();
$updater = UpdaterBootstrapper::boot( $app, [ 'endpoint' => 'https://store.test/update' ] );
check( $updater instanceof PluginUpdater, 'boot() with an endpoint returns a PluginUpdater' );
check( hooks() === [ 'pre_set_site_transient_update_plugins', 'plugins_api' ], 'boot() hooks the update check and the details modal' );
check( $GLOBALS['wp_stub']['filters'][1][2] === 20 && $GLOBALS['wp_stub']['filters'][1][3] === 3, 'plugins_api runs at priority 20 with 3 arguments' );

// Bootstrapper: provider instance.
wp_stub_reset();
$provider = new FixedProvider( [ 'new_version' => '1.3.0' ] );
check( UpdaterBootstrapper::boot( $app, $provider ) instanceof PluginUpdater, 'boot() accepts an UpdateProvider instance' );

// Bootstrapper: nothing to register.
wp_stub_reset();
check( UpdaterBootstrapper::boot( $app, [ 'timeout' => 5 ] ) === null && hooks() === [], 'boot() ignores an array without endpoint' );
check( UpdaterBootstrapper::boot( $app, 'https://store.test' ) === null && hooks() === [], 'boot() ignores a value that is neither array nor provider' );
check( UpdaterBootstrapper::boot( new FakeApp( WP_PLUGIN_DIR . '/missing/missing.php', 'missing' ), [ 'endpoint' => 'x' ] ) === null && hooks() === [], 'boot() does nothing when the plugin file does not exist' );

// Version comes from the plugin header.
wp_stub_reset();
$provider = new FixedProvider( [ 'new_version' => '1.2.0', 'package' => 'https://store.test/acme.zip' ] );
$updater = UpdaterBootstrapper::boot( $app, $provider );
$transient = $updater->checkForUpdate( (object) [ 'checked' => [] ] );
check( isset( $transient->no_update['acme-plugin/acme-plugin.php'] ) && empty( $transient->response ), 'header version 1.2.0 is not offered 1.2.0' );

// Update offered, in the shape core expects.
wp_stub_reset();
$provider = new FixedProvider( [ 'new_version' => '1.3.0', 'package' => 'https://store.test/acme.zip', 'requires_php' => '7.4' ] );
$updater = new PluginUpdater( $provider, 'acme-plugin/acme-plugin.php', 'acme-plugin', '1.2.0' );
$transient = $updater->checkForUpdate( (object) [ 'checked' => [ 'acme-plugin/acme-plugin.php' => '1.2.0' ] ] );
$entry = isset( $transient->response['acme-plugin/acme-plugin.php'] ) ? $transient->response['acme-plugin/acme-plugin.php'] : null;
check( $entry && $entry->plugin === 'acme-plugin/acme-plugin.php' && $entry->id === 'acme-plugin' && $entry->slug === 'acme-plugin', 'update entry carries plugin, id and slug' );
check( $entry && $entry->package === 'https://store.test/acme.zip' && $entry->requires_php === '7.4', 'update entry carries package and requirements' );

// Compares with what core read from disk, not with the version in memory.
$transient = $updater->checkForUpdate( (object) [ 'checked' => [ 'acme-plugin/acme-plugin.php' => '1.3.0' ], 'response' => [ 'acme-plugin/acme-plugin.php' => $entry ] ] );
check( empty( $transient->response ) && isset( $transient->no_update['acme-plugin/acme-plugin.php'] ), 'just-installed version is not offered again' );

// Cached between checks, refreshed on force-check.
check( $provider->calls === 1, 'release payload is cached between checks' );
$_GET['force-check'] = '1';
$updater->checkForUpdate( (object) [ 'checked' => [] ] );
check( $provider->calls === 2, 'force-check bypasses the cache' );

// Failures are cached for a short time.
wp_stub_reset();
$failing = new FixedProvider( new WP_Error( 'down' ) );
$updater = new PluginUpdater( $failing, 'acme-plugin/acme-plugin.php', 'acme-plugin', '1.2.0' );
$transient = $updater->checkForUpdate( (object) [ 'checked' => [] ] );
$cached = $GLOBALS['wp_stub']['transients'][ 'avelpress_update_' . md5( 'acme-plugin' ) ];
check( $cached[0] === [] && $cached[1] === 15 * MINUTE_IN_SECONDS, 'a failed lookup is cached for 15 minutes' );
check( empty( $transient->response ), 'a failed lookup offers nothing' );

// Details modal.
wp_stub_reset();
$provider = new FixedProvider( [ 'new_version' => '1.3.0', 'package' => 'https://store.test/acme.zip', 'sections' => [ 'changelog' => 'x' ] ] );
$updater = new PluginUpdater( $provider, 'acme-plugin/acme-plugin.php', 'acme-plugin', '1.2.0' );
$info = $updater->updateInfo( false, 'plugin_information', (object) [ 'slug' => 'acme-plugin' ] );
check( is_object( $info ) && $info->name === 'Acme Plugin' && $info->download_link === 'https://store.test/acme.zip' && $info->homepage === 'https://acme.test/plugin', 'details modal is filled from the payload and the header' );
check( $updater->updateInfo( false, 'plugin_information', (object) [ 'slug' => 'other' ] ) === false, 'details modal ignores other plugins' );

// HTTP provider without credentials: GET with the slug in the query.
wp_stub_reset();
$GLOBALS['wp_stub']['response'] = [ 'code' => 200, 'body' => '{"new_version":"2.0.0"}' ];
$result = ( new HttpUpdateProvider( 'https://store.test/{slug}/update' ) )->fetch( 'acme-plugin' );
$request = $GLOBALS['wp_stub']['requests'][0];
check( $request[0] === 'GET' && $request[1] === 'https://store.test/acme-plugin/update?plugin_slug=acme-plugin', 'GET replaces {slug} and sends plugin_slug' );
check( $result === [ 'new_version' => '2.0.0' ], 'GET returns the decoded payload' );

// HTTP provider with credentials: POST with JSON body.
wp_stub_reset();
$provider = new HttpUpdateProvider( 'https://store.test/update', [
	'auth'    => function () {
		return [ 'license_key' => 'abc' ];
	},
	'headers' => [ 'X-Test' => '1' ],
	'timeout' => 5,
] );
$provider->fetch( 'acme-plugin' );
$request = $GLOBALS['wp_stub']['requests'][0];
check( $request[0] === 'POST' && json_decode( $request[2]['body'], true ) === [ 'plugin_slug' => 'acme-plugin', 'license_key' => 'abc' ], 'POST sends the slug merged with the credentials' );
check( $request[2]['headers']['Content-Type'] === 'application/json' && $request[2]['headers']['X-Test'] === '1' && $request[2]['timeout'] === 5, 'POST keeps headers and timeout' );

// HTTP provider: anything but a 200 with JSON is an error.
wp_stub_reset();
$GLOBALS['wp_stub']['response'] = [ 'code' => 403, 'body' => '{"message":"Invalid licence"}' ];
$result = ( new HttpUpdateProvider( 'https://store.test/update' ) )->fetch( 'acme-plugin' );
check( is_wp_error( $result ) && $result->get_error_code() === 'avelpress_update_failed' && $result->message === 'Invalid licence', 'non-200 becomes avelpress_update_failed with the endpoint message' );

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
