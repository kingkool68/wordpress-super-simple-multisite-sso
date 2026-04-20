<?php
/**
 * File to kick things off
 *
 * @package Super Simple Multisite SSO
 */

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
	'class-require-plugin.php',
	'class-client-sites.php',
);
foreach ( $files_to_load as $file_name ) {
	$file = __DIR__ . '/' . $file_name;
	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

Require_Plugin::register( 'wp-openid-connect-server/openid-connect-server.php', true );
Require_Plugin::register( 'daggerhart-openid-connect-generic/openid-connect-generic.php', false );

/**
 * Injects the missing email claim into the OIDC user info payload.
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
