<?php
/**
 * Just enough of WordPress to run the updater outside of it.
 *
 * Every stub records what it was asked so the tests can look at the calls a
 * real WordPress would have received.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_PLUGIN_DIR', __DIR__ . '/fixtures' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['wp_stub'] = [];

function wp_stub_reset() {
	$GLOBALS['wp_stub'] = [
		'filters'    => [],
		'transients' => [],
		'requests'   => [],
		'response'   => [ 'code' => 200, 'body' => '{}' ],
	];
	unset( $_GET['force-check'] );
}

class WP_Error {
	public $code;
	public $message;
	public $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['wp_stub']['filters'][] = [ $hook, $callback, $priority, $args ];
	return true;
}

function get_site_transient( $key ) {
	return isset( $GLOBALS['wp_stub']['transients'][ $key ] ) ? $GLOBALS['wp_stub']['transients'][ $key ][0] : false;
}

function set_site_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['wp_stub']['transients'][ $key ] = [ $value, $ttl ];
	return true;
}

function get_file_data( $file, $headers ) {
	$content = file_get_contents( $file );
	$data = [];
	foreach ( $headers as $field => $label ) {
		$data[ $field ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $content, $m ) ? trim( $m[1] ) : '';
	}
	return $data;
}

function get_plugin_data( $file ) {
	return get_file_data( $file, [ 'Name' => 'Plugin Name', 'Author' => 'Author', 'PluginURI' => 'Plugin URI' ] );
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function home_url() {
	return 'https://shop.test';
}

function add_query_arg( $key, $value, $url ) {
	return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $key . '=' . rawurlencode( $value );
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function wp_remote_get( $url, $args ) {
	$GLOBALS['wp_stub']['requests'][] = [ 'GET', $url, $args ];
	return $GLOBALS['wp_stub']['response'];
}

function wp_remote_post( $url, $args ) {
	$GLOBALS['wp_stub']['requests'][] = [ 'POST', $url, $args ];
	return $GLOBALS['wp_stub']['response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

spl_autoload_register( function ( $class ) {
	$prefix = 'AvelPress\\Update\\';
	if ( strpos( $class, $prefix ) !== 0 ) {
		return;
	}
	$file = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( file_exists( $file ) ) {
		require $file;
	}
} );
