<?php

class Require_Client_Plugin {

	/**
	 * The plugin file to load.
	 *
	 * @var string
	 */
	private $plugin_file = 'daggerhart-openid-connect-generic/openid-connect-generic.php';

	/**
	 * The path to the plugin file.
	 *
	 * @var string
	 */
	private $plugin_path;

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
		$this->plugin_path = WP_PLUGIN_DIR . '/' . $this->plugin_file;

		if ( ! file_exists( $this->plugin_path ) ) {
			return;
		}

		if ( ! static::is_hub_site() ) {
			require_once $this->plugin_path;
		}

		add_filter(
			"network_admin_plugin_action_links_{$this->plugin_file}",
			array( $this, 'filter_code_activated_links' )
		);

		if ( ! static::is_hub_site() ) {
			add_filter(
				"plugin_action_links_{$this->plugin_file}",
				array( $this, 'filter_code_activated_links' )
			);
		} else {
			// Prevent activation on the hub site.
			add_filter(
				"plugin_action_links_{$this->plugin_file}",
				array( $this, 'filter_prevent_activation_links' )
			);
		}
		add_filter( 'option_active_plugins', array( $this, 'filter_active_plugins' ) );
		add_filter(
			'site_option_active_sitewide_plugins',
			array( $this, 'filter_active_plugins' )
		);
		add_filter(
			'pre_update_option_active_plugins',
			array( $this, 'filter_prevent_activation_save' )
		);
		add_filter(
			'pre_update_site_option_active_sitewide_plugins',
			array( $this, 'filter_prevent_activation_save' )
		);
	}

	/**
	 * Filter to show as Code Activated in the WP admin plugins screen.
	 *
	 * @param array $actions Plugin action links.
	 * @return array
	 */
	public function filter_code_activated_links( $actions = array() ) {
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}

		unset(
			$actions['activate'],
			$actions['deactivate'],
			$actions['delete'],
			$actions['network_active']
		);

		$actions['code_activated'] = 'Code Activated';

		return $actions;
	}

	/**
	 * Filter to prevent activation link on the hub site.
	 *
	 * @param array $actions Plugin action links.
	 * @return array
	 */
	public function filter_prevent_activation_links( $actions = array() ) {
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}

		unset( $actions['activate'] );

		return $actions;
	}

	/**
	 * Filter to make the plugin appear active (blue background) in the plugins list.
	 *
	 * @param array $plugins Array of active plugins.
	 * @return array
	 */
	public function filter_active_plugins( $plugins = array() ) {
		if ( ! is_array( $plugins ) ) {
			$plugins = array();
		}

		// 'active_plugins' is a numeric array of plugin files.
		// 'active_sitewide_plugins' is an associative array where keys are plugin files.
		if ( current_filter() === 'option_active_plugins' ) {
			if ( static::is_hub_site() ) {
				return $plugins;
			}

			if ( ! in_array( $this->plugin_file, $plugins, true ) ) {
				$plugins[] = $this->plugin_file;
			}
		} elseif ( ! isset( $plugins[ $this->plugin_file ] ) ) {
			$plugins[ $this->plugin_file ] = time();
		}

		return $plugins;
	}

	/**
	 * Filter to prevent network activation / local activation from saving to the DB.
	 *
	 * @param array $plugins Array of active plugins to be saved.
	 * @return array
	 */
	public function filter_prevent_activation_save( $plugins = array() ) {
		if ( ! is_array( $plugins ) ) {
			$plugins = array();
		}

		if ( current_filter() === 'pre_update_option_active_plugins' ) {
			$index = array_search( $this->plugin_file, $plugins, true );
			if ( false !== $index ) {
				unset( $plugins[ $index ] );
				sort( $plugins ); // Re-index array.
			}
		} elseif ( isset( $plugins[ $this->plugin_file ] ) ) {
			unset( $plugins[ $this->plugin_file ] );
		}

		return $plugins;
	}

	public static function is_hub_site() {
		return get_current_blog_id() === SS_MS_SSO_HUB_SITE_ID;
	}
}

Require_Client_Plugin::get_instance();
