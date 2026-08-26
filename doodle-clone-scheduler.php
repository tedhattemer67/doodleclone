<?php
/**
 * Plugin Name: Doodle Clone Scheduler
 * Description: A scheduling plugin for 1-on-1 or small group meetings with multiple time slots.
 * Version: 2.0.4
 * Author: Ted Hattemer
 * Text Domain: doodle-clone-scheduler
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DCS_VERSION',    '2.0.4' );
define( 'DCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Timezone for all slot wall-clock times. Define DCS_TIMEZONE in wp-config.php
// to pin a specific zone; if left undefined, dcs_tz() uses the site's own
// timezone (Settings → General). Kept here for backward compatibility.
if ( ! defined( 'DCS_TIMEZONE' ) ) {
    define( 'DCS_TIMEZONE', 'America/New_York' );
}

require_once DCS_PLUGIN_DIR . 'includes/helpers.php';
require_once DCS_PLUGIN_DIR . 'includes/class-admin.php';
require_once DCS_PLUGIN_DIR . 'includes/class-frontend.php';
require_once DCS_PLUGIN_DIR . 'includes/class-ajax.php';
require_once DCS_PLUGIN_DIR . 'includes/class-mailer.php';

add_action( 'plugins_loaded', [ 'DCS_Admin',    'init' ] );
add_action( 'plugins_loaded', [ 'DCS_Frontend', 'init' ] );
add_action( 'plugins_loaded', [ 'DCS_Ajax',     'init' ] );
