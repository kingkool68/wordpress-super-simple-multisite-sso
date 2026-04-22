<?php

class Client_Sites {

	/**
	 * The option key used for storing clients.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'ss_ms_sso_clients';

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
	 * Set up hooks and actions
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

		return array_merge( $clients, static::get_clients() );
	}

	/**
	 * Returns all network client configs, reading from the site option cache when available.
	 *
	 * @return array
	 */
	public static function get_clients() {
		$cached = get_site_option( static::OPTION_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$clients = array();

		$sites = get_sites(
			array(
				'number'  => 1000,
				'deleted' => 0,
			)
		);

		foreach ( $sites as $site ) {
			$site_id = (int) $site->blog_id;
			if ( (int) SS_MS_SSO_HUB_SITE_ID === $site_id ) {
				continue;
			}

			$client_id    = 'client_' . $site_id;
			$secret       = hash_hmac( 'sha256', $site_id, wp_salt( 'auth' ) );
			$site_url     = get_site_url( $site_id );
			$redirect_uri = rtrim( $site_url, '/' ) . '/wp-admin/admin-ajax.php?action=openid-connect-authorize';
			$site_name    = get_blog_option( $site_id, 'blogname' );
			if ( empty( $site_name ) ) {
				$site_name = $site->domain;
			}

			$clients[ $client_id ] = array(
				'name'         => $site_name,
				'secret'       => $secret,
				'redirect_uri' => $redirect_uri,
				'grant_types'  => array( 'authorization_code' ),
				'scope'        => 'openid profile email',
			);
		}

		update_site_option( static::OPTION_KEY, $clients );

		return $clients;
	}

	/**
	 * Clears the cached clients stored in a site option.
	 */
	public static function clear_clients() {
		delete_site_option( static::OPTION_KEY );
	}

	/**
	 * If a site name gets updated clear cached client configuration.
	 *
	 * @param mixed $old_value The old option value.
	 * @param mixed $new_value The new option value.
	 */
	public function action_update_option_blogname( $old_value, $new_value ) {
		// Compare values: If the name hasn't actually changed, bail.
		if ( $old_value === $new_value ) {
			return;
		}

		// The hub site isn't in the client list, so we can ignore it.
		if ( (int) SS_MS_SSO_HUB_SITE_ID === (int) get_current_blog_id() ) {
			return;
		}

		static::clear_clients();
	}
}

Client_Sites::get_instance();
