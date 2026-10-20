<?php
/**
 * Icon collection registry.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns collection metadata, activation state, and icon discovery.
 */
class CollectionRegistry {
	const MAX_PAGE              = 10000;
	const MAX_REQUEST_MANIFESTS = 2;

	const OPTION_STATE_LOCK = 'icon_library_state_lock';
	const STATE_LOCK_TTL    = 30;

	/**
	 * Manifest loader.
	 *
	 * @var ManifestLoader
	 */
	private $manifest_loader;

	/**
	 * Custom icon repository.
	 *
	 * @var CustomIconRepository|null
	 */
	private $custom_icons;

	/**
	 * Legacy bundled discovery; null retains the standalone registry contract.
	 *
	 * @var array|null
	 */
	private $bundled_slugs;

	/**
	 * Current transaction-scoped collection-state lock.
	 *
	 * @var OptionMutationLock|null
	 */
	private $state_lock;

	/**
	 * Request-local collection summary cache.
	 *
	 * @var array<string,array>
	 */
	private $collection_summaries = array();

	/**
	 * Bounded request-local page cache. Never stores a complete matching catalog.
	 *
	 * @var array<string,array[]>
	 */
	private $filtered_icon_cache = array();

	/**
	 * Request-local provider manifests.
	 *
	 * @var array
	 */
	private $provider_manifests = array();

	/**
	 * Request-local icon name indexes.
	 *
	 * @var array
	 */
	private $name_indexes = array();

	/**
	 * Constructor.
	 *
	 * @param ManifestLoader            $manifest_loader Manifest loader.
	 * @param CustomIconRepository|null $custom_icons    Custom icon repository.
	 * @param array|null                $bundled_slugs   Bundled collections retained for an existing site.
	 */
	public function __construct( ManifestLoader $manifest_loader, ?CustomIconRepository $custom_icons = null, $bundled_slugs = null ) {
		$this->manifest_loader = $manifest_loader;
		$this->custom_icons    = $custom_icons;
		$this->bundled_slugs   = is_array( $bundled_slugs ) ? $this->normalize_keys( $bundled_slugs ) : null;
		foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_option' ) );
		}
		add_action( 'switch_blog', array( $this, 'switch_site' ), 10, 0 );
	}

	/** Reloads legacy availability for the destination site during a blog switch. */
	public function switch_site() {
		if ( null !== $this->bundled_slugs ) {
			$this->bundled_slugs = $this->normalize_keys( Plugin::legacy_collections( $this->manifest_loader ) );
		}
		$this->clear_request_caches();
	}

	/**
	 * Invalidates derived data after a library or custom-icon mutation.
	 *
	 * @param string $option Changed option name.
	 */
	public function invalidate_option( $option ) {
		if ( in_array( $option, array( Plugin::OPTION_ENABLED_COLLECTIONS, Plugin::OPTION_INSTALLED_COLLECTIONS, Plugin::OPTION_ENABLED_VARIANTS, CustomIconRepository::OPTION_ICONS ), true ) ) {
			$this->clear_request_caches();
		}
	}

	/**
	 * Returns all discovered collections.
	 *
	 * @return array[]
	 */
	public function get_collections() {
		$collections = array();
		$enabled     = $this->get_enabled_collection_slugs();
		$installed   = $this->get_installed_collection_slugs();

		foreach ( $this->get_available_collection_slugs() as $slug ) {
			$manifest = $this->get_metadata( $slug );

			if ( ! is_array( $manifest ) || $this->is_retained_collection( $slug, $manifest ) ) {
				continue;
			}

			if ( ! isset( $this->collection_summaries[ $slug ] ) ) {
				$this->collection_summaries[ $slug ]              = $this->prepare_collection_summary( $manifest, in_array( $slug, $enabled, true ) );
				$this->collection_summaries[ $slug ]['installed'] = in_array( $slug, $installed, true ) || ! in_array( $slug, $this->get_bundled_collection_slugs(), true );
			}
			$collections[ $slug ] = $this->collection_summaries[ $slug ];
		}

		/**
		 * Filters available icon collections.
		 *
		 * @param array[] $collections Collection summaries keyed by slug.
		 */
		$filtered = apply_filters( 'icon_library_collections', $collections );
		return is_array( $filtered ) ? $filtered : array();
	}

	/**
	 * Returns one collection summary.
	 *
	 * @param string $slug Collection slug.
	 * @return array|null
	 */
	public function get_collection( $slug ) {
		$slug     = is_string( $slug ) ? sanitize_key( $slug ) : '';
		$manifest = $this->get_metadata( $slug );
		if ( ! is_array( $manifest ) || $this->is_retained_collection( $slug, $manifest ) ) {
			return null;
		}

		if ( ! isset( $this->collection_summaries[ $slug ] ) ) {
			$this->collection_summaries[ $slug ]              = $this->prepare_collection_summary( $manifest, in_array( $slug, $this->get_enabled_collection_slugs(), true ) );
			$this->collection_summaries[ $slug ]['installed'] = in_array( $slug, $this->get_installed_collection_slugs(), true ) || ! in_array( $slug, $this->get_bundled_collection_slugs(), true );
		}
		$collections = apply_filters(
			'icon_library_collections',
			array(
				$slug => $this->collection_summaries[ $slug ],
			)
		);

		return is_array( $collections ) && isset( $collections[ $slug ] ) && is_array( $collections[ $slug ] ) ? $collections[ $slug ] : null;
	}

	/**
	 * Returns collection slugs owned by this plugin.
	 *
	 * @return string[]
	 */
	public function get_available_collection_slugs() {
		$slugs = null === $this->bundled_slugs ? $this->normalize_keys( $this->manifest_loader->get_collection_slugs() ) : $this->bundled_slugs;
		if ( $this->custom_icons && $this->custom_icons->get_manifest() ) {
			$slugs[] = CustomIconRepository::COLLECTION_SLUG;
		}
		foreach ( $this->get_provider_definitions() as $slug => $provider ) {
			if ( $this->is_valid_slug( $slug ) ) {
				$slugs[] = $slug;
			}
		}
		return array_values( array_unique( $this->normalize_keys( $slugs ) ) );
	}

	/**
	 * Returns one collection manifest.
	 *
	 * @param string $slug Collection slug.
	 * @return array|null
	 */
	public function get_manifest( $slug ) {
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';
		if ( CustomIconRepository::COLLECTION_SLUG === $slug && $this->custom_icons ) {
			return $this->custom_icons->get_manifest();
		}
		$providers = $this->get_provider_definitions();
		if ( isset( $providers[ $slug ] ) && is_array( $providers[ $slug ] ) && isset( $providers[ $slug ]['manifest'] ) ) {
			if ( array_key_exists( $slug, $this->provider_manifests ) ) {
				$cached = $this->provider_manifests[ $slug ];
				unset( $this->provider_manifests[ $slug ] );
				$this->provider_manifests[ $slug ] = $cached;
				return $cached;
			}
			if ( count( $this->provider_manifests ) >= self::MAX_REQUEST_MANIFESTS ) {
				$this->forget_collection_request_cache( array_key_first( $this->provider_manifests ) );
			}
			$manifest      = is_callable( $providers[ $slug ]['manifest'] ) ? call_user_func( $providers[ $slug ]['manifest'] ) : $providers[ $slug ]['manifest'];
			$manifest_slug = is_array( $manifest ) && is_string( $manifest['slug'] ?? null ) ? sanitize_key( $manifest['slug'] ) : '';
			if ( ! is_array( $manifest ) || 0 !== strcmp( $slug, $manifest_slug ) ) {
				$this->provider_manifests[ $slug ] = null;
				return null;
			}
			$manifest                          = apply_filters( 'icon_library_icon_manifest', $manifest, $slug, '' );
			$this->provider_manifests[ $slug ] = is_array( $manifest ) && ( $manifest['slug'] ?? null ) === $slug ? $manifest : null;
			return $this->provider_manifests[ $slug ];
		}
		return $this->can_use_bundled( $slug ) ? $this->manifest_loader->get_manifest( $slug ) : null;
	}

	/**
	 * Reads lightweight bundled metadata, preserving dynamic collection providers.
	 *
	 * @param string $slug Library slug.
	 * @return array|null
	 */
	private function get_metadata( $slug ) {
		$providers = $this->get_provider_definitions();
		if ( CustomIconRepository::COLLECTION_SLUG === $slug || isset( $providers[ $slug ] ) ) {
			return $this->get_manifest( $slug );
		}
		return $this->can_use_bundled( $slug ) ? $this->manifest_loader->get_metadata( $slug ) : null;
	}

	/**
	 * Resolves one collection SVG path.
	 *
	 * @param string $slug          Collection slug.
	 * @param string $relative_path Relative SVG path.
	 * @return string|null
	 */
	public function get_svg_path( $slug, $relative_path ) {
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';
		if ( ! is_string( $relative_path ) ) {
			return null;
		}
		if ( CustomIconRepository::COLLECTION_SLUG === $slug && $this->custom_icons ) {
			return $this->custom_icons->get_file_path( $relative_path );
		}
		$providers = $this->get_provider_definitions();
		if ( isset( $providers[ $slug ]['svg_path'] ) && is_callable( $providers[ $slug ]['svg_path'] ) ) {
			$path     = call_user_func( $providers[ $slug ]['svg_path'], $relative_path );
			$resolved = is_string( $path ) ? realpath( $path ) : false;
			return false !== $resolved && ! is_link( $path ) && is_file( $resolved ) && is_readable( $resolved ) && 'svg' === strtolower( pathinfo( $resolved, PATHINFO_EXTENSION ) ) ? $resolved : null;
		}
		return $this->can_use_bundled( $slug ) ? $this->manifest_loader->get_svg_path( $slug, $relative_path ) : null;
	}

	/**
	 * Reads one collection SVG.
	 *
	 * @param string $slug          Collection slug.
	 * @param string $relative_path Relative SVG path.
	 * @return string|null
	 */
	public function get_svg_content( $slug, $relative_path ) {
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';
		if ( ! is_string( $relative_path ) ) {
			return null;
		}
		if ( CustomIconRepository::COLLECTION_SLUG === $slug && $this->custom_icons ) {
			return $this->custom_icons->get_svg_content( $relative_path );
		}
		$providers = $this->get_provider_definitions();
		if ( isset( $providers[ $slug ]['svg_content'] ) && is_callable( $providers[ $slug ]['svg_content'] ) ) {
			$content = call_user_func( $providers[ $slug ]['svg_content'], $relative_path );
			return is_string( $content ) ? $content : null;
		}
		return $this->can_use_bundled( $slug ) ? $this->manifest_loader->get_svg_content( $slug, $relative_path ) : null;
	}

	/**
	 * Blocks direct requests from bypassing the fresh-site installed inventory.
	 *
	 * @param string $slug Bundled collection slug.
	 * @return bool
	 */
	private function can_use_bundled( $slug ) {
		return null === $this->bundled_slugs || in_array( $slug, $this->bundled_slugs, true );
	}

	/** Hides retained inventory only for this plugin's installed provider.
	 *
	 * @param string $slug Collection slug.
	 * @param mixed  $manifest Collection manifest.
	 * @return bool
	 */
	private function is_retained_collection( $slug, $manifest ) {
		if ( ! is_array( $manifest ) || 'retained' !== ( $manifest['version'] ?? '' ) ) {
			return false;
		}
		$providers = $this->get_provider_definitions();
		return 'aculect-icon-library-installed' === ( $providers[ $slug ]['owner'] ?? '' );
	}

	/** Returns bundled slugs not owned by an existing provider. */
	public function get_bundled_collection_slugs() {
		return array_values( array_diff( $this->manifest_loader->get_collection_slugs(), array_keys( $this->get_provider_definitions() ) ) );
	}

	/** Returns selected bundled libraries without writing inferred upgrade state. */
	public function get_installed_collection_slugs() {
		$bundled = $this->get_bundled_collection_slugs();
		$stored  = $this->read_state( Plugin::OPTION_INSTALLED_COLLECTIONS, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$enabled = $this->get_enabled_collection_slugs();
		return array_values( array_intersect( $bundled, $this->normalize_keys( array_merge( $stored, $enabled ) ) ) );
	}

	/** Selects or removes a local bundle; removal never deletes artwork.
	 *
	 * @param string $slug Bundle slug.
	 * @param bool   $installed Desired selection state.
	 * @return bool
	 */
	public function set_collection_installed( $slug, $installed ) {
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';
		if ( ! in_array( $slug, $this->get_bundled_collection_slugs(), true ) || null === $this->get_collection( $slug ) || ! $this->acquire_state_lock() ) {
			return false;
		}
		try {
			$selected = $this->get_installed_collection_slugs();
			$enabled  = $this->read_state( Plugin::OPTION_ENABLED_COLLECTIONS, array() );
			$enabled  = is_array( $enabled ) ? $enabled : array();
			if ( $installed ) {
				$selected[] = $slug;
			} else {
				$selected = array_diff( $selected, array( $slug ) );
				$enabled  = array_diff( $enabled, array( $slug ) );
			}
			$selected     = $this->normalize_keys( $selected );
			$old_selected = $this->read_state( Plugin::OPTION_INSTALLED_COLLECTIONS, array() );
			$old_enabled  = $this->read_state( Plugin::OPTION_ENABLED_COLLECTIONS, array() );
			if ( ! $installed && ! in_array( $slug, is_array( $old_selected ) ? $this->normalize_keys( $old_selected ) : array(), true ) && ! in_array( $slug, is_array( $old_enabled ) ? $this->normalize_keys( $old_enabled ) : array(), true ) && in_array( $slug, $this->get_enabled_collection_slugs(), true ) ) {
				return false;
			}
			if ( ! $installed && $old_enabled !== $enabled && ! $this->persist_state( Plugin::OPTION_ENABLED_COLLECTIONS, $enabled ) ) {
				return false;
			}
			if ( ! $this->persist_state( Plugin::OPTION_INSTALLED_COLLECTIONS, $selected ) ) {
				if ( ! $installed && $old_enabled !== $enabled ) {
					$this->persist_state( Plugin::OPTION_ENABLED_COLLECTIONS, $old_enabled );
				}
				return false;
			}
			if ( ! $installed && in_array( $slug, $this->get_installed_collection_slugs(), true ) ) {
				$this->persist_state( Plugin::OPTION_INSTALLED_COLLECTIONS, $old_selected );
				if ( $old_enabled !== $enabled ) {
					$this->persist_state( Plugin::OPTION_ENABLED_COLLECTIONS, $old_enabled );
				}
				$this->clear_request_caches();
				return false;
			}
			$this->clear_request_caches();
			return $this->state_lock->commit();
		} finally {
			$this->release_state_lock();
		}
	}

	/** Treats an unchanged option as a successful idempotent write.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value Option value.
	 * @return bool
	 */
	private function persist_state( $option, $value ) {
		return $this->state_lock && $this->state_lock->write( $option, $value );
	}

	/**
	 * Uses authoritative reads during mutations, including cached missing options.
	 *
	 * @param string $option Option name.
	 * @param mixed  $fallback Missing value.
	 * @return mixed
	 */
	private function read_state( $option, $fallback = false ) {
		return $this->state_lock ? $this->state_lock->read( $option, $fallback ) : get_option( $option, $fallback );
	}

	/**
	 * Returns enabled collection slugs.
	 *
	 * @return string[]
	 */
	public function get_enabled_collection_slugs() {
		$available = $this->get_available_collection_slugs();
		$enabled   = $this->read_state( Plugin::OPTION_ENABLED_COLLECTIONS, array() );

		if ( ! is_array( $enabled ) ) {
			$enabled = array();
		}

		$enabled = array_values(
			array_intersect(
				$this->normalize_keys( $enabled ),
				$available
			)
		);

		if ( in_array( CustomIconRepository::COLLECTION_SLUG, $available, true ) ) {
			$enabled[] = CustomIconRepository::COLLECTION_SLUG;
			$enabled   = array_values( array_unique( $enabled ) );
		}

		/**
		 * Filters enabled icon collections.
		 *
		 * @param string[] $enabled Enabled collection slugs.
		 */
		$filtered  = apply_filters( 'icon_library_enabled_collections', $enabled );
		$providers = $this->get_provider_definitions();
		return array_values(
			array_filter(
				array_intersect( $this->normalize_keys( $filtered ), $available ),
				static function ( $slug ) use ( $providers ) {
					return 'aculect-icon-library-installed' !== ( $providers[ $slug ]['owner'] ?? '' ) || empty( $providers[ $slug ]['retained'] );
				}
			)
		);
	}

	/**
	 * Updates collection activation state.
	 *
	 * @param string $slug    Collection slug.
	 * @param bool   $enabled Whether the collection should be enabled.
	 * @return bool
	 */
	public function set_collection_enabled( $slug, $enabled ) {
		$slug = is_string( $slug ) ? sanitize_key( $slug ) : '';
		if ( CustomIconRepository::COLLECTION_SLUG === $slug ) {
			return false;
		}

		if ( null === $this->get_collection( $slug ) ) {
			return false;
		}
		if ( ! $this->acquire_state_lock() ) {
			return false;
		}

		try {
			$enabled_slugs = $this->get_enabled_collection_slugs();
			$is_enabled    = in_array( $slug, $enabled_slugs, true );
			$is_bundled    = in_array( $slug, $this->get_bundled_collection_slugs(), true );
			$old_selected  = $this->read_state( Plugin::OPTION_INSTALLED_COLLECTIONS, array() );
			$was_stored    = is_array( $old_selected ) && in_array( $slug, $this->normalize_keys( $old_selected ), true );
			$needs_marker  = $is_bundled && ! $was_stored && ( $enabled || $is_enabled );
			if ( $needs_marker ) {
				$selected   = $this->get_installed_collection_slugs();
				$selected[] = $slug;
				if ( ! $this->persist_state( Plugin::OPTION_INSTALLED_COLLECTIONS, $this->normalize_keys( $selected ) ) ) {
					return false;
				}
			}
			if ( (bool) $enabled === $is_enabled ) {
				$this->clear_request_caches();
				return $this->state_lock->commit();
			}

			if ( $enabled ) {
				$enabled_slugs[] = $slug;
			} else {
				$enabled_slugs = array_diff( $enabled_slugs, array( $slug ) );
			}

			$enabled_slugs = $this->normalize_keys( $enabled_slugs );
			if ( $this->persist_state( Plugin::OPTION_ENABLED_COLLECTIONS, $enabled_slugs ) ) {
				$this->clear_request_caches();
				return $this->state_lock->commit();
			}
			if ( $needs_marker ) {
				$this->persist_state( Plugin::OPTION_INSTALLED_COLLECTIONS, $old_selected );
			}
			$stored  = $this->read_state( Plugin::OPTION_ENABLED_COLLECTIONS, array() );
			$success = is_array( $stored ) && ( in_array( $slug, $this->normalize_keys( $stored ), true ) === (bool) $enabled );
			if ( $success ) {
				$this->clear_request_caches();
			}
			return $success && $this->state_lock->commit();
		} finally {
			$this->release_state_lock();
		}
	}

	/**
	 * Returns enabled variants for a collection. Missing state enables the
	 * manifest variants marked as defaults for backwards compatibility.
	 *
	 * @param string $slug Collection slug.
	 * @return string[]
	 */
	public function get_enabled_variants( $slug ) {
		$slug     = is_string( $slug ) ? sanitize_key( $slug ) : '';
		$manifest = $this->get_metadata( $slug );
		if ( ! is_array( $manifest ) || empty( $manifest['variants'] ) || ! is_array( $manifest['variants'] ) ) {
			return array();
		}
		$variant_rows = array_values( array_filter( $manifest['variants'], 'is_array' ) );
		$available    = array_values(
			array_filter(
				array_map(
					static function ( $variant_slug ) {
						return is_string( $variant_slug ) ? sanitize_key( $variant_slug ) : '';
					},
					wp_list_pluck( $variant_rows, 'slug' )
				)
			)
		);
		$defaults     = array_values(
			array_filter(
				array_map(
					static function ( $variant ) {
						if ( ! is_array( $variant ) || empty( $variant['slug'] ) || false === ( $variant['defaultEnabled'] ?? true ) ) {
							return null;
						}
						return is_string( $variant['slug'] ) ? sanitize_key( $variant['slug'] ) : null;
					},
					$variant_rows
				)
			)
		);
		$state        = $this->read_state( Plugin::OPTION_ENABLED_VARIANTS, array() );
		if ( ! is_array( $state ) || ! array_key_exists( sanitize_key( $slug ), $state ) ) {
			$enabled = $defaults;
		} else {
			$enabled = is_array( $state[ sanitize_key( $slug ) ] ) ? $state[ sanitize_key( $slug ) ] : array();
			$enabled = array_values(
				array_filter(
					array_map(
						static function ( $variant_slug ) {
							return is_string( $variant_slug ) ? sanitize_key( $variant_slug ) : '';
						},
						$enabled
					)
				)
			);
			// The initial Heroicons importer called the 24px Solid style
			// `24-solid`. Keep that saved preference meaningful after the style
			// taxonomy moves to `solid`.
			if ( 'heroicons' === $slug ) {
				$enabled = array_map(
					static function ( $variant ) {
						return '24-solid' === $variant ? 'solid' : $variant;
					},
					$enabled
				);
			}
			$enabled = array_values( array_intersect( $available, $enabled ) );
		}

		/**
		 * Filters enabled variants for one collection.
		 *
		 * @param string[] $enabled Enabled variant slugs.
		 * @param string   $slug    Collection slug.
		 */
		$filtered = apply_filters( 'icon_library_enabled_variants', $enabled, $slug );
		return array_values( array_intersect( $this->normalize_keys( $filtered ), $available ) );
	}

	/**
	 * Updates one variant activation state.
	 *
	 * @param string $slug    Collection slug.
	 * @param string $variant Variant slug.
	 * @param bool   $enabled Desired state.
	 * @return bool
	 */
	public function set_variant_enabled( $slug, $variant, $enabled ) {
		$slug       = is_string( $slug ) ? sanitize_key( $slug ) : '';
		$variant    = is_string( $variant ) ? sanitize_key( $variant ) : '';
		$collection = $this->get_collection( $slug );
		if ( ! is_array( $collection ) || ! in_array( $slug, $this->get_enabled_collection_slugs(), true ) || ! isset( $collection['variants'] ) || ! is_array( $collection['variants'] ) ) {
			return false;
		}
		$variant_rows       = array_values( array_filter( $collection['variants'], 'is_array' ) );
		$available_variants = array_values(
			array_filter(
				array_map(
					static function ( $variant_slug ) {
						return is_string( $variant_slug ) ? sanitize_key( $variant_slug ) : '';
					},
					wp_list_pluck( $variant_rows, 'slug' )
				)
			)
		);
		if ( ! in_array( $variant, $available_variants, true ) ) {
			return false;
		}
		if ( ! $this->acquire_state_lock() ) {
			return false;
		}
		try {
			$state = $this->read_state( Plugin::OPTION_ENABLED_VARIANTS, array() );
			if ( ! is_array( $state ) ) {
				$state = array();
			}
			$state[ $slug ] = $this->get_enabled_variants( $slug );
			$is_enabled     = in_array( $variant, $state[ $slug ], true );
			if ( (bool) $enabled === $is_enabled ) {
				return $this->state_lock->commit();
			}
			if ( $enabled ) {
				$state[ $slug ][] = $variant;
			} else {
				$state[ $slug ] = array_diff( $state[ $slug ], array( $variant ) );
			}
			$state[ $slug ] = array_values( array_unique( $state[ $slug ] ) );
			if ( $this->persist_state( Plugin::OPTION_ENABLED_VARIANTS, $state ) ) {
				$this->clear_request_caches();
				return $this->state_lock->commit();
			}
			$stored  = $this->read_state( Plugin::OPTION_ENABLED_VARIANTS, array() );
			$success = isset( $stored[ $slug ] ) && is_array( $stored[ $slug ] ) && ( in_array( $variant, $this->normalize_keys( $stored[ $slug ] ), true ) === (bool) $enabled );
			if ( $success ) {
				$this->clear_request_caches();
			}
			return $success && $this->state_lock->commit();
		} finally {
			$this->release_state_lock();
		}
	}

	/**
	 * Returns one paginated icon query with totals and variant facets.
	 *
	 * @param array $args Query args.
	 * @return array{items:array,total:int,variant_counts:array}
	 */
	public function query_icons( $args = array() ) {
		$args      = $this->prepare_icon_query_args( $args );
		$cache_key = serialize( $args );
		if ( isset( $this->filtered_icon_cache[ $cache_key ] ) ) {
			return $this->filtered_icon_cache[ $cache_key ];
		}
		$variant               = $args['variant'];
		$facet_args            = $args;
		$facet_args['variant'] = '';
		$matching              = $this->get_filtered_icons( $facet_args );
		$counts                = array();
		$items                 = array();
		$total                 = 0;
		$offset                = ( $args['page'] - 1 ) * $args['per_page'];

		foreach ( $matching as $icon ) {
			$icon_variant = isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';
			if ( $icon_variant ) {
				$counts[ $icon_variant ] = ( $counts[ $icon_variant ] ?? 0 ) + 1;
			}
			if ( $variant && $icon_variant !== $variant ) {
				continue;
			}
			if ( $total >= $offset && count( $items ) < $args['per_page'] ) {
				$items[] = $icon;
			}
			++$total;
		}

		if ( count( $this->filtered_icon_cache ) >= 4 ) {
			array_shift( $this->filtered_icon_cache );
		}
		$this->filtered_icon_cache[ $cache_key ] = array(
			'items'          => $items,
			'total'          => $total,
			'variant_counts' => $counts,
		);
		return $this->filtered_icon_cache[ $cache_key ];
	}

	/**
	 * Returns icons matching query args.
	 *
	 * @param array $args Query args.
	 * @return array[]
	 */
	public function get_icons( $args = array() ) {
		$query = $this->query_icons( $args );
		return $query['items'];
	}

	/**
	 * Counts icons matching query args before pagination.
	 *
	 * @param array $args Query args.
	 * @return int
	 */
	public function count_icons( $args = array() ) {
		$query = $this->query_icons( $args );
		return $query['total'];
	}

	/**
	 * Counts matching icons grouped by variant.
	 *
	 * The variant filter is intentionally ignored so callers can render a
	 * variant selector whose counts reflect the other active filters.
	 *
	 * @param array $args Query args.
	 * @return int[] Counts keyed by variant slug.
	 */
	public function count_icons_by_variant( $args = array() ) {
		$query = $this->query_icons( $args );
		return $query['variant_counts'];
	}

	/**
	 * Counts icons by category using the same filters as the browser.
	 *
	 * @param array $args Query args without a category filter.
	 * @return int[]
	 */
	public function count_icons_by_category( $args = array() ) {
		$args['category'] = '';
		$counts           = array();
		foreach ( $this->get_filtered_icons( $this->prepare_icon_query_args( $args ) ) as $icon ) {
			foreach ( $this->normalize_keys( $icon['categories'] ?? array() ) as $category ) {
				if ( $category ) {
					$counts[ $category ] = ( $counts[ $category ] ?? 0 ) + 1;
				}
			}
		}
		return $counts;
	}

	/**
	 * Normalizes icon query arguments.
	 *
	 * @param array $args Query args.
	 * @return array
	 */
	private function prepare_icon_query_args( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'collection' => '',
				'variant'    => '',
				'category'   => '',
				'search'     => '',
				'enabled'    => null,
				'page'       => 1,
				'per_page'   => 100,
			)
		);

		$args['collection'] = is_string( $args['collection'] ) ? sanitize_key( $args['collection'] ) : '';
		$args['variant']    = is_string( $args['variant'] ) ? sanitize_key( $args['variant'] ) : '';
		$args['category']   = is_string( $args['category'] ) ? sanitize_key( $args['category'] ) : '';
		$args['search']     = $this->lowercase( is_string( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '' );
		$args['page']       = min( self::MAX_PAGE, max( 1, absint( $args['page'] ) ) );
		$args['per_page']   = min( 100, max( 1, absint( $args['per_page'] ) ) );

		return $args;
	}

	/**
	 * Returns all icons matching normalized query arguments.
	 *
	 * @param array $args Normalized query args.
	 * @return \Generator
	 */
	private function get_filtered_icons( $args ) {
		$collection_filter = $args['collection'];
		$variant_filter    = $args['variant'];
		$category_filter   = $args['category'];
		$search            = $args['search'];
		$enabled_slugs     = $this->get_enabled_collection_slugs();

		if ( true === $args['enabled'] && ( empty( $enabled_slugs ) || ( $collection_filter && ! in_array( $collection_filter, $enabled_slugs, true ) ) ) ) {
			return;
		}
		// Preserve the complete-map filter contract; bundled summaries contain no icon records.
		$collections = $collection_filter ? array_filter( array( $collection_filter => $this->get_collection( $collection_filter ) ) ) : $this->get_collections();
		foreach ( $collections as $collection_slug => $collection ) {
			if ( ! is_string( $collection_slug ) || ! is_array( $collection ) || ! isset( $collection['name'], $collection['enabled'] ) || ! is_string( $collection['name'] ) || ! is_bool( $collection['enabled'] ) ) {
				continue;
			}
			if ( $collection_filter && $collection_filter !== $collection_slug ) {
				continue;
			}

			if ( true === $args['enabled'] && ! in_array( $collection_slug, $enabled_slugs, true ) ) {
				continue;
			}

			if ( false === $args['enabled'] && in_array( $collection_slug, $enabled_slugs, true ) ) {
				continue;
			}

			$manifest         = $this->get_manifest( $collection_slug );
			$enabled_variants = true === $args['enabled'] ? $this->get_enabled_variants( $collection_slug ) : array();

			if ( empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				if ( ! is_array( $icon ) || empty( $icon['id'] ) || empty( $icon['coreIconName'] ) ) {
					continue;
				}
				if ( ! empty( $icon['archived'] ) ) {
					continue;
				}

				$icon_variant = is_string( $icon['variant'] ?? null ) ? sanitize_key( $icon['variant'] ) : '';
				if ( true === $args['enabled'] && $icon_variant && ! in_array( $icon_variant, $enabled_variants, true ) ) {
					continue;
				}
				if ( $variant_filter && $icon_variant !== $variant_filter ) {
					continue;
				}

				$categories = isset( $icon['categories'] ) && is_array( $icon['categories'] ) ? $icon['categories'] : array();
				if ( $category_filter && ! in_array( $category_filter, $this->normalize_keys( $categories ), true ) ) {
					continue;
				}

				if ( $search && ! $this->icon_matches_search( $icon, $search ) ) {
					continue;
				}

				yield array_merge(
					$icon,
					array(
						'collection'        => $collection_slug,
						'collectionLabel'   => $collection['name'],
						'collectionEnabled' => $collection['enabled'],
					)
				);
			}
		}
	}

	/**
	 * Finds a collection slug for a core icon name.
	 *
	 * @param string $core_icon_name Core icon registry name.
	 * @return string|null
	 */
	public function get_collection_slug_for_core_icon_name( $core_icon_name ) {
		if ( ! is_string( $core_icon_name ) ) {
			return null;
		}
		$separator = strpos( $core_icon_name, '/' );
		if ( false === $separator ) {
			return null;
		}
		$namespace = substr( $core_icon_name, 0, $separator );
		foreach ( $this->get_available_collection_slugs() as $collection_slug ) {
			if ( ! is_string( $collection_slug ) ) {
				continue;
			}
			if ( $namespace !== $collection_slug && 0 !== strpos( $namespace, $collection_slug . '-' ) ) {
				continue;
			}
			$manifest = $this->get_manifest( $collection_slug );

			if ( empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				if ( is_array( $icon ) && isset( $icon['coreIconName'] ) && is_string( $icon['coreIconName'] ) && $core_icon_name === $icon['coreIconName'] ) {
					return $collection_slug;
				}
			}
		}

		return null;
	}

	/**
	 * Finds one icon by its Core registry name.
	 *
	 * This lookup is intentionally manifest-backed so callers can validate an
	 * icon before the lazy Core registrar has been invoked. Disabled collections
	 * and variants are excluded by default because the result is intended for
	 * new content mutations.
	 *
	 * @param string $core_icon_name Core icon registry name.
	 * @param bool   $enabled_only  Whether to require an enabled collection and variant.
	 * @return array|null
	 */
	public function get_icon_by_core_name( $core_icon_name, $enabled_only = true ) {
		if ( ! is_string( $core_icon_name ) || 1 !== preg_match( '/^[a-z0-9-]+\/[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $core_icon_name ) ) {
			return null;
		}

		$enabled_collections = $enabled_only ? $this->get_enabled_collection_slugs() : array();
		$namespace           = strtok( $core_icon_name, '/' );
		foreach ( $this->get_available_collection_slugs() as $collection_slug ) {
			if ( ! is_string( $collection_slug ) || ( $enabled_only && ! in_array( $collection_slug, $enabled_collections, true ) ) ) {
				continue;
			}
			if ( $namespace !== $collection_slug && 0 !== strpos( $namespace, $collection_slug . '-' ) ) {
				continue;
			}

			if ( ! isset( $this->name_indexes[ $collection_slug ] ) ) {
				if ( count( $this->name_indexes ) >= self::MAX_REQUEST_MANIFESTS ) {
					unset( $this->name_indexes[ array_key_first( $this->name_indexes ) ] );
				}
				$this->name_indexes[ $collection_slug ] = IconNameIndex::build( $collection_slug, $this->get_manifest( $collection_slug ) );
			}
			$entry = $this->name_indexes[ $collection_slug ][ $core_icon_name ] ?? null;
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$enabled_variants = $enabled_only ? $this->get_enabled_variants( $collection_slug ) : array();
			foreach ( array( $entry['icon'] ) as $icon ) {
				if ( ! is_array( $icon ) || ( $enabled_only && ( ! empty( $icon['archived'] ) || ! empty( $entry['legacy'] ) ) ) ) {
					continue;
				}

				$variant = isset( $icon['variant'] ) && is_string( $icon['variant'] ) ? sanitize_key( $icon['variant'] ) : '';
				if ( $enabled_only && $variant && ! in_array( $variant, $enabled_variants, true ) ) {
					continue;
				}

				return array_merge(
					$icon,
					array(
						'collection'        => $collection_slug,
						'collectionEnabled' => in_array( $collection_slug, $this->get_enabled_collection_slugs(), true ),
						'coreIconName'      => $core_icon_name,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Checks if an icon matches a search term.
	 *
	 * @param array  $icon   Icon manifest row.
	 * @param string $search Lowercase search term.
	 * @return bool
	 */
	private function icon_matches_search( $icon, $search ) {
		$values = array(
			$icon['id'] ?? '',
			$icon['coreIconName'] ?? '',
			$icon['label'] ?? '',
			$icon['variant'] ?? '',
		);

		foreach ( array( 'keywords', 'categories' ) as $key ) {
			if ( isset( $icon[ $key ] ) && is_array( $icon[ $key ] ) ) {
				$values = array_merge( $values, $icon[ $key ] );
			}
		}

		$haystack = array();
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$haystack[] = (string) $value;
			}
		}

		return false !== strpos( $this->lowercase( implode( ' ', $haystack ) ), $search );
	}

	/**
	 * Lowercases searchable text with Unicode support when available.
	 *
	 * @param string $value Text value.
	 * @return string
	 */
	private function lowercase( $value ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
	}

	/**
	 * Normalizes a list of string keys without accepting nested values.
	 *
	 * @param mixed $values Candidate values.
	 * @return string[]
	 */
	private function normalize_keys( $values ) {
		$keys = array();
		foreach ( (array) $values as $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}
			$key = sanitize_key( $value );
			if ( '' !== $key ) {
				$keys[] = $key;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/** Releases one manifest and its lookup index without touching stored artwork.
	 *
	 * @param string $slug Collection slug.
	 */
	public function forget_collection_request_cache( $slug ) {
		unset( $this->provider_manifests[ $slug ], $this->name_indexes[ $slug ] );
	}

	/** Clears request-local derived data after a state mutation. */
	public function clear_request_caches() {
		$this->collection_summaries = array();
		$this->filtered_icon_cache  = array();
		$this->provider_manifests   = array();
		$this->name_indexes         = array();
	}

	/**
	 * Returns validated external collection providers.
	 *
	 * Providers may return a manifest and optional SVG callbacks. Callbacks must
	 * return content or a readable path; all output still passes Core/plugin
	 * sanitization at the registration boundary.
	 *
	 * @return array<string,array>
	 */
	private function get_provider_definitions() {
		$providers = apply_filters( 'icon_library_collection_providers', array() );
		return is_array( $providers ) ? $providers : array();
	}

	/**
	 * Checks a provider slug without exposing the loader's private validator.
	 *
	 * @param string $slug Provider slug.
	 * @return bool
	 */
	private function is_valid_slug( $slug ) {
		return is_string( $slug ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug );
	}

	/**
	 * Pins shared state until commit; recovery cannot steal an active transaction.
	 *
	 * @return bool
	 */
	private function acquire_state_lock() {
		if ( $this->state_lock ) {
			return false;
		}
		$lock = new OptionMutationLock( self::OPTION_STATE_LOCK );
		if ( ! $lock->acquire( self::STATE_LOCK_TTL ) ) {
			return false;
		}
		$this->state_lock = $lock;
		return true;
	}

	/**
	 * Releases the shared collection state lock.
	 *
	 * Uncommitted metadata changes are rolled back together.
	 */
	private function release_state_lock() {
		if ( $this->state_lock ) {
			$this->state_lock->release();
		}
		$this->state_lock = null;
	}

	/**
	 * Prepares collection summary data.
	 *
	 * @param array $manifest Manifest data.
	 * @param bool  $enabled  Whether the collection is enabled.
	 * @return array
	 */
	private function prepare_collection_summary( $manifest, $enabled ) {
		$icons          = isset( $manifest['icons'] ) && is_array( $manifest['icons'] ) ? array_values(
			array_filter(
				$manifest['icons'],
				static function ( $icon ) {
					return is_array( $icon ) && empty( $icon['archived'] );
				}
			)
		) : array();
		$variant_counts = array();
		foreach ( $icons as $icon ) {
			$variant_slug = is_string( $icon['variant'] ?? null ) ? sanitize_key( $icon['variant'] ) : '';
			if ( $variant_slug ) {
				$variant_counts[ $variant_slug ] = ( $variant_counts[ $variant_slug ] ?? 0 ) + 1;
			}
		}
		$variants = isset( $manifest['variants'] ) && is_array( $manifest['variants'] ) ? array_values( array_filter( $manifest['variants'], 'is_array' ) ) : array();
		if ( ! isset( $manifest['icons'] ) ) {
			foreach ( $variants as $variant ) {
				if ( is_string( $variant['slug'] ?? null ) ) {
					$variant_counts[ sanitize_key( $variant['slug'] ) ] = absint( $variant['iconCount'] ?? 0 );
				}
			}
		}
		$enabled_variants = $this->get_enabled_variants( is_string( $manifest['slug'] ?? null ) ? $manifest['slug'] : '' );
		$variants         = array_map(
			static function ( $variant ) use ( $enabled_variants, $variant_counts ) {
				$variant_slug         = is_string( $variant['slug'] ?? null ) ? sanitize_key( $variant['slug'] ) : '';
				$variant['iconCount'] = $variant_counts[ $variant_slug ] ?? 0;
				$variant['enabled']   = in_array( $variant_slug, $enabled_variants, true );
				return $variant;
			},
			$variants
		);

		return array(
			'slug'        => is_string( $manifest['slug'] ?? null ) ? sanitize_key( $manifest['slug'] ) : '',
			'name'        => is_string( $manifest['name'] ?? null ) ? sanitize_text_field( $manifest['name'] ) : '',
			'description' => is_string( $manifest['description'] ?? null ) ? sanitize_text_field( $manifest['description'] ) : '',
			'version'     => is_string( $manifest['version'] ?? null ) ? sanitize_text_field( $manifest['version'] ) : '',
			'license'     => $manifest['license'] ?? array(),
			'source'      => $manifest['source'] ?? array(),
			'variants'    => $variants,
			'categories'  => isset( $manifest['categories'] ) && is_array( $manifest['categories'] ) ? $manifest['categories'] : array(),
			'iconCount'   => isset( $manifest['icons'] ) ? count( $icons ) : absint( $manifest['iconCount'] ?? 0 ),
			'enabled'     => (bool) $enabled,
		);
	}
}
