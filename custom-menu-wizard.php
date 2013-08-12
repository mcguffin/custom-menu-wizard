<?php
/*
 * Plugin Name: Custom Menu Wizard
 * Plugin URI: http://wordpress.org/plugins/custom-menu-wizard/
 * Description: Full control over the wp_nav_menu parameters for a custom menu; filter for specific level(s), for children of a selected menu item, the current menu item, or its parent or root item 
 * Version: 1.2.1
 * Author: Roger Barrett
 * Author URI: http://www.wizzud.com/
 * License: GPL2+
*/

/*
 * v1.2.1 change log:
 * - added some extra custom classes, when applicable : cmw-fellback-to-current & cmw-fellback-to-parent (on outer UL/OL) and cmw-the-included-parent, cmw-an-included-parent-sibling & cmw-an-included-ancestor (on relevant LIs)
 * - corrected 'show all from start level 1' processing so that custom classes get applied and 'Title from "Current" Item' works (regardless of filter settings)
 * - changed the defaults for new widgets such that only the Filter section is open by default; all the others are collapsed
 * - updated demo.html and readme.txt, and made demo available from readme
 * 
 * v1.2.0 change log:
 * - added custom_menu_wizard shortcode, to run the widget from within content
 * - moved the 'no ancestor' fallback into new Fallback collapsible section, and added a fallback for Current Item with no children
 * - fixed bug with optgroups/options made available for the 'Children of' selector after the widget has been saved (also affecting disabled fields & styling)
 * - don't include menus with no items
 * - updated demo.html
 * 
 * v1.1.0 change log:
 * - added 'Current Root Item' and 'Current Parent Item' to the 'Children of' filter
 * - added an Output option to include both the parent item and the parent's siblings (for a successful 'Children of' filter)
 * - added Fallback to Current Item option, and subsidiary Output option overrides, to enable Current Root & Current Parent to match a Current Item at root level
 * - added max-width style (100%) to the 'Children of' SELECT in the widget options
 * - added widget version to the admin js enqueuer
 * - ignore/disable hide_empty for WP >= v3.6 (wp_nav_menu() does it automatically)
 * - rebuilt 'Children of' SELECT to account for IE's lack of OPTGROUP/OPTION styling
 * - moved the setting of 'disabled' attributes on INPUTs/SELECTs from PHP into javascript
 */

$Custom_Menu_Wizard_Widget_Version = '1.2.1';

/**
 * registers the widget and adds the shortcode
 */
function custom_menu_wizard_register_widget() {
	register_widget('Custom_Menu_Wizard_Widget');
	add_shortcode('custom_menu_wizard', 'custom_menu_wizard_widget_shortcode');
}
add_action('widgets_init', 'custom_menu_wizard_register_widget');

/**
 * enqueues script file for the widget admin
 */
function custom_menu_wizard_widget_admin_script(){
	wp_enqueue_script('custom-menu-wizard-plugin-script', plugins_url('/custom-menu-wizard.js', __FILE__), array('jquery'), $Custom_Menu_Wizard_Widget_Version);
}
add_action('admin_print_scripts-widgets.php', 'custom_menu_wizard_widget_admin_script');

/*
 * Custom Menu Wizard Walker class
 * NB: Walker_Nav_Menu class is in wp-includes/nav-menu-template.php, and is itself an 
 *     extension of the Walker class (wp-includes/class-wp-walker.php)
 */
class Custom_Menu_Wizard_Walker extends Walker_Nav_Menu {

	/**
	 * opens a sub-level with a UL or OL start-tag
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param int $depth Depth of page. Used for padding.
	 */
	function start_lvl( &$output, $depth = 0, $args = array() ) {
		$indent = str_repeat("\t", $depth);
		$listtag = empty( $args->_custom_menu_wizard['ol_sub'] ) ? 'ul' : 'ol';
		$output .= "\n$indent<$listtag class=\"sub-menu\">\n";
	}

	/**
	 * closes a sub-level with a UL or OL end-tag
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param int $depth Depth of page. Used for padding.
	 */
	function end_lvl( &$output, $depth = 0, $args = array() ) {
		$indent = str_repeat("\t", $depth);
		$listtag = empty( $args->_custom_menu_wizard['ol_sub'] ) ? 'ul' : 'ol';
		$output .= "$indent</$listtag>\n";
	}

	/**
	 * pre-filters elements then calls parent::walk()
	 * 
	 * @filters : custom_menu_wizard_walker_items          array of filtered menu elements; array of args
	 * 
	 * @param array $elements Menu items
	 * @param integer $max_depth
	 * @return string
	 */
	function walk($elements, $max_depth){

		$args = array_slice(func_get_args(), 2);
		$args = $args[0];

		if( $max_depth >= -1 && !empty( $elements ) && isset($args->_custom_menu_wizard) ){

			$cmw =& $args->_custom_menu_wizard;
			//in $cmw (array) :
			//  filter : true = kids of (current [root|parent] item or specific item)
			//  filter_item : 0 = current item, -1 = parent of current (v1.1.0), -2 = root ancestor of current (v1.1.0); else a menu item id
			//  flat_output : true = equivalent of $max_depth == -1
			//  include_parent : true = include the filter_item menu item
			//  include_parent_siblings : true = include the siblings (& parent) of the filter_item menu item
			//  include_ancestors : true = include the filter_item menu item plus all it's ancestors
			//  title_from_parent : true = widget wants parent's title
			//  title_from_current : true = widget wants current item's title (v1.2.0)
			//  start_level : integer, 1+
			//  depth : integer, replacement for max_depth and also applied to 'flat' output
			//  fallback_no_ancestor : true = if looking for an ancestor (root or parent) of a top-level current item, fallback to current item (v1.1.0)
			//  fallback_include_parent : true = if fallback_no_ancestor comes into play then force include_parent to true (v1.1.0)
			//  fallback_include_parent_siblings : true = if fallback_no_ancestor comes into play then force include_parent_siblings to true (v1.1.0)
			//  fallback_no_children : true = if looking for a current item, and that item turns out to have no children, fallback to current parent (v1.2.0)
			//  fallback_nc_include_parent : true = if fallback_no_children comes into play then force include_parent to true (v1.2.0)
			//  fallback_nc_include_parent_siblings : true = if fallback_no_children comes into play then force include_parent_siblings to true (v1.2.0)
			//
			//  _walker (array) : for anything that only the walker can determine and that needs to be communicated back to the widget instance
			//
			//$elements is an array of objects, indexed by position within the menu (menu_order),
			//starting at 1 and incrementing sequentially regardless of parentage (ie. first item is [1],
			//second item is [2] whether it's at root or subordinate to first item)
			$cmw['_walker']['fellback'] = false;

			$find_kids_of = $cmw['filter'];
			$find_current_item = $find_kids_of && empty( $cmw['filter_item'] );
			$find_current_parent = $find_kids_of && $cmw['filter_item'] == -1; //v1.1.0
			$find_current_root = $find_kids_of && $cmw['filter_item'] == -2; //v1.1.0
			$fallback_to_current_item = $cmw['fallback_no_ancestor'] && ( $find_current_parent || $find_current_root ); //v1.1.0
			//these could change depending on whether a fallback comes into play (v1.1.0)
			$include_parent = $cmw['include_parent'];
			$include_parent_siblings = $cmw['include_parent_siblings'];

			$id_field = $this->db_fields['id']; //eg. = 'db_id'
			$parent_field = $this->db_fields['parent']; //eg. = 'menu_item_parent'

			//start level applies to the *kids* of a find_kids_of search, not to the parent, so while we
			//are still looking for the parent, the start_level for a find_kids_of search is actually one
			//up from cmw['start_level']...
			$start_level = $find_kids_of ? $cmw['start_level'] - 1 : $cmw['start_level'];

			$keep_ids = array();
			$keep_items = array();
			$temp = array(0 => array('kids' => array()));
			foreach( $elements as $i=>$item ){
				if( empty( $item->$parent_field ) ){
					//set root level of menu, and no ancestors...
					$temp[ $item->$id_field ] = array(
						'level' => 1,
						//this is an array of indexes into $elements...
						'breadcrumb' => array( $i ),
						'parent' => 0,
						'kids' => array()
						);
					$temp[0]['kids'][] = $i;
				}elseif( isset( $temp[ $item->$parent_field ] ) ){
					//set one greater than parent's level, and ancestors are parent's ancestors plus the parent...
					$temp[ $item->$id_field ] = array(
						'level' => $temp[ $item->$parent_field ]['level'] + 1,
						'breadcrumb' => $temp[ $item->$parent_field ]['breadcrumb'],
						'parent' => $item->$parent_field,
						'kids' => array()
						);
					$temp[ $item->$id_field ]['breadcrumb'][] = $i;
					$temp[ $item->$parent_field ]['kids'][] = $i;
				}
				//if $temp[] hasn't been set then it's an orphan; in order to keep orphans, max_depth must be 0 (ie. unlimited)
				//note that if a child is an orphan then all descendants of that child are also considered to be orphans!
				//also note that orphans (in the original menu) are ignored by this widget!

				if( isset( $temp[ $item->$id_field ] ) ){
					//let's keep track of current menu item (as index into $elements)...
					if( $item->current ){
						$ci_index = $i;
					}
					//are we at or below the start level?...
					if( $temp[ $item->$id_field ]['level'] >= $start_level ){
						//are we still looking for a starting point?...
						if( empty( $keep_ids ) ){
							if( //...we're looking for unspecific items starting at this level...
									!$find_kids_of ||
									//...we're looking for current item, and this is it...
									( $find_current_item && $item->current ) ||
									//...we're looking for current parent, and this is it...
									( $find_current_parent && $item->current_item_parent ) ||
									//...we're looking for a current root ancestor, and this is one...
									( $find_current_root && $item->current_item_ancestor ) ||
									//...we've got a fallback for a top-level current item with no ancestor...
									( $fallback_to_current_item && $item->current && $temp[ $item->$id_field ]['level'] == 1 ) ||
									//...we're looking for a particular menu item, and this is it...
									( $cmw['filter_item'] == $item->$id_field )
									){
								//NOTE : at this point I'm *keeping* the id of the parent of a find_kids_of search, but not the actual item!
								$keep_ids[] = $item->$id_field;
								if( !$find_kids_of ){
									$keep_items[] = $item;
								}
								//v1.1.0 if this was the fallback_no_ancestor option, we may need to update $include_parent[_siblings]...
								if( $fallback_to_current_item && $item->current && $temp[ $item->$id_field ]['level'] == 1 ){
									$include_parent = $include_parent || $cmw['fallback_include_parent'];
									$include_parent_siblings = $include_parent_siblings || $cmw['fallback_include_parent_siblings'];
									$cmw['_walker']['fellback'] = 'to-current'; //v1.2.1
								}
								//depth, if set, kicks in at this point :
								//  if doing a find_kids_of search then this level counts as 0, and the next level (the kids) counts as 1
								//  otherwise, the current level counts as 1
								if( $cmw['depth'] > 0 ){
									$max_level = $temp[ $item->$id_field ]['level'] + $cmw['depth'] - ($find_kids_of ? 0 : 1);
								}else{
									//unlimited...
									$max_level = 9999;
								}
								//...and reset start level...
								$start_level = $cmw['start_level'];
							}
						//having found at least one, any more have to be:
						// - within max_depth of the first one found, and
						// - either it's an unspecific search, or we have the parent already
						}elseif( $temp[ $item->$id_field ]['level'] <= $max_level && ( !$find_kids_of || in_array( $item->$parent_field, $keep_ids ) ) ){
							$keep_ids[] = $item->$id_field;
							$keep_items[] = $item;
						}
					}
				}
			} //end foreach

			//v1.2.0 do we need to invoke the fallback for a childless 'children of current item'?...
			//note that this is slightly different to the no_ancestor fallback, in that we are treating it like a switch from current item
			//to current parent, *except* that here the fallback for not having an ancestor is to pretend there's a level-0 ancestor (above
			//root); obviously it (the level-0 ancestor) will no item of its own, which means the kids now have no (real) parent
			if( $find_current_item && $cmw['fallback_no_children'] && empty( $keep_items ) && !empty( $keep_ids ) ){
				//what are the options?
				// - the current item & its siblings
				// - ... optionally plus its parent (if there is one)
				// - ... optionally plus its parent (if there is one) and the parent's siblings
				//keep_ids[0] is the id of the current item; put the parent's kids into keep_items...
				foreach( $temp[ $temp[ $keep_ids[0] ]['parent'] ]['kids'] as $item ){
					$keep_items[] = $item;
				}
				$include_parent = $include_parent || $cmw['fallback_nc_include_parent'];
				$include_parent_siblings = $include_parent_siblings || $cmw['fallback_nc_include_parent_siblings'];
				$cmw['_walker']['fellback'] = 'to-parent'; //v1.2.1
			}

			unset( $keep_ids );
			if( !empty( $keep_items) ){

				//do we need to prepend parent or ancestors?...
				$breadcrumb = $temp[ $keep_items[0]->$id_field ]['breadcrumb'];
				//remove the last breadcrumb element, which is the item's own index...
				array_pop( $breadcrumb );
				//last element is now the parent (if there is one)
				$i = $j = count( $breadcrumb );
					//might we want the parent's title as the widget title?...
				if( $find_kids_of && $cmw['title_from_parent'] && $i > 0 ){
					$cmw['_walker']['parent_title'] = apply_filters(
						'the_title',
						$elements[ $breadcrumb[ $i - 1 ] ]->title,
						$elements[ $breadcrumb[ $i - 1 ] ]->ID
						);
				}
				//v1.2.0 might we want the current item's title as the widget title?...
				//NB: using $ci_index because it's quite possible that the current menu item is not in $keep_items
				if( !empty( $ci_index ) && $cmw['title_from_current'] ){
					$cmw['_walker']['current_title'] = apply_filters(
						'the_title',
						$elements[ $ci_index ]->title,
						$elements[ $ci_index ]->ID
						);
				}

				//if we have a parent and we also want all the parent siblings, then we need
				//to pop the parent off the bottom of temp and either append or prepend the 
				//kids of the parent's parent onto keep_items...
				$paClass = 'cmw-the-included-parent';
				if( $find_kids_of && $i > 0 && $include_parent_siblings ){
					$siblings = $temp[ $keep_items[0]->$id_field ]['parent'];
					if( !empty( $siblings ) ){
						$siblings = $temp[ $temp[ $siblings ]['parent'] ]['kids'];
					}
					if( !empty( $siblings ) ){
						//remove (and store) parent...
						$j = array_pop( $breadcrumb );
						$parentAt = -1;
						//going backwards thru the array, prepend the parent and anything higher in the array than it...
						for($i = count($siblings) - 1; $i > -1; $i--){
							if( $parentAt < 0 && $siblings[ $i ] == $j ){
								$parentAt = $i;
							}
							if( $parentAt > -1 ){
								array_unshift( $keep_items, $elements[ $siblings[ $i ] ]);
								$keep_items[0]->classes[] = $parentAt == $i ? 'cmw-the-included-parent' : 'cmw-an-included-parent-sibling';
							}
						}
						//going forwards thru the array, append anything lower in the array than the parent...
						for($i = $parentAt + 1; $i < count($siblings); $i++){
							//anything after parent gets appended; parent and before get prepended...
							$j = count( $keep_items );
							array_push( $keep_items, $elements[ $siblings[ $i ] ]);
							$keep_items[$j]->classes[] = 'cmw-an-included-parent-sibling';
						}
						$i = $j = count( $breadcrumb );
						//don't include_parent now because we just have...
						$include_parent = false;
						$paClass = 'cmw-an-included-ancestor';
					}
					unset( $siblings );
				}

				if( $find_kids_of && $i > 0 ){
					if( $cmw['include_ancestors'] ){
						$j = 0; //everything in breadcrumb, from bottom to top
					}elseif( $include_parent ){
						--$j; //just the last item from breadcrumb
					}
					while( $i > $j ){
						array_unshift( $keep_items, $elements[ $breadcrumb[ --$i ] ]);
						$keep_items[0]->classes[] = $paClass;
						$paClass = 'cmw-an-included-ancestor';
					}
				}
				unset( $breadcrumb );
			}

			//for each item we're keeping, use the temp array to hold:
			//  [0] => the level within the new structure (starting at 1), and
			//  [1] => the number of kids each item has
			$temp = array();
			foreach( $keep_items as $item ){
				if( isset( $temp[ $item->$parent_field ] ) ){
					$temp[ $item->$id_field ] = array( $temp[ $item->$parent_field ][0] + 1, 0 );
					$temp[ $item->$parent_field ][1] += 1;
				}else{
					$temp[ $item->$id_field ] = array( 1, 0 );
				}
			}

			//transfer $keep back into $elements, resetting the index to increment from 1; also add
			//new classes to indicate level (starting at 1) and whether any item has kids
			//
			//note that we have already filtered out real orphans, but we may have introduced top-level
			//items that would appear to be orphans to the parent::walk() method, so we need to set all
			//the top-level items to appear as if they are root-level items...
			$elements = array();
			$i = 1;
			foreach( $keep_items as $item ){
				$item->classes[] = 'cmw-level-' . $temp[ $item->$id_field ][0];
				if( $temp[ $item->$id_field ][1] > 0 ){
					$item->classes[] = 'cmw-has-submenu';
				}
				if( $temp[ $item->$id_field ][0] == 1 ){
					//fake as root level item...
					$item->$parent_field = 0;
				}
				$elements[ $i++ ] = $item;
			}
			unset( $keep_items, $temp );
					
			//since we've done all the depth filtering, set max_depth to unlimited (unless 'flat' was requested!)...
			if( !$cmw['flat_output'] ){
				$max_depth = 0;
			}
		} //ends the check for bad max depth, empty elements, or empty cmw args

		return empty( $elements ) ? '' : parent::walk( apply_filters( 'custom_menu_wizard_walker_items', $elements, $args ), $max_depth, $args );
	}

}

/**
 * Custom Menu Wizard Widget class
 */
 class Custom_Menu_Wizard_Widget extends WP_Widget {

	var $_cmw_switches = array(
		'hide_title' => 0,
		'filter' => 0, //v1.1.0 changed from integer
		'fallback_no_ancestor' => 0, //v1.1.0 added
		'fallback_include_parent' => 0, //v1.1.0 added
		'fallback_include_parent_siblings' => 0, //v1.1.0 added
		'fallback_no_children' => 0, //v1.2.0 added
		'fallback_nc_include_parent' => 0, //v1.2.0 added
		'fallback_nc_include_parent_siblings' => 0, //v1.2.0 added
		'flat_output' => 0,
		'include_parent' => 0,
		'include_parent_siblings' => 0, //v1.1.0 added
		'include_ancestors' => 0,
		'hide_empty' => 0, //v1.1.0: this now only has relevance prior to WP v3.6
		'title_from_parent' => 0,
		'title_from_current' => 0, //v1.2.0 added
		'ol_root' => 0,
		'ol_sub' => 0,
		//field section toggles...
		'fs_filter' => 0,
		'fs_fallbacks' => 1, //v1.2.0 added
		'fs_output' => 1,
		'fs_container' => 1,
		'fs_classes' => 1,
		'fs_links' => 1
		);
	var $_cmw_strings = array(
		'title' => '',
		'container' => 'div',
		'container_id' => '',
		'container_class' => '',
		'menu_class' => 'menu-widget',
		'widget_class' => ''
		);
	var $_cmw_html = array(
		'before' => '',
		'after' => '',
		'link_before' => '',
		'link_after' => ''
		);
	var $_cmw_integers = array(
		'depth' => 0,
		'filter_item' => -2, //v1.1.0 changed from 0
		'menu' => 0,
		'start_level' => 1
		);

	//v1.2.1 holds information determined by the walker...
	var $_cmw_walker = array();

	/**
	 * class constructor
	 */
	function __construct() {
		parent::__construct(
			'custom-menu-wizard',
			'Custom Menu Wizard',
			array(
				'classname' => 'widget_custom_menu_wizard',
				'description' => __('Add a custom menu, or part of one, as a widget')
			)
		);
	}

	/**
	 * v1.2.1 stores any walker-determined information back into the widget instance
	 * gets run by the walker, on the filtered array of menu items, just before running parent::walk()
	 * only gets run *if* there are menu items found
	 * 
	 * @param array $items Filtered menu items
	 * @param object $args
	 * @return array Menu items
	 */
	function cmw_filter_walker_items($items, $args){
		if( !empty( $args->_custom_menu_wizard['_walker'] ) ){
			$this->_cmw_walker = $args->_custom_menu_wizard['_walker'];
		}
		return $items;
	}

	/**
	 * this (filter: wp_nav_menu) merely removes itself from the filters and returns an empty string
	 * it gets added by the cmw_filter_check_for_no_items method below, and only
	 * ever gets run when hide_empty is set on the widget instance
	 * 
	 * v1.1.0  As of WP v3.6 this method becomes superfluous because wp_nav_menu() has had code added to immediately
	 *         cop out (return false) if the output from wp_nav_menu_{$menu->slug}_items filter(s) is empty.
	 *         However, it stays in so as to cope with versions < 3.6
	 * 
	 * @param string $nav_menu HTML for the menu
	 * @param object $args
	 * @return string HTML for the menu
	 */
	function cmw_filter_no_output_when_empty($nav_menu, $args){
		remove_filter( 'wp_nav_menu', array( $this, 'cmw_filter_no_output_when_empty' ), 65532, 2 );
		return empty( $args->_custom_menu_wizard ) ? $nav_menu : '';
	}

	/**
	 * this gets run (filter: wp_nav_menu_{$menu->slug}_items) if hide_empty is set
	 * if $items is empty then add a wp_nav_menu filter to do the actual return of an empty string
	 * it gets run before the wp_nav_menu filter, but it gets the $items array whereas the wp_nav_menu filter does not
	 * it gets added by $this->widget() before wp_nav_menu() is called, and removed immediately after wp_nav_menu() returns
	 * 
	 * v1.1.0  As of WP v3.6 this method becomes superfluous because wp_nav_menu() has had code added to immediately
	 *         cop out (return false) if the output from wp_nav_menu_{$menu->slug}_items filter(s) is empty.
	 *         However, it stays in so as to cope with versions < 3.6
	 * 
	 * @param array $items Menu items
	 * @param object $args
	 * @return array Menu items 
	 */
	function cmw_filter_check_for_no_items($items, $args){
		if( !empty( $args->_custom_menu_wizard ) && empty( $items ) ){
			add_filter( 'wp_nav_menu', array( $this, 'cmw_filter_no_output_when_empty' ), 65532, 2 );
		}
		return $items;
	}

	/**
	 * produces the widget HTML at the front end
	 * 
	 * @filters : custom_menu_wizard_nav_params           array of params that will be sent to wp_nav_menu()
	 * 
	 * @param object $args Widget arguments
	 * @param array $instance Configuration for this widget instance
	 */
	function widget($args, $instance) {
		extract( $args, EXTR_SKIP );

		//switches...
		foreach( $this->_cmw_switches as $k=>$v ){
			$instance[ $k ] = !empty( $instance[ $k ] );
		}
		//integers...
		foreach( $this->_cmw_integers as $k=>$v ){
			$instance[ $k ] = max( $v, intval( $instance[ $k ] ) );
		}
		//strings...
		foreach( $this->_cmw_strings as $k=>$v ){
			$instance[ $k ] = empty( $instance[ $k ] ) ? $v : trim( $instance[ $k ] );
		}
		//html strings...
		foreach( $this->_cmw_html as $k=>$v ){
			$instance[ $k ] = empty( $instance[ $k ] ) ? $v : trim( $instance[ $k ] );
		}

		//v1.1.0  As of WP v3.6, wp_nav_menu() automatically prevents any HTML output if there are no items...
		$instance['hide_empty'] = $instance['hide_empty'] && $this->_pre_3point6();

		//fetch menu...
		if( !empty($instance['menu'] ) ){
			$menu = wp_get_nav_menu_object( $instance['menu'] );

			//no menu, no output...
			if ( !empty( $menu ) ){

				if( !empty( $instance['widget_class'] ) ){
					//$before_widget is usually just a DIV start-tag, with an id and a class; if it
					//gets more complicated than that then this may not work as expected...
					if( preg_match( '/^<[^>]+?class=["\']/', $before_widget ) > 0 ){
						$before_widget = preg_replace( '/(class=["\'])/', '$1' . $instance['widget_class'] . ' ', $before_widget, 1 );
					}else{
						$before_widget = preg_replace( '/^(<\w+)(\s|>)/', '$1 class="' . $instance['widget_class'] . '"$2', $before_widget );
					}
				}
				
				if( !empty( $instance['container_class'] ) ){
					$instance['container_class'] = "menu-{$menu->slug}-container {$instance['container_class']}";
				}
				
				$instance['menu_class'] = preg_split( '/\s+/', $instance['menu_class'], -1, PREG_SPLIT_NO_EMPTY );
				if( $instance['fallback_no_ancestor'] || $instance['fallback_no_children'] ){
					//v1.2.1 add a cmw-fellback-maybe class to the menu and we'll remove or replace it later...
					$instance['menu_class'][] = 'cmw-fellback-maybe';
				}
				$instance['menu_class'] = implode( ' ', $instance['menu_class'] );

				$walker = new Custom_Menu_Wizard_Walker;
				$params = array(
					'menu' => $menu,
					'container' => $instance['container'] == 'none' ? false : $instance['container'],
					'container_id' => $instance['container_id'],
					'menu_class' => $instance['menu_class'],
					'echo' => false,
					'fallback_cb' => false,
					'before' => $instance['before'],
					'after' => $instance['after'],
					'link_before' => $instance['link_before'],
					'link_after' => $instance['link_after'],
					'depth' => empty( $instance['flat_output'] ) ? $instance['depth'] : -1,
					'walker' =>$walker,
					//widget specific stuff...
					'_custom_menu_wizard' => array(
						'filter' => $instance['filter'],
						'filter_item' => $instance['filter_item'],
						'fallback_no_ancestor' => $instance['fallback_no_ancestor'], //v1.1.0
						'fallback_include_parent' => $instance['fallback_include_parent'], //v1.1.0
						'fallback_include_parent_siblings' => $instance['fallback_include_parent_siblings'], //v1.1.0
						'fallback_no_children' => $instance['fallback_no_children'], //v1.2.0
						'fallback_nc_include_parent' => $instance['fallback_nc_include_parent'], //v1.2.0
						'fallback_nc_include_parent_siblings' => $instance['fallback_nc_include_parent_siblings'], //v1.2.0
						'include_parent' => $instance['include_parent'],
						'include_parent_siblings' => $instance['include_parent_siblings'], //v1.1.0
						'include_ancestors' => $instance['include_ancestors'],
						'title_from_parent' => $instance['title_from_parent'],
						'title_from_current' => $instance['title_from_current'], //v1.2.0
						'ol_root' => $instance['ol_root'],
						'ol_sub' => $instance['ol_sub'],
						'flat_output' => $instance['flat_output'],
						'start_level' => $instance['start_level'],
						'depth' => $instance['depth'],
						//v1.2.1 this is for the walker's use... 
						'_walker' => array()
						)
					);
				if( $instance['ol_root'] ){
					$params['items_wrap'] = '<ol id="%1$s" class="%2$s">%3$s</ol>';
				}
				if( !empty( $instance['container_class'] ) ){
					$params['container_class'] = $instance['container_class'];
				}

				add_filter('custom_menu_wizard_walker_items', array( $this, 'cmw_filter_walker_items' ), 10, 2);
				if( $instance['hide_empty'] ){
					add_filter( "wp_nav_menu_{$menu->slug}_items", array( $this, 'cmw_filter_check_for_no_items' ), 65532, 2 );
				}

				//NB: wp_nav_menu() is in wp-includes/nav-menu-template.php
				$out = wp_nav_menu( apply_filters( 'custom_menu_wizard_nav_params', $params ) );

				remove_filter('custom_menu_wizard_walker_items', array( $this, 'cmw_filter_walker_items' ), 10, 2);
				if( $instance['hide_empty'] ){
					remove_filter( "wp_nav_menu_{$menu->slug}_items", array( $this, 'cmw_filter_check_for_no_items' ), 65532, 2 );
				}

				//only put something out if there is something to put out...
				if( !empty( $out ) ){

					//title from : 'from parent' has priority over 'from current'...
					//note that 'parent' is whatever you are getting the children of and therefore doesn't apply to a ShowAll, whereas
					//'current' is the current menu item (as determined by WP); also note that neither parent nor current actually has
					//to be present in the results
					if( $instance['title_from_parent'] && !empty( $this->_cmw_walker['parent_title'] ) ){
						$title = $this->_cmw_walker['parent_title'];
					}
					if( empty( $title ) && $instance['title_from_current'] && !empty( $this->_cmw_walker['current_title'] ) ){
						$title = $this->_cmw_walker['current_title'];
					}
					if( empty( $title ) ){
						$title = $instance['hide_title'] ? '' : $instance['title'];
					}

					//remove/replace the cmw-fellback-maybe class...
					$out = str_replace(
						'cmw-fellback-maybe',
						empty( $this->_cmw_walker['fellback'] ) ? '' : 'cmw-fellback-' . $this->_cmw_walker['fellback'],
						$out );

					echo $before_widget;
					if ( !empty($title) ){
						echo $before_title . apply_filters('widget_title', $title, $instance, $this->id_base) . $after_title;
					}
					echo $out . $after_widget;
				}
			}
		}
	}

	/**
	 * updates the widget settings sent from the backend admin
	 */
	function update( $new_instance, $old_instance ) {
		$instance = $old_instance;

		//switches...
		foreach( $this->_cmw_switches as $k=>$v ){
			$instance[ $k ] = empty( $new_instance[ $k ] ) ? 0 : 1;
		}
		//integers...
		foreach( $this->_cmw_integers as $k=>$v ){
			$instance[ $k ] = isset( $new_instance[ $k ]) ? max( $v, intval( $new_instance[ $k ] ) ) : $v;
		}
		//strings...
		foreach( $this->_cmw_strings as $k=>$v ){
			$instance[ $k ] = isset( $new_instance[ $k ] ) ? strip_tags( trim( $new_instance[ $k ] ) ) : $v;
		}
		//html strings...
		foreach( $this->_cmw_html as $k=>$v ){
			$instance[ $k ] = isset( $new_instance[ $k ] ) ? trim( $new_instance[ $k ] ) : $v;
		}

		return $instance;
	}

	/**
	 * produces the backend admin form(s)
	 */
	function form( $instance ) {

		//switches...
		foreach( $this->_cmw_switches as $k=>$v ){
			$instance[ $k ] = isset( $instance[ $k ] ) ? !empty( $instance[ $k ] ) : !empty( $v );
		}
		//integers...
		foreach( $this->_cmw_integers as $k=>$v ){
			$instance[ $k ] = isset( $instance[ $k ]) ? max( $v, intval( $instance[ $k ] ) ) : max($v, 0);
		}
		//strings...
		foreach( $this->_cmw_strings as $k=>$v ){
			$instance[ $k ] = isset( $instance[ $k ] ) ? esc_attr( trim( $instance[ $k ] ) ) : $v;
		}
		//html strings...
		foreach( $this->_cmw_html as $k=>$v ){
			$instance[ $k ] = isset( $instance[ $k ] ) ? esc_html( trim( $instance[ $k ] ) ) : $v;
		}

		//get menus...
		$menus = wp_get_nav_menus( array( 'orderby' => 'name' ) );
		$noitems = true;
		if( !empty( $menus ) ){
			foreach( $menus as $i=>$menu ){
				$menus[ $i ]->_items = wp_get_nav_menu_items( $menu->term_id );
				if( !empty( $menus[ $i ]->_items ) ){
					$noitems = false;
				}
			}
		}

		//if no populated menus exist, suggest the user go create one...
		if( $noitems ){
			echo '<p>'. sprintf( __('No populated menus have been created yet. <a href="%s">Create one</a>.'), admin_url('nav-menus.php') ) .'</p>';
			return;
		}

		/**
		 * permanently visible section : Title (with Hide) and Menu
		 */
?>
		<p>
			<label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:') ?></label>
			<label class="alignright">
				<input id="<?php echo $this->get_field_id('hide_title'); ?>" name="<?php echo $this->get_field_name('hide_title'); ?>"
					type="checkbox" value="1" <?php checked( $instance['hide_title'] ); ?> />
				<?php _e('Hide'); ?></label>
			<input id="<?php echo $this->get_field_id('title'); ?>" class="widefat" name="<?php echo $this->get_field_name('title'); ?>"
				type="text" value="<?php echo $instance['title']; ?>" />
			<small><em><?php _e('Title can be set, but need not be displayed'); ?></em></small>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('menu'); ?>"><?php _e('Select Menu:'); ?></label>
			<select id="<?php echo $this->get_field_id('menu'); ?>"
					class="widget-<?php echo $this->id_base; ?>-selectmenu widget-<?php echo $this->id_base; ?>-listen"
					name="<?php echo $this->get_field_name('menu'); ?>">
<?php
		foreach( $menus as $i=>$menu ){
			if( !empty( $menu->_items ) ){
?>
				<option <?php selected($instance['menu'], $menu->term_id); ?> value="<?php echo $menu->term_id; ?>"><?php echo $menu->name; ?></option>
<?php
			}
		}
?>
			</select>
		</p>

<?php
		/**
		 * start collapsible section : 'Filter'
		 */
		$this->_open_a_field_section($instance, 'Filter', 'fs_filter');
?>
		<small class="alignright" style="line-height:1;"><a href="<?php echo plugins_url('/demo.html', __FILE__); ?>" target="_blank"><?php _e(' demo' ); ?></a></small>
		<p>
			<label>
				<input id="<?php echo $this->get_field_id('filter'); ?>_0" class="widget-<?php echo $this->id_base; ?>-listen"
					name="<?php echo $this->get_field_name('filter'); ?>" type="radio" value="0" <?php checked(!$instance['filter']); ?> />
				<?php _e('Show all'); ?></label>
			<br /><label>
				<input id="<?php echo $this->get_field_id('filter'); ?>_1" class="widget-<?php echo $this->id_base; ?>-listen"
					name="<?php echo $this->get_field_name('filter'); ?>" type="radio" value="1" <?php checked($instance['filter']); ?> />
				<?php _e('Children of:'); ?></label>
			<select id="<?php echo $this->get_field_id('filter_item'); ?>" class="widget-<?php echo $this->id_base; ?>-listen"
					style="max-width:100%;" name="<?php echo $this->get_field_name('filter_item'); ?>">
				<option value="0" <?php selected( $instance['filter_item'], 0 ); ?>><?php _e('Current Item'); ?></option>
				<option value="-2" <?php selected( $instance['filter_item'], -2 ); ?>><?php _e('Current Root Item'); ?></option>
				<option value="-1" <?php selected( $instance['filter_item'], -1 ); ?>><?php _e('Current Parent Item'); ?></option>
<?php
//v1.1.0
// IE is a pita when it comes to SELECTs because it ignores any styling on OPTGROUPs and OPTIONs, so I'm changing the way
// that this SELECT works by introducing a copy from which the javascript can pick the relevant OPTGROUP
		$menuOptions = array(); 

		$maxlevel = 1;
		foreach( $menus as $i=>$menu ){
			//as of v1.2.0 : no items, no optgroup!
			if( !empty( $menu->_items ) ){
				//v1.1.0 changed the indents from padding to hyphen-space (for IE!!!! grrrr...)
				$itemindents = array('0' => 0);
				$menuOptions[] = '<optgroup label="' . $menu->name . '" data-cmw-optgroup-index="' . $i . '">';
				foreach( $menu->_items as $item ){
					//exclude orpans!
					if( isset($itemindents[ $item->menu_item_parent ])){
						$itemindents[ $item->ID ] = $itemindents[ $item->menu_item_parent ] + 1;
						$maxlevel = max( $maxlevel, $itemindents[ $item->ID ] );
						$menuOptions[] = '<option style="padding-left:0.75em;" value="' . $item->ID . '" ' .
							selected( $instance['filter_item'], $item->ID, false ) . '>' .
							str_repeat('- ', $itemindents[ $item->menu_item_parent ]) . $item->title . '</option>';
					}
				}
				$menuOptions[] = '</optgroup>';
			}
		}
		$menuOptions = implode("\n", $menuOptions);
		echo $menuOptions;
?>		
			</select>
			<select id="<?php echo $this->get_field_id('filter_item_ignore'); ?>" disabled="disabled"
					style="display:none;position:absolute;left:-5000px;top:-5000px;"
					name="<?php echo $this->get_field_name('filter_item_ignore'); ?>">
			<?php echo $menuOptions; ?>
			</select>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('start_level'); ?>"><?php _e('Starting Level:'); ?></label>
			<select id="<?php echo $this->get_field_id('start_level'); ?>" name="<?php echo $this->get_field_name('start_level'); ?>">
<?php
		$j = max( $maxlevel, $instance['start_level'] );
		for( $i = 1; $i <= $j; $i++ ){
?>
				<option value="<?php echo $i; ?>" <?php selected($instance['start_level'], $i); ?>><?php echo $i; ?></option>
<?php
		}
?>
			</select>
			<br /><small><em><?php _e('Level to start testing items for inclusion'); ?></em></small>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('depth'); ?>"><?php _e('For Depth:'); ?></label>
			<select id="<?php echo $this->get_field_id('depth'); ?>" name="<?php echo $this->get_field_name('depth'); ?>">
				<option value="0" <?php selected( $instance['depth'], 0 ); ?>><?php _e('unlimited'); ?></option>
<?php
		$j = max( $j, $instance['depth'] );
		for( $i = 1; $i <= $j; $i++ ){
?>
				<option value="<?php echo $i; ?>" <?php selected( $instance['depth'], $i ); ?>><?php echo $i; ?> <?php _e($i > 1 ? 'levels' : 'level'); ?></option>
<?php
		}
?>
			</select>
			<br /><small><em><?php _e('Relative to the first Filtered item found'); ?></em></small>
		</p>
<?php $this->_close_a_field_section(); ?>

<?php
		/**
		 * v1.2.0 start collapsible section : 'Fallbacks'
		 */
		$this->_open_a_field_section($instance, 'Fallbacks', 'fs_fallbacks');
?>
		<small class="alignright" style="line-height:1;"><a href="<?php echo plugins_url('/demo.html', __FILE__); ?>" target="_blank"><?php _e(' demo' ); ?></a></small>
		<p class="clear widget-<?php echo $this->id_base; ?>-disableifnot-rp" style="line-height:1.3333;">
			<small><strong><?php _e( 'If &quot;Children of&quot; is <em>Current Root / Parent Item</em>, and no ancestor exists' ); ?> :</strong></small>
			<br /><label>
				<input id="<?php echo $this->get_field_id('fallback_no_ancestor'); ?>"
					name="<?php echo $this->get_field_name('fallback_no_ancestor'); ?>" type="checkbox" value="1"
					<?php checked($instance['fallback_no_ancestor']); ?> />
				<?php _e('Switch to Current Item, and'); ?></label>
			<br /><label style="padding-left:1em;">
				<input id="<?php echo $this->get_field_id('fallback_include_parent'); ?>"
					name="<?php echo $this->get_field_name('fallback_include_parent'); ?>" type="checkbox" value="1"
					<?php checked($instance['fallback_include_parent']); ?> />
				<?php _e('Include Parent...'); ?> </label>
			<label>
				<input id="<?php echo $this->get_field_id('fallback_include_parent_siblings'); ?>"
					name="<?php echo $this->get_field_name('fallback_include_parent_siblings'); ?>" type="checkbox" value="1"
					<?php checked($instance['fallback_include_parent_siblings']); ?> />
				<?php _e('&amp; its Siblings'); ?></label>
		</p>

		<p class="widget-<?php echo $this->id_base; ?>-disableifnot-ci" style="line-height:1.3333;">
			<small><strong><?php _e( 'If &quot;Children of&quot; is <em>Current Item</em>, and current item has no children' ); ?> :</strong></small>
			<br /><label>
				<input id="<?php echo $this->get_field_id('fallback_no_children'); ?>"
					name="<?php echo $this->get_field_name('fallback_no_children'); ?>" type="checkbox" value="1"
					<?php checked($instance['fallback_no_children']); ?> />
				<?php _e('Switch to Current Parent Item, and'); ?></label>
			<br /><label style="padding-left:1em;">
				<input id="<?php echo $this->get_field_id('fallback_nc_include_parent'); ?>"
					name="<?php echo $this->get_field_name('fallback_nc_include_parent'); ?>" type="checkbox" value="1"
					<?php checked($instance['fallback_nc_include_parent']); ?> />
				<?php _e('Include Parent...'); ?> </label>
			<label>
				<input id="<?php echo $this->get_field_id('fallback_nc_include_parent_siblings'); ?>"
					name="<?php echo $this->get_field_name('fallback_nc_include_parent_siblings'); ?>" type="checkbox" value="1"
					<?php checked($instance['fallback_nc_include_parent_siblings']); ?> />
				<?php _e('&amp; its Siblings'); ?></label>
		</p>


<?php $this->_close_a_field_section(); ?>

<?php
		/**
		 * start collapsible section : 'Output'
		 */
		$this->_open_a_field_section($instance, 'Output', 'fs_output');
?>
		<small class="alignright" style="line-height:1;"><a href="<?php echo plugins_url('/demo.html', __FILE__); ?>" target="_blank"><?php _e(' demo' ); ?></a></small>
		<p>
			<label>
				<input id="<?php echo $this->get_field_id('flat_output'); ?>_0" name="<?php echo $this->get_field_name('flat_output'); ?>"
					type="radio" value="0" <?php checked(!$instance['flat_output']); ?> />
				<?php _e('Hierarchical'); ?></label>
			&nbsp;<label>
				<input id="<?php echo $this->get_field_id('flat_output'); ?>_1" name="<?php echo $this->get_field_name('flat_output'); ?>"
					type="radio" value="1" <?php checked($instance['flat_output']); ?> />
				<?php _e('Flat'); ?></label>
		</p>

		<p class="widget-<?php echo $this->id_base; ?>-disableif">
			<label>
				<input id="<?php echo $this->get_field_id('include_parent'); ?>"
					name="<?php echo $this->get_field_name('include_parent'); ?>" type="checkbox"
					value="1" <?php checked($instance['include_parent']); ?> />
				<?php _e('Include Parent...'); ?> </label>
			<label>
				<input id="<?php echo $this->get_field_id('include_parent_siblings'); ?>"
					name="<?php echo $this->get_field_name('include_parent_siblings'); ?>" type="checkbox"
					value="1" <?php checked($instance['include_parent_siblings']); ?> />
				<?php _e('&amp; its Siblings'); ?></label>
			<br /><label>
				<input id="<?php echo $this->get_field_id('include_ancestors'); ?>"
					name="<?php echo $this->get_field_name('include_ancestors'); ?>" type="checkbox"
					value="1" <?php checked($instance['include_ancestors']); ?> />
				<?php _e('Include Ancestors'); ?></label>
			<br /><label>
				<input id="<?php echo $this->get_field_id('title_from_parent'); ?>"
					name="<?php echo $this->get_field_name('title_from_parent'); ?>" type="checkbox"
					value="1" <?php checked($instance['title_from_parent']); ?> />
				<?php _e('Title from Parent'); ?></label>
			<br /><small><em><?php _e('Only if the &quot;Children of&quot; Filter returns items'); ?></em></small>
		</p>

		<p>
			<label>
				<input id="<?php echo $this->get_field_id('title_from_current'); ?>"
					name="<?php echo $this->get_field_name('title_from_current'); ?>" type="checkbox"
					value="1" <?php checked($instance['title_from_current']); ?> />
				<?php _e('Title from &quot;Current&quot; Item'); ?></label>
			<br /><small><em><?php _e('Lower priority than &quot;Title from Parent&quot;'); ?></em></small>
		</p>

		<p>
			<?php _e('Change UL to OL:'); ?>
			<br /><label>
				<input id="<?php echo $this->get_field_id('ol_root'); ?>" name="<?php echo $this->get_field_name('ol_root'); ?>"
					type="checkbox" value="1" <?php checked($instance['ol_root']); ?> />
				<?php _e('Top Level'); ?></label>
			&nbsp;<label>
				<input id="<?php echo $this->get_field_id('ol_sub'); ?>" name="<?php echo $this->get_field_name('ol_sub'); ?>"
					type="checkbox" value="1" <?php checked($instance['ol_sub']); ?> />
				<?php _e('Sub-Levels'); ?></label>
		</p>

<?php
		//v1.1.0  As of WP v3.6, wp_nav_menu() automatically cops out (without outputting any HTML) if there are no items,
		//        so the hide_empty option becomes superfluous; however, I'll keep the previous setting (if there was one)
		//        in case of reversion to an earlier version of WP...
		if( $this->_pre_3point6() ){
?>
		<p>
			<label>
				<input id="<?php echo $this->get_field_id('hide_empty'); ?>" name="<?php echo $this->get_field_name('hide_empty'); ?>"
					type="checkbox" value="1" <?php checked($instance['hide_empty']); ?> />
				<?php _e('Hide Widget if Empty'); ?></label>
			<br /><small><em><?php _e('Prevents any output when no items are found'); ?></em></small>
		</p>
<?php }else{ ?>
		<input id="<?php echo $this->get_field_id('hide_empty'); ?>" name="<?php echo $this->get_field_name('hide_empty'); ?>"
			type="hidden" value="<?php echo $instance['hide_empty'] ? '1' : ''; ?>" />
<?php } ?>

<?php $this->_close_a_field_section(); ?>

<?php
		/**
		 * start collapsible section : 'Container'
		 */
		$this->_open_a_field_section($instance, 'Container', 'fs_container');
?>
			<p>
				<label for="<?php echo $this->get_field_id('container'); ?>"><?php _e('Element:') ?></label>
				<input id="<?php echo $this->get_field_id('container'); ?>" name="<?php echo $this->get_field_name('container'); ?>"
					type="text" value="<?php echo $instance['container']; ?>" />
				<br /><small><em><?php _e( 'Eg. div or nav; leave empty for no container' ); ?></em></small>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('container_id'); ?>"><?php _e('Unique ID:') ?></label>
				<input id="<?php echo $this->get_field_id('container_id'); ?>" name="<?php echo $this->get_field_name('container_id'); ?>"
					type="text" value="<?php echo $instance['container_id']; ?>" />
				<br /><small><em><?php _e( 'An optional ID for the container' ); ?></em></small>
			</p>
			<p>
				<label for="<?php echo $this->get_field_id('container_class'); ?>"><?php _e('Class:') ?></label>
				<input id="<?php echo $this->get_field_id('container_class'); ?>" name="<?php echo $this->get_field_name('container_class'); ?>"
					type="text" value="<?php echo $instance['container_class']; ?>" />
				<br /><small><em><?php _e( 'Extra class for the container' ); ?></em></small>
			</p>
<?php $this->_close_a_field_section(); ?>

<?php
		/**
		 * start collapsible section : 'Classes'
		 */
		$this->_open_a_field_section($instance, 'Classes', 'fs_classes');
?>
		<p>
			<label for="<?php echo $this->get_field_id('menu_class'); ?>"><?php _e('Menu Class:') ?></label>
			<input id="<?php echo $this->get_field_id('menu_class'); ?>" name="<?php echo $this->get_field_name('menu_class'); ?>"
				type="text" value="<?php echo $instance['menu_class']; ?>" />
			<br /><small><em><?php _e( 'Class for the list element forming the menu' ); ?></em></small>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('widget_class'); ?>"><?php _e('Widget Class:') ?></label>
			<input id="<?php echo $this->get_field_id('widget_class'); ?>" name="<?php echo $this->get_field_name('widget_class'); ?>"
				type="text" value="<?php echo $instance['widget_class']; ?>" />
			<br /><small><em><?php _e( 'Extra class for the widget itself' ); ?></em></small>
		</p>
<?php $this->_close_a_field_section(); ?>

<?php
		/**
		 * start collapsible section : 'Links'
		 */
		$this->_open_a_field_section($instance, 'Links', 'fs_links');
?>
		<p>
			<label for="<?php echo $this->get_field_id('before'); ?>"><?php _e('Before the Link:') ?></label>
			<input id="<?php echo $this->get_field_id('before'); ?>" class="widefat" name="<?php echo $this->get_field_name('before'); ?>"
				type="text" value="<?php echo $instance['before']; ?>" />
			<small><em><?php _e( htmlspecialchars('Text/HTML to go before the <a> of the link') ); ?></em></small>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('after'); ?>"><?php _e('After the Link:') ?></label>
			<input id="<?php echo $this->get_field_id('after'); ?>" class="widefat" name="<?php echo $this->get_field_name('after'); ?>"
				type="text" value="<?php echo $instance['after']; ?>" />
			<small><em><?php _e( htmlspecialchars('Text/HTML to go after the </a> of the link') ); ?></em></small>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('link_before'); ?>"><?php _e('Before the Link Text:') ?></label>
			<input id="<?php echo $this->get_field_id('link_before'); ?>" class="widefat" name="<?php echo $this->get_field_name('link_before'); ?>"
				type="text" value="<?php echo $instance['link_before']; ?>" />
			<small><em><?php _e( 'Text/HTML to go before the link text' ); ?></em></small>
		</p>
		<p>
			<label for="<?php echo $this->get_field_id('link_after'); ?>"><?php _e('After the Link Text:') ?></label>
			<input id="<?php echo $this->get_field_id('link_after'); ?>" class="widefat" name="<?php echo $this->get_field_name('link_after'); ?>"
				type="text" value="<?php echo $instance['link_after']; ?>" />
			<small><em><?php _e( 'Text/HTML to go after the link text' ); ?></em></small>
		</p>	
<?php $this->_close_a_field_section(); ?>

<?php
	} //end form()

	/**
	 * outputs the HTML to begin a collapsible/expandable group of settings
	 * 
	 * @param array $instance
	 * @param string $text Label
	 * @param string $fname Field name
	 */
	function _open_a_field_section( &$instance, $text, $fname ){
?>
<div class="stuffbox widget-<?php echo $this->id_base; ?>-collapsible-fieldset" title="<?php _e( 'Click to show/hide' ); ?>" style="margin:0 0 0.5em;cursor:pointer;">
	<input id="<?php echo $this->get_field_id($fname); ?>" class="hidden-field" name="<?php echo $this->get_field_name($fname); ?>"
		type="checkbox" value="1" <?php checked( $instance[$fname] ); ?> />
	<div style="background:transparent url(images/arrows.png) no-repeat 0 <?php echo $instance[$fname] ? '0' : '-36px'; ?>;height:16px; width:16px;float:right;outline:0 none;"></div>
	<h3 style="font-size:1em;margin:0;padding:2px 0.5em;"><?php _e( $text ); ?></h3>
</div>
<div class="hide-if-js"<?php echo $instance[$fname] ? '' : ' style="display:block;"'; ?>>
<?php
	} //end _open_a_field_section()

	/**
	 * outputs the HTML to close off a collapsible/expandable group of settings
	 */
	function _close_a_field_section(){
?>
</div>
<?php
	} //end _close_a_field_section()
	
	/**
	 * returns true if the version of WP is lower than 3.6 (ie. 3.5* or below)
	 */
	function _pre_3point6(){
		global $wp_version;

		return version_compare( strtolower( $wp_version ), '3.6a', '<' );
	} //end _pre_3point6()

} //end of class

/** 
 * as of v1.2.0
 * shortcode processing for [custom_menu_wizard option="" option="" ...]
 * see wp-includes/widgets.php for the_widget() code
 * Note that hide_empty is set to ON and can not be overridden!
 * 
 * default (ie. no options) is:
 *  - show all
 *  - of first populated menu found (alphabetically)
 *  - from root, for unlimited depth
 *  - as hierarchical nested ULs inside a DIV.widget_custom_menu_wizard.shortcode_custom_menu_wizard
 * 
 * @filters : custom_menu_wizard_shortcode_attributes        array of attributes supplied to the shortcode
 *            custom_menu_wizard_shortcode_settings          array of widget settings derived from the attributes
 *            custom_menu_wizard_shortcode_widget_args       array of the sidebar args used to wrap widgets and their titles (before|after_widget, before|after_title)
 *
 * @param array $atts options supplied to the shortcode
 * @param string $content Within start-end shortcode tags
 * @param string $tag Shortcode tag
 * @return string HTML
 */
function custom_menu_wizard_widget_shortcode($atts, $content, $tag){
	$html = '';
	$ok = false;
	$instance = shortcode_atts( array(
		'title' => '',
		'menu' => 0, // menu id, slug or name
		//determines filter & filter_item...
		'children_of' => '', // empty = show all; menu item id or title (caseless), or current|current-item|parent|current-parent|root|current-ancestor
		'start_level' => 1,
		'depth' => 0, // 0 = unlimited
		//only if children_of is (parent|current-parent|root|current-ancestor); determines fallback_no_ancestor, fallback_include_parent & fallback_include_parent_siblings...
		'fallback_parent' => 0, // 1 = use current-item; 'parent' = *and* include parent, 'siblings' = *and* include both parent and its siblings
		//only if children_of is (current|current-item); determines fallback_no_children, fallback_nc_include_parent & fallback_nc_include_parent_siblings...
		'fallback_current' => 0, // 1 = use current-parent; 'parent' = *and* include parent (if available), 'siblings' = *and* include both parent (if available) and its siblings
				//switches...
		'flat_output' => 0,
		//determines include_parent, include_parent_siblings & include_ancestors...
		'include' =>'', //comma|space|hyphen separated list of 'parent', 'siblings', 'ancestors'
		'ol_root' => 0,
		'ol_sub' => 0,
		//determines title_from_parent & title_from_current...
		'title_from' => '', //comma|space|hyphen separated list of 'parent', 'current'
		//strings...
		'container' => 'div', // a tag : div|nav are WP restrictions, not the widget's; '' =  no container
		'container_id' => '',
		'container_class' => '',
		'menu_class' => 'menu-widget',
		'widget_class' => '',
		//determines before & after...
		'wrap_link' => '', // a tag name (eg. div, p, span, etc)
		//determines link_before & link_after...
		'wrap_link_text' => '' // a tag name (eg. span, em, strong)
		), $atts );

	$instance = apply_filters( 'custom_menu_wizard_shortcode_attributes', $instance );

	if( empty( $instance['menu'] ) ){
		//gonna find the first menu (alphabetically) that has items...
		$menus = wp_get_nav_menus( array( 'orderby' => 'name' ) );
	}else{
		//allow for menu being something other than an id (eg. slug or name), but we need the id for the widget...
		$menus = wp_get_nav_menu_object( $instance['menu'] );
		if( !empty( $menus) ){
			$menus = array( $menus );
		}
	}
	if( !empty( $menus ) ){
		foreach( $menus as $i=>$menu ){
			$items = wp_get_nav_menu_items( $menu->term_id );
			$ok = !empty( $items );
			if( $ok ){
				$instance['menu'] = $menu->term_id;
				break;
			}
		}
	}
	unset( $menus );

	if( $ok ){
		//children_of => filter & filter_item...
		if( empty( $instance['children_of'] ) ){
			$instance['children_of'] = '';
		}
		$instance['filter'] = $instance['filter_item'] = 0;
		switch( $instance['children_of'] ){
			case '':
				break;
			case 'root': case 'current-ancestor':
				--$instance['filter_item']; //ends up as -2
			case 'parent': case 'current-parent':
				--$instance['filter_item']; //ends up as -1
			case 'current': case 'current-item':
				$instance['filter'] = 1;
				break;
			default:
				$instance['filter'] = 1;
				$instance['filter_item'] = strtolower( $instance['children_of'] );
		}
		unset( $instance['children_of'] );
		//if filter_item is non-numeric then it could be the title of a menu item, but we need it to be the menu item's id...
		if( !is_numeric( $instance['filter_item'] ) ){
			foreach( $items as $item ){
				$ok = strtolower( $item->title ) == $instance['filter_item'];
				if( $ok ){
					$instance['filter_item'] = $item->ID;
					break;
				}
			}
		}
	}

	if( $ok ){
		//fallback_parent => fallback_no_ancestor switch (and extension switches)...
		$instance['fallback_no_ancestor'] = $instance['fallback_include_parent'] = $instance['fallback_include_parent_siblings'] = 0;
		if( $instance['filter_item'] < 0 && !empty( $instance['fallback_parent'] ) ){
			$instance['fallback_no_ancestor'] = 1;
			$i = preg_split( '/[\s,-]+/', strtolower( $instance['fallback_parent'] ), -1, PREG_SPLIT_NO_EMPTY );
			foreach( $i as $j ){
				if( $j == 'parent' ){
					$instance['fallback_include_parent'] = 1;
				}elseif( $j == 'siblings' ){
					$instance['fallback_include_parent_siblings'] = 1;
				}
			}
		}
		//fallback_current => fallback_no_children switch (and extension switches)...
		$instance['fallback_no_children'] = $instance['fallback_nc_include_parent'] = $instance['fallback_nc_include_parent_siblings'] = 0;
		if( $instance['filter'] == 1 && $instance['filter_item'] == 0 && !empty( $instance['fallback_current'] ) ){
			$instance['fallback_no_children'] = 1;
			$i = preg_split( '/[\s,-]+/', strtolower( $instance['fallback_current'] ), -1, PREG_SPLIT_NO_EMPTY );
			foreach( $i as $j ){
				if( $j == 'parent' ){
					$instance['fallback_nc_include_parent'] = 1;
				}elseif( $j == 'siblings' ){
					$instance['fallback_nc_include_parent_siblings'] = 1;
				}
			}
		}
		unset( $instance['fallback_parent'], $instance['fallback_current'] );
		//include => include_* ...
		$instance['include_parent'] = $instance['include_parent_siblings'] = $instance['include_ancestors'] = 0;
		if( $instance['filter'] == 1 && !empty( $instance['include'] ) ){
			$i = preg_split( '/[\s,-]+/', strtolower( $instance['include'] ), -1, PREG_SPLIT_NO_EMPTY );
			foreach( $i as $j ){
				if( $j == 'parent' ){
					$instance['include_parent'] = 1;
				}elseif( $j == 'siblings' ){
					$instance['include_parent_siblings'] = 1;
				}elseif( $j == 'ancestors' ){
					$instance['include_ancestors'] = 1;
				}
			}
		}
		unset( $instance['include'] );
		//title_from => title_from_parent, title_from_current ...
		$instance['title_from_parent'] = $instance['title_from_current'] = 0;
		if( !empty( $instance['title_from'] ) ){
			$i = preg_split( '/[\s,-]+/', strtolower( $instance['title_from'] ), -1, PREG_SPLIT_NO_EMPTY );
			foreach( $i as $j ){
				if( $j == 'parent' ){
					$instance['title_from_parent'] = 1;
				}elseif( $j == 'current' ){
					$instance['title_from_current'] = 1;
				}
			}
		}
		unset( $instance['title_from'] );
		//wrap_link => before & after...
		$instance['before'] = $instance['after'] = '';
		$instance['wrap_link'] = esc_attr( trim( $instance['wrap_link'] ) );
		if( !empty( $instance['wrap_link'] ) ){
			$instance['before'] = '<' . $instance['wrap_link'] . '>';
			$instance['after'] = '</' . $instance['wrap_link'] . '>';
		}
		//wrap_link_text => link_before & link_after...
		$instance['link_before'] = $instance['link_after'] = '';
		$instance['wrap_link_text'] = esc_attr( trim( $instance['wrap_link_text'] ) );
		if( !empty( $instance['wrap_link_text'] ) ){
			$instance['link_before'] = '<' . $instance['wrap_link_text'] . '>';
			$instance['link_after'] = '</' . $instance['wrap_link_text'] . '>';
		}
		//handle widget_class here because we have full control over $before_widget...
		$before_widget_class = array(
			'widget_custom_menu_wizard',
			'shortcode_custom_menu_wizard'
			);
		$instance['widget_class'] = empty( $instance['widget_class'] ) ? '' : esc_attr( trim ( $instance['widget_class'] ) );
		if( !empty( $instance['widget_class'] ) ){
			foreach( explode(' ', $instance['widget_class'] ) as $i ){
				if( !empty( $i ) && !in_array( $i, $before_widget_class ) ){
					$before_widget_class[] = $i;
				}
			} 
		}
		$instance['widget_class'] = '';
		//turn on hide_empty...
		$instance['hide_empty'] = 1;
	}

	if( $ok ){
		//apart from before_title, these are lifted from the_widget()...
		$sidebar_args = array(
			'before_widget' => '<div class="' . implode( ' ', $before_widget_class ) . '">',
			'after_widget' => '</div>',
			'before_title' => '<h2 class="widgettitle">',
			'after_title' => '</h2>'
			);
		ob_start();
		the_widget(
			'Custom_Menu_Wizard_Widget',
			apply_filters('custom_menu_wizard_shortcode_settings', $instance ),
			apply_filters('custom_menu_wizard_shortcode_widget_args', $sidebar_args )
			);
		$html = ob_get_clean();
	}
 	return empty($html) ? '' : $html;
}
