<?php
/**
 * WordPress Customize Nav Menu Setting class.
 *
 * @package WordPress
 * @subpackage Customize
 * @since 4.3.0
 */

/**
 * Customize Setting to represent a nav_menu.
 *
 * Subclass of WP_Customize_Setting to represent a nav_menu taxonomy term, and
 * the IDs for the nav_menu_items associated with the nav menu.
 *
 * @since 4.3.0
 *
 * @see wp_get_nav_menu_items()
 * @see WP_Customize_Setting
 */
class WP_Customize_Nav_Menu_Item_Setting extends WP_Customize_Setting {

	const ID_PATTERN = '/^nav_menu_item\[(?P<id>-?\d+)\]$/';

	const POST_TYPE = 'nav_menu_item';

	/**
	 * Setting type.
	 *
	 * @var string
	 */
	public $type = 'nav_menu_item';

	/**
	 * Default setting value.
	 *
	 * @see wp_setup_nav_menu_item()
	 * @var array
	 */
	public $default = array(
		// The $menu_item_data for wp_update_nav_menu_item()
		// 'db_id' => -1, // @todo this is $this->post_id, we probably shouldn't store here.
		'object_id' => 0,
		'object' => '', // Taxonomy name.
		'menu_item_parent' => 0, // A.K.A. menu-item-parent-id; note that post_parent is different, and not included.
		'position' => 0, // A.K.A. menu_order.
		'type' => 'custom', // Note that type_label is not included here.
		'title' => '',
		'url' => '',
		'target' => '',
		'attr_title' => '',
		'description' => '',
		'classes' => '',
		'xfn' => '',
		'status' => 'publish',
		'nav_menu_term_id' => 0, // This will be supplied as the $menu_id arg for wp_update_nav_menu_item().
		// @todo what about additional fields added by wp_update_nav_menu_item action?
	);

	/**
	 * Default transport.
	 *
	 * @var string
	 */
	public $transport = 'postMessage';

	/**
	 * The post ID represented by this setting instance. This is the db_id.
	 *
	 * A negative value represents a placeholder ID for a new menu not yet saved.
	 *
	 * @todo Should this be $db_id, and also use this for WP_Customize_Nav_Menu_Setting::$term_id
	 *
	 * @var int
	 */
	public $post_id;

	/**
	 * Previous (placeholder) post ID used before creating a new menu item.
	 *
	 * This value will be exported to JS via the customize_save_response filter
	 * so that JavaScript can update the settings to refer to the newly-assigned
	 * post ID. This value is always negative to indicate it does not refer to
	 * a real post.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 * @see WP_Customize_Nav_Menu_Item_Setting::amend_customize_save_response()
	 *
	 * @var int
	 */
	public $previous_post_id;

	/**
	 * Whether or not preview() was called.
	 *
	 * @var bool
	 */
	public $is_previewed = false;

	/**
	 * Status for calling the update method, used in customize_save_response filter.
	 *
	 * When status is inserted, the placeholder post ID is stored in $previous_post_id.
	 * When status is error, the error is stored in $update_error.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 * @see WP_Customize_Nav_Menu_Item_Setting::amend_customize_save_response()
	 *
	 * @var string updated|inserted|deleted|error
	 */
	public $update_status;

	/**
	 * Any error object returned by wp_update_nav_menu_item() when setting is updated.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 * @see WP_Customize_Nav_Menu_Item_Setting::amend_customize_save_response()
	 *
	 * @var WP_Error
	 */
	public $update_error;

	/**
	 * Constructor.
	 *
	 * Any supplied $args override class property defaults.
	 *
	 * @param WP_Customize_Manager $manager Manager instance.
	 * @param string               $id      An specific ID of the setting. Can be a
	 *                                       theme mod or option name.
	 * @param array                $args    Setting arguments.
	 * @throws Exception If $id is not valid for this setting type.
	 */
	public function __construct( WP_Customize_Manager $manager, $id, array $args = array() ) {
		if ( empty( $manager->menus ) ) {
			throw new Exception( 'Expected WP_Customize_Manager::$menus to be set.' );
		}

		if ( ! preg_match( self::ID_PATTERN, $id, $matches ) ) {
			throw new Exception( "Illegal widget setting ID: $id" );
		}

		$this->post_id = intval( $matches['id'] );

		parent::__construct( $manager, $id, $args );
	}

	/**
	 * Get the instance data for a given widget setting.
	 *
	 * @see wp_setup_nav_menu_item()
	 * @return array
	 */
	public function value() {
		if ( $this->is_previewed && $this->_previewed_blog_id === get_current_blog_id() ) {
			$undefined = new stdClass(); // Symbol.
			$post_value = $this->post_value( $undefined );
			if ( $undefined === $post_value ) {
				$value = $this->_original_value;
			} else {
				$value = $post_value;
			}
		} else {
			$value = false;

			// Note that a ID of less than one indicates a nav_menu not yet inserted.
			if ( $this->post_id > 0 ) {
				$post = get_post( $this->post_id );
				if ( $post && self::POST_TYPE === $post->post_type ) {
					$value = wp_array_slice_assoc(
						(array) wp_setup_nav_menu_item( $post ),
						array_keys( $this->default )
					);
				}
			}

			if ( ! is_array( $value ) ) {
				$value = $this->default;
			}
		}
		return $value;
	}

	/**
	 * Handle previewing the setting.
	 *
	 * @see WP_Customize_Manager::post_value()
	 * @return void
	 */
	public function preview() {
		if ( $this->is_previewed ) {
			return;
		}
		$this->is_previewed = true;
		$this->_original_value = $this->value();
		$this->_previewed_blog_id = get_current_blog_id();

		add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_wp_get_nav_menu_items' ), 10, 3 );
	}

	/**
	 * Filter the wp_get_nav_menu_items() result to supply the previewed menu items.
	 *
	 * @see wp_get_nav_menu_items()
	 * @param array  $items An array of menu item post objects.
	 * @param object $menu  The menu object.
	 * @param array  $args  An array of arguments used to retrieve menu item objects.
	 * @return array
	 */
	function filter_wp_get_nav_menu_items( $items, $menu, $args ) {
		$menu_item = $this->value();

		// Skip filtering other menus.
		if ( $menu->term_id !== $menu_item['nav_menu_term_id'] ) {
			return $items;
		}

		// Handle deleted menu item.
		if ( false === $menu_item ) {
			// @todo Remove $menu_item from $items
		}

		// @todo if $menu === $menu_item['nav_menu_term_id'], ensure among $items
		// @todo if $this->post_id < 0, then we will surely need to append $menu_item to list
		// @todo make sure that any item in $items is updated with the properties of $menu_item
		return $items;
	}

	/**
	 * Sanitize an input.
	 *
	 * Note that parent::sanitize() erroneously does wp_unslash() on $value, but
	 * we remove that in this override.
	 *
	 * @param array $menu_item_value The value to sanitize.
	 * @return array|false|null Null if an input isn't valid. False if it is marked for deletion. Otherwise the sanitized value.
	 */
	public function sanitize( $menu_item_value ) {
		// Menu is marked for deletion.
		if ( false === $menu_item_value ) {
			return $menu_item_value;
		}

		// Invalid.
		if ( ! is_array( $menu_item_value ) ) {
			return null;
		}

		$default = array(
			'object_id' => 0,
			'object' => '',
			'menu_item_parent' => 0,
			'position' => 0,
			'type' => 'custom',
			'title' => '',
			'url' => '',
			'target' => '',
			'attr_title' => '',
			'description' => '',
			'classes' => '',
			'xfn' => '',
			'status' => 'publish',
			'nav_menu_term_id' => 0,
		);
		$menu_item_value = array_merge( $default, $menu_item_value );
		$menu_item_value = wp_array_slice_assoc( $menu_item_value, array_keys( $default ) );

		foreach ( array( 'object_id', 'menu_item_parent', 'position', 'nav_menu_term_id' ) as $key ) {
			$menu_item_value[ $key ] = max( 0, intval( $menu_item_value[ $key ] ) );
		}
		foreach ( array( 'type', 'object', 'target' ) as $key ) {
			$menu_item_value[ $key ] = sanitize_key( $menu_item_value[ $key ] );
		}
		foreach ( array( 'xfn', 'classes' ) as $key => $value ) {
			if ( ! is_array( $value ) ) {
				$value = explode( ' ', $value );
			}
			$menu_item_value[ $key ] = implode( ' ', array_map( 'sanitize_html_class', explode( ' ', $value ) ) );
		}
		foreach ( array( 'title', 'attr_title', 'description' ) as $key => $value ) {
			$menu_item_value[ $key ] = sanitize_text_field( $value );
		}
		$menu_item_value['url'] = esc_url_raw( $menu_item_value['url'] );

		/** This filter is documented in wp-includes/class-wp-customize-setting.php */
		return apply_filters( "customize_sanitize_{$this->id}", $menu_item_value, $this );
	}

	/**
	 * Create/update the nav_menu_item post for this setting.
	 *
	 * Any created menu items will have their assigned post IDs exported to the client
	 * via the customize_save_response filter. Likewise, any errors will be exported
	 * to the client via the customize_save_response() filter.
	 *
	 * To delete a menu, the client can send false as the value.
	 *
	 * @see wp_update_nav_menu_item()
	 *
	 * @param array|false $value The menu item array to update. If false, then the menu item will be deleted entirely.
	 *                             See {@see WP_Customize_Nav_Menu_Item_Setting::$default} for what the value should consist of.
	 * @return void
	 */
	protected function update( $value ) {
		$is_placeholder = ( $this->post_id < 0 );
		$is_delete = ( false === $value );

		if ( $is_delete ) {
			// If the current setting post is a placeholder, a delete request is a no-op.
			if ( $is_placeholder ) {
				$this->update_status = 'deleted';
			} else {
				$r = wp_delete_post( $this->post_id, true );
				if ( false === $r ) {
					$this->update_error = new WP_Error( 'delete_failure' );
					$this->update_status = 'error';
				} else {
					$this->update_status = 'deleted';
				}
				// @todo send back the IDs for all associated nav menu items deleted, so these settings (and controls) can be removed from Customizer?
			}
		} else {
			// Insert or update menu.
			$menu_item_data = array(
				'menu-item-object-id'   => $value['object_id'],
				'menu-item-object'      => $value['object'],
				'menu-item-parent-id'   => $value['menu_item_parent'],
				'menu-item-position'    => $value['position'],
				'menu-item-type'        => $value['type'],
				'menu-item-title'       => $value['title'],
				'menu-item-url'         => $value['url'],
				'menu-item-description' => $value['description'],
				'menu-item-attr-title'  => $value['attr_title'],
				'menu-item-target'      => $value['target'],
				'menu-item-classes'     => $value['classes'],
				'menu-item-xfn'         => $value['xfn'],
				'menu-item-status'      => $value['status'],
			);

			$r = wp_update_nav_menu_item(
				$value['nav_menu_term_id'],
				$is_placeholder ? 0 : $this->post_id,
				$menu_item_data
			);

			if ( is_wp_error( $r ) ) {
				$this->update_status = 'error';
				$this->update_error = $r;
			} else {
				if ( $is_placeholder ) {
					$this->previous_post_id = $this->post_id;
					$this->post_id = $r;
					$this->update_status = 'inserted';
				} else {
					$this->update_status = 'updated';
				}
			}
		}

		add_filter( 'customize_save_response', array( $this, 'amend_customize_save_response' ) );
	}

	/**
	 * Export data for the JS client.
	 *
	 * @param array $data Additional information passed back to the 'saved'
	 *                      event on `wp.customize`.
	 *
	 * @see WP_Customize_Nav_Menu_Item_Setting::update()
	 * @return array
	 */
	function amend_customize_save_response( $data ) {
		if ( ! isset( $data['nav_menu_item_updates'] ) ) {
			$data['nav_menu_item_updates'] = array();
		}
		$result = array(
			'post_id' => $this->post_id,
			'previous_post_id' => $this->previous_post_id,
			'error' => $this->update_error ? $this->update_error->get_error_code() : null,
			'status' => $this->update_status,
		);

		$data['nav_menu_item_updates'][] = $result;
		return $data;
	}
}
