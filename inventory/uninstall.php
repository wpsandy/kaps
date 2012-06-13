<?php

if (defined('WP_UNINSTALL_PLUGIN')) {
	acg_uninstall_inventory();
}

function acg_uninstall_inventory() {
	global $wpdb, $table_prefix;
	$tables = array($table_prefix . "inventory", 
		$table_prefix . "inventory_categories", 
		$table_prefix . "inventory_config",
		$table_prefix . "inventory_images"
	);
	$views = array(
		$table_prefix . "inventory_counts"
	);
	foreach ($tables as $table) {
		$query = "DROP TABLE " . $table;
		$wpdb->query($query);
	}
	foreach ($views as $view) {
		$query = "DROP VIEW " . $view;
		$wpdb->query($query);
	}
	
}

?>