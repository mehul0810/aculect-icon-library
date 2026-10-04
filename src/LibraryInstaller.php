<?php
/** Durable, pinned, versioned optional-library installation workflow.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/** Queues, claims, validates and atomically activates one library job at a time. */
class LibraryInstaller {
	const LEASE_SECONDS   = 600;
	const MAX_CAS_RETRIES = 5;
	/** Trusted catalog of pinned package releases.
	 *
	 * @var TrustedLibraryCatalog
	 */
	private $catalog;
	/** Immutable package repository.
	 *
	 * @var InstalledLibraryRepository
	 */
	private $repository;
	/** Durable per-library job state.
	 *
	 * @var LibraryJobStore
	 */
	private $jobs;
	/** Package archive validator.
	 *
	 * @var LibraryPackageValidator
	 */
	private $validator;
	/** Optional transport injection for isolated tests.
	 *
	 * @var callable|null
	 */
	private $transport;

	/** Creates an installer with its trusted dependencies.
	 *
	 * @param TrustedLibraryCatalog      $catalog Trusted release catalog.
	 * @param InstalledLibraryRepository $repository Immutable package repository.
	 * @param LibraryJobStore            $jobs Durable job store.
	 * @param LibraryPackageValidator    $validator Package validator.
	 * @param callable|null              $transport Optional test transport.
	 */
	public function __construct( TrustedLibraryCatalog $catalog, InstalledLibraryRepository $repository, LibraryJobStore $jobs, LibraryPackageValidator $validator, $transport = null ) {
		$this->catalog    = $catalog;
		$this->repository = $repository;
		$this->jobs       = $jobs;
		$this->validator  = $validator;
		$this->transport  = is_callable( $transport ) ? $transport : null;
	}

	/** Queues an idempotent install request without enabling the library.
	 *
	 * @param string $library_id Library slug.
	 * @param string $style_id Style slug.
	 * @param string $version Exact release version.
	 * @return array|WP_Error
	 */
	public function request_install( $library_id, $style_id, $version ) {
		$entry = $this->catalog->find( $library_id, $style_id, $version );
		if ( ! $entry ) {
			return new WP_Error( 'icon_library_release_untrusted', __( 'That exact library version is not in the trusted catalog.', 'aculect-icon-library' ), array( 'status' => 400 ) ); }
		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; ++$attempt ) {
			$read  = $this->jobs->read( $library_id );
			$state = $read ? $read['value'] : $this->empty_state();
			if ( isset( $state['job'] ) && is_array( $state['job'] ) && $state['job']['style_id'] === $style_id && $state['job']['release_version'] === $version && in_array( $state['job']['status'], array( 'queued', 'running' ), true ) ) {
				return $this->public_job( $state['job'] ); }
			if ( isset( $state['job']['status'] ) && 'running' === $state['job']['status'] && (int) $state['lease_expires'] > time() ) {
				return new WP_Error( 'icon_library_job_busy', __( 'Another library installation is already running.', 'aculect-icon-library' ) ); }
			$installed = $state['styles'][ $style_id ] ?? null;
			if ( is_array( $installed ) ) {
				$comparison = $this->catalog->compare_versions( $version, $installed['release_version'] );
				if ( false === $comparison || $comparison < 0 ) {
					return new WP_Error( 'icon_library_downgrade', __( 'A library version downgrade is not allowed.', 'aculect-icon-library' ) ); }
				if ( 0 === $comparison ) {
					if ( $entry['package_sha256'] !== $installed['package_sha256'] ) {
						return new WP_Error( 'icon_library_version_collision', __( 'The same release version cannot identify different package bytes.', 'aculect-icon-library' ) ); }
					return array(
						'job_id'          => $state['job']['job_id'] ?? '',
						'library_id'      => $library_id,
						'style_id'        => $style_id,
						'release_version' => $version,
						'status'          => 'succeeded',
						'generation'      => (int) ( $state['generation'] ?? 0 ),
						'progress'        => 100,
						'updated_at'      => $installed['installed_at'],
						'error'           => '',
					);
				}
			}
			$generation             = (int) ( $state['generation'] ?? 0 ) + 1;
			$job                    = array(
				'job_id'             => wp_generate_uuid4(),
				'library_id'         => $library_id,
				'style_id'           => $style_id,
				'release_version'    => $version,
				'release_descriptor' => $entry,
				'status'             => 'queued',
				'generation'         => $generation,
				'progress'           => 0,
				'updated_at'         => time(),
				'error'              => '',
			);
			$state['generation']    = $generation;
			$state['lease_token']   = '';
			$state['lease_expires'] = 0;
			$state['job']           = $job;
			if ( $this->jobs->compare_and_swap( $library_id, $read['raw'] ?? null, $state ) ) {
				return $this->public_job( $job ); }
		}
		return new WP_Error( 'icon_library_state_busy', __( 'Library state changed concurrently. Try again.', 'aculect-icon-library' ) );
	}

	/** Claims or resumes a queued/expired job, then performs its bounded attempt.
	 *
	 * @param string $library_id Library slug.
	 * @param string $job_id Job UUID.
	 * @return array|WP_Error
	 */
	public function run( $library_id, $job_id ) {
		$claim = $this->claim( $library_id, $job_id );
		if ( is_wp_error( $claim ) ) {
			return $claim; }
		// REST requests do not load the administrative file helpers by default.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		list( $generation, $token, $entry ) = $claim;
		$tmp                                = wp_tempnam( 'icon-library-package-' );
		if ( ! $tmp ) {
			return $this->fail_job( $library_id, $job_id, $generation, $token, __( 'A temporary package file could not be created.', 'aculect-icon-library' ) ); }
		try {
			$download = $this->download( $entry, $tmp );
			if ( is_wp_error( $download ) ) {
				return $this->fail_job( $library_id, $job_id, $generation, $token, $download->get_error_message() ); }
			if ( ! is_file( $tmp ) || filesize( $tmp ) !== $entry['package_bytes'] ) {
				return $this->fail_job( $library_id, $job_id, $generation, $token, __( 'The package download was incomplete.', 'aculect-icon-library' ) ); }
			if ( ! $this->progress( $library_id, $job_id, $generation, $token, 45 ) ) {
				return new WP_Error( 'icon_library_job_stale', __( 'This installation attempt was superseded.', 'aculect-icon-library' ) ); }
			$extract_dir = $tmp . '.extract';
			if ( ! wp_mkdir_p( $extract_dir ) ) {
				return $this->fail_job( $library_id, $job_id, $generation, $token, __( 'A private package staging directory could not be created.', 'aculect-icon-library' ) ); }
			$validated = $this->validator->validate( $tmp, $entry, $extract_dir );
			if ( is_wp_error( $validated ) ) {
				return $this->fail_job( $library_id, $job_id, $generation, $token, $validated->get_error_message() ); }
			$identity_error = $this->validate_identities( $library_id, $entry, $validated['manifest'] );
			if ( is_wp_error( $identity_error ) ) {
				return $this->fail_job( $library_id, $job_id, $generation, $token, $identity_error->get_error_message() ); }
			if ( ! $this->progress( $library_id, $job_id, $generation, $token, 80 ) ) {
				return new WP_Error( 'icon_library_job_stale', __( 'This installation attempt was superseded.', 'aculect-icon-library' ) ); }
			$directory = $this->repository->publish( $library_id, $entry['style_id'], $entry['release_version'], $entry['package_sha256'], $job_id, $extract_dir, $validated['members'] );
			if ( is_wp_error( $directory ) ) {
				return $this->fail_job( $library_id, $job_id, $generation, $token, $directory->get_error_message() ); }
			return $this->commit( $library_id, $job_id, $generation, $token, $entry, $validated['manifest'] );
		} finally {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp ); }
			if ( isset( $extract_dir ) && is_dir( $extract_dir ) && ! is_link( $extract_dir ) ) {
				$this->remove_temporary_directory( $extract_dir ); }
		}
	}

	/** Returns the current public job status for one library.
	 *
	 * @param string $library_id Library slug.
	 * @return array|null
	 */
	public function get_job( $library_id ) {
		$state = $this->jobs->read( $library_id );
		return $state && is_array( $state['value']['job'] ?? null ) ? $this->public_job( $state['value']['job'] ) : null;
	}

	/** Returns installed state from the authoritative job store.
	 *
	 * @param string $library_id Library slug.
	 * @return array|null
	 */
	public function get_installed( $library_id ) {
		return $this->repository->get_installed( $library_id ); }
	/** Returns current catalog releases and descriptors pinned by active jobs.
	 *
	 * @return array
	 */
	public function get_entries() {
		$entries = array();
		foreach ( $this->catalog->get_entries() as $entry ) {
			$entry['catalog_available'] = true;
			$entries[ $entry['library_id'] . '/' . $entry['style_id'] . '/' . $entry['release_version'] ] = $entry;
		}
		foreach ( $this->jobs->all_library_ids() as $library_id ) {
			$read  = $this->jobs->read( $library_id );
			$job   = $read['value']['job'] ?? null;
			$entry = is_array( $job ) ? ( $job['release_descriptor'] ?? null ) : null;
			if ( ! is_array( $entry ) || ! $this->valid_pinned_descriptor( $entry ) ) {
				continue; }
			$key = $entry['library_id'] . '/' . $entry['style_id'] . '/' . $entry['release_version'];
			if ( ! isset( $entries[ $key ] ) ) {
				$entry['catalog_available'] = false;
				$entries[ $key ]            = $entry; }
		}
		return array_values( $entries );
	}

	/** Acquires or resumes the matching job's lease.
	 *
	 * @param string $library_id Library slug.
	 * @param string $job_id Job UUID.
	 * @return array|WP_Error
	 */
	private function claim( $library_id, $job_id ) {
		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; ++$attempt ) {
			$read = $this->jobs->read( $library_id );
			if ( ! $read || ! is_array( $read['value']['job'] ?? null ) || $read['value']['job']['job_id'] !== $job_id ) {
				return new WP_Error( 'icon_library_job_missing', __( 'The installation job was not found.', 'aculect-icon-library' ) ); }
			$state = $read['value'];
			if ( 'succeeded' === $state['job']['status'] ) {
				return new WP_Error( 'icon_library_job_complete', __( 'This library version is already installed.', 'aculect-icon-library' ) ); }
			if ( 'running' === $state['job']['status'] && (int) $state['lease_expires'] > time() ) {
				return new WP_Error( 'icon_library_job_busy', __( 'This installation is already running.', 'aculect-icon-library' ) ); }
			if ( ! in_array( $state['job']['status'], array( 'queued', 'running', 'failed' ), true ) ) {
				return new WP_Error( 'icon_library_job_invalid', __( 'This installation job cannot be resumed.', 'aculect-icon-library' ) ); }
			$entry = $state['job']['release_descriptor'] ?? null;
			if ( ! is_array( $entry ) || ( $entry['library_id'] ?? null ) !== $library_id || ( $entry['style_id'] ?? null ) !== $state['job']['style_id'] || ( $entry['release_version'] ?? null ) !== $state['job']['release_version'] || ! $this->valid_pinned_descriptor( $entry ) ) {
				return new WP_Error( 'icon_library_release_untrusted', __( 'The pinned library release descriptor is invalid.', 'aculect-icon-library' ) );
			}
			$generation                 = (int) $state['generation'] + 1;
			$token                      = wp_generate_password( 40, false, false );
			$state['generation']        = $generation;
			$state['lease_token']       = $token;
			$state['lease_expires']     = time() + self::LEASE_SECONDS;
			$state['job']['generation'] = $generation;
			$state['job']['status']     = 'running';
			$state['job']['progress']   = 10;
			$state['job']['updated_at'] = time();
			$state['job']['error']      = '';
			if ( $this->jobs->compare_and_swap( $library_id, $read['raw'], $state ) ) {
				return array( $generation, $token, $entry ); }
		}
		return new WP_Error( 'icon_library_state_busy', __( 'Library state changed concurrently. Try again.', 'aculect-icon-library' ) );
	}

	/** Downloads a release while enforcing redirects, size, and pinned host.
	 *
	 * @param array  $entry Trusted release descriptor.
	 * @param string $target_path Temporary target file.
	 * @return true|WP_Error
	 */
	private function download( $entry, $target_path ) {
		$url  = $entry['url'];
		$seen = array();
		for ( $redirect = 0; $redirect <= 3; ++$redirect ) {
			if ( ! $this->is_safe_release_url( $url, $entry ) || isset( $seen[ $url ] ) ) {
				return new WP_Error( 'icon_library_download_redirect', __( 'The package release redirected to an untrusted location.', 'aculect-icon-library' ) ); }
			$seen[ $url ] = true;
			$response     = $this->fetch_release_url( $url, $entry, $target_path );
			if ( is_wp_error( $response ) ) {
				return $response; }
			$status = isset( $response['status'] ) ? (int) $response['status'] : 0;
			if ( 200 === $status ) {
				return is_file( $target_path ) ? true : new WP_Error( 'icon_library_download', __( 'The package release returned no package bytes.', 'aculect-icon-library' ) ); }
			if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) || 3 === $redirect ) {
				return new WP_Error( 'icon_library_download', __( 'The trusted package release could not be downloaded.', 'aculect-icon-library' ) ); }
			$location = $response['location'] ?? '';
			if ( ! is_string( $location ) || strlen( $location ) > 8192 || ! $this->is_safe_release_url( $location, $entry ) ) {
				return new WP_Error( 'icon_library_download_redirect', __( 'The package release redirected to an untrusted location.', 'aculect-icon-library' ) ); }
			$url = $location;
		}
		return new WP_Error( 'icon_library_download', __( 'The package redirect limit was exceeded.', 'aculect-icon-library' ) );
	}

	/** Fetches one allowed release URL into the bounded temporary file.
	 *
	 * @param string $url Release URL.
	 * @param array  $entry Trusted descriptor.
	 * @param string $target_path Temporary target file.
	 * @return array|WP_Error
	 */
	private function fetch_release_url( $url, $entry, $target_path ) {
		$options = array(
			'timeout'             => 25,
			'redirection'         => 0,
			'limit_response_size' => LibraryPackageValidator::MAX_ARCHIVE,
			'sslverify'           => true,
			'stream'              => true,
			'filename'            => $target_path,
		);
		if ( $this->transport ) {
			$response = call_user_func( $this->transport, $url, $options, $entry );
			if ( is_string( $response ) ) {
				$copied = is_file( $response ) ? copy( $response, $target_path ) : ( strlen( $response ) === file_put_contents( $target_path, $response, LOCK_EX ) );
				return $copied ? array( 'status' => 200 ) : new WP_Error( 'icon_library_download', __( 'The package download failed.', 'aculect-icon-library' ) );
			}
			if ( ! is_array( $response ) ) {
				return new WP_Error( 'icon_library_download', __( 'The package download failed.', 'aculect-icon-library' ) ); }
			if ( isset( $response['body'] ) && is_string( $response['body'] ) && '' !== $response['body'] && ( ! is_file( $target_path ) || 0 === filesize( $target_path ) ) ) {
				file_put_contents( $target_path, $response['body'], LOCK_EX ); }
			return $response;
		}
		if ( ! function_exists( 'wp_safe_remote_get' ) ) {
			return new WP_Error( 'icon_library_download', __( 'The package transport is unavailable.', 'aculect-icon-library' ) ); }
		if ( file_exists( $target_path ) && 0 !== filesize( $target_path ) ) {
			file_put_contents( $target_path, '' ); }
		$response = wp_safe_remote_get( $url, $options );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'icon_library_download', __( 'The trusted package release could not be downloaded.', 'aculect-icon-library' ) ); }
		return array(
			'status'   => (int) wp_remote_retrieve_response_code( $response ),
			'location' => wp_remote_retrieve_header( $response, 'location' ),
			'body'     => '',
		);
	}

	/** Removes a private temporary extraction tree without following links.
	 *
	 * @param string $directory Temporary directory.
	 */
	private function remove_temporary_directory( $directory ) {
		$items = scandir( $directory );
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue; }
			$path = $directory . DIRECTORY_SEPARATOR . $item;
			if ( is_link( $path ) || is_file( $path ) ) {
				wp_delete_file( $path ); } elseif ( is_dir( $path ) ) {
				$this->remove_temporary_directory( $path ); }
		}
		rmdir( $directory );
	}

	/** Checks a URL against the exact pinned release host and path.
	 *
	 * @param string $url Candidate URL.
	 * @param array  $entry Trusted release descriptor.
	 * @return bool
	 */
	private function is_safe_release_url( $url, $entry ) {
		if ( ! is_string( $url ) || strlen( $url ) > 8192 ) {
			return false; }
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return false; }
		$host = strtolower( $parts['host'] ?? '' );
		if ( 'github.com' === $host ) {
			return $url === $entry['url']; }
		return in_array( $host, array( 'release-assets.githubusercontent.com', 'objects.githubusercontent.com' ), true ) && ! empty( $parts['path'] );
	}

	/** Persists progress while the caller still owns the lease.
	 *
	 * @param string $library_id Library slug.
	 * @param string $job_id Job UUID.
	 * @param int    $generation Lease generation.
	 * @param string $token Lease token.
	 * @param int    $progress Progress percentage.
	 * @return bool
	 */
	private function progress( $library_id, $job_id, $generation, $token, $progress ) {
		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; ++$attempt ) {
			$read = $this->jobs->read( $library_id );
			if ( ! $this->owns_lease( $read, $job_id, $generation, $token ) ) {
				return false; }
			$state                      = $read['value'];
			$state['job']['progress']   = $progress;
			$state['job']['updated_at'] = time();
			if ( $this->jobs->compare_and_swap( $library_id, $read['raw'], $state ) ) {
				return true; }
		}
		return false;
	}

	/** Marks the owned job failed while preserving any active installation.
	 *
	 * @param string $library_id Library slug.
	 * @param string $job_id Job UUID.
	 * @param int    $generation Lease generation.
	 * @param string $token Lease token.
	 * @param string $message Failure message.
	 * @return WP_Error
	 */
	private function fail_job( $library_id, $job_id, $generation, $token, $message ) {
		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; ++$attempt ) {
			$read = $this->jobs->read( $library_id );
			if ( ! $this->owns_lease( $read, $job_id, $generation, $token ) ) {
				return new WP_Error( 'icon_library_job_stale', __( 'This installation attempt was superseded.', 'aculect-icon-library' ) ); }
			$state                      = $read['value'];
			$state['job']['status']     = 'failed';
			$state['job']['progress']   = 0;
			$state['job']['updated_at'] = time();
			$state['job']['error']      = sanitize_text_field( $message );
			$state['lease_token']       = '';
			$state['lease_expires']     = 0;
			if ( $this->jobs->compare_and_swap( $library_id, $read['raw'], $state ) ) {
				return new WP_Error( 'icon_library_install_failed', $state['job']['error'] ); }
		}
		return new WP_Error( 'icon_library_install_failed', __( 'The installation failed while saving its status.', 'aculect-icon-library' ) );
	}

	/** Atomically activates validated files after confirming current lease ownership.
	 *
	 * @param string $library_id Library slug.
	 * @param string $job_id Job UUID.
	 * @param int    $generation Lease generation.
	 * @param string $token Lease token.
	 * @param array  $entry Trusted descriptor.
	 * @param array  $manifest Validated package manifest.
	 * @return array|WP_Error
	 */
	private function commit( $library_id, $job_id, $generation, $token, $entry, $manifest ) {
		$source_manifest = $manifest;
		for ( $attempt = 0; $attempt < self::MAX_CAS_RETRIES; ++$attempt ) {
			$read = $this->jobs->read( $library_id );
			if ( ! $this->owns_lease( $read, $job_id, $generation, $token ) ) {
				return new WP_Error( 'icon_library_job_stale', __( 'This installation attempt was superseded before activation.', 'aculect-icon-library' ) ); }
			$state    = $read['value'];
			$manifest = $source_manifest;
			$style    = $entry['style_id'];
			$root     = $style . '/' . $entry['package_sha256'];
			$current  = array();
			foreach ( $manifest['icons'] as &$icon ) {
				$icon['path']           = $root . '/' . $icon['path'];
				$current[ $icon['id'] ] = $icon; }
			unset( $icon );
			$archived = (array) ( $state['styles'][ $style ]['archived_icons'] ?? array() );
			$previous = (array) ( $state['styles'][ $style ]['manifest']['icons'] ?? array() );
			foreach ( $previous as $icon ) {
				if ( ! isset( $current[ $icon['id'] ] ) ) {
					$icon['archived']        = true;
					$archived[ $icon['id'] ] = $icon; }
			}
			foreach ( $current as $id => $icon ) {
				unset( $archived[ $id ] ); }
			$manifest['slug']           = $library_id;
			$manifest['name']           = ucwords( str_replace( '-', ' ', $library_id ) );
			$manifest['icons']          = array_values( $current );
			$manifest['variants']       = array(
				array(
					'slug'           => $style,
					'label'          => ucwords( str_replace( '-', ' ', $style ) ),
					'defaultEnabled' => false,
				),
			);
			$state['styles'][ $style ]  = array(
				'release_version' => $entry['release_version'],
				'package_sha256'  => $entry['package_sha256'],
				'installed_at'    => time(),
				'manifest'        => $manifest,
				'archived_icons'  => $archived,
			);
			$state['identities']        = $this->update_identities( (array) ( $state['identities'] ?? array() ), $style, $current, $archived );
			$state['job']['status']     = 'succeeded';
			$state['job']['progress']   = 100;
			$state['job']['updated_at'] = time();
			$state['job']['error']      = '';
			$state['lease_token']       = '';
			$state['lease_expires']     = 0;
			if ( $this->jobs->compare_and_swap( $library_id, $read['raw'], $state ) ) {
				return $this->public_job( $state['job'] ); }
		}
		return new WP_Error( 'icon_library_job_stale', __( 'Library state changed before activation.', 'aculect-icon-library' ) );
	}

	/** Rejects identity changes, namespace collisions, and oversized Core names.
	 *
	 * @param string $library_id Library slug.
	 * @param array  $entry Trusted descriptor.
	 * @param array  $manifest Validated manifest.
	 * @return true|WP_Error
	 */
	private function validate_identities( $library_id, $entry, $manifest ) {
		$state       = $this->jobs->read( $library_id );
		$identities  = (array) ( $state['value']['identities'] ?? array() );
		$local_names = array();
		foreach ( $identities as $id => $identity ) {
			$local_names[ $identity['local'] ] = $id; }
		$seen = array();
		foreach ( $manifest['icons'] as $icon ) {
			$id    = $icon['id'];
			$local = $icon['core_icon_name'];
			if ( isset( $identities[ $id ] ) && ( $identities[ $id ]['style'] !== $entry['style_id'] || $identities[ $id ]['local'] !== $local ) ) {
				return new WP_Error( 'icon_library_identity_changed', __( 'A saved icon identity cannot be reassigned or moved between styles.', 'aculect-icon-library' ) ); }
			if ( isset( $local_names[ $local ] ) && $local_names[ $local ] !== $id ) {
				return new WP_Error( 'icon_library_identity_collision', __( 'An icon name is already reserved by another saved icon.', 'aculect-icon-library' ) ); }
			if ( isset( $seen[ $local ] ) ) {
				return new WP_Error( 'icon_library_identity_collision', __( 'The package contains colliding saved icon names.', 'aculect-icon-library' ) ); }
			$seen[ $local ] = true;
			if ( strlen( $library_id . '/' . $local ) > 200 || strlen( $library_id . '-' . $entry['style_id'] . '/' . $local ) > 200 ) {
				return new WP_Error( 'icon_library_identity_length', __( 'The saved icon name or style alias exceeds the supported name length.', 'aculect-icon-library' ) ); }
		}
		if ( $this->has_namespace_collision( $library_id, $entry['style_id'] ) ) {
			return new WP_Error( 'icon_library_namespace_collision', __( 'This library or style conflicts with an existing Core icon namespace.', 'aculect-icon-library' ) ); }
		return true;
	}

	/** Checks bundled, installed, Core, and third-party collection namespaces.
	 *
	 * @param string $library_id Library slug.
	 * @param string $style_id Style slug.
	 * @return bool
	 */
	private function has_namespace_collision( $library_id, $style_id ) {
		$occupied = array();
		$loader   = new ManifestLoader( ICON_LIBRARY_DIR . 'assets/icons' );
		foreach ( $loader->get_collection_slugs() as $slug ) {
			$occupied[ $slug ] = $slug;
			$manifest          = $loader->get_manifest( $slug );
			foreach ( (array) ( $manifest['variants'] ?? array() ) as $variant ) {
				if ( is_array( $variant ) && is_string( $variant['slug'] ?? null ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $variant['slug'] ) ) {
					$occupied[ $slug . '-' . $variant['slug'] ] = $slug; }
			}
		}
		$occupied[ CustomIconRepository::COLLECTION_SLUG ] = CustomIconRepository::COLLECTION_SLUG;
		foreach ( $this->jobs->all_library_ids() as $installed_library ) {
			if ( $installed_library === $library_id ) {
				continue; }
			$occupied[ $installed_library ] = $installed_library;
			$installed                      = $this->jobs->read( $installed_library );
			foreach ( (array) ( $installed['value']['styles'] ?? array() ) as $installed_style => $unused ) {
				$occupied[ $installed_library . '-' . $installed_style ] = $installed_library; }
		}
		if ( class_exists( 'WP_Icons_Registry' ) && method_exists( 'WP_Icons_Registry', 'get_instance' ) ) {
			$registry = \WP_Icons_Registry::get_instance();
			if ( method_exists( $registry, 'get_registered_icons' ) ) {
				foreach ( (array) $registry->get_registered_icons() as $icon ) {
					$name = $icon['name'] ?? '';
					if ( is_string( $name ) && ( 0 === strpos( $name, $library_id . '/' ) || 0 === strpos( $name, $library_id . '-' . $style_id . '/' ) ) ) {
						return true; }
				}
			}
			if ( class_exists( 'WP_Icon_Collections_Registry' ) && method_exists( 'WP_Icon_Collections_Registry', 'get_instance' ) ) {
				$collections = \WP_Icon_Collections_Registry::get_instance();
				if ( method_exists( $collections, 'is_registered' ) && ( $collections->is_registered( $library_id ) || $collections->is_registered( $library_id . '-' . $style_id ) ) ) {
					return true;
				}
			}
		}
		$providers = function_exists( 'apply_filters' ) ? apply_filters( 'icon_library_collection_providers', array() ) : array();
		if ( is_array( $providers ) ) {
			foreach ( $providers as $slug => $provider ) {
				if ( ! is_string( $slug ) || ! is_array( $provider ) ) {
					continue; }
				if ( $slug === $library_id && ( $provider['owner'] ?? '' ) !== 'aculect-icon-library-installed' ) {
					return true; }
				if ( $slug === $library_id && ( $provider['owner'] ?? '' ) === 'aculect-icon-library-installed' ) {
					continue; }
				$occupied[ $slug ] = $slug;
				$manifest          = is_callable( $provider['manifest'] ?? null ) ? call_user_func( $provider['manifest'] ) : ( $provider['manifest'] ?? null );
				foreach ( (array) ( is_array( $manifest ) ? ( $manifest['variants'] ?? array() ) : array() ) as $variant ) {
					if ( is_array( $variant ) && is_string( $variant['slug'] ?? null ) && 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $variant['slug'] ) ) {
						$occupied[ $slug . '-' . $variant['slug'] ] = $slug; }
				}
			}
		}
		$style_namespace = $library_id . '-' . $style_id;
		return isset( $occupied[ $library_id ] ) || isset( $occupied[ $style_namespace ] );
	}

	/** Updates stable names for active and archived package icons.
	 *
	 * @param array  $identities Stored identities.
	 * @param string $style Style slug.
	 * @param array  $current Current icons.
	 * @param array  $archived Archived icons.
	 * @return array
	 */
	private function update_identities( $identities, $style, $current, $archived ) {
		foreach ( $archived as $id => $icon ) {
			$identities[ $id ] = array(
				'style'    => $style,
				'local'    => $icon['core_icon_name'],
				'archived' => true,
			); }
		foreach ( $current as $id => $icon ) {
			$identities[ $id ] = array(
				'style'    => $style,
				'local'    => $icon['core_icon_name'],
				'archived' => false,
			); }
		return $identities;
	}

	/** Determines whether a read row still belongs to this live lease.
	 *
	 * @param array|null $read State row.
	 * @param string     $job_id Job UUID.
	 * @param int        $generation Lease generation.
	 * @param string     $token Lease token.
	 * @return bool
	 */
	private function owns_lease( $read, $job_id, $generation, $token ) {
		return $read && ( $read['value']['job']['job_id'] ?? null ) === $job_id && (int) ( $read['value']['generation'] ?? 0 ) === $generation && ( $read['value']['lease_token'] ?? '' ) === $token && (int) ( $read['value']['lease_expires'] ?? 0 ) > time(); }
	/** Validates a descriptor loaded from persisted job state.
	 *
	 * @param array $entry Pinned descriptor.
	 * @return bool
	 */
	private function valid_pinned_descriptor( $entry ) {
		$catalog   = new TrustedLibraryCatalog( array( $entry ) );
		$validated = $catalog->find( $entry['library_id'] ?? '', $entry['style_id'] ?? '', $entry['release_version'] ?? '' );
		return is_array( $validated ) && ( $entry['package_sha256'] ?? null ) === $validated['package_sha256'] && ( $entry['manifest_sha256'] ?? null ) === $validated['manifest_sha256'] && ( $entry['package_bytes'] ?? null ) === $validated['package_bytes'] && ( $entry['url'] ?? null ) === $validated['url'];
	}
	/** Reduces internal job state to its public status fields.
	 *
	 * @param array $job Internal job row.
	 * @return array
	 */
	private function public_job( $job ) {
		return array(
			'job_id'          => (string) $job['job_id'],
			'library_id'      => (string) $job['library_id'],
			'style_id'        => (string) $job['style_id'],
			'release_version' => (string) $job['release_version'],
			'status'          => (string) $job['status'],
			'generation'      => (int) $job['generation'],
			'progress'        => (int) $job['progress'],
			'updated_at'      => (int) $job['updated_at'],
			'error'           => (string) ( $job['error'] ?? '' ),
		); }
	/** Creates a fresh library state record.
	 *
	 * @return array
	 */
	private function empty_state() {
		return array(
			'generation'    => 0,
			'lease_token'   => '',
			'lease_expires' => 0,
			'job'           => null,
			'styles'        => array(),
			'identities'    => array(),
		); }
}
