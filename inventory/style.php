<?php

header("Content-Type: text/css");
header("Vary: Accept");

$url = $_SERVER['SCRIPT_NAME'];
$path = substr($url, stripos($url, "wp-content"));
$offset = $count = 0;
$strerror = "";
$relpath = "";
while (stripos($path, "/", $offset)!==false && $count<=10) {
	$offset = stripos($path, "/", $offset)+1;
	$count++;
	$relpath.= "../";
}
// include_once $relpath . 'wp-config.php';
include_once $relpath . 'wp-load.php';
include_once $relpath . 'wp-includes/wp-db.php';

global $wpdb;

 $styles = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='inventory_style'");

  if (!empty($styles)) {
      $style = $styles[0];
	  echo '
		#inventory_blur {
				position: fixed;
				top: 0;
				left: 0;
				opacity: .7;
				-moz-opacity: .7;
				filter: alpha(opacity = 70);
				display: none;
				background-color: white;
				height: 1000px;
				width: 100%;
				z-index: 250;
		}
		
		#inventory_lightwrap {
			position: fixed;
			left: 0;
			top: 10%;
			width: 100%;
			text-align: center;
			display: none;
			z-index: 251;
			
  		}
		
		#inventory_lightbox {
			width: 80%;
			margin: 0 auto;
			border: 2px solid black;
			background-color: white;
			padding: 5px;
  		}
		
		#inventory_lightbox p {
			position: relative;
			margin: -5px -5px 5px -5px;
			padding: 2px 10px;
			display: block;
			background-color: black;
			text-align: right;
  		}
		
		#inventory_lightbox p a {
			color: white;
			font-weight: bold;
			text-decoration: none;
  		}
		
		#inventory_lightimg {
			max-width: 80%;	
			max-height: 80%;	
			cursor: pointer;
  		}
			
';
          echo $style->config_value;
		
	  echo '
';
}

?>