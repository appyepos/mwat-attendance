<?php
/**
 * Plugin Name: MWAT Attendance
 * Description: Front-end walk attendance management for Men Walking & Talking.
 * Version: 0.8.0
 * Author: AppyEnterprise
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MWAT_ATTENDANCE_VERSION', '0.8.0' );
define( 'MWAT_ATTENDANCE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWAT_ATTENDANCE_URL', plugin_dir_url( __FILE__ ) );

require_once MWAT_ATTENDANCE_DIR . 'includes/class-mwat-attendance.php';
MWAT_Attendance::instance();
