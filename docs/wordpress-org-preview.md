# WordPress.org Preview

The WordPress.org preview configuration is
`.wordpress-org/blueprints/blueprint.json`. The stable release workflow maps
`.wordpress-org` to the WordPress.org SVN `/assets` directory, where the
directory expects the configuration at `assets/blueprints/blueprint.json`.

The Blueprint uses the official [WordPress Playground Blueprint
schema](https://playground.wordpress.net/blueprint-schema.json). It starts
WordPress 7.1 with PHP 8.3, installs and activates the exact production ZIP
referenced in `pluginData.url`, enables Heroicons with its solid variant, and
creates a published page with a native `core/icon` block. It opens Appearance
> Icons after setup.

## Maintaining the preview

Before each stable release, update the production ZIP URL to that release's
exact version, for example:

```
https://downloads.wordpress.org/plugin/aculect-icon-library.1.0.1.zip
```

Keep the version in the URL aligned with the plugin header, release tag, and
published WordPress.org package. Do not use a branch archive, a GitHub
redirect, or an unversioned download URL: the preview must install a stable,
reproducible plugin artifact.

Validate the JSON locally before release:

```
php -r '$blueprint = json_decode( file_get_contents( ".wordpress-org/blueprints/blueprint.json" ), true ); if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $blueprint ) ) { fwrite( STDERR, json_last_error_msg() . PHP_EOL ); exit( 1 ); }'
```

Then test the configuration in [WordPress Playground](https://playground.wordpress.net/)
using the Blueprint file and confirm that the plugin activates, Appearance >
Icons loads, and the seeded page contains the native Icon block. The supported
`installPlugin`, `login`, and `runPHP` steps are documented in the
[WordPress Playground Blueprint step reference](https://wordpress.github.io/wordpress-playground/blueprints/steps/).

The file reaches WordPress.org only after a stable release deploys its
directory assets. A WordPress.org committer must then enable the preview in
the plugin directory's Advanced view. This repository configuration does not
publish or enable a public preview by itself.
