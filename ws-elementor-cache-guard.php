<?php
/**
 * Plugin Name: WS Elementor Cache Guard
 * Plugin URI: https://websqu.ad
 * Description: After core/plugin/theme updates on Elementor sites, regenerates Elementor CSS and purges page + object caches, so auto-updates never leave broken styling. Must-use plugin.
 * Version: 1.0.0
 * Author: websquad
 * Author URI: https://websqu.ad
 * License: GPL-2.0-or-later
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'WSECG_VERSION', '1.0.0' );

/**
 * Rolling log kept in an option (inspect with: wp option get wsecg_log).
 */
function wsecg_log( $message ) {
	$log   = get_option( 'wsecg_log', array() );
	$log[] = gmdate( 'Y-m-d H:i:s' ) . ' ' . $message;
	update_option( 'wsecg_log', array_slice( $log, -30 ), false );
}

/**
 * Fingerprint of everything an auto-update can change.
 */
function wsecg_fingerprint() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$data = array( 'core' => get_bloginfo( 'version' ) );

	foreach ( get_plugins() as $file => $plugin ) {
		$data[ 'p:' . $file ] = isset( $plugin['Version'] ) ? $plugin['Version'] : '';
	}
	foreach ( wp_get_themes() as $slug => $theme ) {
		$data[ 't:' . $slug ] = $theme->get( 'Version' );
	}

	return md5( wp_json_encode( $data ) );
}

/**
 * The actual fix: regenerate Elementor CSS, purge every known cache layer,
 * warm the homepage so CSS regenerates immediately.
 */
function wsecg_flush( $reason ) {
	// Elementor generated CSS.
	if ( class_exists( '\Elementor\Plugin' ) ) {
		\Elementor\Plugin::instance()->files_manager->clear_cache();
	}

	$purged = array();

	// WP Super Cache.
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
		$purged[] = 'wp-super-cache';
	}
	// W3 Total Cache.
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
		$purged[] = 'w3-total-cache';
	}
	// WP Rocket.
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
		if ( function_exists( 'rocket_clean_minify' ) ) {
			rocket_clean_minify();
		}
		$purged[] = 'wp-rocket';
	}
	// LiteSpeed Cache (documented purge-all API action).
	if ( defined( 'LSCWP_V' ) ) {
		do_action( 'litespeed_purge_all' );
		$purged[] = 'litespeed-cache';
	}
	// WP Fastest Cache (true = also delete minified CSS/JS).
	if ( class_exists( 'WpFastestCache' ) ) {
		$wpfc = new WpFastestCache();
		if ( method_exists( $wpfc, 'deleteCache' ) ) {
			$wpfc->deleteCache( true );
			$purged[] = 'wp-fastest-cache';
		}
	}
	// Cache Enabler.
	if ( class_exists( 'Cache_Enabler' ) ) {
		Cache_Enabler::clear_complete_cache();
		$purged[] = 'cache-enabler';
	}
	// Autoptimize.
	if ( class_exists( 'autoptimizeCache' ) ) {
		autoptimizeCache::clearall();
		$purged[] = 'autoptimize';
	}
	// WP-Optimize page cache.
	if ( function_exists( 'wpo_cache_flush' ) ) {
		wpo_cache_flush();
		$purged[] = 'wp-optimize';
	}
	// SiteGround Optimizer.
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache();
		$purged[] = 'sg-cachepress';
	}
	// Breeze.
	if ( defined( 'BREEZE_VERSION' ) ) {
		do_action( 'breeze_clear_all_cache' );
		$purged[] = 'breeze';
	}
	// Hummingbird.
	if ( defined( 'WPHB_VERSION' ) ) {
		do_action( 'wphb_clear_page_cache' );
		$purged[] = 'hummingbird';
	}

	// Object cache (Redis/Memcached) - always safe.
	wp_cache_flush();

	// Warm homepage so Elementor CSS regenerates before a visitor arrives.
	wp_remote_get( home_url( '/' ), array( 'timeout' => 30, 'sslverify' => false ) );

	update_option( 'wsecg_last_flush', gmdate( 'Y-m-d H:i:s' ) . " ({$reason})", false );
	wsecg_log( "flush [{$reason}] purged: " . ( $purged ? implode( ', ', $purged ) : 'object cache only' ) );
}

/**
 * An update just ran (auto-update, wp-admin, or wp-cli). Purge now with the
 * currently loaded code, and schedule a second purge shortly after so it also
 * runs in a fresh process where the NEW plugin code is loaded.
 */
function wsecg_on_update( $upgrader = null, $hook_extra = array() ) {
	$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : 'core';
	if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
		return; // ignore translation updates
	}

	wsecg_log( "update detected ({$type})" );
	wsecg_flush( 'update:' . $type );
	update_option( 'wsecg_fingerprint', wsecg_fingerprint(), false );

	if ( ! wp_next_scheduled( 'wsecg_flush_event' ) ) {
		wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'wsecg_flush_event' );
	}
}
add_action( 'upgrader_process_complete', 'wsecg_on_update', 20, 2 );
add_action( 'automatic_updates_complete', function () {
	wsecg_on_update( null, array( 'type' => 'plugin' ) );
}, 20 );

add_action( 'wsecg_flush_event', function () {
	wsecg_flush( 'post-update recheck' );
} );

/**
 * Hourly safety net: catches anything the hooks missed (FTP deploys,
 * updates run while this plugin was absent, etc.).
 */
add_action( 'wsecg_hourly_check', function () {
	$new = wsecg_fingerprint();
	$old = get_option( 'wsecg_fingerprint', '' );

	if ( '' === $old ) {
		update_option( 'wsecg_fingerprint', $new, false );
		wsecg_log( 'baseline recorded' );
		return;
	}
	if ( $new !== $old ) {
		wsecg_flush( 'fingerprint change' );
		update_option( 'wsecg_fingerprint', $new, false );
	}
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'wsecg_hourly_check' ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', 'wsecg_hourly_check' );
	}
} );

// wp wsecg flush   |   wp wsecg status
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'wsecg flush', function () {
		wsecg_flush( 'manual (wp-cli)' );
		WP_CLI::success( 'Caches purged, Elementor CSS regenerated.' );
	} );
	WP_CLI::add_command( 'wsecg status', function () {
		WP_CLI::line( 'last flush: ' . get_option( 'wsecg_last_flush', 'never' ) );
		foreach ( (array) get_option( 'wsecg_log', array() ) as $line ) {
			WP_CLI::line( $line );
		}
	} );
}
