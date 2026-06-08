<?php
/**
 * MU Plugin: Super Page Cache — Daily Midnight Purge
 *
 * Detects if the "Super Page Cache for Cloudflare" plugin is active and installed.
 * If it is, schedules a cron job that fires daily at midnight to purge the entire cache.
 */

defined( 'ABSPATH' ) || die();

add_action( 'plugins_loaded', 'spc_mu_detect_and_schedule', 0 );

/**
 * Check for Super Page Cache and schedule the daily purge cron.
 */
function spc_mu_detect_and_schedule(): void {
	if ( ! defined( 'SPC_FREE_PATH' ) && ! defined( 'SWCFPC_PLUGIN_PATH' ) ) {
		return;
	}

	if ( ! class_exists( '\SPC\Modules\Cache_Controller' ) ) {
		return;
	}

	if ( ! wp_next_scheduled( 'spc_mu_daily_midnight_purge' ) ) {
		$midnight = strtotime( 'tomorrow midnight' );
		wp_schedule_event( $midnight, 'daily', 'spc_mu_daily_midnight_purge' );
	}
}

/**
 * Perform the cache purge via the plugin's own Cache_Controller.
 */
add_action( 'spc_mu_daily_midnight_purge', 'spc_mu_run_daily_purge' );

function spc_mu_run_daily_purge(): void {
	if ( ! defined( 'SPC_FREE_PATH' ) && ! defined( 'SWCFPC_PLUGIN_PATH' ) ) {
		return;
	}

	if ( ! class_exists( '\SPC\Modules\Cache_Controller' ) ) {
		return;
	}

	\SPC\Modules\Cache_Controller::purge_all();
}
