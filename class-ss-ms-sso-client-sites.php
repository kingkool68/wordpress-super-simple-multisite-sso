<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the registration and configuration of OIDC clients for network sites.
 *
 * This class automatically treats all non-hub sites in a multisite network as OIDC clients,
 * generating their credentials and overriding their OIDC settings to point back to the hub.
 */
class SS_MS_SSO_Client_Sites {

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
		add_filter( 'option_openid_connect_generic_settings', array( $this, 'override_settings' ) );
		add_filter( 'default_option_openid_connect_generic_settings', array( $this, 'override_settings' ) );
		add_action( 'init', array( $this, 'disable_two_factor_ui_on_clients' ) );
		add_action( 'openid-connect-generic-update-user-using-current-claim', array( $this, 'bypass_two_factor_for_sso' ) );

		// Hooks to clear the cache when sites are added, updated, or deleted.
		add_action( 'wp_insert_site', array( $this, 'destroy_clients' ) );
		add_action( 'wp_delete_site', array( $this, 'destroy_clients' ) );
		add_action( 'wp_update_site', array( $this, 'destroy_clients' ) );

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
	 * Overrides OIDC client settings with values derived from the hub's client registry.
	 *
	 * @param mixed $settings The raw option value (array after WP unserializes it).
	 * @return array
	 */
	public function override_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Get the current site ID.
		$blog_id = get_current_blog_id();

		// Bail if we're on the defined hub site.
		if ( SS_MS_SSO_Helpers::is_hub_site( $blog_id ) ) {
			return $settings;
		}

		// Get the Hub Site URL.
		$hub_site_url = get_site_url( (int) SS_MS_SSO_HUB_SITE_ID );
		if ( empty( $hub_site_url ) ) {
			return $settings;
		}
		$hub_site_url = untrailingslashit( $hub_site_url );

		$client_id      = static::get_client_id( $blog_id );
		$client_configs = static::get_clients();

		$overrides = array(
			'login_type'        => 'auto',
			'client_id'         => $client_id,
			'client_secret'     => $client_configs[ $client_id ]['secret'] ?? '',
			'endpoint_login'    => $hub_site_url . '/wp-json/openid-connect/authorize',
			'endpoint_userinfo' => $hub_site_url . '/wp-json/openid-connect/userinfo',
			'endpoint_token'    => $hub_site_url . '/wp-json/openid-connect/token',
			'endpoint_jwks'     => $hub_site_url . '/.well-known/jwks.json',
		);

		/**
		 * Filters the OIDC settings overrides applied to client sites.
		 *
		 * @param array $overrides Key/value pairs to merge into the settings.
		 * @param int   $blog_id   The current site's blog ID.
		 */
		$overrides = apply_filters( 'ss_ms_sso_oidc_settings_overrides', $overrides, $blog_id );

		return array_merge( $settings, $overrides );
	}

	/**
	 * Hides the two-factor authentication UI at /wp-admin/profile.php on client sites.
	 */
	public function disable_two_factor_ui_on_clients() {
		if ( ! SS_MS_SSO_Helpers::is_hub_site() && class_exists( 'Two_Factor_Core' ) ) {
			remove_action( 'show_user_profile', array( 'Two_Factor_Core', 'user_two_factor_options' ) );
			remove_action( 'edit_user_profile', array( 'Two_Factor_Core', 'user_two_factor_options' ) );
			remove_action( 'personal_options_update', array( 'Two_Factor_Core', 'user_two_factor_options_update' ) );
			remove_action( 'edit_user_profile_update', array( 'Two_Factor_Core', 'user_two_factor_options_update' ) );
		}
	}

	/**
	 * Bypasses two-factor authentication during an SSO login.
	 */
	public function bypass_two_factor_for_sso() {
		if ( ! SS_MS_SSO_Helpers::is_hub_site() && class_exists( 'Two_Factor_Core' ) ) {
			remove_action( 'wp_login', array( 'Two_Factor_Core', 'wp_login' ), PHP_INT_MAX );
		}
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
			if ( SS_MS_SSO_Helpers::is_hub_site( $site_id ) ) {
				continue;
			}

			$client_id = static::get_client_id( $site_id );
			$salt      = '';
			if ( defined( 'AUTH_SALT' ) ) {
				$salt = AUTH_SALT;
			}
			$secret       = hash_hmac( 'sha256', $site_id, $salt );
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
	public static function destroy_clients() {
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
		if ( SS_MS_SSO_Helpers::is_hub_site() ) {
			return;
		}

		static::destroy_clients();
	}

	/**
	 * Generates a deterministic client ID for a specific site.
	 *
	 * @param int $site_id Optional. The site ID. Defaults to current site.
	 * @return string The MD5 hashed client ID.
	 */
	public static function get_client_id( $site_id = 0 ) {
		$site_id = absint( $site_id );
		if ( empty( $site_id ) ) {
			$site_id = get_current_blog_id();
		}

		$salt = '';
		if ( defined( 'AUTH_SALT' ) ) {
			$salt = AUTH_SALT;
		}

		return md5( 'client_' . $site_id . $salt );
	}
}

SS_MS_SSO_Client_Sites::get_instance();
