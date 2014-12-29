<?php

/*
Plugin Name: WP.org Embed
Plugin URI: http://www.leewillis.co.uk/wordpress-plugins
Description: Paste the URL to a WordPress.org plugin into your posts or pages, and have the plugin information pulled in and displayed automatically
Version: 1.4
Author: Lee Willis
Author URI: http://www.leewillis.co.uk/
*/

/**
 * Copyright (c) 2013 Lee Willis. All rights reserved.
 *
 * Released under the GPL license
 * http://www.opensource.org/licenses/gpl-license.php
 *
 * This is an add-on for WordPress
 * http://wordpress.org/
 *
 * **********************************************************************
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * **********************************************************************
 */

/**
 * This class handles being the oEmbed provider in terms of registering the URLs that
 * we can embed, and handling the actual oEmbed calls. It relies on the WP.org API
 * class to retrieve the information from WordPress.org
 * @uses class wpdotorg_api
 */
class wpdotorg_embed {



	private $api;



	/**
	 * Constructor. Registers hooks and filters
	 * @param class $api An instance of the wpdotorg_api classs
	 */
	public function __construct( $api ) {

		$this->api = $api;

		add_action( 'init', array( $this, 'register_oembed_handler' ) );
		add_action( 'init', array( $this, 'maybe_handle_oembed' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'admin_init', array( $this, 'schedule_expiry' ) );
        add_action( 'wpdotorg_embed_cron', array( $this, 'cron' ) );
		// @TODO i18n

	}



    /**
     * Make sure we have a scheduled event set to clear down the oEmbed cache until
     * WordPress supports cache_age in oEmbed responses.
     */
    function schedule_expiry() {

        if ( ! wp_next_scheduled( 'wpdotorg_embed_cron' ) ) {
            $frequency = apply_filters( 'wpdotorg_embed_cache_frequency', 'daily' );
           wp_schedule_event( time(), $frequency, 'wpdotorg_embed_cron' );
        }

    }



    /**
     * Expire old oEmbeds.
     * Note: This is a bit sledgehammer-to-crack-a-nut hence why I'm only running it
     * daily. Ideally WP should honour cache_age in oEmbed responses properly
     */
    function cron() {

        global $wpdb, $table_prefix;

        $sql = "DELETE
                  FROM {$table_prefix}postmeta
                 WHERE meta_key LIKE '_oembed_%'";

        $results = $wpdb->get_results( $sql );

    }



	/**
	 * Enqueue the frontend CSS
	 * @return void
	 */
	function enqueue_styles() {

		wp_register_style( 'wpdotorg-embed', plugins_url( basename( dirname( __FILE__ ) ) . '/css/wpdotorg-embed.css' ) );
        wp_enqueue_style( 'wpdotorg-embed' );

	}



	/**
	 * Register the oEmbed provider, and point it at a local endpoint since wpdotorg
	 * doesn't directly support oEmbed yet. Our local endpoint will use the wpdotorg
	 * API to fulfil the request.
	 * @param  array $providers The current list of providers
	 * @return array            The list, with our new provider added
	 */
	public function register_oembed_handler() {
		$oembed_url = home_url();
		$key = $this->get_key();
		$oembed_url = add_query_arg( array( 'wpdotorg_oembed' => $key ), $oembed_url );
		wp_oembed_add_provider( '#https?://wordpress.org/extend/plugins/.*/?#i', $oembed_url, true );
		wp_oembed_add_provider( '#https?://wordpress.org/extend/themes/.*/?#i', $oembed_url, true );
		wp_oembed_add_provider( '#https?://wordpress.org/plugins/.*/?#i', $oembed_url, true );
		wp_oembed_add_provider( '#https?://wordpress.org/themes/.*/?#i', $oembed_url, true );
	}



	/**
	 * Generate a unique key that can be used on our requests to stop others
	 * hijacking our internal oEmbed API
	 * @return string The site key
	 */
	private function get_key() {

		$key = get_option( 'wpdotorg_oembed_key' );

		if ( ! $key ) {
			$key = md5( time() . rand( 0,65535 ) );
			add_option( 'wpdotorg_oembed_key', $key, '', 'yes' );
		}

		return $key;

	}



	/**
	 * Check whether this is an oembed request, handle if it is.
	 * Ignore it if not.
	 * Insert rant here about WP's lack of a front-end AJAX handler.
	 */
	public function maybe_handle_oembed() {

		if ( isset( $_GET['wpdotorg_oembed'] ) ) {
			return $this->handle_oembed();
		}

	}



	/**
	 * Handle an oembed request
	 */
	public function handle_oembed() {
		// Check this request is valid
		if ( $_GET['wpdotorg_oembed'] != $this->get_key() ) {
            header( 'HTTP/1.0 403 Forbidden' );
			die( 'Matt Mullenweg is sad.' );
		}

		// Check we have the required information
		$url = isset( $_REQUEST['url'] ) ? $_REQUEST['url'] : null;
		$format = isset( $_REQUEST['format'] ) ? $_REQUEST['format'] : null;

		if ( ! empty( $format ) && $format != 'json' ) {
			header( 'HTTP/1.0 501 Not implemented' );
			die( 'Only JSON here, probably #blamenacin' );
		}

		if ( preg_match( '#https?://wordpress.org/extend/plugins/([^/]*)/?$#i', $url, $matches ) ) {
			$this->oembed_wpdotorg_plugin( $matches[1] );
		} elseif ( preg_match( '#https?://wordpress.org/plugins/([^/]*)/?$#i', $url, $matches ) ) {
			$this->oembed_wpdotorg_plugin( $matches[1] );
		} elseif ( preg_match( '#https?://wordpress.org/extend/themes/([^/]*)/?$#i', $url, $matches ) ) {
			$this->oembed_wpdotorg_theme( $matches[1] );
		} elseif ( preg_match( '#https?://wordpress.org/themes/([^/]*)/?$#i', $url, $matches ) ) {
			$this->oembed_wpdotorg_theme( $matches[1] );
		} else {
        	header( 'HTTP/1.0 404 Not Found' );
			die( 'Mike Little is lost, and afraid' );
		}

	}



	/**
	 * Retrieve the information from wpdotorg for a plugin, and
	 * output it as an oembed response
	 */
	private function oembed_wpdotorg_plugin( $slug ) {

		$plugin = $this->api->get_plugin( $slug );

		$response = new stdClass();
		$response->type = 'rich';
		$response->width = '10';
		$response->height = '10';
		$response->version = '1.0';
		$response->title = $plugin->sections['description'];
		$response->html = '<div class="wpdotorg-embed wpdotorg-embed-plugin">';

		// @TODO This should all be templated
		$response->html .= '<p><a href="http://wordpress.org/extend/plugins/' . esc_attr( $slug ) . '" target="_blank"><strong>' . esc_html( $plugin->name ) . '</strong></a><br/>';
		if ( ! empty( $plugin->author ) ) {
		    $response->html .= 'by <span class="wpdotorg-embed-plugin-author">' . wp_kses_post( $plugin->author ) . '</span></p>';
		}

		if ( ! empty( $plugin->sections['description'] ) ) {
			$response->html .= '<p class="wpdotorg-embed-plugin-description">' . wp_kses_post( $plugin->sections['description'] ) . '</p>';
		}

		$stats = '';

		if ( ! empty( $plugin->version) ) {
			$stats .= '<li>Current version: ' . esc_html( $plugin->version ) . '</li>';
		}

		if ( ! empty( $plugin->rating ) ) {
			$stats .= '<li>Rating: ' . esc_html( $plugin->rating ) . '(' . esc_html( $plugin->num_ratings ) . ' ratings)</li>';
		}

		if ( ! empty( $plugin->downloaded ) ) {
			$stats .= '<li>Downloaded ' . esc_html( number_format_i18n( $plugin->downloaded ) ) . ' times</li>';
		}

		if ( ! empty( $stats ) ) {
			$response->html .= '<p><strong>Stats:</strong></p><ul class="wpdotorg-embed-stats-list">'.$stats.'</ul>';
		}

		$response->html .= '</div>';

		header( 'Content-Type: application/json' );
		echo json_encode( $response );
		die();

	}



	/**
	 * Retrieve the information from wpdotorg for a theme, and
	 * output it as an oembed response
	 */
	private function oembed_wpdotorg_theme( $slug ) {

		$theme = $this->api->get_theme( $slug );

		$response = new stdClass();
		$response->type = 'rich';
		$response->width = '10';
		$response->height = '10';
		$response->version = '1.0';
		$response->title = $theme->sections['description'];
		$response->html = '<div class="wpdotorg-embed wpdotorg-embed-theme">';

		// @TODO This should all be templated
		$response->html .= '<p><a href="http://wordpress.org/extend/themes/' . esc_attr( $slug ) . '" target="_blank"><strong>' . esc_html( $theme->name ) . '</strong></a><br/>';
		if ( ! empty( $theme->author ) )
		    $response->html .= 'by <span class="wpdotorg-embed-theme-author">' . wp_kses_post( $theme->author ) . '</span></p>';

		if ( ! empty( $theme->sections['description'] ) )
			$response->html .= '<p class="wpdotorg-embed-theme-description">' . wp_kses_post( $theme->sections['description'] ) . '</p>';

		$stats = '';

		if ( ! empty( $theme->version) ) {
			$stats .= '<li>Current version: ' . esc_html( $theme->version ) . '</li>';
		}

		if ( ! empty( $theme->rating ) ) {
			$stats .= '<li>Rating: ' . esc_html( $theme->rating ) . '(' . esc_html( $theme->num_ratings ) . ' ratings)</li>';
		}

		if ( ! empty( $theme->downloaded ) ) {
			$stats .= '<li>Downloaded ' . esc_html( number_format_i18n( $plugin->downloaded ) ) . ' times</li>';
		}

		if ( ! empty( $stats ) ) {
			$response->html .= '<p><strong>Stats:</strong></p><ul class="wpdotorg-embed-stats-list">'.$stats.'</ul>';
		}

		$response->html .= '</div>';

		header( 'Content-Type: application/json' );
		echo json_encode( $response );
		die();

	}

}



require_once( 'wpdotorg-api.php' );

$wpdotorg_api = new wpdotorg_api();
$wpdotorg_embed = new wpdotorg_embed( $wpdotorg_api );