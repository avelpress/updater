# AvelPress Updater

Offers new versions of a WordPress plugin on the WordPress update screen, for
plugins that are distributed **outside wordpress.org** (sold or given away from
your own store).

It used to live inside `avelpress/avelpress` (1.3.0 to 1.3.2). It is a separate
package now, so plugins that do not need it do not ship it.

## Never ship it in a wordpress.org build

wordpress.org delivers updates itself and its guidelines forbid a plugin from
changing the update source. Code that hooks the update check gets a plugin
rejected in review. Plugins distributed through wordpress.org must **not**
require this package.

`avel build` (avelpress-cli 1.2.0 or later) enforces this: with
`'build' => ['wordpress_org' => true]` in `avelpress.config.php`, the build
fails if `avelpress/updater` is among the installed dependencies, if the plugin
still configures an `updater`, or if the installed `avelpress/avelpress` still
bundles it (1.3.0 to 1.3.2). Older CLI versions, up to 1.1.5, ignore
`wordpress_org` silently and check nothing.

## Installation

```bash
composer require avelpress/updater
```

The package has no dependency on `avelpress/avelpress`, and it works with or
without it.

Require it in the same change that moves the plugin to `avelpress/avelpress`
2.0. AvelPress 1.3.0 to 1.3.2 still carries its own copy of these classes, so
with both installed Composer reports an "Ambiguous class resolution" when it
optimizes the autoloader and loads whichever copy it finds first; a fix made in
this package can then be silently shadowed by the 1.3 copy. Treat core 1.3.x
plus this package as a transitional state only.

## Usage with AvelPress (2.0 or later)

Declare `updater` in `AvelPress::init()`, exactly as before the split:

```php
AvelPress::init( 'my-plugin', [
	'base_path' => __DIR__ . '/src',
	'updater'   => [
		// {slug} is replaced with the plugin id.
		'endpoint' => 'https://store.example.com/wp-json/v1/plugin/update',
	],
] );
```

An endpoint that only answers with a valid licence takes an `auth` callback.
Its data is sent as a JSON body, and the request becomes a POST:

```php
'updater' => [
	'endpoint' => 'https://store.example.com/wp-json/v1/plugin/update',
	'auth'     => function () {
		return [ 'license_key' => get_option( 'my_plugin_license' ) ];
	},
	'headers'  => [ 'X-Site' => home_url() ], // optional
	'timeout'  => 15,                         // optional, seconds
],
```

`updater` also accepts any object implementing
`AvelPress\Update\Contracts\UpdateProvider`, for a source that is not a plain
HTTP endpoint.

When the application boots, AvelPress hands the config to
`AvelPress\Update\UpdaterBootstrapper::boot()`. It reads the version from the
header of `{plugin_root}/{id}.php`, uses the plugin id as slug, and registers
the hooks. If `updater` is configured but this package is not installed,
AvelPress shows a notice to administrators instead of failing.

## Usage without AvelPress

```php
add_action( 'plugins_loaded', function () {
	$updater = new \AvelPress\Update\PluginUpdater(
		new \AvelPress\Update\HttpUpdateProvider( 'https://store.example.com/wp-json/v1/plugin/update' ),
		plugin_basename( __FILE__ ), // e.g. my-plugin/my-plugin.php
		'my-plugin',                 // slug
		'1.2.0'                      // installed version
	);

	$updater->register();
} );
```

## How it hooks into WordPress

`PluginUpdater::register()` adds two filters:

- `pre_set_site_transient_update_plugins` puts the plugin in the `response`
  list when the endpoint has a newer version than the one WordPress read from
  disk, or in `no_update` otherwise, with the `plugin`, `id` and `slug` keys
  core expects.
- `plugins_api` (priority 20) fills the "View details" modal.

The endpoint answer is cached in a site transient named
`avelpress_update_<md5(slug)>`: 6 hours on success, 15 minutes on failure.
"Check again" on the updates screen (`force-check`) skips the cache.

## Endpoint contract

Without `auth`, a GET with `?plugin_slug=<slug>`. With `auth`, a POST whose JSON
body is `plugin_slug` merged with the credentials. Anything other than a 200
with a JSON object counts as a failure (`WP_Error` code
`avelpress_update_failed`, with the endpoint's `message` when there is one).

The keys read from the answer:

| Key | Use |
|---|---|
| `new_version` | Required. Version offered. |
| `package` | ZIP WordPress downloads. Must be reachable without credentials. |
| `url` / `download_url` | Details link (falls back to the plugin URI header). |
| `requires`, `tested`, `requires_php`, `last_updated` | Shown in the details modal. |
| `sections` | Tabs of the details modal, e.g. `changelog`. |
| `banners`, `banners_rtl`, `icons` | Images of the details modal and update row. |

## Prefixed builds

`avel build` prefixes this package along with the rest of the vendor folder, so
two plugins shipping different versions of it do not collide. Keep references
to it in the form `\AvelPress\Update\...` or `use AvelPress\Update\...;` so the
prefixer finds them.

## Tests

```bash
composer test
```

## License

MIT
