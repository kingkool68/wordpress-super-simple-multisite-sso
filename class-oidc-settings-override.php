<?php

class OIDC_Settings_Override {

	public static function get_instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new static();
			$instance->setup();
		}
		return $instance;
	}

	private function setup() {
		// WordPress auto-unserializes options, so the filter receives a PHP array.
		add_filter( 'option_openid_connect_generic_settings', array( $this, 'override_settings' ) );
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

		$blog_id = get_current_blog_id();

		if ( (int) SS_MS_SSO_HUB_SITE_ID === (int) $blog_id ) {
			return $settings;
		}

		$client_id = 'client_' . $blog_id;
		$salt      = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'fallback-oidc-secret-salt';
		$secret    = hash_hmac( 'sha256', $blog_id, $salt );

		$overrides = array(
			'client_id'     => $client_id,
			'client_secret' => $secret,
			// 'endpoint_jwks' => '', // Should be /.well-known/jwks.json once https://github.com/Automattic/wp-openid-connect-server/pull/132 is merged
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
}

OIDC_Settings_Override::get_instance();
