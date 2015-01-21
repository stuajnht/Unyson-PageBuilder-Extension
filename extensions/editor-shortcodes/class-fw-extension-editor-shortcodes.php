<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Class FW_Extension_Editor_Shortcodes
 * Integrate shortcodes with wp_editor
 */

class FW_Extension_Editor_Shortcodes extends FW_Extension {
	private $meta_key = 'fw-shortcode-settings';
	private $meta_key_defaults = 'fw-shortcode-default-values';

	/**
	 * @internal
	 */
	protected function _init() {
		if ( is_admin() ) {
			$this->add_admin_filters();
			$this->add_admin_actions();
		} else {
			$this->add_theme_filters();
		}
	}

	private function add_admin_filters() {
		global $tinymce_version;
		if ( version_compare( $tinymce_version[0], 4, ">=" ) ) {
			add_filter( 'mce_buttons', array( $this, '_filter_admin_register_button_menu' ) );
			add_filter( 'mce_external_plugins', array( $this, '_filter_admin_register_tinymce_javascript' ) );
		} else {
			//todo: enquee other js plugins for other tinymce versions ??
		}

		add_filter( 'mce_css', array( $this, '_filter_admin_enquee_editor_styles' ) );
	}

	private function add_admin_actions() {
		add_action( 'admin_print_scripts', array( $this, '_action_admin_global_variables' ) );
		add_action( 'edit_form_after_editor', array( $this, '_action_admin_render_hidden' ) );
		add_action( 'admin_enqueue_scripts', array( $this, '_action_admin_enqueue_scripts' ) );
		add_action( 'save_post', array( $this, '_action_admin_save_shortcodes' ), 10, 2 );
	}

	private function add_theme_filters() {
		add_filter( 'fw_shortcode_atts', array( $this, '_theme_filter_fw_shortcode_atts' ), 10, 3 );
	}

	/**
	 * Enquee common styles for page which consist wp_editor
	 */
	public function _action_admin_enqueue_scripts( $hook ) {

		if ( ! $this->is_supported_post() ) {
			return;
		}

		wp_enqueue_style( 'fw-ext-' . $this->get_name() . '-css',
			$this->get_declared_URI( '/static/css/styles.css' ),
			array(),
			fw()->manifest->get_version()
		);
	}


	public function _action_admin_save_shortcodes( $post_id, $post ) {
		if ( ! $this->is_supported_post($post_id) ) {
			return false;
		}
		$post_type = get_post_type( $post_id );

		if ( ! post_type_supports( $post_type, $this->get_parent()->get_supports_feature_name() ) ) {
			return false;
		}


		//todo: field 'content' smth changes ?
		$post_content = FW_Request::POST( 'content' );
		$input_value  = FW_Request::POST( $this->meta_key );

		$tmp_val = json_decode( $input_value, true );
		$new_val = array();

		//supported shortcodes
		$tags       = implode( '|', array_keys( fw_ext( 'shortcodes' )->get_shortcodes() ) );

		$default_values = array();

		//only supported tags & integer\alphabetic string id
		if ( preg_match_all( '/\[(' . $tags . ')(?:\s+[^\[\]]*)fw_shortcode_id=[\"\']([A-Za-z0-9]+)[\"\'](?:\s?[^\[\]]*)\]/', $post_content, $output_array ) ) {
			foreach ( $output_array[0] as $match_key => $match ) {
				$tag = $output_array[1][ $match_key ];
				$id  = $output_array[2][ $match_key ];

				if ( ! isset( $tmp_val[ $tag ] ) || ! isset( $tmp_val[ $tag ][ $id ] ) || empty( $tmp_val[ $tag ][ $id ] ) ) {
					$shortcode = fw_ext( 'shortcodes' )->get_shortcode( $tag );
					if ( $shortcode ) {
						$new_val[ $tag ][ $id ] = fw_get_options_values_from_input( $shortcode->get_options(), array() );
					}
				} elseif ( isset( $tmp_val[ $tag ][ $id ] ) and false === empty( $tmp_val[ $tag ][ $id ] ) ) {
					$new_val[ $tag ][ $id ] = $tmp_val[ $tag ][ $id ];
				}
			}
		}

		//only supported tags match (defaults)
		if ( preg_match_all( '/\[(' . $tags . ')(?:\s+[^\[\]]*).*(?:\s?[^\[\]]*)\]/', $post_content, $output_array ) ) {
			foreach ( $output_array[0] as $match_key => $match ) {
				$tag = $output_array[1][ $match_key ];
				$shortcode = fw_ext( 'shortcodes' )->get_shortcode( $tag );
				$default_values[$tag] =  fw_get_options_values_from_input( $shortcode->get_options(), array() );
			}
		}


		update_post_meta($post_id, $this->meta_key_defaults, str_replace( '\\', '\\\\', json_encode( $default_values ) ) );
		update_post_meta( $post_id, $this->meta_key, str_replace( '\\', '\\\\', json_encode( $new_val ) ) );
	}

	public function _filter_admin_register_button_menu( $buttons ) {
		if ( $this->is_supported_post() ) {
			array_push( $buttons, 'separator', 'simple_builder_button' );
		}

		return $buttons;
	}

	/**
	 * Register plugin js in wp_editor
	 */
	public function _filter_admin_register_tinymce_javascript( $plugin_array ) {
		if ( $this->is_supported_post() ) {
			$plugin_array['simple_builder_button'] = $this->get_declared_URI( '/static/js/plugin.js' );
		}

		return $plugin_array;
	}

	/**
	 * Enquee styling for tinymce iframe content
	 */
	public function _filter_admin_enquee_editor_styles( $mce_css ) {
		if ( $this->is_supported_post() ) {
			$mce_css .= ', ' . $this->get_declared_URI( '/static/css/content.css' );
		}

		return $mce_css;
	}

	/**
	 * Printing global js variables on page
	 */
	public function  _action_admin_global_variables() {
		if ( ! $this->is_supported_post() ) {
			return false;
		}
		echo "<script type='text/javascript'>\n";
		echo 'var fw_option_shortcode_globals=' . json_encode(
				array(
					'plugin_name'      => 'simple_builder_button',
					'storage_selector' => '#' . $this->meta_key,
					'shortcode_list'   => $this->build_shortcodes_list()
				) );
		echo "\n</script>";
	}

	/**
	 * Replace shortcode atts with saved options
	 */
	public function _theme_filter_fw_shortcode_atts( $atts, $content, $tag ) {
		global $post;
		if (! isset($atts['fw_shortcode_id'])){
			return $atts;
		}

		$option_values = json_decode( get_post_meta( $post->ID, $this->meta_key, true ), true );
		$default_values = json_decode( get_post_meta( $post->ID, $this->meta_key_defaults, true ), true );

		$id = $atts['fw_shortcode_id'];
		$atts = $default_values[$tag];

		if ( is_array( $option_values ) and false === empty( $option_values ) ) {
			if (preg_match('/^[A-Za-z0-9]+$/', $id)) {
				if ( isset( $option_values[ $tag ][ $id ] ) ) {
					$atts = $option_values[ $tag ][ $id ];
				}
			}
		}

		return $atts;
	}

	/**
	 * Render hidden under editor for storing shortcodes settings, which user entered
	 */
	public function _action_admin_render_hidden() {
		global $post;
		if ( ! $this->is_supported_post( $post ) ) {
			return false;
		}

		$value = get_post_meta( $post->ID, $this->meta_key, true );
		echo '<input id="' . $this->meta_key . '" type="hidden" name="' . $this->meta_key . '" value="' . fw_htmlspecialchars( $value ) . '">';
	}

	private function build_shortcodes_list() {
		$shortcodes = fw()->extensions->get( 'shortcodes' )->get_shortcodes();
		$result     = array();
		foreach ( $shortcodes as $tag => $shortcode ) {
			//todo: smth changes with section\column\row
			if ( in_array( $tag, array( 'section', 'column', 'row' ) ) ) {
				continue;
			}

			$config = $shortcode->get_config( 'page_builder' );
			if ( $config ) {
				// check if the shortcode type is valid
				$item_data               = array_merge(
					array(
						'title' => $tag,
					),
					$config
				);
				$item_data['popup_size'] = isset( $config['popup_size'] ) ? $config['popup_size'] : 'small';

				$builder_icon_uri = $shortcode->locate_URI( '/static/img/page_builder.png' );
				if ( $builder_icon_uri ) {
					$item_data['image'] = $builder_icon_uri;
				}

				// if the shortcode has options we store them and then they are passed to the modal
				$options = $shortcode->get_options();
				if ( $options ) {
					$item_data['options'] = $this->transform_options( $options );
					fw()->backend->enqueue_options_static( $options );
				}

				$result[ $tag ] = $item_data;
			}
		}

		return $result;
	}

	/**
	 * Puts each option into a separate array
	 * to keep it's order inside the modal dialog
	 */
	private function transform_options( $options ) {
		$new_options = array();
		foreach ( $options as $id => $option ) {
			$new_options[] = array( $id => $option );
		}

		return $new_options;
	}

	/**
	 * Checks if a post was built with shortcode editor
	 */
	public function is_supported_post( $post_id = '' ) {
		if ( ! $post_id ) {
			global $post;
		} else {
			$post = get_post( $post_id );
		}

		$page_builder_feature = $this->get_parent()->get_supports_feature_name();
		return $post && post_type_supports($post->post_type, $page_builder_feature);
	}

}