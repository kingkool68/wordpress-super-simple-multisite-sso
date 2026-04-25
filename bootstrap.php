<?php
/**
 * File to kick things off
 *
 * @package Super Simple Multisite SSO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default to the 1st site as the SSO Hub Site if the constant isn't set.
 */
if ( ! defined( 'SS_MS_SSO_HUB_SITE_ID' ) ) {
	define( 'SS_MS_SSO_HUB_SITE_ID', 1 );
}

/**
 * Load needed classes
 */
$files_to_load = array(
	'class-ss-ms-sso-helpers.php',
	'class-ss-ms-sso-require-plugin.php',
	'class-ss-ms-sso-client-sites.php',
);
foreach ( $files_to_load as $file_name ) {
	$file = __DIR__ . '/' . $file_name;
	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

SS_MS_SSO_Require_Plugin::register( 'wp-openid-connect-server/openid-connect-server.php', true );
SS_MS_SSO_Require_Plugin::register( 'openid-connect-generic/openid-connect-generic.php', false );

/**
 * Injects identity claims into the OIDC user info payload.
 *
 * Ensures that the email and preferred_username claims are present for the client sites.
 *
 * @param array   $claims The existing claims.
 * @param WP_User $user   The user object.
 * @return array Updated claims.
 */
add_filter(
	'oidc_user_claims',
	function ( $claims, $user ) {
		// Ensure we have a valid user object.
		if ( $user && ! empty( $user->user_email ) ) {
			$claims['email'] = $user->user_email;
		}

		if ( ! empty( $user->user_login ) ) {
			$claims['preferred_username'] = $user->user_login;
		}

		return $claims;
	},
	10,
	2
);

/**
 * Destroys all session tokens for a user upon logout, effectively logging them out
 * of all sites in the network simultaneously.
 */
add_action(
	'wp_logout',
	function ( $user_id = 0 ) {
		delete_user_meta( $user_id, 'session_tokens' );
	}
);
