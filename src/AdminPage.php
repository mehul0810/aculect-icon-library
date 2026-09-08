<?php
/**
 * Admin page.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders Appearance > Icons.
 */
class AdminPage {
	const MENU_SLUG = 'icon-library';

	/**
	 * Collection registry.
	 *
	 * @var CollectionRegistry
	 */
	private $collection_registry;

	/**
	 * SVG sanitizer.
	 *
	 * @var SvgSanitizer
	 */
	private $svg_sanitizer;

	/**
	 * Constructor.
	 *
	 * @param CollectionRegistry $collection_registry Collection registry.
	 * @param SvgSanitizer       $svg_sanitizer       SVG sanitizer.
	 */
	public function __construct( CollectionRegistry $collection_registry, SvgSanitizer $svg_sanitizer ) {
		$this->collection_registry = $collection_registry;
		$this->svg_sanitizer       = $svg_sanitizer;
	}

	/**
	 * Registers admin hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_icon_library_preview_page', array( $this, 'preview_page' ) );
		add_action( 'load-appearance_page_' . self::MENU_SLUG, array( $this, 'suppress_unrelated_notices' ) );
	}

	/**
	 * Keeps notices from other plugins out of the application-style screen.
	 */
	public function suppress_unrelated_notices() {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Registers the Appearance submenu.
	 */
	public function register_menu() {
		add_theme_page(
			__( 'Icons', 'icon-library' ),
			__( 'Icons', 'icon-library' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueues screen-specific admin styles.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'appearance_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'icon-library-admin',
			ICON_LIBRARY_URL . 'assets/admin.css',
			array(),
			ICON_LIBRARY_VERSION
		);

		wp_enqueue_script(
			'icon-library-admin',
			ICON_LIBRARY_URL . 'assets/admin.js',
			array( 'wp-api-fetch' ),
			ICON_LIBRARY_VERSION,
			true
		);
		$dataviews_asset = require ICON_LIBRARY_DIR . 'assets/build/custom-icons-dataviews.asset.php';
		foreach ( $dataviews_asset['dependencies'] as $dependency ) {
			wp_enqueue_script( $dependency );
		}
		wp_enqueue_style( 'wp-components' );

		wp_localize_script(
			'icon-library-admin',
			'iconLibraryAdmin',
			array(
				'nonce'             => wp_create_nonce( 'wp_rest' ),
				'previewUrl'        => admin_url( 'admin-ajax.php' ),
				'previewNonce'      => wp_create_nonce( 'icon_library_preview' ),
				'restPath'          => '/' . Plugin::REST_NAMESPACE . '/collections/',
				'customPath'        => '/' . Plugin::REST_NAMESPACE . '/custom-icons',
				'dataViewsUrl'      => ICON_LIBRARY_URL . 'assets/build/custom-icons-dataviews.js?ver=' . rawurlencode( $dataviews_asset['version'] ),
				'dataViewsEnqueued' => 'custom' === $this->get_active_tab(),
				'i18n'              => array(
					'updating'       => __( 'Updating library...', 'icon-library' ),
					'error'          => __( 'The library could not be updated. Try again.', 'icon-library' ),
					'uploading'      => __( 'Validating and storing icon...', 'icon-library' ),
					'updated'        => __( 'Icon library updated.', 'icon-library' ),
					'deleteConfirm'  => __( 'Permanently delete this icon and its SVG file? Existing blocks using it will no longer render.', 'icon-library' ),
					'purgeConfirm'   => __( 'Permanently delete this archived icon and its SVG file? Existing blocks will no longer render it.', 'icon-library' ),
					'fileTooLarge'   => __( 'SVG files must be 64 KB or smaller.', 'icon-library' ),
					'loadingMore'    => __( 'Loading more icons...', 'icon-library' ),
					'loadMoreError'  => __( 'More icons could not be loaded. Try again.', 'icon-library' ),
					/* translators: 1: number of icons loaded, 2: total matching icons. */
					'loadMoreStatus' => __( 'Loaded %1$s of %2$s icons.', 'icon-library' ),
				),
			)
		);

		if ( 'custom' === $this->get_active_tab() ) {
			wp_enqueue_script(
				'icon-library-custom-dataviews',
				ICON_LIBRARY_URL . 'assets/build/custom-icons-dataviews.js',
				array_merge( array( 'icon-library-admin' ), $dataviews_asset['dependencies'] ),
				$dataviews_asset['version'],
				true
			);
		}
	}

	/**
	 * Renders the admin page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab  = $this->get_active_tab();
		$collections = 'custom' === $active_tab ? array() : $this->collection_registry->get_collections();
		$filters     = $this->get_filters( $collections );
		?>
		<div class="wrap icon-library-admin is-loading">
			<h1><?php esc_html_e( 'Icons', 'icon-library' ); ?></h1>
			<?php $this->render_tabs( $active_tab ); ?>
			<div class="icon-library-status" role="status" aria-live="polite"></div>
			<?php $this->render_notice(); ?>

			<?php if ( 'browse' === $active_tab ) : ?>
				<?php $this->render_install_tab( $filters, $collections ); ?>
			<?php elseif ( 'custom' === $active_tab ) : ?>
				<?php $this->render_custom_tab(); ?>
			<?php else : ?>
				<?php $this->render_library_tab( $collections ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renders status notice after a toggle.
	 */
	private function render_notice() {
		// This read-only query parameter controls feedback after a verified mutation.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['icon-library-updated'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$updated = absint( $_GET['icon-library-updated'] );
		$class   = $updated ? 'notice-success' : 'notice-error';
		$message = $updated
			? __( 'Library settings updated.', 'icon-library' )
			: __( 'Library settings could not be updated.', 'icon-library' );
		?>
		<div class="notice icon-library-notice <?php echo esc_attr( $class ); ?> is-dismissible" role="<?php echo $updated ? 'status' : 'alert'; ?>">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Renders the tab navigation.
	 *
	 * @param string $active_tab Active tab slug.
	 */
	private function render_tabs( $active_tab ) {
		$tabs = array(
			'library' => __( 'Library', 'icon-library' ),
			'custom'  => _x( 'Upload', 'noun', 'icon-library' ),
			'browse'  => __( 'Install Library', 'icon-library' ),
		);
		?>
		<nav class="icon-library-tabs" aria-label="<?php esc_attr_e( 'Icon management', 'icon-library' ); ?>">
			<?php foreach ( $tabs as $tab => $label ) : ?>
				<?php
				$url = add_query_arg(
					array(
						'page' => self::MENU_SLUG,
						'tab'  => $tab,
					),
					admin_url( 'themes.php' )
				);
				?>
				<a class="icon-library-tab <?php echo $active_tab === $tab ? 'is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>" <?php echo $active_tab === $tab ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Renders local custom icon management.
	 */
	private function render_custom_tab() {
		?>
		<section class="icon-library-panel icon-library-custom">
			<form class="icon-library-custom-upload" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'icon_library_upload_custom_icon' ); ?>
				<input type="hidden" name="action" value="icon_library_upload_custom_icon" />
				<h2 class="icon-library-custom-title"><?php esc_html_e( 'Upload custom icon', 'icon-library' ); ?></h2>
				<p class="icon-library-custom-description"><?php esc_html_e( 'Upload an SVG icon from your computer. Give it a name and label so you can easily find and use it in the Icon block.', 'icon-library' ); ?></p>
				<label class="icon-library-upload-area" for="icon-library-svg-upload">
					<span class="dashicons dashicons-upload" aria-hidden="true"></span>
					<span class="icon-library-upload-prompt"><?php esc_html_e( 'Drop an SVG here or browse', 'icon-library' ); ?></span>
					<span class="icon-library-upload-file-name" aria-live="polite"><?php esc_html_e( 'SVG files up to 64 KB', 'icon-library' ); ?></span>
					<input id="icon-library-svg-upload" class="screen-reader-text" name="svg" type="file" accept=".svg,image/svg+xml" aria-describedby="icon-library-upload-help" required />
				</label>
				<p id="icon-library-upload-help" class="screen-reader-text"><?php esc_html_e( 'Select an SVG file up to 64 KB.', 'icon-library' ); ?></p>
				<div class="icon-library-upload-details">
					<label><span><?php esc_html_e( 'Name', 'icon-library' ); ?></span><input name="name" type="text" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required placeholder="<?php esc_attr_e( 'my-icon', 'icon-library' ); ?>" /></label>
					<label><span><?php esc_html_e( 'Label', 'icon-library' ); ?></span><input name="label" type="text" required /></label>
				</div>
				<div class="icon-library-footer">
					<div class="icon-library-footer-actions">
						<div class="icon-library-upload-submit"><?php submit_button( __( 'Upload icon', 'icon-library' ), 'primary', 'submit', false ); ?></div>
					</div>
				</div>
			</form>

			<h2 class="icon-library-custom-heading"><?php esc_html_e( 'Uploaded icons', 'icon-library' ); ?></h2>
			<div id="icon-library-custom-dataviews"><p><?php esc_html_e( 'Loading uploaded icons...', 'icon-library' ); ?></p></div>
		</section>
		<?php
	}

	/**
	 * Renders the collection library tab.
	 *
	 * @param array[] $collections Collections.
	 */
	private function render_library_tab( $collections ) {
		// Read-only navigation state; no nonce is required.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_slug = isset( $_GET['collection'] ) && is_string( $_GET['collection'] ) ? sanitize_key( wp_unslash( $_GET['collection'] ) ) : '';

		if ( $selected_slug && isset( $collections[ $selected_slug ] ) && ! empty( $collections[ $selected_slug ]['enabled'] ) ) {
			$this->render_collection_detail( $collections[ $selected_slug ] );
			return;
		}

		$installed_collections = array_filter(
			$collections,
			static function ( $collection ) {
				return ! empty( $collection['enabled'] );
			}
		);
		$install_url           = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'browse',
			),
			admin_url( 'themes.php' )
		);

		?>
		<section class="icon-library-panel">
			<h2><?php esc_html_e( 'Installed Libraries', 'icon-library' ); ?></h2>
			<?php if ( empty( $installed_collections ) ) : ?>
				<div class="icon-library-empty-state">
					<p><?php esc_html_e( 'No icon libraries are installed.', 'icon-library' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( $install_url ); ?>"><?php esc_html_e( 'Install Library', 'icon-library' ); ?></a>
				</div>
			<?php else : ?>
				<div class="icon-library-collection-list">
					<?php foreach ( $installed_collections as $collection ) : ?>
						<?php $this->render_collection_row( $collection ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Renders available libraries and collection previews.
	 *
	 * @param array   $filters     Current filters.
	 * @param array[] $collections Collections.
	 */
	private function render_install_tab( $filters, $collections ) {
		$selected_slug = $filters['collection'];

		if ( $selected_slug && isset( $collections[ $selected_slug ] ) && CustomIconRepository::COLLECTION_SLUG !== $selected_slug ) {
			$this->render_install_collection_detail( $collections[ $selected_slug ], $filters );
			return;
		}
		?>
		<section class="icon-library-panel">
			<h2><?php esc_html_e( 'Available Libraries', 'icon-library' ); ?></h2>
			<div class="icon-library-collection-list">
				<?php foreach ( $collections as $collection ) : ?>
					<?php if ( CustomIconRepository::COLLECTION_SLUG !== $collection['slug'] ) : ?>
						<?php $this->render_available_collection_row( $collection ); ?>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders one available collection row.
	 *
	 * @param array $collection Collection summary.
	 */
	private function render_available_collection_row( $collection ) {
		$url = add_query_arg(
			array(
				'page'       => self::MENU_SLUG,
				'tab'        => 'browse',
				'collection' => $collection['slug'],
			),
			admin_url( 'themes.php' )
		);
		?>
		<a class="icon-library-collection-row" href="<?php echo esc_url( $url ); ?>">
			<div class="icon-library-collection-main">
				<h3><?php echo esc_html( $collection['name'] ); ?></h3>
			</div>
			<div class="icon-library-collection-actions">
				<span class="icon-library-variant-summary"><?php echo esc_html( ! empty( $collection['enabled'] ) ? __( 'Installed', 'icon-library' ) : __( 'Available', 'icon-library' ) ); ?></span>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			</div>
		</a>
		<?php
	}

	/**
	 * Renders a library preview and install action.
	 *
	 * @param array $collection Collection summary.
	 * @param array $filters    Current filters.
	 */
	private function render_install_collection_detail( $collection, $filters ) {
		// This read-only query parameter controls the current browser page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page       = isset( $_GET['icon-page'] ) && is_scalar( $_GET['icon-page'] ) ? min( CollectionRegistry::MAX_PAGE, max( 1, absint( $_GET['icon-page'] ) ) ) : 1;
		$query_args = array(
			'collection' => $collection['slug'],
			'variant'    => $filters['variant'],
			'category'   => $filters['category'],
			'search'     => $filters['search'],
			'page'       => $page,
			'per_page'   => 72,
		);
		$query      = $this->collection_registry->query_icons( $query_args );
		$total      = $query['total'];
		$icons      = $query['items'];
		$enabled    = ! empty( $collection['enabled'] );
		$back_url   = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'browse',
			),
			admin_url( 'themes.php' )
		);
		?>
		<section class="icon-library-panel icon-library-install-detail">
			<a class="icon-library-back" href="<?php echo esc_url( $back_url ); ?>">
				<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Install Library', 'icon-library' ); ?>
			</a>
			<h2><?php echo esc_html( $collection['name'] ); ?></h2>
			<?php $this->render_filters( $filters, array( $collection['slug'] => $collection ), $query['variant_counts'] ); ?>
			<?php $this->render_icon_grid( $icons ); ?>
			<?php $this->render_icon_pagination( $page, $total, 72, count( $icons ) ); ?>
			<div class="icon-library-footer">
				<div class="icon-library-footer-actions">
					<form class="icon-library-toggle" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-collection="<?php echo esc_attr( $collection['slug'] ); ?>" data-state="<?php echo esc_attr( $enabled ? 'deactivate' : 'activate' ); ?>">
						<?php wp_nonce_field( 'icon_library_toggle_collection' ); ?>
						<input type="hidden" name="action" value="icon_library_toggle_collection" />
						<input type="hidden" name="collection" value="<?php echo esc_attr( $collection['slug'] ); ?>" />
						<input type="hidden" name="state" value="<?php echo esc_attr( $enabled ? 'deactivate' : 'activate' ); ?>" />
						<button type="submit" class="button button-primary"><?php echo esc_html( $enabled ? __( 'Uninstall', 'icon-library' ) : __( 'Install', 'icon-library' ) ); ?></button>
					</form>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders pagination for a collection icon browser.
	 *
	 * @param int $current_page Current page.
	 * @param int $total        Matching icon count.
	 * @param int $per_page     Icons per page.
	 * @param int $loaded       Icons rendered on the current page.
	 */
	private function render_icon_pagination( $current_page, $total, $per_page, $loaded = 0 ) {
		$total_pages = (int) ceil( $total / $per_page );

		if ( $total_pages < 2 ) {
			return;
		}

		$base_url = remove_query_arg( 'icon-page' );
		$next_url = add_query_arg( 'icon-page', $current_page + 1, $base_url );
		$links    = paginate_links(
			array(
				'base'      => add_query_arg( 'icon-page', '%#%', $base_url ),
				'current'   => min( $current_page, $total_pages ),
				'total'     => $total_pages,
				'type'      => 'list',
				'prev_text' => __( 'Previous', 'icon-library' ),
				'next_text' => __( 'Next', 'icon-library' ),
			)
		);

		if ( $links ) {
			echo '<nav class="icon-library-pagination" aria-label="' . esc_attr__( 'Icon pages', 'icon-library' ) . '">' . wp_kses_post( $links ) . '</nav>';
		}

		if ( $current_page >= $total_pages ) {
			return;
		}

		?>
		<div class="icon-library-load-more" data-total="<?php echo esc_attr( $total ); ?>" data-loaded="<?php echo esc_attr( $loaded ); ?>">
			<button
				type="button"
				class="button button-secondary icon-library-load-more-button"
				aria-controls="icon-library-icon-grid"
				data-grid="icon-library-icon-grid"
				data-page="<?php echo esc_attr( $current_page ); ?>"
				data-total-pages="<?php echo esc_attr( $total_pages ); ?>"
				data-url="<?php echo esc_url( $next_url ); ?>"
			>
				<?php esc_html_e( 'Load more icons', 'icon-library' ); ?>
			</button>
				<span class="icon-library-load-more-status" role="status" aria-live="polite">
				<?php
				printf(
					/* translators: 1: number of icons loaded, 2: total matching icons. */
					esc_html__( 'Loaded %1$s of %2$s icons.', 'icon-library' ),
					esc_html( number_format_i18n( $loaded ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</span>
		</div>
		<?php
	}

	/**
	 * Renders one collection row.
	 *
	 * @param array $collection Collection summary.
	 */
	private function render_collection_row( $collection ) {
		$enabled      = ! empty( $collection['enabled'] );
		$is_custom    = CustomIconRepository::COLLECTION_SLUG === $collection['slug'];
		$variant_list = array();

		if ( ! empty( $collection['variants'] ) && is_array( $collection['variants'] ) ) {
			foreach ( $collection['variants'] as $variant ) {
				if ( isset( $variant['label'] ) ) {
					$variant_list[] = $variant['label'];
				}
			}
		}

		$variant_count = count( $variant_list );
		$active_count  = count(
			array_filter(
				$collection['variants'],
				static function ( $variant ) {
					return ! empty( $variant['enabled'] );
				}
			)
		);
		$url           = $is_custom
			? add_query_arg(
				array(
					'page' => self::MENU_SLUG,
					'tab'  => 'custom',
				),
				admin_url( 'themes.php' )
			)
			: add_query_arg(
				array(
					'page'       => self::MENU_SLUG,
					'tab'        => 'library',
					'collection' => $collection['slug'],
				),
				admin_url( 'themes.php' )
			);
		?>
		<a class="icon-library-collection-row" href="<?php echo esc_url( $url ); ?>">
			<div class="icon-library-collection-main">
				<h3><?php echo esc_html( $collection['name'] ); ?></h3>
			</div>
			<div class="icon-library-collection-actions">
				<span class="icon-library-variant-summary">
					<?php
					echo esc_html(
						$enabled
							? sprintf(
								/* translators: 1: active variant count, 2: total variant count. */
								__( '%1$d/%2$d variants active', 'icon-library' ),
								$active_count,
								$variant_count
							)
							: __( 'Inactive', 'icon-library' )
					);
					?>
				</span>
				<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
			</div>
		</a>
		<?php
	}

	/**
	 * Renders collection settings using the same list/detail pattern as Fonts.
	 *
	 * @param array $collection Collection summary.
	 */
	private function render_collection_detail( $collection ) {
		$enabled  = ! empty( $collection['enabled'] );
		$back_url = add_query_arg(
			array(
				'page' => self::MENU_SLUG,
				'tab'  => 'library',
			),
			admin_url( 'themes.php' )
		);
		?>
		<section class="icon-library-panel icon-library-collection-detail">
			<a class="icon-library-back" href="<?php echo esc_url( $back_url ); ?>">
				<span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
				<?php esc_html_e( 'Library', 'icon-library' ); ?>
			</a>
			<h2><?php echo esc_html( $collection['name'] ); ?></h2>
			<div class="icon-library-variant-list">
				<?php foreach ( $collection['variants'] as $variant ) : ?>
					<div class="icon-library-variant-row">
						<span>
							<?php echo esc_html( $variant['label'] ); ?>
							<?php if ( isset( $variant['iconCount'] ) ) : ?>
								<small class="icon-library-variant-count">
									<?php
									$count = absint( $variant['iconCount'] );
									echo esc_html(
										sprintf(
										/* translators: %s: number of icons in this variant. */
											_n( '%s icon', '%s icons', $count, 'icon-library' ),
											number_format_i18n( $count )
										)
									);
									?>
								</small>
							<?php endif; ?>
						</span>
						<span class="icon-library-variant-state">
							<?php echo esc_html( $enabled && ! empty( $variant['enabled'] ) ? __( 'Active', 'icon-library' ) : __( 'Inactive', 'icon-library' ) ); ?>
						</span>
						<?php if ( $enabled ) : ?>
							<form class="icon-library-toggle icon-library-variant-toggle" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-collection="<?php echo esc_attr( $collection['slug'] ); ?>" data-variant="<?php echo esc_attr( $variant['slug'] ); ?>" data-state="<?php echo esc_attr( ! empty( $variant['enabled'] ) ? 'deactivate' : 'activate' ); ?>">
								<?php wp_nonce_field( 'icon_library_toggle_variant' ); ?>
								<input type="hidden" name="action" value="icon_library_toggle_variant" />
								<input type="hidden" name="collection" value="<?php echo esc_attr( $collection['slug'] ); ?>" />
								<input type="hidden" name="variant" value="<?php echo esc_attr( $variant['slug'] ); ?>" />
								<input type="hidden" name="state" value="<?php echo esc_attr( ! empty( $variant['enabled'] ) ? 'deactivate' : 'activate' ); ?>" />
								<button type="submit" class="button button-secondary"><?php echo esc_html( ! empty( $variant['enabled'] ) ? __( 'Disable', 'icon-library' ) : __( 'Enable', 'icon-library' ) ); ?></button>
							</form>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<div class="icon-library-footer icon-library-collection-footer">
				<div class="icon-library-footer-actions">
					<form class="icon-library-toggle" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-collection="<?php echo esc_attr( $collection['slug'] ); ?>" data-state="<?php echo esc_attr( $enabled ? 'deactivate' : 'activate' ); ?>">
						<?php wp_nonce_field( 'icon_library_toggle_collection' ); ?>
						<input type="hidden" name="action" value="icon_library_toggle_collection" />
						<input type="hidden" name="collection" value="<?php echo esc_attr( $collection['slug'] ); ?>" />
						<input type="hidden" name="state" value="<?php echo esc_attr( $enabled ? 'deactivate' : 'activate' ); ?>" />
						<button type="submit" class="button button-primary"><?php echo esc_html( $enabled ? __( 'Uninstall', 'icon-library' ) : __( 'Install', 'icon-library' ) ); ?></button>
					</form>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Renders icon browser filters.
	 *
	 * @param array   $filters     Current filters.
	 * @param array[] $collections    Collections.
	 * @param int[]   $variant_counts Precomputed variant counts.
	 */
	private function render_filters( $filters, $collections, $variant_counts = null ) {
		$selected_collection = isset( $collections[ $filters['collection'] ] ) ? $collections[ $filters['collection'] ] : reset( $collections );
		$variants            = isset( $selected_collection['variants'] ) && is_array( $selected_collection['variants'] ) ? $selected_collection['variants'] : array();
		$categories          = $this->get_categories( $filters['collection'], $filters['variant'], $filters['search'] );
		if ( null === $variant_counts ) {
			$variant_counts = $this->collection_registry->count_icons_by_variant(
				array(
					'collection' => isset( $selected_collection['slug'] ) ? $selected_collection['slug'] : '',
					'category'   => $filters['category'],
					'search'     => $filters['search'],
				)
			);
		}
		?>
		<form class="icon-library-filters" method="get" action="<?php echo esc_url( admin_url( 'themes.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
			<input type="hidden" name="tab" value="browse" />
			<input type="hidden" name="collection" value="<?php echo esc_attr( $selected_collection['slug'] ); ?>" />
			<label>
				<span><?php esc_html_e( 'Category', 'icon-library' ); ?></span>
				<select name="category">
					<option value=""><?php esc_html_e( 'All categories', 'icon-library' ); ?></option>
					<?php foreach ( $categories as $category ) : ?>
						<?php
						$category_slug  = sanitize_key( $category['slug'] ?? '' );
						$category_label = ! empty( $category['label'] ) ? sanitize_text_field( $category['label'] ) : ucwords( str_replace( '-', ' ', $category_slug ) );
						$icon_count     = isset( $category['iconCount'] ) ? absint( $category['iconCount'] ) : 0;
						$option_label   = $icon_count ? sprintf( '%1$s (%2$s)', $category_label, number_format_i18n( $icon_count ) ) : $category_label;
						?>
						<?php if ( '' !== $category_slug ) : ?>
							<option value="<?php echo esc_attr( $category_slug ); ?>" <?php selected( $filters['category'], $category_slug ); ?>>
								<?php echo esc_html( $option_label ); ?>
							</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Variant', 'icon-library' ); ?></span>
				<select name="variant">
					<option value=""><?php esc_html_e( 'All variants', 'icon-library' ); ?></option>
					<?php foreach ( $variants as $variant ) : ?>
						<?php
						$variant_slug  = sanitize_key( $variant['slug'] ?? '' );
						$variant_label = ! empty( $variant['label'] ) ? sanitize_text_field( $variant['label'] ) : ucwords( str_replace( '-', ' ', $variant_slug ) );
						$icon_count    = isset( $variant_counts[ $variant_slug ] ) ? absint( $variant_counts[ $variant_slug ] ) : 0;
						$option_label  = sprintf( '%1$s (%2$s)', $variant_label, number_format_i18n( $icon_count ) );
						?>
						<?php if ( '' !== $variant_slug ) : ?>
						<option value="<?php echo esc_attr( $variant_slug ); ?>" <?php selected( $filters['variant'], $variant_slug ); ?>>
							<?php echo esc_html( $option_label ); ?>
						</option>
						<?php endif; ?>
					<?php endforeach; ?>
				</select>
			</label>
			<label>
				<span><?php esc_html_e( 'Search', 'icon-library' ); ?></span>
				<input type="search" name="search" value="<?php echo esc_attr( $filters['search'] ); ?>" />
			</label>
			<?php submit_button( __( 'Filter', 'icon-library' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Returns categories available for the current collection filter.
	 *
	 * @param string $collection_slug Selected collection slug.
	 * @param string $variant        Selected variant slug.
	 * @param string $search         Selected search text.
	 * @return array[]
	 */
	private function get_categories( $collection_slug, $variant = '', $search = '' ) {
		$categories = array();
		$slugs      = $collection_slug ? array( $collection_slug ) : $this->collection_registry->get_enabled_collection_slugs();
		$counts     = $this->collection_registry->count_icons_by_category(
			array(
				'collection' => $collection_slug,
				'variant'    => $variant,
				'search'     => $search,
			)
		);

		foreach ( $slugs as $slug ) {
			$manifest = $this->collection_registry->get_manifest( $slug );
			if ( ! is_array( $manifest ) ) {
				continue;
			}

			if ( ! empty( $manifest['categories'] ) && is_array( $manifest['categories'] ) ) {
				foreach ( $manifest['categories'] as $category ) {
					if ( ! is_array( $category ) || empty( $category['slug'] ) || empty( $category['label'] ) ) {
						continue;
					}

					$category_slug = sanitize_key( $category['slug'] );
					if ( '' === $category_slug ) {
						continue;
					}
					if ( ! isset( $categories[ $category_slug ] ) ) {
						$categories[ $category_slug ] = array(
							'slug'      => $category_slug,
							'label'     => sanitize_text_field( $category['label'] ),
							'iconCount' => 0,
						);
					}
					$categories[ $category_slug ]['iconCount'] = $counts[ $category_slug ] ?? 0;
				}
				continue;
			}

			if ( empty( $manifest['icons'] ) || ! is_array( $manifest['icons'] ) ) {
				continue;
			}

			foreach ( $manifest['icons'] as $icon ) {
				if ( ! empty( $icon['categories'] ) && is_array( $icon['categories'] ) ) {
					foreach ( $icon['categories'] as $category ) {
						$category_slug = sanitize_key( $category );
						if ( '' === $category_slug ) {
							continue;
						}
						if ( ! isset( $categories[ $category_slug ] ) ) {
							$categories[ $category_slug ] = array(
								'slug'      => $category_slug,
								'label'     => ucwords( str_replace( '-', ' ', $category_slug ) ),
								'iconCount' => 0,
							);
						}
						$categories[ $category_slug ]['iconCount'] = $counts[ $category_slug ] ?? 0;
					}
				}
			}
		}

		$categories = array_values( $categories );
		usort(
			$categories,
			static function ( $left, $right ) {
				return strnatcasecmp( (string) ( $left['label'] ?? '' ), (string) ( $right['label'] ?? '' ) );
			}
		);

		return $categories;
	}

	/** Sends only one preview grid, without rendering the admin shell or facets. */
	public function preview_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'icon_library_forbidden' ), 403 );
			return;
		}
		check_ajax_referer( 'icon_library_preview', '_ajax_nonce' );
		$filters = $this->get_filters( array() );
		if ( '' === $filters['collection'] ) {
			wp_send_json_error( array( 'code' => 'icon_library_invalid_collection' ), 400 );
			return;
		}
		$page  = isset( $_GET['icon-page'] ) && is_scalar( $_GET['icon-page'] ) ? min( CollectionRegistry::MAX_PAGE, max( 1, absint( $_GET['icon-page'] ) ) ) : 1;
		$query = $this->collection_registry->query_icons(
			array_merge(
				$filters,
				array(
					'page'     => $page,
					'per_page' => 72,
				)
			)
		);
		ob_start();
		$this->render_icon_grid( $query['items'] );
		$html = ob_get_clean();
		wp_send_json_success(
			array(
				'html'     => $html,
				'page'     => $page,
				'total'    => $query['total'],
				'has_more' => $page * 72 < $query['total'],
			)
		);
	}

	/**
	 * Renders preview icons.
	 *
	 * @param array[] $icons Icons.
	 */
	private function render_icon_grid( $icons ) {
		if ( empty( $icons ) ) {
			echo '<p>' . esc_html__( 'No icons match the current filters.', 'icon-library' ) . '</p>';
			return;
		}
		?>
		<div id="icon-library-icon-grid" class="icon-library-grid">
			<?php foreach ( $icons as $icon ) : ?>
				<?php
				$svg = $this->collection_registry->get_svg_content( $icon['collection'], $icon['path'] ?? '' );
				$svg = $this->svg_sanitizer->sanitize( $svg );
				?>
				<div class="icon-library-icon">
					<div class="icon-library-icon-preview" aria-hidden="true">
						<?php echo wp_kses( $svg, SvgSanitizer::get_allowed_svg_tags() ); ?>
					</div>
					<div class="icon-library-icon-label"><?php echo esc_html( $icon['label'] ); ?></div>
					<code><?php echo esc_html( $icon['coreIconName'] ); ?></code>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Reads and sanitizes current filters.
	 *
	 * @param array[] $collections Collections.
	 * @return array
	 */
	private function get_filters( $collections ) {
		unset( $collections );

		return array(
			// These read-only values filter escaped admin output and do not mutate state.
			// phpcs:disable WordPress.Security.NonceVerification.Recommended
			'collection' => isset( $_GET['collection'] ) && is_string( $_GET['collection'] ) ? sanitize_key( wp_unslash( $_GET['collection'] ) ) : '',
			'variant'    => isset( $_GET['variant'] ) && is_string( $_GET['variant'] ) ? sanitize_key( wp_unslash( $_GET['variant'] ) ) : '',
			'category'   => isset( $_GET['category'] ) && is_string( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '',
			'search'     => isset( $_GET['search'] ) && is_string( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '',
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		);
	}

	/**
	 * Returns the active page tab.
	 *
	 * @return string
	 */
	private function get_active_tab() {
		// Read-only navigation state; no nonce is required.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'library';

		return in_array( $tab, array( 'library', 'browse', 'custom' ), true ) ? $tab : 'library';
	}
}
