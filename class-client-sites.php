<?php

class Client_Sites {

	/**
	 * The option key used for storing clients.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'simple_ms_oidc_clients';

	/**
	 * Get an instance of this class
	 */
	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new static();
			$instance->setup();
		}
		return $instance;
	}

	/**
	 * Constructor.
	 */
	private function setup() {
		add_filter( 'oidc_registered_clients', array( $this, 'register_clients' ) );

		// Hooks to clear the cache when sites are added, updated, or deleted.
		add_action( 'wp_insert_site', array( $this, 'clear_cache' ) );
		add_action( 'wp_delete_site', array( $this, 'clear_cache' ) );
		add_action( 'wp_update_site', array( $this, 'clear_cache' ) );

		// Hook to catch site title changes on any site.
		add_action(
			'update_option_blogname',
			array( $this, 'action_update_option_blogname' ),
			10,
			2
		);
	}

	/**
	 * Filters the registered OIDC clients to include network sites.
	 *
	 * @param array $clients Existing clients.
	 * @return array
	 */
	public function register_clients( $clients ) {
		if ( ! is_array( $clients ) ) {
			$clients = array();
		}

		$cached_clients = get_site_option( static::OPTION_KEY );
		if ( false !== $cached_clients && is_array( $cached_clients ) ) {
			return array_merge( $clients, $cached_clients );
		}

		$generated_clients = $this->generate_clients();

		// Save the generated clients to sitemeta. The option is cleared automatically by hooks.
		update_site_option( static::OPTION_KEY, $generated_clients );

		return array_merge( $clients, $generated_clients );
	}

	/**
	 * Generates the array of clients dynamically based on active network sites.
	 *
	 * @return array
	 */
	private function generate_clients() {
		$generated = array();

		// Fetch all active sites in the network.
		$sites = get_sites(
			array(
				'number'  => 1000, // Safe upper limit, adjust if network is substantially larger.
				'deleted' => 0,
			)
		);

		foreach ( $sites as $site ) {
			// Skip the hub site.
			if ( (int) SS_MS_SSO_HUB_SITE_ID === (int) $site->blog_id ) {
				continue;
			}

			// Generate a unique and consistent client ID and secret per site.
			$client_id = 'client_' . $site->blog_id;
			$salt      = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'fallback-oidc-secret-salt';
			$secret    = hash_hmac( 'sha256', $site->blog_id, $salt );

			// Determine the correct redirect URI for the site.
			$site_url     = get_site_url( $site->blog_id );
			$redirect_uri = rtrim( $site_url, '/' ) . '/wp-admin/admin-ajax.php?action=openid-connect-authorize';

			// Get the site name, fallback to the domain if it's empty.
			$site_name = get_blog_option( $site->blog_id, 'blogname' );
			if ( empty( $site_name ) ) {
				$site_name = $site->domain;
			}

			$generated[ $client_id ] = array(
				'name'         => $site_name,
				'secret'       => $secret,
				'redirect_uri' => $redirect_uri,
				'grant_types'  => array( 'authorization_code' ),
				'scope'        => 'openid profile email',
			);
		}

		return $generated;
	}

	/**
	 * Clears the site option cache.
	 */
	public function clear_cache() {
		delete_site_option( static::OPTION_KEY );
	}

	/**
	 * Updates the site name in the OIDC client cache without rebuilding the entire network cache.
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $new_value The new option value.
	 */
	public function action_update_option_blogname( $old_value, $new_value ) {
		// 1. Compare values: If the name hasn't actually changed, bail.
		if ( $old_value === $new_value ) {
			return;
		}

		$blog_id = get_current_blog_id();

		// 2. The hub site isn't in the client list, so we can ignore it.
		if ( (int) SS_MS_SSO_HUB_SITE_ID === (int) $blog_id ) {
			return;
		}

		// 3. Fetch the existing cache.
		$cached_clients = get_site_option( static::OPTION_KEY );

		// If the cache doesn't exist or isn't an array, bail.
		// The standard generation method will handle it the next time clients are requested.
		if ( false === $cached_clients || ! is_array( $cached_clients ) ) {
			return;
		}

		$client_id = 'client_' . $blog_id;

		// 4. If this specific site is in the cache, update its name and save.
		if ( isset( $cached_clients[ $client_id ] ) ) {

			// Fallback to domain if the new name is saved as completely empty
			// (This matches the logic in your generate_clients() method).
			if ( empty( $new_value ) ) {
				$site      = get_site( $blog_id );
				$new_value = $site ? $site->domain : '';
			}

			$cached_clients[ $client_id ]['name'] = $new_value;

			// Save the modified array back to the database.
			update_site_option( static::OPTION_KEY, $cached_clients );
		}
	}
}

Client_Sites::get_instance();
