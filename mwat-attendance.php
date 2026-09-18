<?php
/**
 * Plugin Name: MWAT Attendance
 * Description: Walk attendance logging for Men Walking & Talking.
 * Version: 0.1.0
 * Author: AppyEnterprise
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MWAT_ATTENDANCE_VERSION', '0.1.0' );
define( 'MWAT_ATTENDANCE_DIR', plugin_dir_path( __FILE__ ) );

require_once MWAT_ATTENDANCE_DIR . 'includes/class-mwat-attendance.php';

MWAT_Attendance::instance();
