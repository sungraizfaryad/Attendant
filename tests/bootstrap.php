<?php
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
}
if ( ! defined( 'ATTENDANT_PLUGIN_DIR' ) ) {
	define( 'ATTENDANT_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'ATTENDANT_VERSION' ) ) {
	define( 'ATTENDANT_VERSION', '2.2.0-dev' );
}

require_once __DIR__ . '/stubs/wp-classes.php';
