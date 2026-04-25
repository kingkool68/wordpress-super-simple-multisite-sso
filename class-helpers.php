<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper methods for Super Simple Multisite SSO.
 */
class Helpers {

	/**
	 * Checks if a given site ID (or the current site) is the hub site.
	 *
	 * @param int $site_id Optional. Site ID to check. Defaults to current site.
	 * @return bool
	 */
	public static function is_hub_site( $site_id = 0 ) {
		$site_id = absint( $site_id );
		if ( empty( $site_id ) ) {
			$site_id = get_current_blog_id();
		}
		return SS_MS_SSO_HUB_SITE_ID === $site_id;
	}
}
