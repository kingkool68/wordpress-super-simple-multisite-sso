<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adjusts the OpenID Connect Server running on the hub site so users can
 * authenticate to the client sites they belong to, even if they aren't members
 * of the hub site.
 *
 * Out of the box the OpenID Connect Server plugin:
 * - Requires the `edit_posts` capability on the hub site (`oidc_minimal_capability`).
 * - Looks up authorization codes only among users of the hub site (`oidc_auth_code_storage_blog_id`).
 *
 * Both checks fail for users who are members of a client site but not the hub.
 * Instead, a user may authenticate only if they are a member of the client site
 * that is requesting the login (or are a super admin).
 */
class SS_MS_SSO_Hub_Site {

	/**
	 * The custom capability required to use OpenID Connect on the hub.
	 *
	 * @var string
	 */
	const CAPABILITY = 'ss_ms_sso_use_oidc';

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
		add_filter( 'oidc_minimal_capability', array( $this, 'filter_oidc_minimal_capability' ) );
		add_filter( 'user_has_cap', array( $this, 'filter_user_has_cap' ), 10, 4 );
		add_filter( 'oidc_auth_code_storage_blog_id', array( $this, 'filter_oidc_auth_code_storage_blog_id' ) );
	}

	/**
	 * Swap the OIDC Server's minimal capability for our own site-aware capability.
	 *
	 * @param string $capability The capability required to use OpenID Connect.
	 * @return string
	 */
	public function filter_oidc_minimal_capability( $capability = '' ) {
		if ( ! SS_MS_SSO_Helpers::is_hub_site() ) {
			return $capability;
		}
		return static::CAPABILITY;
	}

	/**
	 * Dynamically grant our custom capability if the user may log in to the
	 * client site making the current OIDC request.
	 *
	 * @param array   $allcaps All capabilities the user has.
	 * @param array   $caps    Primitive capabilities being checked.
	 * @param array   $args    Arguments passed to the capability check.
	 * @param WP_User $user    The user being checked.
	 * @return array
	 */
	public function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( static::CAPABILITY, (array) $caps, true ) ) {
			return $allcaps;
		}

		$site_id = SS_MS_SSO_Client_Sites::get_site_id_from_client_id( static::get_requested_client_id() );

		$allcaps[ static::CAPABILITY ] = static::user_can_sso( $user, $site_id );

		return $allcaps;
	}

	/**
	 * Look up authorization codes across all users in the network instead of only
	 * users of the hub site. A blog_id of 0 tells WP_User_Query not to limit by site.
	 *
	 * This is safe because codes are only issued after the membership check above.
	 *
	 * @param int $blog_id The site ID to limit the user query to.
	 * @return int
	 */
	public function filter_oidc_auth_code_storage_blog_id( $blog_id = 0 ) {
		if ( ! SS_MS_SSO_Helpers::is_hub_site() ) {
			return $blog_id;
		}
		return 0;
	}

	/**
	 * Gets the client ID of the current OIDC request.
	 *
	 * The authorize endpoint and the consent screen receive it as a query
	 * parameter, while the consent form submits it via POST.
	 *
	 * @return string
	 */
	public static function get_requested_client_id() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$client_id = '';
		if ( isset( $_GET['client_id'] ) ) {
			$client_id = sanitize_text_field( wp_unslash( $_GET['client_id'] ) );
		} elseif ( isset( $_POST['client_id'] ) ) {
			$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ) );
		}
		// phpcs:enable

		return $client_id;
	}

	/**
	 * Determines whether a user may authenticate via the hub to a client site.
	 *
	 * Super admins may log in to any client site. Everyone else must be a member
	 * of the client site, and that site must be active (not archived, spammed, or deleted).
	 *
	 * @param WP_User|int $user    The user object or ID.
	 * @param int         $site_id The client site ID the user is logging in to.
	 * @return bool
	 */
	public static function user_can_sso( $user, $site_id = 0 ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( absint( $user ) );
		}

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		$site_id = absint( $site_id );

		if ( empty( $site_id ) ) {
			// Unknown client: nobody gets in.
			$can_sso = false;
		} elseif ( is_super_admin( $user->ID ) ) {
			$can_sso = true;
		} elseif ( is_user_spammy( $user ) ) {
			$can_sso = false;
		} else {
			// Only includes sites that aren't archived, spammed, or deleted.
			$blogs   = get_blogs_of_user( $user->ID );
			$can_sso = isset( $blogs[ $site_id ] );
		}

		/**
		 * Filters whether a user is allowed to authenticate via the SSO hub to a client site.
		 *
		 * @param bool    $can_sso Whether the user can use SSO.
		 * @param WP_User $user    The user being checked.
		 * @param int     $site_id The client site ID the user is logging in to (0 if unknown).
		 */
		return (bool) apply_filters( 'ss_ms_sso_user_can_sso', $can_sso, $user, $site_id );
	}
}

SS_MS_SSO_Hub_Site::get_instance();
