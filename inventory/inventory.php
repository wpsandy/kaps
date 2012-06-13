<?php
/*
Plugin Name: Inventory Manager
Plugin URI: http://www.alphachannelgroup.com/wp/inventory_manager.html
Description: This plugin allows you to manage an n of products and display them.
Author: Alpha Channel Group
Author URI: http://www.alphachannelgroup.com
Version: 1.8
*/

/*  Copyright 2009-2011  Cale Bergh, Alpha Channel Group (email : cale@alphachannelgroup.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Enable internationalization
$plugin_dir = basename(dirname(__FILE__));
if(!load_plugin_textdomain('inventory', false, '/wp-content/languages/')) {
	load_plugin_textdomain( 'inventory', false, $plugin_dir . "/languages/");
}

// Define the tables used in Inventory
define('WP_INVENTORY_TABLE', $table_prefix . 'inventory');
define('WP_INVENTORY_IMAGES_TABLE', $table_prefix . 'inventory_images');
define('WP_INVENTORY_CONFIG_TABLE', $table_prefix . 'inventory_config');
define('WP_INVENTORY_CATEGORIES_TABLE', $table_prefix . 'inventory_categories');
define('WP_INVENTORY_COUNTS_VIEW', $table_prefix . 'inventory_counts');
define('INVENTORY_IMAGE_DIR', content_url() . "/uploads/inventory_images");
define('INVENTORY_SAVE_TO', "../wp-content/uploads/inventory_images");
define('INVENTORY_LOAD_FROM', "wp-content/uploads/inventory_images"); 
$adminurl = (function_exists('get_admin_url')) ? get_admin_url(inv_current_blog_id()) : get_bloginfo('url') . "/wp-admin";
$adminurl.= "admin.php";
define('INVENTORY_ADMIN_URL', $adminurl);

// Create a master category for Inventory and its sub-pages
add_action('admin_menu', 'inventory_menu');

// Enable the shortcode
add_shortcode('inventory', 'inventory_shortcode');

// Enable the ability for the inventory to be loaded from pages
add_filter('the_content','inventory_insert');

// Add the function that puts style information in the header
add_action('wp_head', 'inventory_wp_head');

// Add the function that puts the search results in the page
add_action( 'wp_footer', 'inventory_wp_footer');

// Function to add the inventory style into the header
function inventory_wp_head() {
  global $wpdb;
  // If the inventory isn't installed or upgraded this won't work
  check_inventory();
  echo '<link rel="stylesheet" href="' . content_url() . '/plugins/inventory/style.php" />' . "\r\n";
	inventory_add_javascript();
}

function inventory_wp_footer() {
	echo '<div id="inventory_blur"></div>';
	echo '<div id="inventory_lightwrap"><div id="inventory_lightbox"><p><a href="javascript:void(0);" onclick="invHideLightbox();">Close [X]</a></p><img id="inventory_lightimg" src="" onclick="invHideLightbox();" title="Click to Close Image" /></div></div>';
}

// Function to deal with adding the inventory menus
function inventory_menu()  {
  global $wpdb;
  // We make use of the Inventory tables so we must have installed Inventory
  check_inventory();
  // Set admin as the only one who can use Inventory for security
  $allowed_group = 'manage_options';
  // Use the database to *potentially* override the above if allowed
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='can_manage_inventory'");
	if (!empty($configs)) {
  		foreach ($configs as $config) {
		  	$allowed_group = $config->config_value;
		}
    }
  // Add the admin panel pages for Inventory. Use permissions pulled from above
   if (function_exists('add_menu_page')) 
     {
       add_menu_page(__('Inventory','inventory'), __('Inventory','inventory'), $allowed_group, 'inventory', 'edit_inventory');
     }
   if (function_exists('add_submenu_page')) 
     {
	
       add_submenu_page('inventory', __('Manage Inventory','inventory'), __('Manage Inventory','inventory'), $allowed_group, 'inventory', 'edit_inventory');
	add_submenu_page('inventory', __('Instructions', 'inventory'), __('Instructions','inventory'), $allowed_group, 'inventory-instructions', 'inventory_instructions');
       // Note only admin can change inventory options
       add_submenu_page('inventory', __('Manage Categories','inventory'), __('Manage Categories','inventory'), 'manage_options', 'inventory-categories', 'manage_inventory_categories');
       add_submenu_page('inventory', __('Manage Artists','inventory'), __('Manage Artists','inventory'), 'manage_options', 'inventory-artists', 'manage_inventory_artists');
       add_submenu_page('inventory', __('Inventory Config','inventory'), __('Inventory Options','inventory'), 'manage_options', 'inventory-config', 'edit_inventory_config');
       add_submenu_page('inventory', __('Inventory Upload','inventory'), __('Inventory Upload','inventory'), 'manage_options', 'inventory-upload', 'inventory_upload');
     }
}

// Function to add the javascript to the admin header
function inventory_add_javascript() { 
  echo '<script type="text/javascript" src="';
  echo  content_url();
  echo '/plugins/inventory/javascript/inventory.js"></script>' . "\r\n";
}

// Function to deal with loading the inventory into pages
function inventory_insert($content) {
	global $shownImages;
	if (isset($shownImages)) {
		$shownImages = array();
	}
	if (stripos($content,'{INVENTORY')!==false) {
		$inv_action = (isset($_GET["inv_action"])) ? $_GET["inv_action"] : "";
		if ($inv_action=="") {
			$count = 0;
			while (stripos($content,'{INVENTORY')!==false && $count<=20) {
				$start = stripos($content, "{INVENTORY");
				$end = stripos($content, "}", $start)+1;
				$parameters = trim(substr($content, $start+10, ($end-$start-11)));
				$content_output = inventory_display($parameters);
				$content = substr($content, 0, $start) . $content_output . substr($content, $end);
				$count++;
			}
		} elseif ($inv_action="detail") {
			$inventory_id = (isset($_GET["inventory_id"])) ? $_GET["inventory_id"] : "";
			$start = stripos($content, "{INVENTORY");
			$end = stripos($content, "}", $start)+1;
			$content_output = inventory_detail($inventory_id);
			$content = substr($content, 0, $start) . $content_output . substr($content, $end);
		}
	}
	return $content;
}

// Used on the manage events admin page to display a list of inventory items
function wp_inventory_display_list() {
	global $wpdb;
	global $current_user;
    	get_currentuserinfo();
  	    	
    	$where = $sort = "";
    	$sortby = (isset($_GET["sortby"])) ? $_GET["sortby"] : "";
    	$asc = (isset($_GET["asc"])) ? $_GET["asc"] : "";
    	$sorturl = ($sortby) ? "&sortby=" . $sortby : "";
    	$sorturl.= ($asc) ? "&asc=" . $asc : "";
    	$orderby = ($sortby) ? $sortby . " " . $asc : "inventory_order DESC";
    	$currentcategory = (isset($_POST["category_id"])) ? $_POST["category_id"]: "";
    	$currentcategory = (isset($_GET["category_id"])) ? $_GET["category_id"]: $currentcategory;
    	$url = "&category_id=" . $currentcategory;
	$cur_user = $current_user->ID;
	$cur_user_admin = ( current_user_can('manage_options')) ? 1 : 0;
	$config = inv_getConfig();
	$limit_edit = inv_checkConfig($config, "limit_edit");
	$where.= ($currentcategory) ? " " . WP_INVENTORY_TABLE . ".category_id = " . $currentcategory : "";
	$where = ($where) ? " WHERE " . $where : "";
	$ipp = (isset($_POST["items_per_page"])) ? $_POST["items_per_page"] * 1 : 20;
	$ipp = (isset($_GET["items_per_page"])) ? $_GET["items_per_page"] * 1 : $ipp;
    	if ($ipp) {
		$showpages = false;
		$start = (isset($_GET["start"])) ? ($_GET["start"] * 1) : 0;
		$start = (!$start && isset($_POST["start"])) ? ($_POST["start"] * 1) : $start;
		$next = ($start + $ipp)*1;
		$prev = max(0,($start-$ipp)*1);
		$limit = " LIMIT " . $start . ", " . $ipp;
		$counts = $wpdb->get_results("SELECT count(inventory_id) AS count FROM " . WP_INVENTORY_TABLE . " LEFT JOIN " . WP_INVENTORY_CATEGORIES_TABLE .
			" ON " .  WP_INVENTORY_TABLE . ".category_id = " . WP_INVENTORY_CATEGORIES_TABLE . ".category_id" .
		$inv_search_sql);
		foreach ($counts as $count) {
			$last = ($count->count*1);
		}
		$url.="&items_per_page=" . $ipp;
		$page_name = INVENTORY_ADMIN_URL . "?page=inventory&";
		if ($last>$ipp) {
			$showpages = true;
			$last=($last-$ipp)*1;
			$next = min($next, $last);
			$inventory_pages.= '<div id="inv_page">';
			$inventory_pages.=($start!=0) ? '<a class="first" href="' . $page_name . 'start=0"' . $sorturl . $url . '>&laquo; ' . __('First', 'inventory') . '</a>' : '<span>&laquo; ' . __('First', 'inventory') . '</span>';	
			$inventory_pages.=($start!=0) ? '<a class="prev" href="' . $page_name . 'start=' . $prev . $sorturl . $url . '">&lt;' .  __('Prev', 'inventory') . '</a> | ' : '<span>&lt; ' . __('Prev', 'inventory') . '</span> | ';
			$inventory_pages.=($start<$next) ? '<a class="next" href="' . $page_name . 'start=' . $next .  $sorturl . $url . '">' . __('Next', 'inventory') . ' &gt;</a>' : '<span> ' . __('Next', 'inventory') . ' &gt;</span>';	
			$inventory_pages.=($start<$last) ? '<a class="last" href="' . $page_name . 'start=' . $last  . $sorturl . $url . '">' . __('Last', 'inventory') . ' &raquo; </a>' : '<span>' . __('Last', 'inventory') . ' &raquo;</span>';
			$inventory_pages.='</div>';
		}
		
	}
	
	$items = $wpdb->get_results("SELECT " . WP_INVENTORY_TABLE . ".*, category_name, category_colour 
		FROM " . WP_INVENTORY_TABLE . " LEFT JOIN " . WP_INVENTORY_CATEGORIES_TABLE . 
		" ON " .  WP_INVENTORY_TABLE . ".category_id = " . WP_INVENTORY_CATEGORIES_TABLE . ".category_id" .
		$where . " ORDER BY " . $orderby . $limit);
	echo '<form name="inventory_manager" method="post" action="' . INVENTORY_ADMIN_URL . '?page=inventory' . $sorturl . '">' . __('View Category', 'inventory') . inventory_category_list($currentcategory, 1, 1);
	inventory_items_per_page();
	echo  '</form>';
	if ( !empty($items) ) {
		$numberlabel = inv_getLabel($config, "number");
		// New feature limiting what is displayed....
		if ($config["display_inventory_number"]<=0) {$config["display_inventory_number"]=1;}
		$fieldorder = inv_fieldOrder($config);
		?>
		<table class="widefat page fixed" cellpadding="3" cellspacing="3" style="clear: both; width: 100%; margin-bottom: 15px;">
		        <thead>
			    <tr>
		    		<th class="manage-column" scope="col" width="50"><?php doHeaderSort("ID", "inventory_id", $url, $sorturl); ?></th>
		    		<?php
		    			// Load the fields into the array so we can sort and display in proper order
		    			$invlist[$fieldorder["number"]] = '<th class="manage-column" scope="col" width="80">' .  doHeaderSort($numberlabel,'inventory_number', $url, $sorturl, 0) . '</th>';
		    			$invlist[$fieldorder["name"]] = '<th class="manage-column" scope="col" width="100">' . doHeaderSort(inv_getLabel($config, "name"),'inventory_name', $url, $sorturl, 0) . '</th>';
		    			$invlist[$fieldorder["description"]] = '<th class="manage-column" scope="col" width="*">' .  doHeaderSort(inv_getLabel($config, "description"),'inventory_description', $url, $sorturl, 0) . '</th>';
					$invlist[$fieldorder["date_added"]] = '<th class="manage-column" scope="col" width="90">' . doHeaderSort('Date Added','date_added', $url, $sorturl, 0) . '</th>';
					$invlist[$fieldorder["price"]] = '<th class="manage-column" scope="col" width="70">' .  doHeaderSort('Price','inventory_price', $url, $sorturl, 0) . '</th>';
					$invlist[$fieldorder["reserved"]] = '<th class="manage-column" scope="col" width="70">' . doHeaderSort('Reser.','inventory_reserved', $url, $sorturl, 0) . '</th>';
					$invlist[$fieldorder["category_name"]] = '<th class="manage-column" scope="col" width="70">' . doHeaderSort(inv_getLabel($config, "category"),'category_id', $url, $sorturl, 0) . '</th>';
					unset($invlist[0]);
					unset($invlist[-1]);
					ksort($invlist);
					$count = count($invlist) + 2;
					echo implode('', $invlist);
					unset($invlist);
		    		?>
		    		<th class="manage-column" scope="col" width="50"><?php _e('Delete','inventory'); ?></th>	
			    </tr>
		        </thead>
				<tr><td colspan="<?php echo $count; ?>"><a href="<?php echo INVENTORY_ADMIN_URL; ?>?page=inventory&amp;action=add&amp;inventory_id=<?php echo $item->inventory_id;?>" class='edit'><?php _e('Add New Item', 'inventory'); ?></a></td></tr>
		<?php
		$class = '';
		$editlink = INVENTORY_ADMIN_URL . "?page=inventory&amp;action=edit&amp;inventory_id=";
		foreach ( $items as $item ) {
			$class = ($class == 'alternate') ? '' : 'alternate';
			$number = ($item->inventory_number) ? $item->inventory_number : "(no " . $numberlabel . ")";
			if ($cur_user == $item->inventory_userid || $item->inventory_userid==0 || $cur_user_admin || !$limit_edit) {
				$number = '<a href="' . $editlink . $item->inventory_id . '">' . $number . '</a>';
			} else {
				$number = $item->inventory_number;
			}
			?>
			<tr class="<?php echo $class; ?>">
				<th scope="row"><?php echo stripslashes($item->inventory_id); ?></th>
				<?php
					$reserved = ($item->inventory_reserved) ? "Yes" : "-";
					$invlist[$fieldorder["number"]] = '<td>' .  $number . '</td>';
					$invlist[$fieldorder["name"]] = '<td>' .  inventory_excerpt(stripslashes($item->inventory_name), 30, 45) . '</td>';
					$invlist[$fieldorder["description"]] = '<td>' .  inventory_excerpt(stripslashes($item->inventory_description), 50, 75) . '</td>';
					$invlist[$fieldorder["date_added"]] = '<td>' .  date("m/d/Y", $item->date_added) . '</td>';
					$invlist[$fieldorder["price"]] = '<td>' .  __("$", "inventory") . number_format($item->inventory_price, 2) . '</td>';
					$invlist[$fieldorder["reserved"]] = '<td>' .  $reserved . '</td>'; 
					$invlist[$fieldorder["category_name"]] = '<td style="background-color:' .  $category_colour . ';">' .  stripslashes($item->category_name) . '</td>';
					unset($invlist[0]);
					unset($invlist[-1]);
					ksort($invlist);
					echo implode('', $invlist);
				?>
				<?php if ($cur_user == $item->inventory_userid || $item->inventory_userid==0 || $cur_user_admin || !$limit_edit) { ?>
				<td><a href="<?php echo INVENTORY_ADMIN_URL; ?>?page=inventory&amp;action=delete&amp;inventory_id=<?php echo $item->inventory_id;?>" class="delete" onclick="return confirm('<?php _e('Are you sure you want to delete this item?','inventory'); ?>')"><?php echo __('Delete','inventory'); ?></a></td>
				<?php } else {
					echo "<td>&nbsp;</td>";
				} ?>
			</tr>
			<?php
		}
		?>
		</table>
		<?php
		echo $inventory_pages;
	}
	else
	{
		?>
		<p><?php _e("There are no inventory items in the database!",'inventory')	?></p>
		<p><a href="<?php echo INVENTORY_ADMIN_URL; ?>?page=inventory&amp;action=add&amp;inventory_id=<?php echo $item->inventory_id;?>" class='edit'>Add New Item</a></p>
		<?php	
	}
}

function doHeaderSort($label, $field, $url, $sort="", $echo=1) {
	$sort = explode("&", $sort);
	$arg = array();
	foreach ($sort as $s) {
		$s = explode("=", $s);
		if (count($s) == 2) {
			list($key, $val) = $s;
			$arg[$key] = $val;
		}
	}
	if (isset($arg["sortby"]) && $arg["sortby"]==$field) {
		// Set the little carat symbol....
		$carat = ' <sub style="font-weight: normal;">^</sub>';
		// We are switching between asc/descending
		if (!isset($arg["asc"]) || $arg["asc"]=="asc" || $arg["asc"]=="") {
			$asc = "&asc=desc";
		} else {
			$carat = '<sub style="font-weight: normal;"> v</sub>';
			$asc = "";
		}
	}
	if ($echo) {
		echo '<a href="' . INVENTORY_ADMIN_URL . '?page=inventory' . $url . '&sortby=' . $field . $asc . '">' . __($label . $carat,'inventory') . '</a>';
	} else {
		return '<a href="' . INVENTORY_ADMIN_URL . '?page=inventory' . $url . '&sortby=' . $field . $asc . '">' . __($label . $carat,'inventory') . '</a>';
	}
}

function inventory_items_per_page() {
	$ipp = isset($_POST["items_per_page"]) ? $_POST["items_per_page"] : 20;
	$ipp = isset($_GET["items_per_page"]) ? $_GET["items_per_page"] : $ipp;
	$choices = array(0=>"All Items", 10=>"10 per page", 20=>"20 per page", 50=>"50 per page", 100=>"100 per page");
	echo '<select name="items_per_page" onchange="this.form.submit();">';
	foreach ($choices as $v=>$d) {
		echo '<option value="' . $v . '"';
		if ($ipp==$v) {echo ' selected="selected"';}
		echo '>' . $d . '</option>';
	}
	echo '</select>';
	
}

// The item edit form for the manage inventory admin page
function wp_inventory_edit_form($mode='add', $inventory_id=false) {
	global $wpdb,$users_entries;
	$config = inv_getConfig();
	$limit_edit = inv_checkConfig($config, "limit_edit");
	$data = false;
	$fieldorder = inv_fieldOrder($config);
	if ($inventory_id !== false) {
		if ( intval($inventory_id) != $inventory_id ) {
			echo "<div class=\"error\"><p>".__('No inventory id set.','inventory')."</p></div>";
			return;
		} else {
			$data = $wpdb->get_results("SELECT * FROM " . WP_INVENTORY_TABLE . " WHERE inventory_id='" . mysql_escape_string($inventory_id) . "' LIMIT 1");
			if (empty($data)) {
				echo "<div class=\"error\"><p>".__("An item with that ID couldn't be found",'inventory')."</p></div>";
				return;
			}
			$data = $data[0];
		}
		// Check if it's the current user's item
		$cur_user_admin = (current_user_can('manage_options')) ? 1 : 0;
		if ($limit_edit && !$cur_user_admin) {
			$cur_user = wp_get_current_user();
			$cur_user = $cur_user->ID;
			if (!($data->inventory_userid==$cur_user || $data->inventory_userid==0)) {
				echo "<div class=\"error\"><p>".__("Not authorized to edit this item.",'inventory')."</p></div>";
					return;
			}
		}
		// Recover users entries if they exist; in other words if editing an event went wrong
		if (!empty($users_entries)) {
		    $data = $users_entries;
		}
	}
	// Deal with possibility that form was submitted but not saved due to error - recover user's entries here
	else {
	    $data = $users_entries;
	}
	?>
	<form enctype="multipart/form-data" name="itemform" id="itemform" class="wrap" method="post" action="<?php echo INVENTORY_ADMIN_URL; ?>?page=inventory">
		<input type="hidden" name="action" value="<?php echo $mode; ?>" />
		<input type="hidden" name="inventory_id" value="<?php echo $inventory_id; ?>" />
		<input type="hidden" name="inventory_prev_userid" value="<?php echo $data->inventory_userid; ?>" />
		<div id="linkadvanceddiv" class="postbox">
			<div style="float: left; width: 98%; clear: both;" class="inside">
                                <table cellpadding="5" cellspacing="5">
                                <tr>				
					<td><label><?php _e(inv_getLabel($config, "number"), 'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_number", $config, $data); ?></td>
                                </tr>
				<?php if ($fieldorder["name"]>=0) { ?>
                                <tr>				
					<td><label><?php _e(inv_getLabel($config, "name"),'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_name", $config, $data); ?></td>
                                </tr>
                                <?php } else { echo '<input type="hidden" name="inventory_name" value="' . stripslashes($data->inventory_name) . '" />';} ?>
       				<?php if ($fieldorder["image"]>=0) { ?>
					<?php 
						if (!empty($data) && $data->inventory_id) {
							$ires = $wpdb->get_results("SELECT * FROM " . WP_INVENTORY_IMAGES_TABLE . " WHERE inventory_id=" . $data->inventory_id);
							foreach ($ires as $imagedata) {
									echo '<tr><td><label>Existing Image</label>';
									echo '<br /><a href="' . INVENTORY_ADMIN_URL . '?page=inventory&action=delimage&image_id=' . $imagedata->inventory_images_id . '&inventory_id=' . $imagedata->inventory_id . '">(Remove)</a>';
									echo '</td><td><img style="max-width: 300px;"  src="' . INVENTORY_IMAGE_DIR . "/" . $imagedata->inventory_image . '">';
							} 
						}
					?>
				<tr><?php /* IMAGE UPLOAD */ ?>
					<td><label><?php _e('Add Image','inventory'); ?></label></td>
					<td>
						<input type="file" name="inv_image"  class="input" size="60" />
					</td>
                                </tr>
                                <tr>
                                	<td><label><?php _e('Create Thumbnail', 'inventory'); ?></label></td>
                                	<td>
                                		<input type="checkbox" name="inv_thumbnail" />
                                	</td>
                                </tr>
                                <?php } ?>
       				<?php if ($fieldorder["description"]>=0) { ?>
				<tr>
					<td style="vertical-align:top;"><label><?php _e(inv_getLabel($config, "description"),'inventory'); ?></label></td>
					<td><textarea name="inventory_description" class="input" rows="5" cols="50"><?php if ( !empty($data) ) echo stripslashes($data->inventory_description); ?></textarea></td>
                                </tr>
	                        <?php } else { echo '<input type="hidden" name="inventory_description" value="' . stripslashes($data->inventory_description) . '" />';} ?>
       				<?php if ($fieldorder["category_name"]>=0) { ?>
                                <tr>
				<td><label><?php _e(inv_getLabel($config, "category"),'inventory'); ?></label></td>
				<td><?php echo inventory_category_list($data->category_id, 0); ?></td></tr>
                                <tr>
	                        <?php } else { echo '<input type="hidden" name="category_id" value="' . $data->category_id . '" />';}  ?>
       				<?php if ($fieldorder["date_added"]>=0) { ?>
				<td><label><?php _e('Added Date','inventory'); ?></label></td>
	                                <td>
						<input type="text" name="date_added" class="input" size="12"
						value="<?php 
						if ( !empty($data))  {
							echo htmlspecialchars(date("m/d/Y", $data->date_added));
						} else {
							echo date("m/d/Y");
						} 
						?>" /> <a href="#" onClick="cal_begin.select(document.forms['quoteform'].event_begin,'event_begin_anchor','yyyy-MM-dd'); return false;" name="event_begin_anchor" id="event_begin_anchor"><?php _e('Select Date','inventory'); ?></a>
					</td>
                                </tr>
	                         <?php } else { echo '<input type="hidden" name="date_added" value="' . htmlspecialchars(date("m/d/Y", $data->date_added)) . '" />';}  ?>
                                <tr>				
					<td><label><?php _e('Display Order','inventory'); ?></label></td>
					<td><input type="text" name="inventory_order" class="input" size="10" maxlength="10"
					value="<?php if ( !empty($data) ) echo stripslashes($data->inventory_order); ?>" /></td>
                                </tr>
       				<?php if ($fieldorder["price"]>=0) { ?>	
				<tr>				
					<td><label><?php _e('Item Price','inventory'); ?></label></td>
					<td><input type="text" name="inventory_price" class="input" size="10" maxlength="20"
					value="<?php if ( !empty($data) ) echo stripslashes($data->inventory_price); ?>" /></td>
                                </tr>
	                       	<?php } else { echo '<input type="hidden" name="inventory_price" value="' . $data->inventory_price . '" />';}  ?>
       				<?php if ($fieldorder["size"]>=0) { ?>								
				<tr>				
					<td><label><?php _e(inv_getLabel($config, "size"),'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_size", $config, $data); ?></td>
                                </tr>
	                        <?php } else { echo '<input type="hidden" name="inventory_size" value="' . stripslashes($data->inventory_size) . '" />';}  ?>
       				<?php if ($fieldorder["quantity"]>=0) { ?>
				<tr>				
					<td><label><?php _e('Quantity','inventory'); ?></label></td>
					<td><input type="text" name="inventory_quantity" class="input" size="10" maxlength="30"
					value="<?php if ( !empty($data) ) echo stripslashes($data->inventory_quantity); ?>" /></td>
                                </tr>
	                        <?php } else { echo '<input type="hidden" name="inventory_quantity" value="' . stripslashes($data->inventory_quantity) . '" />';}  ?>
       				<?php if ($fieldorder["manufacturer"]>=0) { ?>
				<tr>				
					<td><label><?php _e(inv_getLabel($config, "manufacturer"),'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_manufacturer", $config, $data); ?></td>
                                </tr>
	                        <?php } else { echo '<input type="hidden" name="inventory_manufacturer" value="' . stripslashes($data->inventory_manufacturer) . '" />';}  ?>
       				<?php if ($fieldorder["make"]>=0) { ?>
				<tr>				
					<td><label><?php _e(inv_getLabel($config, "make"),'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_make", $config, $data); ?></td>
                                </tr>
                        	<?php } else { echo '<input type="hidden" name="inventory_make" value="' . stripslashes($data->inventory_make) . '" />';}  ?>
       				<?php if ($fieldorder["model"]>=0) { ?>
				<tr>				
					<td><label><?php _e(inv_getLabel($config, "model"),'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_model", $config, $data); ?></td>
                                </tr>
                        	<?php } else { echo '<input type="hidden" name="inventory_model" value="' . stripslashes($data->inventory_model) . '" />';}  ?>
       				<?php if ($fieldorder["year"]>=0) { ?>
				<tr>				
					<td><label><?php _e(inv_getLabel($config, "year"),'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_year", $config, $data); ?></td>
                                </tr>
                        	<?php } else { echo '<input type="hidden" name="inventory_year" value="' . stripslashes($data->inventory_year) . '" />';}  ?>
       				<?php if ($fieldorder["serial"]>=0) { ?>
				<tr>				
					<td><label><?php _e(inv_getLabel($config, "serial"),'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_serial", $config, $data); ?></td>
                                </tr>
                        	<?php } else { echo '<input type="hidden" name="inventory_serial" value="' . stripslashes($data->inventory_serial) . '" />';}  ?>
       				<?php if ($fieldorder["FOB"]>=0) { ?>
				<tr>				
					<td><label><?php _e(inv_getLabel($config, "FOB"),'inventory'); ?></label></td>
					<td><?php inv_type_input("inventory_FOB", $config, $data) ?></td>
                                </tr>
                                <?php } else { echo '<input type="hidden" name="inventory_FOB" value="' . stripslashes($data->inventory_FOB) . '" />';}  ?>
				<tr>				
					<td><label><?php _e('Owner E-mail','inventory'); ?></label></td>
					<td><input type="text" name="inventory_owner_email" class="input" size="60" maxlength="150"
					value="<?php if ( !empty($data) ) echo stripslashes($data->inventory_owner_email); ?>" /></td>
                                </tr>		
       				<?php if ($fieldorder["reserved"]>=0) { ?>	
				<tr>				
					<td><label><?php _e('Item Reserved','inventory'); ?></label></td>
					<td><input type="checkbox" name="inventory_reserved" class="input" <?php echo (!empty($data) && $data->inventory_reserved) ? ' checked="CHECKED"' : ''; ?> /></td>
                                </tr>
                                <?php } else { echo '<input type="hidden" name="inventory_reserved" value="' . stripslashes($data->inventory_reserved) . '" />';}  ?>
                                <tr>
                                	<td>&nbsp;</td>
                                	<td style="padding-top: 20px;"><input type="submit" name="save" class="button-primary" value="<?php _e('Save','inventory'); ?> &raquo;" />
                                	</td>
                                </tr>
                                </table>
			</div>
		</div>
	</form>
	<div style="clear:both; height:50px;">&nbsp;</div>
	<?php
}

function inventory_entry_field($order, $label, $fieldname, $value, $size=10) {
	if ($order>0) {
		echo '<tr>' . "\r\n";
		echo '<td><label>' . __($label,'inventory') . '</label></td>' . "\r\n";
		echo '<td><input type="text" name="' . $fieldname . '" class="input" size="' . $size . '" ';
		echo 'value="';
		if ( !empty($value) ) {echo stripslashes($value);}
		echo '" /></td>'  . "\r\n";
		echo '</tr>'  . "\r\n";
	} else {
		echo '<input type="hidden" name="' . $fieldname . '" value="' . stripslashes($value) . '" />';
	}
}

function inventory_category_list($current = "", $all=0, $onclick=0) {
	global $wpdb;
	$list = '<select class="inventory_category" name="category_id"';
	$list.= ($onclick) ? ' onchange="this.form.submit();"' : '';
	$list.= '>' . "\r\n";
	$cats = $wpdb->get_results("SELECT * FROM " . WP_INVENTORY_CATEGORIES_TABLE);
	if ($all) {
		$list.= '<option value=""';
		$list.= ($current == "") ? ' selected="selected"' : '';
		$list.= '>All</option>';
	}
	foreach($cats as $cat) {
		$list.= '<option value="'.$cat->category_id.'"';
		if ($current == $cat->category_id) {
		    	$list.= ' selected="selected"';
		}
                $list.= '>' . $cat->category_name . '</option>' . "\r\n";
	}
	$list.= "</select>" . "\r\n";
	return $list;
}

function inventory_do_Thumb($image) {
	global $shownImages;
	/**
	 *  
	 * This function is to return a "lightbox" functionality if there's a thumb/full-size combo of images.
	 * First, it checks the directory to see if there's any naming conventions that match
	 * image.jpg -> sm_image.jpg (sm_image.jpg is thumb, image is full size)
	 * lg_image.jpg -> sm_image.jpg (sm_image.jpg is thumb, lg_image is full size)
	 * lg_image.jpg -> image.jpg (lg_image.jpg is full, image.jpg is thumb)
	 * image.jpg -> image_large.jpg (image is thumb, image_large.jpg is full size)
	 * image_small.jpg -> image_large.jpg (image_small is thumb, image_large is full size)
	 * 
	 **/
	
	if (is_array($shownImages)) {
		if (in_array(strtolower($image), $shownImages))  {
			return "";
		}
	}
	
	$shownImages[] = strtolower($image);
	
	$prefixes = array("sm_", "lg_", "lrg_", "small_", "large_", "thumb_");
	$suffixes = array("_sm", "_lg", "lrg_", "_small", "_large", "_thumb");
	$ext = substr($image, strrpos($image, "."));
	$image_name = substr($image, 0, strlen($image)-strlen($ext));
	$image_base = $type = "";
	// Find base-name
	
	// Look for prefixes first
	foreach ($prefixes as $prefix) {
		if (strpos(strtolower($image_name), $prefix)===0) {
			$image_base = substr($image_name, strlen($prefix));
			$type = $prefix;
		}
	}
	
	// If no base found, look for suffixes
	if (!$image_base) {
		foreach ($suffixes as $suffix) {
			if (strpos(strtolower($suffix), $prefix)!==false) {
				$image_base = substr($image_name, strlen($prefix));
				$type = $prefix;
			}
		}
	}
	
	if (!$image_base) {$image_base = $image_name;}
	$match = inv_findImageMatch($image, $image_base, $ext);
	
	$shownImages[] = strtolower($match);
	
	// Sort out which image is the full and which is the small
	$small = array("sm_", "_sm", "_small", "small_", "thumb_", "_thumb");
	$images = array($image=>$match, $match=>$image);
	foreach($images as $s=>$f) {
		foreach ($small as $v) {
			if (stripos($s, $v)!==false) {
				$thumb_image = $s;
				$full_image = $f;
				break;
			}
		}
		if (isset($thumb_image) && $thumb_image) {break;}
	}
	
	if (!isset($thumb_image) || !$thumb_image) {
		$small = array("lg_", "_lg", "_large", "large_", "lrg_", "_lrg");
		foreach($images as $s=>$f) {
			foreach ($small as $v) {
				if (stripos($s, $v)!==false) {
					$thumb_image = $s;
					$full_image = $f;
					break;
				}
			}
			if (isset($thumb_image) && $thumb_image) {break;}
		}
	}

	if (isset($thumb_image) && $thumb_image && $full_image) {
		return '<a class="inv_lightbox" href="javascript:void(0);" onclick="invLightbox(\'' . INVENTORY_IMAGE_DIR . '/' . $full_image . '\');"><img class="inv_image" src="' . INVENTORY_IMAGE_DIR . '/' . $thumb_image . '" title="Click for Larger Image"></a>';
	} else {
		return '<img class="inv_image" src="' . INVENTORY_IMAGE_DIR . '/' . $image . '" title="">';
	}
}

function inv_findImageMatch($exist, $base, $ext) {
	$dir = @opendir(INVENTORY_LOAD_FROM);
	$prefixes = array("sm_", "lg_", "lrg_", "small_", "large_", "thumb_", "");
	$suffixes = array("_sm", "_lg", "_lrg", "_small", "_large", "_thumb");
	while ($file = readdir($dir)) {
		if ($file != $exist) {
			foreach ($prefixes as $prefix) {
				if (strtolower($file) == strtolower($prefix . $base . $ext)) {
					return $file;
				}
			}
			foreach ($suffixes as $suffix) {
				if (strtolower($file) == strtolower($base . $suffix . $ext)) {
					return $file;
				}
			}
		}
	}
}

// The actual function called to render the manage inventory page and 
// to deal with posts
function edit_inventory() {
    global $current_user, $wpdb, $users_entries;
	inv_showStyles("edit");

// First some quick cleaning up 
$edit = $create = $save = $delete = false;

$action = !empty($_REQUEST['action']) ? $_REQUEST['action'] : '';
$inventory_id = !empty($_REQUEST['inventory_id']) ? $_REQUEST['inventory_id'] : '';
$inventory_prev_userid = !empty($_REQUEST['inventory_prev_userid']) ? $_REQUEST['inventory_prev_userid'] : '';


// Lets see if this is first run and create us a table if it is!
check_inventory();

// Check if it's the current user's item
if ($inventory_id) {
	$config = inv_getConfig();
	$limit_edit = inv_checkConfig($config, "limit_edit");
	$cur_user_admin = (current_user_can('manage_options')) ? 1 : 0;
	if ($limit_edit && !$cur_user_admin) {
		$cur_user = wp_get_current_user();
		$cur_user = $cur_user->ID;
		$query = "SELECT inventory_userid FROM " . WP_INVENTORY_TABLE . " WHERE inventory_id=" . mysql_escape_string($inventory_id);
		$data = $wpdb->get_results($query);
		$data = $data[0];
		if (!($data->inventory_userid==$cur_user || $data->inventory_userid==0)) {
			echo "<div class=\"error\"><p>".__("Not authorized to edit this item.",'inventory')."</p></div>";
				return;
		}
	}
}

// First, let's check the delete image action and perform it
if ($action=="delimage") {
	$image_id = !empty($_REQUEST['image_id']) ? $_REQUEST['image_id'] : '';

	if ($image_id) {
		$directory = INVENTORY_SAVE_TO;
		$directory .= ($directory!="") ? "/" : "";
		$query = sprintf('SELECT inventory_image FROM ' . WP_INVENTORY_IMAGES_TABLE . ' WHERE inventory_images_id=%d', $image_id);
		$data = $wpdb->get_results($query);
		if (empty($data)) {
			echo "<div class=\"error\"><p>".__("An image with that ID couldn't be found",'inventory')."</p></div>";
			return;
		}
		$data = $data[0];
		inv_deleteFile($directory . $data->inventory_image);
		$query = sprintf('DELETE FROM ' . WP_INVENTORY_IMAGES_TABLE . ' WHERE inventory_images_id=%d', $image_id);
		
		$wpdb->query($query);
	}
	$action="edit";
	
}

// Deal with adding an item to the database
if ( $action == 'add' || $action=='edit_save') {
	$save = (isset($_REQUEST["inventory_name"])) ? 1 : 0;
	$number = !empty($_REQUEST['inventory_number']) ? $_REQUEST['inventory_number'] : '';
	$name = !empty($_REQUEST['inventory_name']) ? $_REQUEST['inventory_name'] : '';
	$desc = !empty($_REQUEST['inventory_description']) ? $_REQUEST['inventory_description'] : '';
	$order = !empty($_REQUEST['inventory_order']) ? $_REQUEST['inventory_order'] : '';
	$cat = !empty($_REQUEST['category_id']) ? $_REQUEST['category_id'] : '';
	$price = !empty($_REQUEST['inventory_price']) ? $_REQUEST['inventory_price'] : '';
	$size = !empty($_REQUEST['inventory_size']) ? $_REQUEST['inventory_size'] : '';
	$reserved = !empty($_REQUEST['inventory_reserved']) ? 1 : 0;
	$added = !empty($_REQUEST['date_added']) ? $_REQUEST['date_added'] : '';
	
	$quantity = !empty($_REQUEST['inventory_quantity']) ? $_REQUEST['inventory_quantity'] : '';
	$manufacturer = !empty($_REQUEST['inventory_manufacturer']) ? $_REQUEST['inventory_manufacturer'] : '';
	$FOB = !empty($_REQUEST['inventory_FOB']) ? $_REQUEST['inventory_FOB'] : '';
	$make = !empty($_REQUEST['inventory_make']) ? $_REQUEST['inventory_make'] : '';
	$model = !empty($_REQUEST['inventory_model']) ? $_REQUEST['inventory_model'] : '';
	$year = !empty($_REQUEST['inventory_year']) ? $_REQUEST['inventory_year'] : '';
	$serial = !empty($_REQUEST['inventory_serial']) ? $_REQUEST['inventory_serial'] : '';
	$owneremail = !empty($_REQUEST['inventory_owner_email']) ? $_REQUEST['inventory_owner_email'] : '';
	$detailpage = !empty($_REQUEST['inventory_detail_page']) ? $_REQUEST['inventory_detail_page'] : '';
	
	$image = isset($_FILES["inv_image"]["name"]) ? $_FILES["inv_image"]["name"] : "";
	if ($image) {
		$imgname = inv_doImages("inv_image");
		if ($imgname && isset($_POST["inv_thumbnail"])) {
			$scale="height";
			require_once "thumbnail.php";
			$type = strtolower(substr($imgname, strrpos($imgname, ".")+1));
			if ($type == "jpg") {$type = "jpeg";}
			$type = "image/" . $type;
			$tn = new Thumbnail(200,250, $scale);
			$image=file_get_contents(INVENTORY_IMAGE_DIR . "/" . $imgname);
			if ($tn->loadData($image, $type)) {
				$tn->buildThumb(INVENTORY_SAVE_TO . "/thumb_" . $imgname);
			}
		}
		if (!$imgname) {
			$strerror = "Image could NOT be uploaded.<br />";
		} else {
			$strerror = "";
		}
	}
	$order= ($order * 1);
	$order = (!$order) ? 0 : $order;
	$price = ($price * 1);
	$price = (!$price) ? 0 : $price;
	$added = strtotime($added);
	if (!$added) {$added = time();}

	if ( ini_get('magic_quotes_gpc') ) {
		$number = stripslashes($number);
		$name = stripslashes($name);
		$desc = stripslashes($desc);
		$order = stripslashes($order);
		$cat = stripslashes($cat);
		$price = stripslashes($price);
		$reserved = stripslashes($reserved);
        $added = stripslashes($added);
		$size = stripslashes($size);
		
		$quantity = stripslashes($quantity);
		$manufacturer = stripslashes($manufacturer);
		$detailpage = stripslashes($detailpage);
		$FOB = stripslashes($FOB);
		$make = stripslashes($make);
		$model = stripslashes($model);
		$year = stripslashes($year);
		$serial = stripslashes($serial);
		$owneremail = stripslashes($owneremail);
		
	}	
	
	// The name must be at least one character in length and no more than 100 - no non-standard characters allowed
	if ($save) {
		if (strlen(trim($name))>1 && strlen(trim($name))<100) {
		    $title_ok = 1;
		  } else { ?>
	              <div class="error"><p><strong><?php _e('Error','inventory'); ?>:</strong> <?php _e('The item name must be between 1 and 100 characters in length and contain no punctuation. Spaces are allowed but the title must not start with one.','inventory'); ?></p></div>
	              <?php
		  }
	}
	if ($save && $title_ok == 1) {
		 $wpdb->show_errors();
		 $cur_user = wp_get_current_user();
		$cur_user = $cur_user->ID;
		if ($action=="add") {
		    $sql = "INSERT INTO " . WP_INVENTORY_TABLE . " SET inventory_number='" . mysql_escape_string($number)
			. "', inventory_name='" . mysql_escape_string($name)
		    . "', inventory_description='" . mysql_escape_string($desc) . "', inventory_order='" . mysql_escape_string($order) 
	        . "', category_id='" . mysql_escape_string($cat) . "', date_added='" . mysql_escape_string($added)
			. "', inventory_size='" . mysql_escape_string($size)
			. "', inventory_price='" . mysql_escape_string($price) . "', inventory_reserved='" . mysql_escape_string($reserved)
			. "', inventory_userid='" . $cur_user
			. "', inventory_quantity='" . ($quantity*1)
			. "', inventory_manufacturer='" . mysql_escape_string($manufacturer)
			. "', inventory_FOB='" . mysql_escape_string($FOB)
			. "', inventory_make='" . mysql_escape_string($make)
			. "', inventory_model='" . mysql_escape_string($model)
			. "', inventory_year='" . mysql_escape_string($year)
			. "', inventory_serial='" . mysql_escape_string($serial)
			. "', inventory_owner_email='" . mysql_escape_string($owneremail) . "'"
			;
		    $wpdb->query($sql);
			$inventory_id = $wpdb->insert_id;
			$inv_msg = "Added";
		} elseif ($action="edit_save") {
			// ONLY update the user if the previous userid was set to zero
			$inv_user_update = (!$inventory_prev_userid) ? "', inventory_userid='" . $cur_user : "";
			// Save the changes
			$sql = "UPDATE " . WP_INVENTORY_TABLE . " SET inventory_number='" . mysql_escape_string($number)
			. "', inventory_name='" . mysql_escape_string($name)
		    . "', inventory_description='" . mysql_escape_string($desc) . "', inventory_order='" . mysql_escape_string($order) 
	        . "', category_id='" . mysql_escape_string($cat) . "', date_added='" . mysql_escape_string($added)
			. "', inventory_size='" . mysql_escape_string($size)
			. "', inventory_price='" . mysql_escape_string($price) . "', inventory_reserved='" . mysql_escape_string($reserved) 
			. "', inventory_quantity='" . ($quantity*1) 
			. "', inventory_manufacturer='" . mysql_escape_string($manufacturer)
			. "', inventory_detail_page='" . mysql_escape_string($detailpage)
			. "', inventory_FOB='" . mysql_escape_string($FOB)
			. "', inventory_make='" . mysql_escape_string($make)
			. "', inventory_model='" . mysql_escape_string($model)
			. "', inventory_year='" . mysql_escape_string($year)
			. "', inventory_serial='" . mysql_escape_string($serial)
			. "', inventory_owner_email='" . mysql_escape_string($owneremail)
			. $inv_user_update
			. "' WHERE inventory_id='" . mysql_escape_string($inventory_id) . "'";
		    $wpdb->query($sql);
			$inv_msg = "Updated";
		}
	
	    if (!$inventory_id) {
        	echo '<div class="error">
				<p><strong>' .  _e('Error','inventory') . ':</strong>' .
				_e('An item with the details you submitted could not be found in the database. This may indicate a problem with your database or the way in which it is configured.','inventory') 
				. '</p></div>';
	      } else {
	      	if ($imgname) {
				$imgquery = 'INSERT INTO ' . WP_INVENTORY_IMAGES_TABLE . ' SET inventory_id=' . $inventory_id . ', inventory_image="' . mysql_escape_string($imgname) . '"';
				$wpdb->query($imgquery);
		 }
		 echo '
			<div class="updated"><p>' .  __($strerror . 'Inventory item ' . $inv_msg . '. It will now show in your inventory.', 'inventory') . '<a href="' . INVENTORY_ADMIN_URL . '?page=inventory">' . __('View Inventory', 'inventory') . '</a>'
			 . '</p></div>';
	      }
	  } else {
	    // The form is going to be rejected due to field validation issues, so we preserve the users entries here
	    $users_entries->inventory_number = $number;
	    $users_entries->inventory_name = $name;
	    $users_entries->inventory_description = $desc;
	    $users_entries->date_added = $added;
	    $users_entries->inventory_price = $price;
	    $users_entries->inventory_order = $order;
	    $users_entries->inventory_reserved = $reserved;
	    $users_entries->inventory_category = $cat;
		
		$users_entries->inventory_quantity = $quantity;
		$users_entries->inventory_manufacturer = $manufacturer;
		$users_entries->inventory_FOB = $FOB;
		$users_entries->inventory_make = $make;
		$users_entries->inventory_model = $model;
		$users_entries->inventory_year = $year;
		$users_entries->inventory_serial = $serial;
		$users_entries->inventory_owner_email = $owneremail;
		$users_entries->inventory_detail_page = $detailpage;
	  }
}
// Deal with deleting an item from the database
elseif ($action == 'delete') {
	if (empty($inventory_id)) {
		?>
		<div class="error"><p><strong><?php _e('Error','inventory'); ?>:</strong> <?php _e("You can't delete an item if you haven't submitted an event id",'inventory'); ?></p></div>
		<?php			
	} else {
		$sql = "DELETE FROM " . WP_INVENTORY_TABLE . " WHERE inventory_id='" . mysql_escape_string($inventory_id) . "'";
		$wpdb->get_results($sql);
		
		$sql = "SELECT inventory_id FROM " . WP_INVENTORY_TABLE . " WHERE inventory_id='" . mysql_escape_string($inventory_id) . "'";
		$result = $wpdb->get_results($sql);
		
		if ( empty($result) || empty($result[0]->inventory_id) )
		{
			?>
			<div class="updated"><p><?php _e('Item deleted successfully','inventory'); ?></p></div>
			<?php
		} else {
		?>
			<div class="error"><p><strong><?php _e('Error','inventory'); ?>:</strong> <?php _e('Despite issuing a request to delete, the item still remains in the database. Please investigate.','inventory'); ?></p></div>
			<?php
		}		
	}
}
?>

<div class="wrap">
	<?php
	if ($action == 'edit' || ($action == 'edit_save' && $error_with_saving == 1))
	{
		?>
		<h2><?php _e('Edit Inventory Item','inventory'); ?></h2>
		<?php
		if ( empty($inventory_id) ) {
			echo "<div class=\"error\"><p>".__("You must provide an item id in order to edit it",'inventory')."</p></div>";
		} else {
			wp_inventory_edit_form('edit_save', $inventory_id);
		}	
	} elseif ($action=='add') { ?>
		 <h2><?php _e('Add Inventory Item','inventory'); ?></h2>
		<?php wp_inventory_edit_form();
	} else {
		?>
		
		<h2><?php _e('Manage Inventory','inventory'); ?></h2>
		<?php
		wp_inventory_display_list();
	}
	?>
</div>

<?php
 
}

function inv_current_blog_id() {
    global $wpdb;
    return $wpdb->blogid;
}

// Display the admin configuration page
function edit_inventory_config() {
  global $wpdb, $initial_style;

  // We can't use this page unless Inventory is installed/upgraded
  check_inventory();

  if (isset($_POST['permissions']) && isset($_POST['style'])) {
      if ($_POST['permissions'] == 'subscriber') { $new_perms = 'read'; }
      else if ($_POST['permissions'] == 'contributor') { $new_perms = 'edit_posts'; }
      else if ($_POST['permissions'] == 'author') { $new_perms = 'publish_posts'; }
      else if ($_POST['permissions'] == 'editor') { $new_perms = 'moderate_comments'; }
      else if ($_POST['permissions'] == 'admin') { $new_perms = 'manage_options'; }
      else { $new_perms = 'manage_options'; }
	$inventory_page = mysql_escape_string($_POST['inventory_page']);
	$inventory_style = mysql_escape_string($_POST['style']);
	$admin_email = mysql_escape_string($_POST['admin_email']);
	$email_owner = mysql_escape_string($_POST['email_owner']);
	$enable_categories='true';
	$limit_edit = mysql_escape_string($_POST["limit_edit"]);
	$items_per_page = mysql_escape_string($_POST["items_per_page"]);
	$placeholder_image = mysql_escape_string($_POST["placeholder_image"]);
	$item_name_link = (isset($_POST["item_name_link"])) ? 1 : 0;
	
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$inventory_page."' WHERE config_item='inventory_page'");
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$new_perms."' WHERE config_item='can_manage_inventory'");
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$inventory_style."' WHERE config_item='inventory_style'");
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$admin_email."' WHERE config_item='admin_email'");
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$email_owner."' WHERE config_item='e-mail_owner'");
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$limit_edit."' WHERE config_item='limit_edit'");
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$items_per_page."' WHERE config_item='items_per_page'");
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$placeholder_image."' WHERE config_item='placeholder_image'");
	$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '".$item_name_link."' WHERE config_item='item_name_link'");
	foreach ($_POST as $var=>$val) {
		if (strtolower(substr($var,0,5)=="label" || strtolower(substr($var,0,7)=="reserve"))) {
			$query = sprintf("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '%s' WHERE config_item='%s'", $val, $var);
			$wpdb->query($query);
		}
		if (strtolower(substr($var,0,4)=="type")) {
			$values = $_POST[$var . "_options"];
			$val.="||" . $values;
			$query = sprintf("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '%s' WHERE config_item='%s'", $val, $var);
			$wpdb->query($query);
		}
	}
	// Special treatment for display... enforce unique ordering
	foreach ($_POST as $var=>$val) {
		if ( strtolower(substr($var, 0, 7))=="display") {
			if (stripos($var, "spreadsheet")!==false || stripos($var, "everything")!==false) {
				$query = sprintf("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '%s' WHERE config_item='%s'", $val, $var);
				$wpdb->query($query);
			} else {
				$display[$var] = $val;
			}
		}
	}
	// Now write the order to the database, with adjustments for duplicates
	asort($display);
	$lastv = 0; 
	$index = 0;
	foreach ($display as $k=>$v) {
		if ($v>0) {
			$index++;
			$v = $index;
		}
		$query = sprintf("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value = '%s' WHERE config_item='%s'", $v, $k);
		$wpdb->query($query);
	}
      

      echo "<div class=\"updated\"><p><strong>".__('Settings saved','inventory').".</strong></p></div>";
    }

  // Pull the values out of the database that we need for the form
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='inventory_page'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $inventory_page = $config->config_value;
      }
  }
  
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='can_manage_inventory'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $allowed_group = $config->config_value;
      }
  }
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='limit_edit'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $limit_edit = $config->config_value;
      }
  }
  
   $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='wordpress_search'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $wordpress_search = $config->config_value;
      }
  }
  
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='admin_email'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $admin_email = $config->config_value;
      }
  } else {
  		$wpdb->query("INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='admin_email', config_value=''");
		$admin_email = "";
  }
  
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='e-mail_owner'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $email_owner = $config->config_value;
      }
  }

  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='inventory_style'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $inventory_style = $config->config_value;
      }
  }
  
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='items_per_page'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $items_per_page = $config->config_value;
      }
  }
  
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='placeholder_image'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $placeholder_image = $config->config_value;
      }
  }
  $configs = $wpdb->get_results("SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='item_name_link'");
  if (!empty($configs)) {
      foreach ($configs as $config) {
          $item_name_link = $config->config_value;
      }
  }
  
	if ($allowed_group == 'read') { $subscriber_selected='selected="selected"';}
	else if ($allowed_group == 'edit_posts') { $contributor_selected='selected="selected"';}
	else if ($allowed_group == 'publish_posts') { $author_selected='selected="selected"';}
	else if ($allowed_group == 'moderate_comments') { $editor_selected='selected="selected"';}
	else if ($allowed_group == 'manage_options') { $admin_selected='selected="selected"';}
  // Now we render the form
  
  inv_showStyles("config");
  ?>
 

  <div class="wrap">
  <h2><?php _e('Inventory Options','inventory'); ?></h2>
<form name="quoteform" id="quoteform" class="wrap" method="post" action="<?php echo INVENTORY_ADMIN_URL; ?>?page=inventory-config">
	<div id="linkadvanceddiv" class="postbox">
	<div id="optiontabs">
		<a href="javascript:void(0);" id="tab_all" class="current"><?php _e("All Options", "inventory"); ?></a>
		<a href="javascript:void(0);" id="tab_general"><?php _e("General", "inventory"); ?></a>
		<a href="javascript:void(0);" id="tab_display"><?php _e("Display", "inventory"); ?></a>
		<a href="javascript:void(0);" id="tab_label"><?php _e("Label", "inventory"); ?></a>
		<a href="javascript:void(0);" id="tab_type"><?php _e("Field Types", "inventory"); ?></a>
		<a href="javascript:void(0);" id="tab_reserve"><?php _e("Reserve", "inventory"); ?></a>
		<a href="javascript:void(0);" id="tab_style"><?php _e("Style", "inventory"); ?></a>
	</div>
    	<div style="float: left; width: 98%; clear: both;" class="inside">
    		<div id="general_options" class="inv_options">
        	<table cellspacing="0" class="inventory_options">
        		<tr>
        			<td class="h" colspan="2">
        				<h4>General Options</h4>
        			</td>
        		</tr>
        			<tr>
        				<td class="legend">
        					<label><?php _e('Page your inventory displays on','inventory'); ?></label>
        					<span class="tip"><?php _e("So the links to your item details work properly.  This is the page that should have the [INVENTORY] shortcode installed on it.", "inventory"); ?></span>
        					<?php echo (!$inventory_page) ? '<span class="tip" style="color: #900; font-weight: bold;">' . __('Warning: you may experience problems with links if this is not set to a specific page', 'inventory') . '</span>' : ''; ?>
        				</td>
        				<td>
        				<?php $list = wp_dropdown_pages(array('echo'=>'', 'selected'=>$inventory_page, 'name'=>'inventory_page')); 
        					$pos = stripos($list, ">");
        					$list = substr($list, 0, $pos+1) . '<option value="">' . __('None', 'inventory') . ' / ' . __('Multiple Pages', 'inventory') . '</option>' . substr($list, $pos+1);
        					echo $list;
        				?>
        				</td>
        			</tr>
				<tr>
		                	<td class="label">
			                	<label><?php _e('Lowest user group that may manage inventory','inventory'); ?></label>
					</td>
					<td>
					<select name="permissions">
				            <option value="subscriber"<?php echo $subscriber_selected ?>><?php _e('Subscriber','inventory')?></option>
				            <option value="contributor" <?php echo $contributor_selected ?>><?php _e('Contributor','inventory')?></option>
				            <option value="author" <?php echo $author_selected ?>><?php _e('Author','inventory')?></option>
				            <option value="editor" <?php echo $editor_selected ?>><?php _e('Editor','inventory')?></option>
				            <option value="admin" <?php echo $admin_selected ?>><?php _e('Administrator','inventory')?></option>
				        </select>
					</td>
				</tr>
				<tr>
	                		<td class="legend">
	                			<label><?php _e('Limit ability to edit to same user that added item?','inventory'); ?></label>
						<span class="tip"><?php _e("Yes: Only Admin and the user who entered item may edit.<br />No: Any user may edit.", 'inventory'); ?></span>
					</td>
					<td>
						<select name="limit_edit">
				            <option value="1"<?php inv_selected(1, $limit_edit); ?>>Yes</option>
				            <option value="0" <?php inv_selected(0, $limit_edit); ?>>No</option>
				        </select>
					</td>
				</tr>
				<tr>
					<td class="legend">
						<label><?php _e("Number of Items per page", 'inventory'); ?></label>
						<span class="tip"><?php _e("The number of items per page.<br />Leave as zero/blank for no limit.", 'inventory'); ?></span>
					</td>
					<td>
						<input type="text" name="items_per_page" value="<?php echo $items_per_page; ?>" size="10" />
					</td>
				</tr>
				<tr>
					<td class="legend">
						<label><?php _e("Item Name is link", 'inventory'); ?></label>
						<span class="tip"><?php _e("If you want the item name to be a link to view the details.", 'inventory'); ?></span>
					</td>
					<td>
						<?php $checked = ($item_name_link) ? ' checked="checked"' : ''; ?>
						<input type="checkbox" name="item_name_link"<?php echo $checked; ?>size="10" />
					</td>
				</tr>
				<tr>
					<td class="legend">
						<label><?php _e("Placeholder Image", 'inventory'); ?></label><span class="tip"><?php _e("Shown if there is no image for an item.<br />Leave blank to not use this feature.", 'inventory'); ?></span>
					</td>
					<td>
						<input type="text" name="placeholder_image" value="<?php echo $placeholder_image; ?>" size="35" />
					</td>
				</tr>
			</table>
			</div>
	    		<div id="display_options" class="inv_options">
        		<table cellspacing="0" class="inventory_options">
				<tr>
					<td class="h" colspan="2">
						<h4><?php _e("Display Options", 'inventory'); ?></h4>
					</td>
				</tr>
					<?php
					$config = inv_getConfig();
					echo '<tr><td class="legend"><label>' . __("Display as spreadsheet", 'inventory') . '?</label></td>';
					inv_yesnobox('display_as_spreadsheet', $config["display_as_spreadsheet"]);
					echo '<tr><td class="legend"><label>Display everything on detail page?</label></td>';
					inv_yesnobox('display_everything_on_detail_page', $config["display_everything_on_detail_page"]);
					foreach ($config as $key=>$value) {
						if (strtolower(substr($key,0, 7))=="display") {
							if (stripos($key, "spreadsheet")===false && stripos($key, "everything")===false) {
								$pair[$key] = $value;
							}
						}
					}
					asort($pair);
					$index = 1;
					// Show the ones that are NOT hidden first
					foreach ($pair as $key=>$value) {
							if ($value > 0) {
								$dispkey = str_replace("_", " ", $key) . "?";
								echo "<tr><td><label>" . __($dispkey, 'inventory') ."</label></td>";
								$value = $index++;
								inv_displayorderbox($key, $value);
							}
					}
					// Now show the ones that ARE hidden
					foreach ($pair as $key=>$value) {
							if ($value <= 0) {
								$dispkey = str_replace("_", " ", $key) . "?";
								echo "<tr><td><label>" . __($dispkey, 'inventory') ."</label></td>";
								inv_displayorderbox($key, $value);
							}
					}
						?>
			</table>
			</div>
	    		<div id="label_options" class="inv_options">
        		<table cellspacing="0" class="inventory_options">
				<tr>
					<td class="h" colspan="2">
						<h4><?php _e("Custom Labels", 'inventory'); ?></h4>
					</td>
				</tr>
					<?php
					foreach ($config as $key=>$value) {
						if (strtolower(substr($key,0, 5))=="label") {
							$dispkey = str_replace("_", " ", $key);
							$dispkey = substr($dispkey, 0, 5) . " for " . substr($dispkey, 6);
							echo "<tr><td  class='legend'><label>" . __($dispkey, 'inventory') ."</label></td>";
							echo '<td><input name="' . $key . '" value="' . $value . '" /></td></tr>';
						}
					}
						?>		
			</table>
			</div>
			<div id="type_options" class="inv_options">
        		<table cellspacing="0" class="inventory_options">
				<tr>
					<td class="h" colspan="2">
						<h4><?php _e("Field Types", 'inventory'); ?></h4>
					</td>
				</tr>
				<tr>
					<td colspan="2">Select the type of Input you would like to use for these fields (when you are adding/editing an inventory item).<br /><br />For <em>Select Dropdown</em> and <em>Radio Buttons</em>, enter the list of options in the &ldquo;Options&rdquo;, with each option separated by a semicolon.  <em>Example: light;heavy;super heavy;black hole heavy</em></td>
				</tr>
					<?php
					foreach ($config as $key=>$value) {
						if (strtolower(substr($key,0, 4))=="type") {
							$dispkey = str_replace("_", " ", $key);
							$dispkey = substr($dispkey, 0, 4) . " for " . inv_getLabel($config, substr($dispkey, 5)) .  " <small>(" . substr($dispkey, 5) . ")</small>";
							$values = explode("||", $value);
							echo "<tr><td class='types'><label>" . __($dispkey, 'inventory') ."</label></td>";
							echo '<td>' . inv_type_dropdown($key, $values[0]);
							echo ' <br /><label for="' . $key . '_options">Options:</label><input type="text" name="' . $key . '_options" value="' . $values[1] . '" class="types" />'
							 . '</td></tr>';
						}
					}
						?>		
			</table>
			</div>
	    		<div id="reserve_options" class="inv_options">
        		<table cellspacing="0" class="inventory_options">		
				<tr>
					<td class="h" colspan="2">
						<h4><?php _e("Reserve Item Options", 'inventory'); ?></h4>
					</td>
				</tr>
				<?php
					$config = inv_getConfig();
					foreach ($config as $key=>$value) {
						if (strtolower(substr($key,0, 7))=="reserve") {
							$dispkey = str_replace("_", " ", substr($key, 8));
							echo "<tr><td class='legend'><label>" . __($dispkey, 'inventory') ."</label>";
							if (stripos($key, "_display_")!==false) {
								echo '<span class="tip">' . __("Allows user to enter a quantity when reserving.", 'inventory') . '</span>';
								echo '</td>';
								inv_yesnobox($key, $value);
							} elseif (stripos($key, "_units")!==false) {
								echo '<span class="tip">' . __('For a drop-down, enter multiple items separated by commas.<br />For example "bottles, cases', 'inventory') . '</span>';
								echo '</td>';
								echo '<td><input name="' . $key . '" value="' . $value . '" /></td></tr>';
							} else {
								echo '</td>';
								inv_yesnobox($key, $value);
							}
						}
					}
						?>
				<tr>
					<td class='legend'>
						<label><?php _e("E-mail address to send to", 'inventory'); ?></label>
						<span class="tip"><?php _e("The admin/shop owner e-mail address that is used when<br />someone reserves an item.", 'inventory'); ?></span>
					</td>
					<td>
						<input type="text" name="admin_email" value="<?php echo $admin_email; ?>" size="40" />
					</td>
				</tr>
				<tr>
					<td>
						<label><?php _e("E-mail item owner also?", 'inventory'); ?></label>
						<span class="tip"><?php __("When reserving an item, an e-mail will also be sent to the <br />owner's e-mail address entered for the Inventory Item.", 'inventory'); ?></span>
					</td>
					<td>
					<select name="email_owner">
				            <option value="1"<?php inv_selected(1, $email_owner); ?>><?php _e("Yes", 'inventory'); ?></option>
				            <option value="0" <?php inv_selected(0, $email_owner); ?>><?php _e("No", 'inventory'); ?></option>
				        </select>
					</td>
				</tr>
			</table>
			</div>
	    		<div id="style_options" class="inv_options">
        		<table cellspacing="0" class="inventory_options">	
				<tr>
					<td class="h">
						<h4><?php _e("Stylesheet Settings", 'inventory'); ?></h4>
					</td>
				</tr>
				<tr>
					<td><label><?php _e('Configure the stylesheet for Inventory Display','inventory'); ?></label></td>
				</tr>
				<tr>
					<td><textarea name="style" rows="20" cols="80" style="width: 630px; height: 400px;"><?php echo $inventory_style; ?></textarea><br /></td>
				</tr>
			</table>
			</div>
			</div>
                        <div style="clear:both; height:1px;">&nbsp;</div>
	        </div>
                <input type="submit" name="save" class="button bold" value="<?php _e('Save','inventory'); ?> &raquo;" />
  </form>
  </div>
  <?php
}
// Function to mark option in drop-down as selected
function inv_selected($oval, $val) {
	echo ($oval == $val) ? ' selected="selected"' : '';
}

function inv_type_dropdown($key, $type) {
	$types = array("text"=>"Text Input",
		"textarea"=>"Text Area",
		"select"=>"Select Dropdown",
		"radio"=>"Radio Buttons");
	$list = '<select name="' . $key . '">' . "\r\n";
	foreach ($types as $v=>$d) {
		$list.= '<option value="' . $v . '"';
		$list.= ($v==$type) ? ' selected="selected"' : '';
		$list.= '>' . $d . '</option>' . "\r\n";
	}
	$list.= '</select>' . "\r\n";
	return $list;
}

function inv_type_input($field, $config, $data) {
	$sizes = array(
		"inventory_number"=>30,
		"inventory_name"=>100,
		"inventory_size"=>30,
		"inventory_manufacturer"=>75,
		"inventory_make"=>75,
		"inventory_model"=>75
	);
	$key = str_replace("inventory_", "", $field);
	$type = inv_getType($config, $key);
	$type = explode("||", $type);
	$opts = $type[1];
	$value = (!empty($data)) ? stripslashes($data->$field) : '';
	switch ($type[0]) {
		case "text";
			echo '<input type="text" name="' . $field . '" class="input" size="' . (int)(.66 * $sizes[$field]) . '" value="' . $value . '" />' . "\r\n";
			break;
		case "textarea":
			echo '<textarea name="' . $field . '" class="input" rows="5" cols="50">' . $value . '</textarea>' . "\r\n";
			break;
		case "select":
			$opts = explode(";", $opts);
			echo '<select name="' . $field . '">' . "\r\n";
			echo '<option value=""> - Select - </option>' . "\r\n";
			foreach ($opts as $opt) {
				if (trim($opt)) {
					echo '<option value="' . $opt . '"';
					echo ($opt == $value) ? ' selected="selected"' : '';
					echo '>' . $opt . '</option>' . "\r\n";
				}
			}
			echo '</select>' . "\r\n";
			break;
		case "radio":
			$opts = explode(";", $opts);
			foreach ($opts as $opt) {
				if (trim($opt)) {
					echo '<div class="radio" style="margin: 1px; padding: 1px; border-bottom: 1px solid #ddd;">';
					echo '<input type="radio" name="' . $field . '" id="' . $field . "-" . $opt . '" value="' . $opt . '"';
					echo ($opt == $value) ? ' checked="checked" />' : ' />';
					echo '<label style="margin-left: 5px;" for="' . $field . "-" . $opt . '">' . $opt . '</label>';
					echo '</div>';
				}
			}
			break;
		default:
			echo $type;
			print_r($opts);
			break;
	}
}

function inv_yesnobox($key, $val) {
	$val = $val * 1;
	echo '<td><select name="' . $key . '">';
	echo '<option value="1"';
	inv_selected(1, $val * 1);
	echo '>' . __("Yes", 'inventory') . '</option>' . "\r\n" . '<option value="0"';
	inv_selected(0, $val * 1);
	echo '>' . __("No", 'inventory') . '</option>' . "\r\n";
	echo "</select>\r\n</td></tr>" . "\r\n";
}

function inv_displayorderbox($key, $val) {
	$val = $val * 1;
	echo '<td><select name="' . $key . '">';
	if ($key!="display_inventory_category_name" && $key!="display_inventory_number") {
		echo '<option value="-1"';
		inv_selected(-1, $val * 1);
		echo '>' . __("Not Used", 'inventory') . '</option>' . "\r\n";
	}
	echo '<option value="0"';
	inv_selected(0, $val * 1);
	echo '>' . __("Do Not Display", 'inventory') . '</option>' . "\r\n";
	for ($i=1; $i<=16; $i++) {
		echo '<option value="' . $i . '"';
		inv_selected($i, $val);
		echo '>' . inv_ordinal($i) . '</option>';
	}
	echo "</select>\r\n</td></tr>" . "\r\n";
}

function inv_ordinal($value, $sup = 0){
// Function written by Marcus L. Griswold (vujsa)
// Can be found at http://www.handyphp.com
// Do not remove this header!
    is_numeric($value) or trigger_error("<b>\"$value\"</b> is not a number!, The value must be a number in the function <b>ordinal_suffix()</b>", E_USER_ERROR);
    if(substr($value, -2, 2) == 11 || substr($value, -2, 2) == 12 || substr($value, -2, 2) == 13){
        $suffix = "th";
    }
    else if (substr($value, -1, 1) == 1){
        $suffix = "st";
    }
    else if (substr($value, -1, 1) == 2){
        $suffix = "nd";
    }
    else if (substr($value, -1, 1) == 3){
        $suffix = "rd";
    }
    else {
        $suffix = "th";
    }
    if($sup){
        $suffix = "<sup>" . $suffix . "</sup>";
    }
    return $value . $suffix;
}

// Function to handle the management of categories
function manage_inventory_categories() {
  global $wpdb;

  // Inventory must be installed and upgraded before this will work
  check_inventory();
  inv_showStyles("categories");

  // We do some checking to see what we're doing
  if (isset($_POST['mode']) && $_POST['mode'] == 'add')
    {
      $sql = "INSERT INTO " . WP_INVENTORY_CATEGORIES_TABLE . " SET category_name='".mysql_escape_string($_POST['category_name'])."', category_colour='".mysql_escape_string($_POST['category_colour'])."'";
      $wpdb->get_results($sql);
      echo "<div class=\"updated\"><p><strong>".__('Category added successfully','inventory')."</strong></p></div>";
    }
  else if (isset($_GET['mode']) && isset($_GET['category_id']) && $_GET['mode'] == 'delete')
    {
      $sql = "DELETE FROM " . WP_INVENTORY_CATEGORIES_TABLE . " WHERE category_id=".mysql_escape_string($_GET['category_id']);
      $wpdb->get_results($sql);
      $sql = "UPDATE " . WP_INVENTORY_TABLE . " SET category_id=1 WHERE category_id=".mysql_escape_string($_GET['category_id']);
      $wpdb->get_results($sql);
      echo "<div class=\"updated\"><p><strong>".__('Category deleted successfully','inventory')."</strong></p></div>";
    }
  else if (isset($_GET['mode']) && isset($_GET['category_id']) && $_GET['mode'] == 'edit' && !isset($_POST['mode']))
    {
      $sql = "SELECT * FROM " . WP_INVENTORY_CATEGORIES_TABLE . " WHERE category_id=".mysql_escape_string($_GET['category_id']);
      $cur_cat = $wpdb->get_row($sql);
      ?>
<div class="wrap">
   <h2><?php _e('Edit Category','inventory'); ?></h2>
    <form name="catform" id="catform" class="wrap" method="post" action="<?php echo INVENTORY_ADMIN_URL; ?>?page=inventory-categories">
                <input type="hidden" name="mode" value="edit" />
                <input type="hidden" name="category_id" value="<?php echo $cur_cat->category_id ?>" />
                <div id="linkadvanceddiv" class="postbox">
                        <div style="float: left; width: 98%; clear: both;" class="inside">
				<table cellpadding="5" cellspacing="5">
                                <tr>
				<td><label><?php _e('Category Name','inventory'); ?>:</label></td>
                                <td><input type="text" name="category_name" class="input" size="30" maxlength="30" value="<?php echo $cur_cat->category_name ?>" /></td>
				</tr>
                                <tr>
				<td><label><?php _e('Category Colour (Hex format)','inventory'); ?>:</label></td>
                                <td><input type="text" name="category_colour" class="input" size="10" maxlength="7" value="<?php echo $cur_cat->category_colour ?>" /></td>
                                </tr>
                                </table>
                        </div>
                        <div style="clear:both; height:1px;">&nbsp;</div>
                </div>
                <input type="submit" name="save" class="button bold" value="<?php _e('Save','inventory'); ?> &raquo;" />
    </form>
</div>
      <?php
    }
  else if (isset($_POST['mode']) && isset($_POST['category_id']) && isset($_POST['category_name']) && isset($_POST['category_colour']) && $_POST['mode'] == 'edit')
    {
      $sql = "UPDATE " . WP_INVENTORY_CATEGORIES_TABLE . " SET category_name='".mysql_escape_string($_POST['category_name'])."', category_colour='".mysql_escape_string($_POST['category_colour'])."' WHERE category_id=".mysql_escape_string($_POST['category_id']);
      $wpdb->get_results($sql);
      echo "<div class=\"updated\"><p><strong>".__('Category edited successfully','inventory')."</strong></p></div>";
    }

  if ($_GET['mode'] != 'edit' || $_POST['mode'] == 'edit')
    {
?>

  <div class="wrap">
    <h2><?php _e('Add Category','inventory'); ?></h2>
    <form name="catform" id="catform" class="wrap" method="post" action="<?php echo INVENTORY_ADMIN_URL; ?>?page=inventory-categories">
                <input type="hidden" name="mode" value="add" />
                <input type="hidden" name="category_id" value="">
                <div id="linkadvanceddiv" class="postbox">
                        <div style="float: left; width: 98%; clear: both;" class="inside">
       				<table cellspacing="5" cellpadding="5">
                                <tr>
                                <td><label><?php _e('Category Name','inventory'); ?>:</label></td>
                                <td><input type="text" name="category_name" class="input" size="30" maxlength="30" value="" /></td>
                                </tr>
                                <tr>
                                <td><label><?php _e('Category Colour (Hex format)','inventory'); ?>:</label></td>
                                <td><input type="text" name="category_colour" class="input" size="10" maxlength="7" value="" /></td>
                                </tr>
                                </table>
                        </div>
		        <div style="clear:both; height:1px;">&nbsp;</div>
                </div>
                <input type="submit" name="save" class="button bold" value="<?php _e('Save','inventory'); ?> &raquo;" />
    </form>
    <h2><?php _e('Manage Categories','inventory'); ?></h2>
<?php
    
    // We pull the categories from the database	
    $categories = $wpdb->get_results("SELECT * FROM " . WP_INVENTORY_CATEGORIES_TABLE . " ORDER BY category_id ASC");

 if ( !empty($categories) )
   {
     ?>
     <table class="widefat page fixed" width="50%" cellpadding="3" cellspacing="3">
       <thead> 
       <tr>
         <th class="manage-column" scope="col"><?php _e('ID','inventory') ?></th>
	 <th class="manage-column" scope="col"><?php _e('Category Name','inventory') ?></th>
	 <th class="manage-column" scope="col"><?php _e('Category Colour','inventory') ?></th>
	 <th class="manage-column" scope="col"><?php _e('Edit','inventory') ?></th>
	 <th class="manage-column" scope="col"><?php _e('Delete','inventory') ?></th>
       </tr>
       </thead>
       <?php
       $class = '';
       foreach ( $categories as $category ) {
	   $class = ($class == 'alternate') ? '' : 'alternate';
         echo '<tr class="' .  $class . '">
	     <th scope="row">' . $category->category_id . '</th>
	     <td>' . $category->category_name . '</td>
	     <td style="background-color:' . $category->category_colour . '">&nbsp;</td>
	     <td><a href="' . INVENTORY_ADMIN_URL . '?page=inventory-categories&amp;mode=edit&amp;category_id=' . $category->category_id . 
		 '" class="edit">' . __('Edit','inventory') . '</a></td>';
	     if ($category->category_id == 1)  {
		 	echo '<td>' . __('N/A','inventory') . '</td>';
	     } else {
         	echo '<td><a href="' . INVENTORY_ADMIN_URL . '?page=inventory-categories&amp;mode=delete&amp;category_id=' . $category->category_id . 
				'" class="delete" onclick="return confirm(\'' . __('Are you sure you want to delete this category?','inventory') . '\');">' .
				 __('Delete','inventory') . '</a></td>';
         }
		 echo '</tr>';
      }
	  echo '</table>';
   } else {
     echo '<p>'.__('There are no categories in the database - something has gone wrong!','inventory').'</p>';
   }
   echo '</div>';
      } 
}

// Function to return a prefix which will allow the correct 
// placement of arguments into the query string.
function inventory_permalink_prefix() {
  // Get the permalink structure from WordPress
  
  $p_link = get_permalink();
  // Work out what the real URL we are viewing is
  $s = empty($_SERVER["HTTPS"]) ? '' : ($_SERVER["HTTPS"] == "on") ? "s" : ""; 
  $protocol = substr(strtolower($_SERVER["SERVER_PROTOCOL"]), 0, strpos(strtolower($_SERVER["SERVER_PROTOCOL"]), "/")).$s;
  $port = ($_SERVER["SERVER_PORT"] == "80") ? "" : (":".$_SERVER["SERVER_PORT"]);
  $real_link = $protocol.'://'.$_SERVER['SERVER_NAME'].$port.$_SERVER['REQUEST_URI'];

  // Now use all of that to get the correctly craft the Inventory link prefix
  if (strstr($p_link, '?') && $p_link == $real_link) {
      $link_part = $p_link.'&';
  } else if ($p_link == $real_link) {
      $link_part = $p_link.'?';
  } else if (strstr($real_link, '?')) {
      if (isset($_GET['month']) && isset($_GET['yr'])) {
	  		$new_tail = split("&", $real_link);
	  		foreach ($new_tail as $item) {
	      		if (!strstr($item, 'month') && !strstr($item, 'yr')) {
		  			$link_part .= $item.'&';
				}
	    	}
	  		if (!strstr($link_part, '?')) {
	      		$new_tail = split("month", $link_part);
	      		$link_part = $new_tail[0].'?'.$new_tail[1];
	  		}
		} else {
	  		$link_part = $real_link.'&';
		}
    } else {
      $link_part = $real_link.'?';
    }
  return $link_part;
}

function inventory_detail($inventory_id) {
    global $wpdb;
    check_inventory();
    	$config = inv_getConfig();
	$page_name = inv_get_permalink($config);
	if (isset($_POST["save"])) {
		//Means someone clicked the "reserve" button
		$showform = do_reserve_item();
	}
	
	$placeholder = inv_checkConfig($config, "placeholder_image");
	$show_everything = inv_checkConfig($config, "display_everything_on_detail_page");
	$form = "";
	$query = sprintf("SELECT " . WP_INVENTORY_TABLE . ".*, category_name, category_colour 
		FROM " . WP_INVENTORY_TABLE . " LEFT JOIN " . WP_INVENTORY_CATEGORIES_TABLE . 
		" ON " .  WP_INVENTORY_TABLE . ".category_id = " . WP_INVENTORY_CATEGORIES_TABLE . ".category_id" .
		" WHERE inventory_id=%d", $inventory_id);
	$items = $wpdb->get_results($query);	
	foreach ($items as $item) {
		$inventory_body.= "<h2>" . __('Item Detail', 'inventory') . ": " . $item->inventory_name . "</h2>";
		$inventory_body.= '<a href="' . inv_get_permalink($config) . '">&laquo; ' . __('Back to Item List', 'inventory')  . '</a>';
		$inventory_body.= '<div class="inventory">';
		$inventory_body.= (inv_checkConfig($config, "display_inventory_number")) ? '<p class="number"><span>' . inv_getLabel($config, "number") . ':</span> ' . $item->inventory_number . '</p>' : "";
		$inventory_body.= '<p><span>' . inv_getLabel($config, "name") . ':</span> ' . stripslashes($item->inventory_name) . '</p>';
		if ($show_everything || inv_checkConfig($config, "display_inventory_image")) {
			if (!empty($item) && $item->inventory_id) {
				$ires = $wpdb->get_results("SELECT * FROM " . WP_INVENTORY_IMAGES_TABLE . " WHERE inventory_id=" . $item->inventory_id);
				$imagecount=0;
				foreach ($ires as $imagedata) {
					$imagecount++;
					$inventory_body.= inventory_do_Thumb($imagedata->inventory_image);
					// $inventory_body.= '<img class="inv_image" style="max-width: 200px;" src="' . INVENTORY_IMAGE_DIR . '/' . $imagedata->inventory_image . '" alt="' . $item->inventory_name . '">';
				} 
				if (!$imagecount && $placeholder) {
					$inventory_body.= '<img class="inv_image" style="max-width: 200px;" src="' . INVENTORY_IMAGE_DIR . '/' . $placeholder . '" alt="' . $item->inventory_name . '">';
				}
			}
		}
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_manufacturer")) ? '<p><span>' . inv_getLabel($config, "manufacturer") . ':</span> ' . stripslashes($item->inventory_manufacturer) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_size")) ? '<p class="size"><span>' . inv_getLabel($config, "size") . ':</span> '. stripslashes($item->inventory_size) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_make")) ? '<p class="make"><span>' . inv_getLabel($config, "make") . ':</span> '. stripslashes($item->inventory_make) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_model")) ? '<p class="model"><span>' . inv_getLabel($config, "model") . ':</span> '. stripslashes($item->inventory_model) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_year")) ? '<p class="year"><span>' . inv_getLabel($config, "year") . ':</span> '. stripslashes($item->inventory_year) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_serial")) ? '<p class="serial"><span>' . inv_getLabel($config, "serial") . ':</span> '. stripslashes($item->inventory_serial) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_category_name")) ? '<p class="category"><span>' . inv_getLabel($config, "category") . ':</span> '. stripslashes($item->inventory_category_name) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_FOB")) ? '<p class="fob"><span>' . inv_getLabel($config, "FOB") . ':</span> '. stripslashes($item->inventory_FOB) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_description")) ? '<p class="description"><span>' . __(inv_getLabel($config, "description"), 'inventory') . ':</span> '. stripslashes($item->inventory_description) . '</p>' : "";
		$inventory_body.= ($show_everything || inv_checkConfig($config, "display_inventory_price")) ? '<p class="price">' . __('$', 'inventory') . number_format($item->inventory_price, 2) . '</p>' : "";
		
		$inventory_body.="</div>";
	}
	if (!$showform && inv_checkConfig($config, "display_inventory_reserved")) {
		$name = get_Inventory_Post("inv_name");
		$address = get_Inventory_Post("inv_address");
		$city = get_Inventory_Post("inv_city");
		$state = get_Inventory_Post("inv_state");
		$zip = get_Inventory_Post("inv_zip");
		$phone = get_Inventory_Post("inv_phone");
		$email = get_Inventory_Post("inv_email");
		$quantity = get_Inventory_Post("inv_quantity");
		$unit = get_Inventory_Post("inv_unit");
		$form.= inv_checkConfig($config, "reserve_require_name") ? '<tr><td><label for="inv_name">Your Name</label><input type="text" name="inv_name" value="' . $name . '" size="20" maxlength="50" /></td></tr>' : "";
		$form.= inv_checkConfig($config, "reserve_require_address") ? '<tr><td><label for="inv_address">Address</label><input type="text" name="inv_address" value="' . $address . '" size="40" maxlength="100" /></td></tr>' : '';
		$form.= inv_checkConfig($config, "reserve_require_city") ? '<tr><td><label for="inv_city">City, State, Zip</label><input type="text" name="inv_city" value="' . $city . '" class="city" maxlength="30" />
			<input type="text" name="inv_state" value="' . $state . '" class="state" maxlength="2" />
			<input type="text" name="inv_zip" value="' . $zip . '" class="zip" maxlength="12" /></td></tr>' : '';
		$form.= inv_checkConfig($config, "reserve_require_phone") ? '<tr><td><label for="inv_phone">Phone Number</label><input type="text" name="inv_phone" value="' . $phone . '" maxlength="50" /></td></tr>' : '';
		$form.= inv_checkConfig($config, "reserve_require_email") ? '<tr><td><label for="inv_email">E-Mail</label><input type="text" name="inv_email" value="' . $email . '" maxlength="150" /></td></tr>' : '';
		if (inv_checkConfig($config, "reserve_display_quantity")) {
			$form.= '<tr><td><label for="inv_quantity">Quantity</label><input type="text" name="inv_quantity" value="' . $quantity . '" class="zip">';
			$units = inv_checkConfig($config, "reserve_quantity_units");
			if (!$units) {$units = "each";}
			$units = explode(",", $units);
			if (is_array($units) && count($units)>1) {
				$form.= '<select name="inv_unit">';
				foreach ($units as $u) {
					$form.= '<option value="' . $u . '"';
					$form.= ($unit == $u) ? " SELECTED" : "";
					$form.= '>' . $u . '</option>';
				}
				$form.='</select>';
			} else {
				$form.= $units[0];
				$form.= '<input type="hidden" name="units" value="' . $units[0] . '">';
			}
			$form.= '</td></tr>';
		}
		$form.= '<input type="hidden" name="inv_id" value="' . $item->inventory_id . '">';
		$form.= '<tr><td><input type="submit" class="submit" name="save" value="Reserve"></td></tr>';
		$pageid = get_the_ID();
		$inventory_body.= '<h2>' . inv_getLabel($config, 'reserve_item_link') . '</h2>' . 
			'<form name="catform" id="catform" class="wrap" method="post" action="' . $page_name . 'inv_action=reserve&inventory_id=' . $item->inventory_id . '">' . 
			'<fieldset><legend>Your Information</legend><table cellspacing="0" cellpadding="0">' . 
			$form . "</table></form></td></tr></table></fieldset></form>";
	}
    return $inventory_body;	
}

// Function to deal with post of reserve item page
function do_reserve_item() {
	$config = inv_getConfig();
	// Get the posted information
	$name = get_Inventory_Post("inv_name");
	$address = get_Inventory_Post("inv_address");
	$city = get_Inventory_Post("inv_city");
	$state = get_Inventory_Post("inv_state");
	$zip = get_Inventory_Post("inv_zip");
	$phone = get_Inventory_Post("inv_phone");
	$email = get_Inventory_Post("inv_email");
	$inventory_id = get_Inventory_Post("inv_id");
	$strerror.=validate_Inventory_Input($inventory_id, "", "No item indicated!");
	$strerror.=inv_checkConfig($config, "reserve_require_name") ? validate_Inventory_Input($name, "",  "<br />You must enter your name.") : '';
	$strerror.=inv_checkConfig($config, "reserve_require_address") ? validate_Inventory_Input($address, "",  "<br />You must enter your address.") : '';
	$strerror.=inv_checkConfig($config, "reserve_require_city") ? validate_Inventory_Input($city, "",  "<br />You must enter your city, state and zip.") : '';
	$strerror.=inv_checkConfig($config, "reserve_require_email") ? validate_Inventory_Input($email, "E", "<br />You must enter a valid e-mail.") : '';
	$strerror.=inv_checkConfig($config, "reserve_require_phone") ? validate_Inventory_Input($phone, "P", "<br />You must enter your phone.") : '';
	
	if ($strerror) {
		echo "<p style='width: 450px; color: #900; padding: 0 10px 10px 10px; border: 1px solid #900;font-weight:bold;font-size:1.5em;margin-left: 10px;'>" . $strerror . "</p>";
		return false;	
	}
	
	global $wpdb;
	$query = sprintf("SELECT " . WP_INVENTORY_TABLE . ".*, category_name, category_colour 
		FROM " . WP_INVENTORY_TABLE . " LEFT JOIN " . WP_INVENTORY_CATEGORIES_TABLE . 
		" ON " .  WP_INVENTORY_TABLE . ".category_id = " . WP_INVENTORY_CATEGORIES_TABLE . ".category_id" .
		" WHERE inventory_id=%d", $inventory_id);
	$items = $wpdb->get_results($query);
	$item = $items[0];
	
	$config = inv_getConfig();
	DEFINE("LF", "\r\n");
	$subject = __("Item Reservation", "inventory") . " - " . stripslashes($item->inventory_number) . " - " . stripslashes($item->inventory_name);
	$body = __("The following person would like to reserve an item", "inventory") . ":" . LF . $name . LF;
	$body.= $address . LF;
	$body.= $city . ", " . $state . " " . $zip . LF;
	$body.= __("Phone", "inventory") . ": " . $phone . LF;
	$body.= __("Email", "inventory") . ": " . $email;
	$body.= LF . LF;
	$body.= inv_getLabel($config, "number") . ": " . stripslashes($item->inventory_number) . LF;
	$body.= inv_getLabel($config, "name") . ": " . stripslashes($item->inventory_name) . LF;
	if (inv_checkConfig($config, "reserve_display_quantity")) {
		$quantity = get_Inventory_Post("inv_quantity");
		$unit = get_Inventory_Post("inv_unit");
		$body.= __("Quantity", "inventory") . ": " . $quantity . " " . $unit . LF;
	}
	$body.= __("Price", "inventory") . ': ' . __("$", "inventory") . number_format($item->inventory_price);
	$owner_email = $item->inventory_owner_email;

	$toemail = inv_checkConfig($config, "admin_email");
	$email_owner = inv_checkConfig($config, "e-mail_owner");
	if (!$toemail) {
		echo "<p style='width: 450px; color: #900; padding: 0 10px 10px 10px; border: 1px solid #900;font-weight:bold;font-size:1.5em;'>" . __("No e-mail configured in Inventory Options.", "inventory") . "</p>";
		return false;
	}
	wp_mail($toemail, $subject, $body);
	if ($email_owner && $owner_email) {
		wp_mail($owner_email, $subject, $body);
	}
	echo "<p style='color: black;font-weight:bold;border:1px solid black;padding: 10px;font-size: 1.5em; width:450px;margin-left: 10px;'>" . __("Reservation request sent.  Thank you!", "inventory") . "</p>";
	return true;
}

function inventory_shortcode($atts, $content = null) {
	return inventory_display($atts);
}

// Function to DISPLAY the inventory ... called by {INVENTORY} tag
function inventory_display($params = "") {
    	global $wpdb;
    	check_inventory();
	$config = inv_getConfig();
	if (!is_array($params)) {
		$p = explode(";", $params);
		foreach ($p as $k=>$v) {
			$v = explode("=", $v);
			if (is_array($v)) {
				$v[0] = strtolower($v[0]);
				$v[0] = str_replace("<code>", "", str_replace("</code>", "", $v[0]));
				if ($v[0]) {
					$settings[trim(strtolower($v[0]))]=$v[1];
				}
			} else {
				$v[0] = strtolower($v[0]);
				$v[0] = str_replace("<code>", "", str_replace("</code>", "", $v[0]));
				$settings[strtolower($v)] = true;
			}
		}
	} else {
		$settings = $params;
	}

	if (isset($settings["category"])) {
		$categoryid = $settings["category"];
		if (!is_numeric($categoryid)) {
			$cat = $wpdb->get_results("SELECT category_id, category_name FROM " . WP_INVENTORY_CATEGORIES_TABLE . " WHERE category_name='" . mysql_real_escape_string($categoryid) . "'");
			foreach ($cat as $c) {
				$categoryid = $c->category_id;
				$catname = $c->category_name;
			}
		} else {
			$cat = $wpdb->get_results("SELECT category_id, category_name FROM " . WP_INVENTORY_CATEGORIES_TABLE . " WHERE category_id='" . $categoryid * 1 . "'");
			foreach ($cat as $c) {
				$categoryid = $c->category_id;
				$catname = $c->category_name;
			}
		}
		$inv_search_sql = " WHERE " . WP_INVENTORY_TABLE . ".category_id=" . $categoryid * 1;
	} else {
		$inv_search = (isset($_POST["category_id"])) ? $_POST["category_id"] : "";
		$inv_search = (!$inv_search && isset($_GET["category_id"])) ? $_GET["category_id"] : $inv_search;
		$inv_search = mysql_escape_string($inv_search);
		$inv_search_sql = ($inv_search) ? " WHERE " . WP_INVENTORY_TABLE . ".category_id=" . $inv_search : "";
		$catname = "";
	}
	// The WordPress search page
	$wpsearch = get_search_query();
	if ($wpsearch) {
		// We're using the WordPress search function.  Let's build our where clause....
		$words = explode(" ", $wpsearch);
		foreach ($words as $w) {
			$where.= ($where) ? " AND " : '';
			$where.= ' [[FIELD]] LIKE "%' . $w . '%"';
		}
		$where = '(' . $where . ')';
		$inv_search_sql = ' WHERE (' . str_replace('[[FIELD]]', 'inventory_name', $where) . ' OR ' . 
			str_replace('[[FIELD]]', 'inventory_description', $where) . ' OR ' . 
			str_replace('[[FIELD]]', 'inventory_size', $where) . ' OR ' . 
			str_replace('[[FIELD]]', 'inventory_manufacturer', $where) . ' OR ' . 
			str_replace('[[FIELD]]', 'inventory_description', $where) . ' OR ' . 
			str_replace('[[FIELD]]', 'inventory_make', $where) . ' OR ' .
			str_replace('[[FIELD]]', 'inventory_model', $where) . ' OR ' .
			str_replace('[[FIELD]]', 'inventory_serial', $where) . ' OR ' .
			str_replace('[[FIELD]]', 'inventory_number', $where) . ')';
	}
	
	if (isset($settings["sort"])) {
		$sort = "";
		$sb = strtolower($settings["sort"]);
		$asc = "";
		if (stripos($sb, " asc")!==false || stripos($sb, " desc")!==false) {
			$asc = (stripos($sb, " desc")!==false) ? $asc = " DESC" : " ASC";
			$sb = str_replace(" desc", "", str_replace(" asc", "", $sb)); 
		}
		$easy = array("number", "name", "description", "order", "size", "price", "id", "quantity", "manufacturer", "FOB", "make", "model", "year", "serial");
		// Check if they used default/simple names
		if (in_array($sb, $easy)) {
			"GOT EASY";
			$sort = "inventory_" . $sb;
		}
		if (!$sort) {
			if (stripos($sb, "date")!==false) {$sort = "date_added";}
			if (stripos($sb, "category")!==false) {$sort = "category_name";}
		}
		if (!$sort) {
			$conf = $wpdb->get_results("SELECT config_item FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_value='" . mysql_real_escape_String($sb) . "'");
			foreach ($conf as $c) {
				if (stripos($c->config_item, "label")!==false) {
					$sort = "inventory_" . str_replace("label_", "", $c->config_item);
					break;
				}
			}
		}
		$inv_sort = ($sort) ? $sort . $asc : '';
		
	} else {
		$inv_sort = (isset($_POST["inv_sort"])) ? $_POST["inv_sort"] : ""; 
		$inv_sort = (!$inv_sort && isset($_GET["inv_sort"])) ? $_GET["inv_sort"] : $inv_sort;
		$inv_sort = mysql_escape_string($inv_sort);
	}

	$inv_sort_sql = ($inv_sort) ? $inv_sort . ", inventory_order" : "inventory_order";
	
	if (isset($settings["display"]) && $settings["display"]) {
		$inv_display_spreadsheet = (stripos($settings["display"], "spread")!==false) ? 1 : 0;
	} else {
		$inv_display_spreadsheet = inv_checkConfig($config, "display_as_spreadsheet");
	}

	if (isset($settings["items_per_page"])) {
		$ipp = $settings["items_per_page"] * 1;
		if ($ipp < 2) {$ipp = 2;}
	} else {
		$ipp = (inv_checkConfig($config, "items_per_page")*1);
	}

	$inventory_pages = $limit = "";
	if ($ipp) {
		$showpages = false;
		$start = (isset($_GET["start"])) ? ($_GET["start"] * 1) : 0;
		$start = (!$start && isset($_POST["start"])) ? ($_POST["start"] * 1) : $start;
		$next = ($start + $ipp)*1;
		$prev = max(0,($start-$ipp)*1);
		$limit = " LIMIT " . $start . ", " . $ipp;
		$counts = $wpdb->get_results("SELECT count(inventory_id) AS count FROM " . WP_INVENTORY_TABLE . " LEFT JOIN " . WP_INVENTORY_CATEGORIES_TABLE .
			" ON " .  WP_INVENTORY_TABLE . ".category_id = " . WP_INVENTORY_CATEGORIES_TABLE . ".category_id" .
		$inv_search_sql);
		foreach ($counts as $count) {
			$last = ($count->count*1);
		}
		$page_name = inv_get_permalink($config);
		if ($last>$ipp) {
			$showpages = true;
			$last=($last-$ipp)*1;
			$next = min($next, $last);
			$inv_qs = "&inv_sort=" . $inv_sort;
			$inv_qs.= "&inv_search=" . $inv_search;
			$inventory_pages.= '<div id="inv_page">';
			$inventory_pages.=($start!=0) ? '<a class="first" href="' . $page_name . 'start=0"' . $inv_qs . '>&laquo; ' . __('First', 'inventory') . '</a>' : '<span>&lt;&lt ' . __('First', 'inventory') . '</span>';	
			$inventory_pages.=($start!=0) ? '<a class="prev" href="' . $page_name . 'start=' . $prev . $inv_qs . '">&lt; ' .  __('Prev', 'inventory') . '</a> | ' : '<span>&lt; ' .  __('Prev', 'inventory') . '</span> | ';
			$inventory_pages.=($start<$next) ? '<a class="next" href="' . $page_name . 'start=' . $next . $inv_qs . '">' . __('Next', 'inventory') . ' &gt;</a>' : '<span>' . __('Next', 'inventory') . ' &gt;</span>';	
			$inventory_pages.=($start<$last) ? '<a class="last" href="' . $page_name . 'start=' . $last . $inv_qs . '">' . __('Last', 'inventory') . ' &raquo; </a>' : '<span>' . __('Last', 'inventory') . ' &gt;&gt;</span>';
			$inventory_pages.='</div>';
		}
		
	}
	echo (isset($_GET["ts"])) ? "SELECT " . WP_INVENTORY_TABLE . ".*, category_name, category_colour 
		FROM " . WP_INVENTORY_TABLE . " LEFT JOIN " . WP_INVENTORY_CATEGORIES_TABLE . 
		" ON " .  WP_INVENTORY_TABLE . ".category_id = " . WP_INVENTORY_CATEGORIES_TABLE . ".category_id" .
		$inv_search_sql . 
		" ORDER BY " . $inv_sort_sql . " ASC" . $limit : "";
	$items = $wpdb->get_results("SELECT " . WP_INVENTORY_TABLE . ".*, category_name, category_colour 
		FROM " . WP_INVENTORY_TABLE . " LEFT JOIN " . WP_INVENTORY_CATEGORIES_TABLE . 
		" ON " .  WP_INVENTORY_TABLE . ".category_id = " . WP_INVENTORY_CATEGORIES_TABLE . ".category_id" .
		$inv_search_sql . 
		" ORDER BY " . $inv_sort_sql . " ASC" . $limit);
	$inventory_body = "";
	$count = 0;
	foreach ($items as $item) {
		$inventory_body.= inv_displayItem($inv_display_spreadsheet, $item, $config, $count++);
	}
	if ($inv_display_spreadsheet==1) {
		$inventory_body = '<table class="inv_table">' . inv_tableTitles($config) . $inventory_body . '</table>';
	}
	if ($inventory_body) {
		$sort_fields = array(""=>"Default",
				"category_name"=>inv_getLabel($config, "category"),
				"inventory_number"=>inv_getLabel($config, "number"), 
				"inventory_name"=>inv_getLabel($config, "name"), 
				"inventory_make"=>inv_getLabel($config, "make"),
				"inventory_model"=>inv_getLabel($config, "model"),
				"inventory_year"=>inv_getLabel($config, "year"),
				"inventory_fob"=>inv_getLabel($config, "FOB"),
				"inventory_price ASC"=>"Price (Low to High)",
				"inventory_price DESC"=>"Price (High to Low)", 
				"inventory_manufacturer"=>inv_getLabel($config, "manufacturer")
			);
		if (!$wpsearch) {
			$inventory_search = '<form class="inv_sort_form" method="post">';
			$inventory_search.= '<label for="inv_sort" class="inv_sort_label">' . __('Sort By', 'inventory') . ':</label><select name="inv_sort"  class="inv_sort" onchange="this.form.submit();">';
			$fieldorder = inv_fieldOrder($config);
			foreach ($sort_fields as $v=>$d) {
				$fok = strtolower(str_replace("inventory_", "", $v));
				$fok = (strripos($fok, " ") !== false) ? substr($fok, 0, strripos($fok, " ")) : $fok;
				$fok = ($fok == "fob") ? "FOB" : $fok;
				if ($d && $fieldorder[$fok] > 0) {
					$inventory_search.= '<option value="' . $v . '"';
					$inventory_search.= ($inv_sort && $inv_sort==$v) ? " SELECTED" : "";
					$inventory_search.= '>' . $d . '</option>';
				}
			}
			$inventory_search.= '</select>';
			$inventory_search.= '<input type="hidden" name="start" value="' . $start . '">';
			if (!$catname) {
				$inventory_search.= '<label for="inv_search" class="inv_search_label">' . __('Show Only', 'inventory') . ':</label>';
				$inventory_search.= inventory_category_list($inv_search, 1, 1);
			} else {
				$inventory_search.= '<label for="inv_search" class="inv_search">' . __('Category','inventory') . ':</label> <strong>' . $catname . '</strong>';
			}
			
			$inventory_search.= '<input type="submit" id="inv_sort_submit" name="inv_go" value="Sort">';
			$inventory_search.= '</form>';
			$inventory_search.='<script type="text/javascript">document.getElementById("inv_sort_submit").style.display="none";</script>';
			$h1 = (isset($h1_title)) ? '<h1 class="inventory_title">' . $h1_title . '</h1>' : '';
		} else {
			$inventory_search = "";
			$h1 = '<h1 class="inventory_title">' . __('Inventory Search Results for', 'inventory') . ': ' . $wpsearch . '</h1>';
		}
		$inventory_body = $inventory_search . $h1 . $inventory_pages . $inventory_body . $inventory_pages;
	}
	$inventory_body.='<div style="clear:both;height:10px;"></div>';
	if (!$wpsearch) {
		return $inventory_body;
	} else {
		echo $inventory_body;
	}
}

function inv_fieldOrder($config) {
	foreach ($config as $k=>$v) {
		if (stripos($k, "display")===0 && stripos($k, "spreadsheet")===false && stripos($k, "everything")===false && stripos($k, "reserve_quantity")===false) {
			$order[str_replace("display_", "", str_replace("inventory_", "", $k))] = $v;
		}
	}
	asort($order);
	$index = 0;
	foreach ($order as $k=>$v) {
		if ($v>0) {
			$index++;
			$order[$k] = $index;
		}
	}
	return $order;
}

function inv_displayItem($type, $item, $config, $count=0) {
	$page_name = inv_get_permalink($config);
	global $wpdb;
	$placeholder = inv_checkConfig($config, "placeholder_image");
	$item_name_link = inv_checkConfig($config, "item_name_link");
	$rowclass = ($count%2) ? ' class="oddrow"' : '';
	$divclass = ($count%2) ? ' oddrow' : '';
	$fieldorder = inv_fieldOrder($config);
	$item_name = stripslashes($item->inventory_name);
	$item_name = ($item_name_link) ? '<a href="' . $page_name . 'inv_action=detail&inventory_id=' . $item->inventory_id . '">' . $item_name . '</a>' : $item_name;
	if ($type) {
		//Spreadsheet Style
		$inv_body[$fieldorder["number"]] = '<td class="number">' . stripslashes($item->inventory_number) . '</td>';
		$inv_body[$fieldorder["name"]] = '<td class="name">' . $item_name . '</td>';
		if (inv_checkConfig($config, "display_inventory_image")) {
			$imagedata = $wpdb->get_results("SELECT * FROM " . WP_INVENTORY_IMAGES_TABLE . " WHERE inventory_id=" . $item->inventory_id);
			$inv_body[$fieldorder["image"]] = '<td class="images">';
			$imagecount = 0;
			foreach ($imagedata as $image) {
				$imagecount++;
				$inv_body[$fieldorder["image"]].= inventory_do_Thumb($image->inventory_image);
			}
			if (!$imagecount && $placeholder) {
				$inv_body[$fieldorder["image"]].= '<img style="max-width: 100px;" src="' . INVENTORY_IMAGE_DIR . '/' . $placeholder . '" alt="' . $item->inventory_name . '">';
			}
			$inv_body[$fieldorder["image"]].= '</td>';
		}
		$inv_body[$fieldorder["manufacturer"]] = '<td class="manufacturer">'. stripslashes($item->inventory_manufacturer) . '</td>';
		$inv_body[$fieldorder["size"]] = '<td class="size">'. stripslashes($item->inventory_size) . '</td>';
		$inv_body[$fieldorder["quantity"]] = '<td class="quantity">'. stripslashes($item->inventory_quantity) . '</td>';
		$inv_body[$fieldorder["make"]] = '<td class="make">'. stripslashes($item->inventory_make). '</td>';
		$inv_body[$fieldorder["model"]] = '<td class="model">'. stripslashes($item->inventory_model) . '</td>';
		$inv_body[$fieldorder["year"]] = '<td class="year">'. stripslashes($item->inventory_year) . '</td>';
		$inv_body[$fieldorder["serial"]] = '<td class="serial">'. stripslashes($item->inventory_serial) . '</td>';
		$inv_body[$fieldorder["category_name"]] = '<td class="category">'. stripslashes($item->category_name) . '</td>';
		$inv_body[$fieldorder["FOB"]] = '<td class="FOB">'. stripslashes($item->inventory_FOB) . '</td>';
		$inv_body[$fieldorder["description"]] = '<td class="description">'. stripslashes($item->inventory_description) . '</td>';
		$inv_body[$fieldorder["price"]] = '<td class="price">$'. number_format($item->inventory_price, 2) . '</td>';
		if (inv_checkConfig($config, "display_inventory_reserved")) {
			$inv_body[$fieldorder["reserved"]] = '<td class="reserved">';
			if ($item->inventory_reserved) {
				$inv_body[$fieldorder["reserved"]].= 'Reserved';
			} else {
				$inv_body[$fieldorder["reserved"]].= '<a href="' . $page_name . 'inv_action=reserve&inventory_id=' . $item->inventory_id . '">' . inv_getLabel($config, 'reserve_item_link') . '</a>';
			}
			$inv_body[$fieldorder["reserved"]].= '</td>';
		}
		unset($inv_body[0]);
		unset($inv_body[-1]);
		ksort($inv_body);
		$inventory_body = '<tr' . $rowclass . '>' . implode("", $inv_body) . "</tr>
		";
	} else {
		// Div Style
		$inv_body[$fieldorder["number"]] = '<p class="number"><span>' . inv_getLabel($config, "number") . ': </span> ' . stripslashes($item->inventory_number) . '</p>';
		$inv_body[$fieldorder["name"]] = '<p class="name"><span>' . inv_getLabel($config, "name") . ': </span>' . $item_name . '</p>';
		if (inv_checkConfig($config, "display_inventory_image")) {
			$imagedata = $wpdb->get_results("SELECT * FROM " . WP_INVENTORY_IMAGES_TABLE . " WHERE inventory_id=" . $item->inventory_id);
			$imagecount=0;
			$inv_body[$fieldorder["image"]] = "";
			foreach ($imagedata as $image) {
				$imagecount++;
				$inv_body[$fieldorder["image"]].= inventory_do_Thumb($image->inventory_image);
			}
			if (!$imagecount && $placeholder) {
				$inv_body[$fieldorder["image"]].= '<img style="max-width: 100px;" src="' . INVENTORY_IMAGE_DIR . '/' . $placeholder . '" alt="' . $item->inventory_name . '">';
			}
		}
		$inv_body[$fieldorder["manufacturer"]] = '<p class="manufacturer"><span>' . inv_getLabel($config, "manufacturer") . ': </span>'. stripslashes($item->inventory_manufacturer) . '</p>';
		$inv_body[$fieldorder["size"]] = '<p class="size"><span>' . inv_getLabel($config, "size") . ': </span>'. stripslashes($item->inventory_size) . '</p>';
		$inv_body[$fieldorder["quantity"]] = '<p class="quantity"><span>Quantity: </span>'. stripslashes($item->inventory_quantity) . '</p>';
		$inv_body[$fieldorder["make"]] = '<p class="make"><span>' . inv_getLabel($config, "make") . ': </span>'. stripslashes($item->inventory_make) . '</p>';
		$inv_body[$fieldorder["model"]] = '<p class="model"><span>' . inv_getLabel($config, "model") . ': </span>'. stripslashes($item->inventory_model) . '</p>';
		$inv_body[$fieldorder["year"]] = '<p class="year"><span>' . inv_getLabel($config, "year") . ': </span>'. stripslashes($item->inventory_year) . '</p>';
		$inv_body[$fieldorder["serial"]] = '<p class="serial"><span>' . inv_getLabel($config, "serial") . ': </span>'. stripslashes($item->inventory_serial). '</p>';
		$inv_body[$fieldorder["category_name"]] = '<p class="category"><span>' . inv_getLabel($config, "category") . ': </span>'. stripslashes($item->category_name) . '</p>';
		$inv_body[$fieldorder["FOB"]] = '<p class="FOB"><span>' . inv_getLabel($config, "FOB") . ': </span>'. stripslashes($item->inventory_FOB) . '</p>';
		$inv_body[$fieldorder["description"]] = '<p class="description"><span>' . __(inv_getLabel($config, "description"), 'inventory') . ': </span>'. stripslashes($item->inventory_description) . '</p>';
		$inv_body[$fieldorder["price"]] = '<p class="price"><span>' . __('Price', 'inventory') . ': </span>' . __('$', 'inventory') . number_format($item->inventory_price, 2) . '</p>';
		if (inv_checkConfig($config, "display_inventory_reserved")) {
			$inv_body[$fieldorder["reserved"]] = "";
			if ($item->inventory_reserved) {
				$inv_body[$fieldorder["reserved"]].= '<p class="reserved">Reserved</p>';
			} else {
				$inv_body[$fieldorder["reserved"]].= '<a href="' . $page_name . 'inv_action=detail&inventory_id=' . $item->inventory_id . '">' . inv_getLabel($config, 'reserve_item_link') . '</a>';
			}
		}
		unset($inv_body[0]);
		unset($inv_body[-1]);
		ksort($inv_body);
		$inventory_body.= '<div class="inventory' . $divclass . '">' . implode("", $inv_body) .  "</div>
		";
	}
	return $inventory_body;
}

function inventory_instructions() {
	echo '<h2>' . __('Inventory Plugin Instructions', 'inventory') . '</h2>';
	echo '<h3>' . __('Displaying Inventory in a Page', 'inventory') . '</h3>';
	echo '<p>' . __('To display inventory on a page, simply add the shortcode <code>[INVENTORY]</code> at the location in the page you would like the inventory to display.' , 'inventory') . '</p>';
	echo '<p>' . __('To display inventory from only a single category, or sorted a certain way, you can use some parameters, like so: </p>
	<p><code>[INVENTORY]</code> will cause the page to list all items, sorted by the Order field.
				<br /><code>[INVENTORY <span>category=2</span>]</code> will cause the page to list ONLY those items in category with id #2
				<br /><code>[INVENTORY <span>category="logging"</span>]</code> will list ONLY those items in the Logging category
				<br /><code>[INVENTORY <span>items_per_page="3"</span>]</code> will over-ride the Configuration items per page, and only list 3 items per page
				<br /><code>[INVENTORY <span>sort="number"</span>]</code> will over-ride the Configuration sorting, and sort items by their number (most fields work - see complete sort field listing below)
				<br /><code>[INVENTORY <span>display="spreadsheet"</span>]</code> will over-ride the Configuration display, display the listing in a spreadsheet
				<br /><code>[INVENTORY <span>category="general" items_per_page="5" sort="date desc" display="normal"</span>]</code> will list ONLY items from General category, 5 items per page, sorted by date added descending, displaying as individual divs (instead of spreadsheet style).  <strong>Note the semi-colons used to separate the parameters.</strong>', 'inventory');
	echo '<h3>' . __('Customizing the Display of Your Inventory', 'inventory') . '</h3>';
	echo '<p>' . __('There&rsquo;s lots of settings in the <a href="' . INVENTORY_ADMIN_URL . '?page=manage-options.php">Manage Options</a> page for how to display the inventory, as well as which fields to display, etc.  I recommend playing with those and seeing the effects.</p>
	<p>Also, you can control a lot of the display through your CSS.  Pretty much everything has classes or ids to help you change the display of the items on an item-by-item basis.  Use your browsers &ldquo;View Source&rdquo; function to see the code and classes.', 'inventory') . '</p>';
	echo '<h3>' . __('Adding Inventory to Your WordPress Search Results', 'inventory') . '</h3>';
	echo '<p>' . __('After much thrashing around trying to hack together a more automatic way, it became clear that the cleanest, best, and really ONLY way to add inventory items to your WordPress search results is through a slight modification to the search.php template file.  All you need to do is put this line of code in the search.php template file where you want the search results to display: <code>&lt;?php inventory_display(); ?&gt;</code></p>
	<p>See below for an example, based on the code from the Twenty-Ten theme:', 'inventory') . '</p>';
	echo "<code>&lt;div id=&quot;container&quot;>
		<br />&nbsp;&nbsp;&nbsp;&nbsp; &lt;div id=&quot;content&quot; role=&quot;main&quot;&gt;
			<br />&nbsp;&nbsp;&nbsp;&nbsp; &nbsp;&nbsp;&nbsp;&nbsp;<span style='color:#900; font-weight: bold;'>&lt;?php inventory_display(); ?&gt;</span>
				<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; &lt;?php if ( have_posts() ) : ?&gt;
					<br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; &lt;h1 class=&quot;page-title&quot;>&lt?php printf( __( &apos;Search Results for: %s&;squo, &apos;twentyten&apos; ), &apos;<span>&apos; . get_search_query() . &apos;</span>&apos; ); ?&gt;&lt;/h1&gt;
				<br /> &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; &lt;?php
				/* Run the loop for the search to output the results.
				 <br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; * If you want to overload this in a child theme then include a file
				 <br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; * called loop-search.php and that will be used instead.
				 <br />&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; */</code>";

}

function inv_tableTitles($config) {
	$fieldorder = inv_fieldOrder($config);
	$inv_body[$fieldorder["number"]] = '<td class="number">' . inv_getLabel($config, "number")  . '</td>';
	$inv_body[$fieldorder["name"]] = '<td class="name">' . inv_getLabel($config, "name")  . '</td>';
	$inv_body[$fieldorder["image"]] = '<td class="images">Images</td>';
	$inv_body[$fieldorder["manufacturer"]] = '<td class="manufacturer">' . inv_getLabel($config, "manufacturer")  . '</td>';
	$inv_body[$fieldorder["size"]] = '<td class="size">' . inv_getLabel($config, "size")  . '</td>';
	$inv_body[$fieldorder["quantity"]] = '<td class="quantity">Quantity</td>';
	$inv_body[$fieldorder["make"]] = '<td class="make">' . inv_getLabel($config, "make")  . '</td>';
	$inv_body[$fieldorder["model"]] = '<td class="model">' . inv_getLabel($config, "model")  . '</td>';
	$inv_body[$fieldorder["year"]] = '<td class="year">' . inv_getLabel($config, "year")  . '</td>';
	$inv_body[$fieldorder["serial"]] = '<td class="serial">' . inv_getLabel($config, "serial")  . '</td>';
	$inv_body[$fieldorder["category_name"]] = '<td class="category">' . inv_getLabel($config, "category")  . '</td>';
	$inv_body[$fieldorder["FOB"]] = '<td class="FOB">' . inv_getLabel($config, "FOB")  . '</td>';
	$inv_body[$fieldorder["description"]] = '<td class="description">' . __(inv_getLabel($config, "description"), 'inventory') . '</td>';
	$inv_body[$fieldorder["price"]] = '<td class="price">' . __('Price', 'inventory') . '</td>';
	if (inv_checkConfig($config, "display_inventory_reserved")) {
		$inv_body[$fieldorder["reserved"]] = '<td class="reserved">Reserve</td>';
	}
	unset($inv_body[0]);
	ksort($inv_body);	
	$inventory_body = '<tr class="titles">' . implode("", $inv_body) . "</tr>";
	return $inventory_body;
}

function inventory_upload() {
	// The purge process
	if (isset($_GET["action"])) {
		$type = (isset($_GET["type"])) ? strtolower(substr($_GET["type"], 0, 1)) : '';
		$desc = __(' ALL INVENTORY ITEMS AND CATEGORIES', 'inventory');
		$desc = ($type=="c") ? ' INVENTORY CATEGORIES ONLY' : '';
		$desc = ($type=="i") ? ' INVENTORY ITEMS ONLY' : '';
		switch(strtolower(substr($_GET["action"], 0, 1))) {
			case "p":
				echo '<h2>' . __('Confirm Database Purge', 'inventory') . '</h2>';
				echo '<p class="error" style="width: 450px;"><strong>' . __('FIRST WARNING', 'inventory') . ':</strong><br />This action will remove ALL ' . $desc . ' from your database.
					<br /><br />This means that:';
				$count=1;
				if ($type!='c') {
					echo '<br />' . $count . ') ' . __('All INVENTORY items will be removed,', 'inventory');
					$count++;
					echo '<br />' . $count . ') ' . __('All IMAGE RECORDS will be removed (images ARE NOT deleted)', 'inventory');
					$count++;
				}
				if ($type!='i') {
					echo '<br />' . $count . ') ' . __('and all CATEGORIES will be removed.', 'inventory');
				}
				echo '<br /><br />' . __('There is NO undoing this action.', 'inventory') . '
					<br />' . __('Are you sure you want to proceed?', 'inventory') . '</p>';
				echo '<p style="margin-left: 10px;"><a class="button-highlighted" href="' . INVENTORY_ADMIN_URL . '?page=inventory-upload&action=confirm&type=' . $type . '">' . __('Yes - Proceed', 'inventory') . '</a>';
				echo ' <a class="button-primary" href="' . INVENTORY_ADMIN_URL . '?page=inventory-upload">' . __('No! Cancel!', 'inventory') . '</a></p>';
				break;
			case "c":
				echo '<h2>' . __('Confirm Database Purge', 'inventory') . '</h2>';
				echo '<p class="error" style="width: 450px;"><strong>' . __('FINAL WARNING', 'inventory') . ':</strong><br />' . __('This is your final warning.  If you click "CONFIRM" below, you will delete ALL', 'inventory') . ' ' . $desc . ' ' . __('from your database, and will not be able to undo-it.  Are you sure?', 'inventory'), '</p>';
				echo '<p style="margin-left: 10px;"><a class="button-primary" href="' . INVENTORY_ADMIN_URL . '?page=inventory-upload">' . __('No! Cancel!', 'inventory') . '</a>';
				echo ' <a class="button-highlighted" href="' . INVENTORY_ADMIN_URL . '?page=inventory-upload&action=final_confirm&type=' . $type . '">' . __('CONFIRM - DELETE MY INVENTORY', 'inventory') . '</a>';
				break;
			case "f":
				inventory_purge();
				echo '<h2>' . __('Database Purged', 'inventory') . '</h2>';
				echo '<p>' . __('The database has been purged', 'inventory') . ':</p>';
				echo ($type!='c') ? '<ol><li>' . __('All inventory items removed', 'inventory') . '</li>' : '';
				echo ($type!='c') ? '<li>' . __('All inventory image records removed (images were NOT deleted)', 'inventory') . '</li>' : '';
				echo ($type!='i') ?  '<li>' . __('All categories removed (except default "General" category)', 'inventory') . '</li>' : '';
				echo '</ol>';
				break;
		}
		return;
	}
	
	inv_showStyles("edit");
	echo '<div class="wrap">';
	
	echo '<h2>' . __('Inventory Upload','inventory') . '</h2>';
	if (isset($_POST["file_select"])) {
		if (isset($_FILES["inv_upload"])) {
			$inv_file = $_FILES["inv_upload"];
			$inv_file_name = $inv_file["name"];
			$ext =strtoupper( substr($inv_file_name ,strlen($inv_file_name )-(strlen($inv_file_name  ) - (strrpos($inv_file_name ,".") ? strrpos($inv_file_name ,".")+1 : 0) ))  ) ;
			if ($ext!="CSV" && $ext!="TXT") {
				$strerror = '"' . __('The file must be csv or txt format.','inventory') . '"';
			}
			if (!$strerror) {
				inv_uploadFile($inv_file, $inv_file_name, INVENTORY_SAVE_TO, true);
				inventory_map_form($inv_file_name);
			}
		}
	} elseif (isset($_POST["map_fields"])) {
		inventory_map_fields();
	} else {
		$showform = true;
	}
	
	$strerror = ($strerror) ? '<p class="error">' . $strerror . '</p>' : "";
	echo $strerror;
	
	// This section is the form
	if ($showform) {inventory_upload_form();}
	
}

function inventory_purge() {
	global $wpdb;
	$type = (isset($_GET["type"])) ? strtolower(substr($_GET["type"], 0, 1)) : '';
	if ($type!="c") {
		$wpdb->query("TRUNCATE TABLE " . WP_INVENTORY_TABLE);
		$wpdb->query("TRUNCATE TABLE " . WP_INVENTORY_IMAGES_TABLE);
	}
	if ($type!="i") {
		$wpdb->query("DELETE FROM " . WP_INVENTORY_CATEGORIES_TABLE . " WHERE category_id<>1");
	}
}

function inventory_map_fields() {
	$directory = INVENTORY_SAVE_TO;
	$directory .= ($directory!="") ? "/" : "";
	$inv_file_name = $_POST["inv_file_name"];
	$gotdate = $gotdescription = $gotorder = $gotprice = $gotcategory = false;
	$gotnumber = $gotname = false;
	foreach ($_POST as $k=>$v) {
		if (substr($k, 0, 10)=="field_map_") {
			$index = substr($k, 10);
			$field_map[$index]=$v;
			if ($v=="date_added") {$gotdate = true;}
			if ($v=="inventory_description") {$gotdescription=true;}
			if ($v=="inventory_order") {$gotorder=true;}
			if ($v=="category_id") {$gotcategory=true;}
			if ($v=="inventory_number") {$gotnumber=true;}
			if ($v=="inventory_name") {$gotname=true;}
			if ($v=="inventory_price") {$gotprice=true;}
		}
	}
	if (!$gotnumber) {$strerror.=__("You must have a field for &ldquo;Inventory Number&rdquo;", 'inventory') . '.<br />';}
	if (!$gotname) {$strerror.=__('You must have a field for &ldquo;Inventory Name&rdquo;.', 'inventory') . '<br />';}
	foreach ($field_map as $k=>$v) {
		foreach ($field_map as $ck=>$cv) {
			if ($v==$cv && $k!=$ck && $v!="") {
				$strerror.=__("You have indicated the same field twice for", "inventory") . " &ldquo;" . str_replace("_", " ", $v) . "&rdquo;.<br />";
			}
		}
	}
	
	if (!$strerror) {
		global $wpdb;
		$inv_file = @fopen($directory . $inv_file_name, "r") or die(__("Unable to open uploaded file!", "inventory"));
		$inv_row = fgets($inv_file, 4096);
		if (isset($_POST["titlerow"])) {
			$inv_row = fgets($inv_file, 4096);
		}
		if (stripos($inv_row, "\t")) {
			$sep = "\t";
		} else {
			$sep = ",";
		}
		while (!feof($inv_file)) {
			$inv_fields = explode($sep, $inv_row);
			$rowcount = 0;
			$rowname = true;
			$inv_upload_sql[$count]="";
			$inv_upload_image_sql[$count]="";
			foreach ($inv_fields as $f) {
				$f = inventory_clean_import($f, $field_map[$rowcount]);
				if (isset($field_map[$rowcount]) && $field_map[$rowcount] && $field_map[$rowcount]!="inventory_image") {
					$inv_upload_sql[$count].=($inv_upload_sql[$count]) ? "," : "";
					$inv_upload_sql[$count].=sprintf(' %s="%s"', mysql_real_escape_string($field_map[$rowcount]), mysql_real_escape_string($f));
				}
				if (!$gotdescription && $field_map[$rowcount]=="inventory_name") {
					$inv_upload_sql[$count].= sprintf(', inventory_description="%s"', mysql_real_escape_string($f));
				}
				if ($field_map[$rowcount]=="inventory_image") {
					$images = explode(",", $f);
					foreach ($images as $k=>$i) {
						$inv_upload_image_sql[$count][] = 'inventory_image = "' . trim(mysql_real_escape_string($i)) . '"';
					}
				}
				$rowcount++;
			}
			$inv_upload_sql[$count].=(!$gotdate) ? ", date_added=" . time() : "";
			$inv_upload_sql[$count].=(!$gotorder) ? ", inventory_order=1" : "";
			$inv_upload_sql[$count].=(!$gotcategory) ? ", category_id=1" : "";
			$inv_upload_sql[$count].=(!$gotprice) ? ", inventory_price=0" : "";
			if (stripos($inv_upload_sql[$count], "inventory_name")!==false) {
				$count++;
			} else {
				$inv_upload_sql[$count]="";
			}
			$inv_row = fgets($inv_file, 4096);
		}
		foreach($inv_upload_sql as $i=>$query) {
			if ($query) {
				$query = "INSERT INTO " . WP_INVENTORY_TABLE . " SET " . $inv_upload_sql[$i];
				$wpdb->query($query);
				if (isset($inv_upload_image_sql[$i]) && is_array($inv_upload_image_sql[$i])) {
					$itemid = $wpdb->insert_id;
					foreach($inv_upload_image_sql[$i] as $k=>$q) {
						$query = "INSERT INTO " . WP_INVENTORY_IMAGES_TABLE . " SET inventory_id=" . $itemid . ", " . $q;
						$wpdb->query($query);
					}
				}
			}
		}
		echo "<strong>" . __("Success", "inventory") . "!</strong>";
		echo "<p>" . $count . " " . __("records imported", "inventory") . ".</p>";
	} else {
		echo '<p class="error">' . $strerror . '</p>';
		inventory_map_form($inv_file_name);
	}
	
}

function inventory_clean_import($f, $field="") {
	$f=utf8_encode($f);
	$f = str_replace("\r\n", "", $f);
	if (substr($f, 0, 1)=='"' && substr($f, -1)=='"') {
		$f = substr($f, 1, strlen($f)-2);
	}
	if ($field=="inventory_price") {
		$f=preg_replace("/[^0-9\.]/", "", $f);
		$f=$f*1;
	}
	if ($field=="inventory_quantity") {
		$f=preg_replace("/[^0-9]/", "", $f);
		$f=$f*1;
	}
	if ($field=="category_id") {
		$f=$f*1;
	}
	return $f;
}

function inventory_upload_categories($inv_file_name) {
	$directory = INVENTORY_SAVE_TO;
	$directory .= ($directory!="") ? "/" : "";
	if (!$strerror) {
		global $wpdb;
		$inv_file = @fopen($directory . $inv_file_name, "r") or die(__("Unable to open uploaded categories file!", "inventory"));
		$inv_row = fgets($inv_file, 4096);
		if (stripos($inv_row, "\t")) {
			$sep = "\t";
		} else {
			$sep = ",";
		}
		$count = 0;
		while (!feof($inv_file)) {
			$inv_fields = explode($sep, $inv_row);
			$inv_fields[0] = inventory_clean_import($inv_fields[0]);
			$inv_upload_sql[$count].=sprintf(' %s="%s"', 'category_name', mysql_real_escape_string($inv_fields[0]));
			$count++;
			$inv_row = fgets($inv_file, 4096);
		}
		foreach($inv_upload_sql as $i=>$query) {
			if ($query) {
				$query = "INSERT INTO " . WP_INVENTORY_CATEGORIES_TABLE . " SET category_colour='', " . $inv_upload_sql[$i];
				echo '<br />' . $query;
				$wpdb->query($query);
			}
		}
		echo "<strong>" . __("Success", "inventory") . "!</strong>";
		echo "<p>" . $count . " " . __("category records imported", "inventory") . ".</p>";
		die();
	} else {
		echo '<p class="error">' . $strerror . '</p>';
		inventory_upload();
	}
	
}

function inventory_map_form($inv_file_name) {
	if (isset($_POST["inv_categories"])) {
		inventory_upload_categories($inv_file_name);
	}
	global $wpdb;
	echo '<style type="text/css">.inv_map_form {border-collapse: collapse;}.inv_map_form td {border:1px solid #888; padding: 3px 5px;font-size:8pt;}</style>';
	echo '<form name="inventory_upload" action="' . INVENTORY_ADMIN_URL . '?page=inventory-upload" method="post">';
	echo '<input type="hidden" name="inv_file_name" value="' . $inv_file_name . '">';
	echo '<p>Sample rows from your file....</p>';
	// Do some housecleaning - delete downloaded files over 5 days old
	$days = (60 * 60 * 24);
	$directory = INVENTORY_SAVE_TO;
	$directory .= ($directory!="") ? "/" : "";
	//echo $directory;
	$handler = opendir($directory);
	while ($file = readdir($handler)) {
		  if ($file != '.' && $file != '..' && (substr($file, -4)==".csv" || substr($file, -4)==".txt")) {
		  	$fullfile = $directory . $file;
			if (filemtime($fullfile) < time() - (60 * 60 * 24 * 5)) {
				unlink($fullfile);
			}
		  }
	}
	closedir($handler);
	$inv_file = @fopen($directory . $inv_file_name, "r") or die(__("Unable to open uploaded file!", "inventory"));
	$inv_row = fgets($inv_file, 4096);
	if (stripos($inv_row, "\t")) {
		$sep = "\t";
	} else {
		$sep = ",";
		echo '<div class="error">' . __('Warning! Your file appears to be comma separated.  Be certain that any number fields do not contain commas (such as 1,500)', 'inventory') . '</div>';
	}
	$inv_table = "";
	$count = 0;
	$rowcount= 0;
	// List the fields from the database into a select box so we can prompt for which field is which
	$field_vars = array(""=>"Select Field...",
		"inventory_number"=>__("Item Number", "inventory"),
		"inventory_name"=>__("Item Name", "inventory"),
		"inventory_description"=>__("Item Description", "inventory"),
		"inventory_order"=>__("Item Sort Order", "inventory"),
		"inventory_size"=>__("Item Size", "inventory"),
		"inventory_price"=>__("Item Price", "inventory"),
		"inventory_quantity"=>__("Quantity", "inventory"),
		"inventory_manufacturer"=>__("Manufacturer", "inventory"),
		"inventory_FOB"=>__("FOB", "inventory"),
		"inventory_make"=>__("Make", "inventory"),
		"inventory_model"=>__("Model", "inventory"),
		"inventory_year"=>__("Year", "inventory"),
		"inventory_serial"=>__("Serial #", "inventory"),
		"inventory_owner_email"=>__("Owner e-mail", "inventory"),
		"inventory_image"=>__("Image Name", "inventory"),
		"category_id"=>__("Category ID (NOT name!)", "inventory"));
	// Display the sample rows
	while (!feof($inv_file) && $count<=4) {
		$inv_fields = explode($sep, $inv_row);
		$inv_table.="<tr>";
		$inv_table.="<td>Row #" . ($count+1) . ":</td>";
		foreach ($inv_fields as $f) {
			$rowcount = ($count==0) ? $rowcount+1 : $rowcount;
			if ($count == 0 && !isset($_POST["field_map_" . $count])) {
				foreach ($field_vars as $k=>$v) {
					$testf = str_replace("\r", "", str_replace("\n", "", str_replace('"', '', $f)));
					$testf2 = str_replace(' ', '', $testf);
					if (strtolower($testf)==str_replace("inventory_", "", $k) || strtolower($testf)==strtolower($v) ||
						strtolower($testf2)==str_replace("inventory_", "", $k) || strtolower($testf2)==strtolower($v) ||
						(stripos($testf2, "category")!==false && $k == "category_id")) {
						$presel[$rowcount-1] = $k;
					}
				}
			}
			$f=utf8_encode($f);
			$inv_table.= "<td>" . $f . "</td>";
		}
		$count++;
		$inv_row = fgets($inv_file, 4096);
		$inv_table.="</tr>";
	}
	$titlerowchecked =  (count($presel) > 2) ? ' checked="checked"' : '';
	// Display the select-boxes to map to
	$inv_table.="<tr><td>Maps To:</td>";
	for ($i=0; $i<$rowcount; $i++) {
		$selected = (isset($presel[$i]) && $presel[$i]) ? $presel[$i] : '';
		$selected = (isset($_POST["field_map_" . $i])) ? $_POST["field_map_" . $i] : $selected;
		$field_list = "";
		foreach ($field_vars as $f=>$d) {
			$field_list.='<option value="' . $f . '"';
			$field_list.= ($selected==$f && $f!="") ? ' selected="selected"' : "";
			$field_list.= '>' . $d . '</option>';
		}
		$inv_table.='<td><select name="field_map_' . $i . '">' . $field_list . '</select></td>'; 	
	}
	$inv_table.="</tr>";
	
	echo "<table class='inv_map_form'>" . $inv_table . "</table>";
	echo "<p>" . __("Map the columns to the appropriate Inventory Item fields using the drop-down boxes, then click", "inventory") . " <strong>" . __("Go!", "inventory") . "</strong> " . __("to import", "inventory") . ".</p>";
	echo '<p><input type="checkbox" name="titlerow"' . $titlerowchecked . '> ' . __('This file contains a Title Row', "inventory") . '</p>';
	echo '<div><input type="submit" name="map_fields" value="' . __("Go!", "inventory") . '">';
	echo '</form></div>';
} 

function inventory_upload_form() {
	echo '<form enctype="multipart/form-data" name="inventory_upload" action="' . INVENTORY_ADMIN_URL . '?page=inventory-upload" method="post">';
	echo '<p>' . __("Upload a file to quickly add inventory items", "inventory") . '.</p>';
	echo '<strong>' . __("Requirements", "inventory") . ':</strong>';
	echo '<ol>';
	echo '<li>' . __(".csv or .txt file format", "inventory") . '</li>';
	echo '<li>' . __("Comma or tab-separated", "inventory") . '</li>';
	echo '<li>' . __("You will be prompted to identify/map fields once file is uploaded", "inventory") . '</li>';
	echo '</ol>';
	echo '<label>' . __("File to Upload", "inventory") . ':</label><input type="file" name="inv_upload">';
	echo '<br /><label>' . __('This is a categories file', 'inventory') . '</label> <input type="checkbox" name="inv_categories">';
	echo '<div><input type="submit" name="file_select" value="' . __("Upload", "inventory") . '">';
	echo '<h3>' . __("Purge Database", "inventory") . '</h3>';
	echo '<p>' . __("If you would like to purge all of the data from the database for a fresh import, click the link below.", "inventory") . '</p>';
	echo '<a href="' . INVENTORY_ADMIN_URL . '?page=inventory-upload&action=purge">' . __("Purge Inventory Database", "inventory") . '</a>';
	echo '<br /><a href="' . INVENTORY_ADMIN_URL . '?page=inventory-upload&action=purge&type=i">' . __("Purge Inventory Items Only", "inventory") . '</a>';
	echo '<br /><a href="' . INVENTORY_ADMIN_URL . '?page=inventory-upload&action=purge&type=c">' . __("Purge Categories Only", "inventory") . '</a>';
	echo '</form></div>';
} 

function inv_getConfig() {
	global $wpdb;
	$val = "";
	$query = "SELECT * FROM " . WP_INVENTORY_CONFIG_TABLE;
	$config = $wpdb->get_results($query);
	foreach ($config as $k=>$v) {
		$cv[$k]=$v;
		foreach ($cv[$k] as $t=>$s) {
			if ($t=="config_item") {
				$key = $s;
			} else {
				$val = $s;
			}
			$conf[$key]=$val;
		}
	}
	return $conf;
}

function inv_checkConfig($array, $key) {
	if (array_key_exists($key, $array)) {
		return $array[$key];
	} else {
		return 1;
	}
}

function inv_getLabel($array, $name) {
	return inv_checkConfig($array, "label_" . $name);
}

function inv_getType($array, $name) {
	return inv_checkConfig($array, "type_" . $name);
}

function inv_checkConfigExists($key, $value) {
	// Function to check if config key exists, and if not, write it to table
	global $wpdb;
	$query = "SELECT * FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='" . $key . "'";
	$config = $wpdb->get_results($query);
	if (!$config) {
		$query = "INSERT INTO " . WP_INVENTORY_CONFIG_TABLE . " SET config_item='" . $key . "', config_value='" . $value . "'";
		$wpdb->query($query);
	}
}

function inv_ConfigUpdate($key, $value) {
	global $wpdb;
	$query = "UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value='" . $value . "' WHERE config_item='" . $key . "'";
	$wpdb->query($query);
}

function inv_doImages($key) {
	if (!isset($_FILES[$key])) {
		return "";
	}
	$image = $_FILES[$key];
	$name = $image["name"];
	if ($name) {
		if (!inv_isImage($name)) {
			echo __("Warning! NOT an image file! File not uploaded.", "inventory");
			return "";
		}
		if (inv_uploadFile($image, $name, INVENTORY_SAVE_TO, true)) {
			return $name;
		} else {
			return false;
		}
	}
	
}

function inv_isImage($file) {
	$imgtypes =array("BMP", "JPG", "JPEG", "GIF", "PNG");
	$ext =strtoupper( substr($file ,strlen($file )-(strlen( $file  ) - (strrpos($file ,".") ? strrpos($file ,".")+1 : 0) ))  ) ;
	if (in_array($ext, $imgtypes)) {
		return true;
	} else {
		return false;
	}
}

function inv_uploadFile($field, $filename, $savetopath, $overwrite, $name="") { 
		global $message;
		if (!is_array($field)) {
			$field = $_FILES[$field];
		}
		if (!file_exists($savetopath)) {
			echo "<br />" . __("The save-to path doesn't exist.... attempting to create...", "inventory") . "<br />";
			mkdir(ABSPATH . "/" . str_replace("../", "", $savetopath));
		}
		if (!file_exists($savetopath)) {
			echo "<br />" . __("The save-to directory", "inventory") . " (" . $savetopath . ") " . __("does not exist, and could not be created automatically", "inventory") . ".<br />";
			return false;
		}
		$saveto = $savetopath . "/" . $filename;
		if ($overwrite!=true) {
			if(file_exists($saveto)) {
				echo "<br />The " . $name . " file " . $saveto . " " . __("already exists", "inventory") . ".<br />";
				return false;
			}
		}
		if ($field["error"] > 0) {
            switch ($field["error"]) {
                case 1:
                    $error = __("The file is too big. (php.ini)", "inventory"); // php installation max file size error
                    break;
                case 2:
                    $error = __("The file is too big. (form)", "inventory"); // form max file size error
                    break;
                case 3:
                    $error = __("Only part of the file was uploaded", "inventory");
                    break;
                case 4:
                    $error = __("No file was uploaded", "inventory");
                    break;
                case 6:
                    $error = __("Missing a temporary folder.", "inventory");
                    break;
                case 7:
                    $error = __("Failed to write file to disk", "inventory");
                    break;
                case 8:
                    $error = __("File upload stopped by extension", "inventory");
                    break;
				default:
					$error = "Unknown error (" . $field["error"] . ")";
					break;
            }
			
		echo $field["error"];
		echo $error;
		  	return "<br />Error: " . $error . "<br />";
		  } else {
			if (move_uploaded_file($field["tmp_name"], $saveto)) {
				return true;
			} else {
				die(__("Unable to write uploaded file.  Check permissions on upload directory.", "inventory"));
			};
			
		}
	}
	
function inv_deleteFile($fileloc) {
	if (file_exists($fileloc)) {
		$del = unlink($fileloc);
		
		return ($del) ? "<br />" . __("Image deleted", "inventory") . "." : "<br />" . __("Image not found to delete", "inventory") . ".";
	} else {
		return "<br />" . __("File", "inventory") . " " . $fileloc . " " . __("could not be deleted (does not exist)", "inventory") . ".";
	}
}

function get_Inventory_Post($field, $default="") {
	return (isset($_POST[$field])) ? $_POST[$field] : $default;
}

Function validate_Inventory_Input($input, $type, $message, $minlength=1) {
	$type=strtoupper(substr($type,0,1));
	if (is_null($input)) {
		return $message;
	}
	switch ($type) {
		default: /* TEXT */
			if (strlen(trim($input))<$minlength) {
				return $message;
			}
			break;
		case "1":
		case "N": /* NUMERIC */
			if (!is_numeric($input)) {
				return $message;
			}
			break;
		case "3":
		case "D": /* DATE */
			echo $input . " -- " . $message . "<br />";
			if (!isDate($input)) {
				return $message;
			}
			break;
		case "4":
		case "E": /* EMAIL */
			if(!eregi("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,3})$", $input)) {
			  return $message;
			}
			break;
		case "P": /* US PHONE NUMBER */
			$phone = preg_replace('/\D/', '', $input);
			# Ensure it's well-formed
			if (strlen($phone)<10) {
				return $message;
			}
			break;
	}
	return "";
}

// Function to check what version of Inventory is installed and install if needed
function check_inventory()
{
  // Checks to make sure Inventory is installed, if not it adds the default
  // database tables and populates them with test data. If it is, then the 
  // version is checked through various means and if it is not up to date 
  // then it is upgraded.

  // Lets see if this is first run and create us a table if it is!
  global $wpdb, $initial_style;

  // All this style info will go into the database on a new install
  $initial_style = "[temp style]";
     

  // Assume this is not a new install until we prove otherwise
  $new_install = false;

  $wp_inventory_exists = false;
  $wp_inventory_images_exists = false;
  $wp_inventory_config_exists = false;
  $wp_inventory_config_version_number_exists = false;

  // Determine the inventory version
  $tables = $wpdb->get_results("show tables;");
  foreach ( $tables as $table ) {
      foreach ( $table as $value ) {
		  if ( $value == WP_INVENTORY_TABLE ) {
		      $wp_inventory_exists = true;
	      }
		  if ($value == WP_INVENTORY_IMAGES_TABLE) {
		  	$wp_inventory_images_exists = true;
		  }
		  if ( $value == WP_INVENTORY_CONFIG_TABLE ) {
	        $wp_inventory_config_exists = true;
	       }
        }
    }

  if ($wp_inventory_exists == false && $wp_inventory_config_exists == false) {
      $new_install = true;
  }

  // Now we've determined what the current install is or isn't 
  // we perform operations according to the findings
  if ( $new_install == true) {
  	$initial_style = 'table td label {
display: block;
float: left;
width: 80px;
text-align: right;
margin: 3px 7px 0 0;
padding: 0;
}

table td input {
display: inline;
float: none;
clear: none;
margin: 0 5px 0 0;
padding: 0;
width: 200px;
}

table td input.city {
width: 100px;
}

table td input.state {
width: 30px;
}

table td input.zip {
width: 50px;
}

table td input.submit {
width: auto;
margin-left: 90px;
}

div.inventory {
border: 1px solid #ccc;
margin: 10px;
padding: 10px;
font-size: 10pt;
}

div.inventory p.name {
font-weight: bold;
}';
      $sql = "CREATE TABLE " . WP_INVENTORY_TABLE . " (
                                inventory_id INT(11) NOT NULL AUTO_INCREMENT ,
                                date_added INT(11) NOT NULL ,
								inventory_number VARCHAR(30) NOT NULL,
								inventory_name VARCHAR(100) NOT NULL,
								inventory_description TEXT NOT NULL,
								inventory_order INT(11) NOT NULL,
								inventory_size VARCHAR(30) NULL,
                                category_id INT(11) NOT NULL,
								inventory_price FLOAT(10, 2) NOT NULL,
								inventory_reserved TINYINT(3),
								inventory_image TEXT NULL,
                                PRIMARY KEY (inventory_id)
                        )";
      $wpdb->get_results($sql);
      $sql = "CREATE TABLE " . WP_INVENTORY_CONFIG_TABLE . " (
                                config_item VARCHAR(40) NOT NULL ,
                                config_value TEXT NOT NULL ,
                                PRIMARY KEY (config_item)
                        )";
      $wpdb->get_results($sql);
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='can_manage_inventory', config_value='edit_posts'";
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='inventory_style', config_value='".$initial_style."'";
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='display_inventory_number', config_value='1'";
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='display_date_added', config_value='1'";
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='display_inventory_description', config_value='1'";
	  $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='display_inventory_category_name', config_value='1'";
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='display_inventory_image', config_value='1'";
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='display_inventory_price', config_value=1";
	  $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='display_inventory_size', config_value=1";
	  $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='display_inventory_reserved', config_value=1";
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='inventory_version', config_value='0.0.1'";
      $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='enable_categories', config_value='false'";
	  $query[] = "INSERT INTO ".WP_INVENTORY_CONFIG_TABLE." SET config_item='admin_email', config_value=''";
	  foreach($query as $q) {
	      $wpdb->query($q);
	 }
      $sql = "CREATE TABLE " . WP_INVENTORY_CATEGORIES_TABLE . " ( 
                                category_id INT(11) NOT NULL AUTO_INCREMENT, 
                                category_name VARCHAR(30) NOT NULL , 
                                category_colour VARCHAR(30) NOT NULL , 
                                PRIMARY KEY (category_id) 
                             )";
      $wpdb->query($sql);
      $sql = "INSERT INTO " . WP_INVENTORY_CATEGORIES_TABLE . " SET category_id=1, category_name='General', category_colour='#F6F79B'";
      $wpdb->query($sql);
    }
  	if ($wp_inventory_images_exists==false) {
  		// We've got to add the images table
		 $sql = "CREATE TABLE " . WP_INVENTORY_IMAGES_TABLE . " (
		 						inventory_images_id INT(11) NOT NULL AUTO_INCREMENT ,
                                inventory_id INT(11) NOT NULL ,
								inventory_image TEXT NULL,
                                PRIMARY KEY (inventory_images_id)
                        )";
      $wpdb->query($sql);
	  $sql = "SELECT inventory_id, inventory_image FROM " . WP_INVENTORY_TABLE;
	  $items=$wpdb->get_results($sql);
	  $sql = "";
	  if (!empty($items)) {
	  	foreach($items as $item) {
	  		$sql.= ($sql) ? ", " : "";
	  		$sql.= '("' . $item->inventory_id . '", "' . $item->inventory_image . '")';
	  	}
		if ($sql) {
			$sql = "INSERT INTO " . WP_INVENTORY_IMAGES_TABLE . " (inventory_id, inventory_image) VALUES " . $sql;
			$wpdb->query($sql);
		}
	  }
  	}
	
	/**
	 *  Various version upgrades
	 **/
	
	/** 
	 * Version 0.2 - no database changes 
	 **/
	
	/** 
	 * Version 0.3 - added control to limit edit by user who entered only 
	 **/
	
	$config = inv_getConfig();
	if (!array_key_exists("limit_edit", $config)) {
		inv_checkConfigExists("limit_edit", "0");
		$version = "0.3";
		$sql = "ALTER TABLE " . WP_INVENTORY_TABLE . " ADD COLUMN inventory_userid INT(11) DEFAULT 0";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "0.3");
	}
	$inv_version = inv_checkConfig($config, "inventory_version");
	/***
	 *  Version 0.4 - added multiple fields/features
	 *  1) Added "owner_email", "detail_page", "quantity"
	 *  
	 * **/
	if (($inv_version *1) <0.4) {
		$sql = "ALTER TABLE " . WP_INVENTORY_TABLE . " 
			ADD COLUMN inventory_quantity INT(11) DEFAULT 0,
			ADD COLUMN inventory_manufacturer VARCHAR(75) NULL,
			ADD COLUMN inventory_FOB VARCHAR(75) NULL,
			ADD COLUMN inventory_detail_page VARCHAR(75) NULL,
			ADD COLUMN inventory_owner_email VARCHAR(150) NULL";
		$wpdb->query($sql);
		$sql = "INSERT INTO " . WP_INVENTORY_CONFIG_TABLE. "(config_item, config_value) VALUES 
			('display_as_spreadsheet', 0),
			('display_inventory_quantity', 0), 
			('display_inventory_manufacturer', 0), 
			('display_inventory_FOB', 0),
			('e-mail_owner', 0)";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "0.4");
	}
	/***
	 *  Version 0.5 - added multiple config fields
	 *  1) Added "number of items per page", Added "placeholder photo"
	 * **/
	if (($inv_version *1) <0.5) {
		$sql = "INSERT INTO " . WP_INVENTORY_CONFIG_TABLE. "(config_item, config_value) VALUES 
			('items_per_page', 0),
			('placeholder_image', ''),
			('display_everything_on_detail_page', 0)";
		$wpdb->query($sql);
		$sql = "SELECT config_value FROM " . WP_INVENTORY_CONFIG_TABLE . " WHERE config_item='inventory_style'";
		$results = $wpdb->get_results($sql);
		foreach ($results as $conf) {
			$style = $conf->config_value;
			$style.="
			#inv_page {
				width: 100%;
				text-align: right;
				margin: 0;
				padding: 0;
			}
			#inv_page a:link, #inv_page a:visited, #inv_page span {
				padding: 0 4px;
			}";
		}
		$wpdb->query("UPDATE " . WP_INVENTORY_CONFIG_TABLE . " SET config_value='" . $style . "' WHERE config_item='inventory_style'");
		inv_ConfigUpdate("inventory_version", "0.5");
	}
	
	/***
	 *  Version 0.6 - added multiple config fields
	 *  1) Added "Label" definitions for Item Number, Item Name, Item Category, Item Size, Manufacturer, FOB
	 *  2) Added "Reserve" options - quantity and units
	 * **/
	if (($inv_version *1) <0.6) {
		$sql = "INSERT INTO " . WP_INVENTORY_CONFIG_TABLE. "(config_item, config_value) VALUES 
			('label_number', 'Item Number'),
			('label_name', 'Item Name'),
			('label_category', 'Item Category'),
			('label_size', 'Item Size'),
			('label_manufacturer', 'Manufacturer'),
			('label_FOB', 'FOB'),
			('reserve_display_quantity', '0'),
			('reserve_quantity_units', 'Each')
			";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "0.6");
	}
	
	/***
	 *  Version 0.7 - no database changes
	 */
	
	/***
	 *  Version 0.8 - added more fields, made custom-definable through labels
	 *  1) Added 4 new fields, made labels customizable, made display on/off-abl
	 *  2) Added "Reserve" field customization
	 * **/
	if (($inv_version *1) <0.8) {
		$sql = "ALTER TABLE " . WP_INVENTORY_TABLE . " 
			ADD COLUMN inventory_make VARCHAR(75) NULL,
			ADD COLUMN inventory_model VARCHAR(75) NULL,
			ADD COLUMN inventory_year VARCHAR(75) NULL,
			ADD COLUMN inventory_serial VARCHAR(75) NULL";
		$wpdb->query($sql);
		$sql = "INSERT INTO " . WP_INVENTORY_CONFIG_TABLE. "(config_item, config_value) VALUES 
			('label_make', 'Item Make'),
			('label_model', 'Item Model'),
			('label_year', 'Item Year'),
			('label_serial', 'Item Serial #'),
			('display_inventory_make', 0),
			('display_inventory_model', 0), 
			('display_inventory_year', 0), 
			('display_inventory_serial', 0),
			('label_reserve_item_link', 'Reserve This Item')";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "0.8");
	}
	
	/***
	 * version 1.0 - added widget that shows counts of items in category
	 *  1) Added a view to use in the widget for showing counts
	 *  2) Added a widget
	 * **/ 
	 if (($inv_version *  1) < 1.0) {
	 	$sql = "CREATE VIEW " . WP_INVENTORY_COUNTS_VIEW . " 
			AS SELECT COUNT(distinct inventory_id) as item_count, category_id FROM " . WP_INVENTORY_TABLE . 
			" GROUP BY category_id";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "1.0");
	 }
	/***
	 * version 1.1 - various debugs in the import items function - no db changes
	 * **/
	/***
	 * version 1.2 - various debugs to displaying images, stripslashes for display
	 * **/
	/***
	 * version 1.3 - added parameter support to {INVENTORY} tag
	 * 				added purge database function
	 * 				add check/attempt to create images directory automatically
	 * 				changed widget to select inventory page from dropdown (rather than type it in)
	 * **/
	 /***
	 * version 1.4 - minor tweaks to parameters
	 * 				made it clean any '<code>' markup (in case people copy parameters directly from instructions website)
	 * 				removed 'display as spreadsheet' limitation for pagination
	 * **/
	$path = ABSPATH . INVENTORY_LOAD_FROM;
	if (!file_exists($path)) {
		echo '<p>WARNING: Inventory Images directory (' . INVENTORY_LOAD_FROM . ') does not exist. Attempting to create....';
		$success = mkdir($path);
		echo ($success) ? '<br />CREATED!' : '<br />**** FAILED ****<br />Please create this directory manually.';
	}
	/***
	* version 1.5 - Major Update
	*		Put styles into external stylsheets
	*		Move category drop-down to centralized function
	*		Added category filter to dashboard listing
	*		Added sorting by header in dashboard listing
	*		Added reserve option configuration options to not ask for certain fields
	*		Improved css classes - even/odd rows, form inputs/labels
	*		Added integration for WordPress search - must modify search.php template!
	*		Fixed bug when permalink is default, link to item detail was broken
	*		Added control over order of displayed fields/columns
	*		Added control over which fields to ask for in add/edit item
	*		Added automatic generation of thumbnail in add/edit item screen
	*		Added support necessary to work with multi-site installations
	*		Added tabs to organize inventory options
	*		Added function to import to automatically detect and map field names
	*		Added ability to import image file name (one image)
	*		Cleaned up import function to strip quote containers
	*		Fixed bug(s) to make it play nice with other plugins with multiple the_content calls
	*		Cleaned up some warning-level errors
	*		Fixed bug with category widget not displaying counts
	* **/
	if (($inv_version *  1) < 1.5) {
		$sql = "INSERT INTO " . WP_INVENTORY_CONFIG_TABLE. "(config_item, config_value) VALUES 
			('reserve_require_name', 1),
			('reserve_require_address', 1), 
			('reserve_require_city', 1), 
			('reserve_require_phone', 1),
			('reserve_require_email', 1),
			('wordpress_search', 1),
			('display_inventory_name', 1)";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "1.5");
	}
	/***
	 * version 1.6 - Major Update Continued
	 *		Added support for internationalization
	 *		Added ability to support to display inventory detail on multiple pages
	 *		Bug fixes
	 *		Provided uninstall script to remove database files
	 *		Added ability to Import categories
	 *		Added ability to purge only categories, only inventory items
	 * **/
	if (($inv_version *  1) < 1.6) {
		$sql = "INSERT INTO " . WP_INVENTORY_CONFIG_TABLE. "(config_item, config_value) VALUES 
			('inventory_page', '')";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "1.6");
	}
	/***
	* version 1.7 - Internationalization support improved
			bug fixes: save button not displaying, category shorttag failing, sort-by drop-down not displaying properly 
			Completed Field Type functionality
	* ***/
	if (($inv_version *  1) < 1.7) {
		$sql = "INSERT INTO " . WP_INVENTORY_CONFIG_TABLE. "(config_item, config_value) VALUES 
			('type_number', 'text'),
			('type_name', 'text'),
			('type_size', 'text'),
			('type_manufacturer', 'text'),
			('type_FOB', 'text'),
			('type_make', 'text'),
			('type_model', 'text'),
			('type_year', 'text'),
			('type_serial', 'text')";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "1.7");
	}
	/***
	* version 1.8 - Extend column sizes
	* ***/
	if (($inv_version * 1) < 1.8) {
		$wpdb->show_errors();
		// Incorrectly added this config in previous version
		$sql = "DELETE FROM " . WP_INVENTORY_CONFIG_TABLE. " WHERE config_item='type_category'";
		$wpdb->query($sql);
		// Discovered users wanted to make fields larger
		$sql = "ALTER TABLE " . WP_INVENTORY_TABLE. " 
				MODIFY inventory_number VARCHAR(255),
				MODIFY inventory_name VARCHAR(255),
				MODIFY inventory_size VARCHAR(255),
				MODIFY inventory_manufacturer VARCHAR(255),
				MODIFY inventory_FOB VARCHAR(255),
				MODIFY inventory_make VARCHAR(255),
				MODIFY inventory_model VARCHAR(255),
				MODIFY inventory_year VARCHAR(255),
				MODIFY inventory_serial VARCHAR(255)";
		$wpdb->query($sql);
		inv_ConfigUpdate("inventory_version", "1.8");
	}
	inv_checkConfigExists("label_description", "Description");
	/***
	* version 1.8.1 - Allow configuration of item name as link or static
	* 		  Increased number of times shortcode can be displayed to 20 (from 5)
	* ***/
	inv_checkConfigExists("item_name_link", "1");

}

function inv_showStyles($type) {
	$pluginurl = WP_PLUGIN_URL.'/'.str_replace(basename( __FILE__),"",plugin_basename(__FILE__)); 
	echo '<link rel="stylesheet" href="' . $pluginurl . 'admin-style.css" />' . "\r\n";
	echo '<script type="text/javascript" src="' . $pluginurl . 'admin-script.js"></script>' . "\r\n";
}

function inv_get_permalink($config) {
	$pageid = inv_checkConfig($config, "inventory_page");
	/* if (!$pageid) {echo "<p style='border: 2px solid red; padding: 10px;'>WARNING: The Inventory Page is NOT set up in the dashboard!</p>";} */
	if (!$pageid) {
		global $post;
		$pageid = $post->ID;
	}
	$link = get_permalink($pageid);
	$link.= (stripos($link, "?")!==false) ? "&" : "?";
	return $link;
}



class inventory_widget extends WP_Widget {
	
	function inventory_widget() {
		parent::WP_Widget('inventory_widget', 'Inventory Categories', array('description'=>'Display a menu of categories in your sidebar to link to the inventory listings.'));
	}
	
	function widget($args, $instance) {
		extract($args);
		$options = $instance;
		echo $before_widget;
		$title = apply_filters('widget_title', $options['title']);
		echo ($title) ? $before_title . $title . $after_title : '';
		global $wpdb;

		$cats = $wpdb->get_results("SELECT cat.*, item_count FROM " . WP_INVENTORY_CATEGORIES_TABLE . " as cat 
			LEFT JOIN " . WP_INVENTORY_COUNTS_VIEW . " as counts ON cat.category_id = counts.category_id");
		$page = (isset($options["page"]) && $options["page"] && !$options["page_id"]) ? $options["page"] : $options["page_id"];
		$link = (is_numeric($page)) ? get_permalink($page) : $page;
		$link.= (stripos($link, "?")!==false) ? "&" : "?";
		echo '<ul>';
        foreach($cats as $cat) {
			echo '<li>';
			
            echo '<a href="' . $link . 'category_id=' . $cat->category_id . '">' . $cat->category_name;
			echo ($options["counts"]) ? ' (' . ($cat->item_count * 1) . ')' : '';
			echo '</a></li>';
		}
		echo "</ul>";
		echo $after_widget;
	}
	
	function update($new_instance, $old_instance) {
		$instance = $old_instance;
		$instance['title'] = strip_tags(stripslashes($new_instance["title"]));
		$instance['page_id'] = $new_instance["page_id"];
		$instance['counts'] = !empty($new_instance['counts']) ? 1 : 0;
		return $instance;
	}
	
	function form($instance) {	
		$default = 	array( 'page_id' => __('inventory', 'inventory'), 'counts'=>'1' );
		$instance = wp_parse_args((array) $instance, $default);
		$pageid = $instance["page_id"];
 		$title_id = $this->get_field_id('title');
		$title_name = $this->get_field_name('title');
		$page_name = $this->get_field_name('page_id');
		$count_id = $this->get_field_id('counts');
		$count_name = $this->get_field_name('counts');
		$counts = isset( $instance['counts'] ) ? (int) $instance['counts'] : 0;
		$countschecked = ($counts) ? ' checked="checked"' : '';
		echo "\r\n".'<p><label for="'.$title_name.'">'.__('Title', 'inventory').': <input type="text" class="widefat" id="'.$title_id.'" name="'.$title_name.'" value="'.attribute_escape( $instance['title'] ).'" /></label></p>';
		echo "\r\n".'<p><label for="'.$page_name.'">'.__('Inventory Page', 'inventory').': ';
		wp_dropdown_pages(array('selected'=>$pageid, 'name'=>$page_name));
		echo '</label></p>';
		echo "\r\n".'<p><input type="checkbox" class="checkbox" id="' . $count_id .'" name="' .  $count_name . '"' . $countschecked .' /> <label for="' . $count_name . '">Include Counts</a>';
	}
}

function inventory_excerpt($s, $n, $x) {
	$breaks = array(" ", ".", ", ", "-", "!");
	$bestpos = $x;
	if (strlen($s) <= $n) {return $s;}
	foreach ($breaks as $k) {
		$newpos = stripos($s, $k, $n);
		$bestpos = ($newpos < $bestpos && $newpos > 0) ? $newpos : $bestpos;
	}
	return substr($s, 0, $bestpos) . "...";
}

// Register the widget
add_action('widgets_init', create_function('', 'return register_widget("inventory_widget");'));

?>
