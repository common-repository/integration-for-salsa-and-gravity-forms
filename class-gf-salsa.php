<?php
/**
 * Major to-do items:
 *	- cache groups and display them properly on feed list
 *	- provide some sort of control over "update" vs. "create or update"
 */

GFForms::include_feed_addon_framework();

class GFSalsa extends GFFeedAddOn {

	protected $_version = GF_SALSA_VERSION;
	protected $_min_gravityforms_version = '1.9.3';
	protected $_slug = 'gravityforms-salsa';
	protected $_path = 'gravityforms-salsa/gravityforms-salsa.php';
	protected $_full_path = __FILE__;
	protected $_url = 'https://cornershopcreative.com';
	protected $_title = 'Gravity Forms Salsa Add-On';
	protected $_short_title = 'Salsa API';
	protected $_enable_rg_autoupgrade = true;

	/**
	 * Members plugin integration
	 */
	protected $_capabilities = array( 'gravityforms_salsa', 'gravityforms_salsa_uninstall' );

	/**
	 * Permissions
	 */
	protected $_capabilities_settings_page = 'gravityforms_salsa';
	protected $_capabilities_form_settings = 'gravityforms_salsa';
	protected $_capabilities_uninstall = 'gravityforms_salsa_uninstall';

	/**
	 * Other stuff
	 */
	private static $settings;
	private static $api;
	private static $_instance = null;
	private $tags = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return GFSalsa
	 */
	public static function get_instance() {
		if ( null === self::$_instance ) {
			self::$_instance = new GFSalsa();
		}

		return self::$_instance;
	}

	// # FEED PROCESSING -----------------------------------------------------------------------------------------------
	/**
	 * Process the feed, add the submission to Salsa.
	 *
	 * @param array $feed  The feed object to be processed.
	 * @param array $entry The entry object currently being processed.
	 * @param array $form  The form object currently being processed.
	 *
	 * @return void
	 */
	public function process_feed( $feed, $entry, $form ) {
		$this->log_debug( __METHOD__ . '(): Processing feed.' );

		// login to Salsa
		$api = $this->get_api();
		if ( ! is_object( $api ) ) {
			$this->log_error( __METHOD__ . '(): Failed to set up the API.' );
			return;
		}

		$feed_meta = $feed['meta'];

		// retrieve name => value pairs for all fields mapped in the 'mappedFields' field map
		$field_map            = $this->get_field_map_fields( $feed, 'mappedFields' );
		$email                = $this->get_field_value( $form, $entry, $field_map['Email'] );

		// abort if email is invalid
		if ( GFCommon::is_invalid_or_empty_email( $email ) ) {
			$this->log_error( __METHOD__ . '(): A valid Email address must be provided.' );
			return;
		}

		$override_empty_fields = gf_apply_filters( 'gform_salsa_override_empty_fields', $form['id'], true, $form, $entry, $feed );
		if ( ! $override_empty_fields ) {
			$this->log_debug( __METHOD__ . '(): Empty fields will not be overridden.' );
		}

		$tags      = array();
		$post_vars = array(
			'object' => 'supporter',
			'Email' => $email,
		);

		// Loop through the fields, populating $post_vars as necessary
		foreach ( $field_map as $name => $field_id ) {

			if ( 'Email' === $name || '' === $field_id ) {
				continue; // we already did email, and we can skip unassigned stuff
			}

			$field_value = $this->get_field_value( $form, $entry, $field_id );

			// tags are special
			if ( 'Tags' === $name ) {
				$tags = $this->normalize_tags( $field_value );
				continue;
			}

			// abbreviate things
			if ( 'Country' === $name || 'State' === $name ) {
				$field_value = $api->abbreviate( $field_value, $name );
			}

			if ( empty( $field_value ) && ! $override_empty_fields ) {
				continue;
			} else {
				$post_vars[ $name ] = $field_value;
			}
		}//end foreach

		try {
			$params = $post_vars;
			$params = gf_apply_filters( 'gform_salsa_args_pre_post', $form['id'], $params, $form, $entry, $feed );
			$this->log_debug( __METHOD__ . '(): Calling - subscribe, Parameters ' . print_r( $params, true ) );
			$call = $api->post( '/save', $params );

		} catch ( Exception $e ) {

			$this->log_error( __METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage() );
		}

		// if we successfully added the supporter, try to add groups
		if ( 'success' === $call[0]->result ) {

			$this->log_debug( __METHOD__ . "(): API subscribe for $email successful." );
			$supporter_id = $call[0]->key;

			// inspect the feed to get the groups
			$group_ids = array();

			foreach ( $feed_meta as $key => $value ) {
				if ( '1' === $value && strpos( $key, 'group_' ) === 0 ) {
					$group_ids[] = str_replace( 'group_', '', $key );
				}
			}

			// pass the group IDs if present in the feed
			if ( count( $group_ids ) ) {
				$group_params = array(
					'object' => 'supporter_groups',
					'supporter_KEY' => $supporter_id,
				);
				foreach ( $group_ids as $group_id ) {
					$group_params['groups_KEY'] = $group_id;
					$group = $api->post( '/save', $group_params );
				}
			}

			// insert tags
			if ( count( $tags ) ) {
				foreach ( $tags as $tag ) {
					$ret = $api->post('/api/tagObject.sjs', array(
						'object' => 'supporter',
						'key'    => $supporter_id,
						'tag'    => $tag,
					));
				}
			}
		} else {
			$this->log_error( __METHOD__ . "(): API subscribe for $email failed." );
		}//end if
	}

	/**
	 * Returns the value of the selected field.
	 *
	 * @param array  $form      The form object currently being processed.
	 * @param array  $entry     The entry object currently being processed.
	 * @param string $field_id The ID of the field being processed.
	 *
	 * @return array
	 */
	public function get_field_value( $form, $entry, $field_id ) {
		$field_value = '';

		switch ( strtolower( $field_id ) ) {

			case 'form_title':
				$field_value = rgar( $form, 'title' );
				break;

			case 'date_created':
				$date_created = rgar( $entry, strtolower( $field_id ) );
				if ( empty( $date_created ) ) {
					// the date created may not yet be populated if this function is called during the validation phase and the entry is not yet created
					$field_value = gmdate( 'Y-m-d H:i:s' );
				} else {
					$field_value = $date_created;
				}
				break;

			case 'ip':
			case 'source_url':
				$field_value = rgar( $entry, strtolower( $field_id ) );
				break;

			default:

				$field = GFFormsModel::get_field( $form, $field_id );

				if ( is_object( $field ) ) {

					$is_integer = intval( $field_id ) === $field_id;
					$input_type = RGFormsModel::get_input_type( $field );

					if ( $is_integer && 'address' === $input_type ) {

						$field_value = $this->get_full_address( $entry, $field_id );

					} elseif ( $is_integer && 'name' === $input_type ) {

						$field_value = $this->get_full_name( $entry, $field_id );

					} elseif ( $is_integer && 'checkbox' === $input_type ) {

						$selected = array();
						foreach ( $field->inputs as $input ) {
							$index = (string) $input['id'];
							if ( ! rgempty( $index, $entry ) ) {
								$selected[] = rgar( $entry, $index );
							}
						}
						$field_value = implode( '|', $selected );

					} elseif ( 'phone' === $input_type && 'standard' === $field->phoneFormat ) {

						// reformat standard format phone to match preferred Salsa format
						// format: NPA-NXX-LINE (404-555-1212) when US/CAN
						$field_value = rgar( $entry, $field_id );
						if ( ! empty( $field_value ) && preg_match( '/^\D?(\d{3})\D?\D?(\d{3})\D?(\d{4})$/', $field_value, $matches ) ) {
							$field_value = sprintf( '%s-%s-%s', $matches[1], $matches[2], $matches[3] );
						}
					} else {

						if ( is_callable( array( 'GF_Field', 'get_value_export' ) ) ) {
							$field_value = $field->get_value_export( $entry, $field_id );
						} else {
							$field_value = rgar( $entry, $field_id );
						}
					}//end if
				} else {

					$field_value = rgar( $entry, $field_id );

				}//end if
		}//end switch

		return $field_value;
	}



	// # ADMIN FUNCTIONS -----------------------------------------------------------------------------------------------
	/**
	 * Plugin starting point. Handles hooks, loading of language files and PayPal delayed payment support.
	 */
	public function init() {

		parent::init();

	}

	/**
	 * Clear the cached settings on uninstall.
	 *
	 * @return bool
	 */
	public function uninstall() {

		parent::uninstall();

		GFCache::delete( 'salsa_plugin_settings' );

		return true;
	}

	// ------- Plugin settings -------
	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		return array(
			array(
				'title'       => '',
				'description' => '<p>' . esc_html__( 'Use Gravity Forms to collect user information and add it to your Salsa supporter list, provided your Salsa account supports API calls', 'gfsalsa' ) . '</p>',
				'fields'      => array(
					array(
						'name'              => 'salsa_host',
						'label'             => esc_html__( 'Salsa Domain', 'gfsalsa' ),
						'type'              => 'text',
						'class'             => 'medium wide',
						'feedback_callback' => array( $this, 'is_valid_salsa_host' ),
						'tooltip'           => esc_html__( 'Enter the domain (aka node) of your Salsa instance. It should be something like salsa.wiredforchange.com or salsa4.salsalabs.com', 'gfsalsa' ),
					),
					array(
						'name'              => 'salsa_user',
						'label'             => esc_html__( 'Salsa Username', 'gfsalsa' ),
						'type'              => 'text',
						'class'             => 'medium',
						'feedback_callback' => array( $this, 'is_valid_email' ),
						'tooltip'           => esc_html__( 'Enter the email address of your Salsa administrator account.', 'gfsalsa' ),
					),
					array(
						'name'              => 'salsa_pass',
						'label'             => esc_html__( 'Salsa Password', 'gfsalsa' ),
						'type'              => 'text',
						'input_type'        => 'password',
						'class'             => 'medium password',
						'tooltip'           => esc_html__( 'Enter the password of your Salsa administrator account. IMPORTANT: This is not stored encrypted; make sure it\'s not too valuable', 'gfsalsa' ),
					),
					array(
						'name'              => 'salsa_organization',
						'label'             => esc_html__( 'Salsa Organization Key', 'gfsalsa' ),
						'type'              => 'text',
						'input_type'        => 'text',
						'class'             => 'medium',
						'tooltip'           => esc_html__( 'Enter the numeric organization key of the Salsa account you want to sync data to. This is only needed if your Salsa account has administrator access to more than one organization and you want to make sure data is sent to the correct organization', 'gfsalsa' ),
					),
					array(
						'name'              => 'salsa_chapter',
						'label'             => esc_html__( 'Salsa Chapter Key', 'gfsalsa' ),
						'type'              => 'text',
						'input_type'        => 'text',
						'class'             => 'medium',
						'tooltip'           => esc_html__( 'Enter the numeric chapter key of the Salsa account you want to sync data to. This is only needed if the provided Salsa organization key has more than one chapter connected to its Salsa account and you want to make sure the data is synced to the correct chapter.', 'gfsalsa' ),
					),
				),
			),
		);
	}

	/**
	 * Fetch the settings the user submitted.
	 *
	 * @return array The post data containing the updated settings.
	 */
	public function get_posted_settings() {
		$post_data = parent::get_posted_settings();

		if ( $this->is_plugin_settings( $this->_slug ) && $this->is_save_postback() && ! empty( $post_data ) ) {

			$feed_count = $this->count_feeds();

			if ( $feed_count > 0 ) {
				$settings               = $this->get_previous_settings();
				$settings['salsa_host'] = rgar( $post_data, 'salsa_host' );
				$settings['salsa_user'] = rgar( $post_data, 'salsa_user' );
				$settings['salsa_pass'] = rgar( $post_data, 'salsa_pass' );
				return $settings;
			} else {
				GFCache::delete( 'salsa_plugin_settings' );
			}
		}

		return $post_data;
	}

	/**
	 * Count how many Salsa feeds exist. Presumably this'll be just one, but the Feeds framework allows for more
	 *
	 * @return int
	 */
	public function count_feeds() {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "SELECT count(*) FROM {$wpdb->prefix}gf_addon_feed WHERE addon_slug=%s", $this->_slug ) );
	}

	// ------- Feed list page -------
	/**
	 * Prevent feeds being listed or created if the api key isn't valid.
	 *
	 * @return bool
	 */
	public function can_create_feed() {

		$settings = $this->get_plugin_settings();

		return $this->is_valid_salsa_auth( $settings['salsa_host'], $settings['salsa_user'], $settings['salsa_pass'] );
	}

	/**
	 * If the api key is invalid or empty return the appropriate message.
	 *
	 * @return string
	 */
	public function configure_addon_message() {

		$settings_label = sprintf( esc_html__( '%s Settings', 'gravityforms' ), $this->get_short_title() );
		$settings_link  = sprintf( '<a href="%s">%s</a>', esc_url( $this->get_plugin_settings_url() ), $settings_label );

		$settings = $this->get_plugin_settings();

		if ( rgempty( 'salsa_host', $settings ) ) {

			return sprintf( esc_html__( 'To get started, please configure your %s.', 'gravityforms' ), $settings_link );
		}

		return sprintf( esc_html__( 'Unable to connect to Salsa with the provided credentials. Please make sure you have entered valid information on the %s page.', 'gfsalsa' ), $settings_link );

	}

	/**
	 * Display a warning message instead of the feeds if the API key isn't valid.
	 *
	 * @param array   $form The form currently being edited.
	 * @param integer $feed_id The current feed ID.
	 */
	public function feed_edit_page( $form, $feed_id ) {

		if ( ! $this->can_create_feed() ) {

			echo '<h3><span>' . $this->feed_settings_title() . '</span></h3>';
			echo '<div>' . $this->configure_addon_message() . '</div>';

			return;
		}

		parent::feed_edit_page( $form, $feed_id );
	}

	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedName'     => esc_html__( 'Name', 'gfsalsa' ),
			'salsa_groups' => esc_html__( 'Salsa Group(s)', 'gfsalsa' ),
		);
	}

	/**
	 * Output a list of the groups this feed pushes into
	 */
	public function get_column_value_salsa_groups( $item ) {

		$group_ids = array();

		foreach ( $item['meta'] as $meta => $value ) {
			if ( strpos( $meta, 'group_' ) === 0  && $value ) {
				$group_ids[] = str_ireplace( 'group_', '', $meta );
			}
		}
		if ( ! count( $group_ids ) ) { $group_ids = array( '<em>none</em>' ); }

		// try to convert group ids to group names using names stored in transient
		if ( get_transient( 'gfsalsa-groups' ) ) {
			$group_data = get_transient( 'gfsalsa-groups' );
			foreach ( $group_ids as $key => $gid ) {
				foreach ( $group_data as $g ) {
					if ( $g['groups_KEY'] == $gid ) {
						$group_ids[ $key ] = $g['name'];
					}
				}
			}
		}

		return implode( ', ', $group_ids );
	}

	/**
	 * Configures the settings which should be rendered on the feed edit page.
	 *
	 * @return array The feed settings.
	 */
	public function feed_settings_fields() {
		return array(
			array(
				'title'       => esc_html__( 'Salsa Feed Settings', 'gfsalsa' ),
				'description' => '',
				'fields'      => array(
					array(
						'name'     => 'feedName',
						'label'    => esc_html__( 'Name', 'gfsalsa' ),
						'type'     => 'text',
						'required' => true,
						'class'    => 'medium',
						'tooltip'  => '<h6>' . esc_html__( 'Name', 'gfsalsa' ) . '</h6>' . esc_html__( 'Enter a feed name to uniquely identify this setup.', 'gfsalsa' ),
					),
					array(
						'name'      => 'mappedFields',
						'label'     => esc_html__( 'Map Fields', 'gfsalsa' ),
						'type'      => 'field_map',
						'field_map' => $this->supporter_field_map(),
						'tooltip'   => '<h6>' . esc_html__( 'Map Fields', 'gfsalsa' ) . '</h6>' . esc_html__( 'Associate your Salsa supporter fields with the appropriate Gravity Form fields.', 'gfsalsa' ),
					),
					array(
						'name'       => 'groups',
						'label'      => esc_html__( 'Groups', 'gfsalsa' ),
						'dependency' => array( $this, 'has_salsa_groups' ),
						'type'       => 'checkbox',
						'tooltip'    => '<h6>' . esc_html__( 'Groups', 'gfsalsa' ) . '</h6>' . esc_html__( 'Select one or more groups users will be assigned to in addition to being subscribed to Salsa. Optional.', 'gfsalsa' ),
						'choices'    => $this->salsa_group_choices(),
					),
					array(
						'name'    => 'optinCondition',
						'label'   => esc_html__( 'Conditional Logic', 'gfsalsa' ),
						'type'    => 'feed_condition',
						'tooltip' => '<h6>' . esc_html__( 'Conditional Logic', 'gfsalsa' ) . '</h6>' . esc_html__( 'When conditional logic is enabled, form submissions will only be passed to Salsa when the conditions are met. When disabled all form submissions will be exported.', 'gfsalsa' ),
					),
					array( 'type' => 'save' ),
					array(
						'type'    => 'marketing_plea',
						'name'    => 'sharing',
						'label'   => esc_html__( 'Like This Add-On?', 'gfsalsa' ),
					),
				),
			),
		);
	}

	/**
	 * Return an array of Salsa supporter fields which can be mapped to the Form fields/entry meta.
	 *
	 * @return array
	 */
	public function supporter_field_map() {

		$field_map = array();

		$supporter_fields = array(
			// @TODO BUILD THIS OUT, MAYBE PULLING FROM API TO GET CUSTOM FIELDS?
			'First_Name',
			'MI',
			'Last_Name',
			'Suffix',
			'Email',
			'Phone',
			'Cell_Phone',
			'Work_Phone',
			'Street',
			'Street_2',
			'Street_3',
			'City',
			'State',
			'Zip',
			'County',
			'Country',
			'Organization',
			'Department',
			'Occupation',
			'Web_Page',
			'Other_Data_1',
			'Other_Data_2',
			'Other_Data_3',
			'Source_Tracking_Code',
			'Tracking_Code',
			'Timezone',
			'Tags',
		);

		foreach ( $supporter_fields as $field ) {
			$field_map[] = array(
				'name'       => $field,
				'label'      => str_replace( '_', ' ', $field ),
				'required'   => 'Email' === $field ? true : false,
				'field_type' => 'Email' === $field ? array( 'email', 'hidden' ) : '',
			);
		}

		return $field_map;
	}

	/**
	 * Does the Salsa account have any groups configured?
	 *
	 * @return bool
	 */
	public function has_salsa_groups() {
		$groupings = $this->get_salsa_groups();
		return ! empty( $groupings );
	}

	/**
	 * Define the markup for the salsa_groups type field.
	 *
	 * @return string|void
	 */
	public function salsa_group_choices() {

		$groups  = $this->get_salsa_groups();
		$choices = array();

		foreach ( $groups as $group ) {
			$choices[] = array(
				'label' => $group['name'],
				'name'  => 'group_' . $group['groups_KEY'],
			);
		}

		return $choices;

	}

	/**
	 * Define which field types can be used for the group conditional logic.
	 * Probably NO LONGER NECESSARY
	 *
	 * @return array
	 */
	public function get_conditional_logic_fields() {
		$form   = $this->get_current_form();
		$fields = array();
		foreach ( $form['fields'] as $field ) {
			if ( $field->is_conditional_logic_supported() ) {
				$fields[] = array( 'value' => $field->id, 'label' => GFCommon::get_label( $field ) );
			}
		}

		return $fields;
	}


	// # HELPERS -------------------------------------------------------------------------------------------------------
	/**
	 * Validate the API Connection
	 *
	 * @param string $hostname The Salsa host name to be validated.
	 *
	 * @return bool|null
	 */
	public function is_valid_salsa_host( $hostname ) {
		if ( empty( $hostname ) ) {
			return false;
		}

		if ( ! class_exists( 'SalsaConnector' ) ) {
			require_once( 'class-salsa-api.php' );
		}

		$this->log_debug( __METHOD__ . "(): Validating user-supplied hostname {$hostname}." );
		return SalsaConnector::isValidHostname( $hostname );
	}


	/**
	 * Checks to make sure the Salsa credentials stored in settings actually work!
	 */
	public function is_valid_salsa_auth( $host, $user, $pass ) {
		if ( ! class_exists( 'SalsaConnector' ) ) {
			require_once( 'class-salsa-api.php' );
		}
		$api = SalsaConnector::initialize( $host, $user, $pass );
		if ( count( $api->getErrors() ) ) {
			return false;
		}
		return $api;
	}

	/**
	 * Validate the API Key and return an instance of SalsaConnector class.
	 *
	 * @return SalsaConnector|null
	 */
	private function get_api() {

		if ( self::$api ) {
			return self::$api;
		}

		if ( self::$settings ) {
			$settings = self::$settings;
		} else {
			$settings = $this->get_plugin_settings();
			self::$settings = $settings;
		}

		$settings = array_merge( array( 'salsa_organization' => '', 'salsa_chapter' => '' ), $settings );

		$api      = null;

		require_once( 'class-salsa-api.php' );

		if ( empty( $settings['salsa_organization'] ) ) {
			$settings['salsa_chapter'] = '';
		}

		try {
			$api = SalsaConnector::initialize( $settings['salsa_host'], $settings['salsa_user'], $settings['salsa_pass'], $settings['salsa_organization'], $settings['salsa_chapter'] );

		} catch ( Exception $e ) {
			$this->log_error( __METHOD__ . '(): Failed to set up the API.' );
			$this->log_error( __METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage() );
			return null;
		}

		self::$api = $api;
		return self::$api;
	}

	/**
	 * Retrieve the interest groups.
	 *
	 * @return array|bool
	 */
	private function get_salsa_groups() {

		$this->log_debug( __METHOD__ . '(): Retrieving groups.' );
		$api = $this->get_api();

		try {

			$groups = $api->getGroups();

			// Save the array for a day to speed up later recall.
			set_transient( 'gfsalsa-groups', $groups, DAY_IN_SECONDS );

		} catch ( Exception $e ) {

			$this->log_error( __METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage() );
			$groups = array();

		}

		if ( rgar( $groups, 'status' ) === 'error' ) {

			$this->log_error( __METHOD__ . '(): ' . print_r( $groups, 1 ) );
			$groups = array();

		}

		return $groups;
	}

	/**
	 * Get the list of known tags from Salsa
	 */
	private function get_salsa_tags() {

		if ( $this->tags ) {
			return $this->tags;
		}

		$api = $this->get_api();

		try {

			$tags = $api->getTags();

		} catch ( Exception $e ) {

			$this->log_error( __METHOD__ . '(): ' . $e->getCode() . ' - ' . $e->getMessage() );
			$tags = array();

		}

		if ( rgar( $groups, 'status' ) === 'error' ) {

			$this->log_error( __METHOD__ . '(): ' . print_r( $groups, 1 ) );
			$tags = array();

		}

		$this->tags = $tags;
		return $tags;

	}


	/**
	 * Returns the combined value of the specified Address field.
	 *
	 * @param array  $entry The entry currently being processed.
	 * @param string $field_id The ID of the field to retrieve the value for.
	 *
	 * @return string
	 */
	public function get_full_address( $entry, $field_id ) {
		$street_value  = str_replace( '  ', ' ', trim( rgar( $entry, $field_id . '.1' ) ) );
		$street2_value = str_replace( '  ', ' ', trim( rgar( $entry, $field_id . '.2' ) ) );
		$city_value    = str_replace( '  ', ' ', trim( rgar( $entry, $field_id . '.3' ) ) );
		$state_value   = str_replace( '  ', ' ', trim( rgar( $entry, $field_id . '.4' ) ) );
		$zip_value     = trim( rgar( $entry, $field_id . '.5' ) );
		$country_value = trim( rgar( $entry, $field_id . '.6' ) );

		if ( ! empty( $country_value ) ) {
			$country_value = GF_Fields::get( 'address' )->get_country_code( $country_value );
		}

		$address = array(
			! empty( $street_value ) ? $street_value : '-',
			$street2_value,
			! empty( $city_value ) ? $city_value : '-',
			! empty( $state_value ) ? $state_value : '-',
			! empty( $zip_value ) ? $zip_value : '-',
			$country_value,
		);

		return implode( '  ', $address );
	}

	/**
	 * Check if a provided email is, in fact, an email address.
	 */
	static function is_valid_email( $email ) {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * We want to turn arrays and pipe-separated text fields into simple arrays of tag names.
	 */
	private function normalize_tags( $tag_field ) {
		$tags = array();
		if ( is_string( $tag_field ) ) {
			$tags = explode( '|', $tag_field );
		} else if ( is_array( $tag_field ) ) {
			foreach ( $tag_field as $key => $value ) {
				$tags[] = $value;
			}
		}

		return array_map( 'trim', $tags );
	}

	/**
	 * Sharing tout
	 */
	public function settings_marketing_plea() {
		?>
		<ul class="gf-salsa-marketing">
			<li class="share"><a href="#" data-service="facebook">Share it on Facebook »</a></li>
			<li class="share"><a href="#" data-service="twitter">Tweet it »</a></li>
			<li><a href="https://wordpress.org/plugins/integration-for-salsa-and-gravity-forms/reviews/#new-post" target="_blank">Review it on WordPress.org »</a></li>
		</ul>
		<script>
			/**
			 * Sharing tools
			 */
			function GFS_Sharing( $ ) {

				var sharer = {
					// Initialize the singleton
					init: function() {
						this.buttons = $( '.gf-salsa-marketing > .share a' );
						if ( this.buttons.length == 0 ) {
							return;
						}
						this.buttons.on( 'click', $.proxy( this, 'onClick' ) );
					},

					// Get the url, title, and description of the page
					// Cache the data after the first get
					getPageData: function( e ) {
						if ( !this._data ) {
							this._data = {};
							this._data.title       = "I've got the flexibility of #WordPress Gravity Forms married with my Salsa CRM thanks to @Cornershop, it's awesome!";
							this._data.url         = "https://wordpress.org/plugins-wp/integration-for-salsa-and-gravity-forms/";
							this._data.description = "Check out this Gravity Forms Add-On to feed submission data into the Salsa \"Classic\" CRM/fundraising/advocacy platform.";
							this._data.target = e;
						}
						return this._data;
					},

					// Event handler for the share buttons
					onClick: function( event ) {
						var service = $(event.target).data('service');
						if ( this[ 'do_' + service ] ) {
							this[ 'do_' + service ]( this.getPageData( event.target ) );
						}
						return false;
					},

					// Handle Twitter
					do_twitter: function( data ) {
						var url = 'https://twitter.com/intent/tweet?' + $.param({
							original_referer: document.title,
							text: $(data.target).data('tweet') || data.title,
							url: data.url
						});
						if ( $('.en_social_buttons .en_twitter a').length ) {
							url = $.trim( $('.en_social_buttons .en_twitter a').attr('href') );
						}
						this.popup({
							url: url,
							name: 'twitter_share'
						});
					},

					// Handle Facebook
					do_facebook: function( data ) {
						var url = 'https://www.facebook.com/sharer/sharer.php?' + $.param({
							u: data.url
						});
						if ( $('.en_social_buttons .en_facebook a').length ) {
							url = $.trim( $('.en_social_buttons .en_facebook a').attr('href') );
						}
						this.popup({
							url: url,
							name: 'facebook_share'
						});
					},

					// Create and open a popup
					popup: function( data ) {
						if ( !data.url ) {
							return;
						}

						$.extend( data, {
							name: '_blank',
							height: 600,
							width: 845,
							menubar: 'no',
							status: 'no',
							toolbar: 'no',
							resizable: 'yes',
							left: Math.floor(screen.width/2 - 845/2),
							top: Math.floor(screen.height/2 - 600/2)
						});

						var specNames = 'height width menubar status toolbar resizable left top'.split( ' ' );
						var specs = [];
						for( var i=0; i<specNames.length; ++i ) {
							specs.push( specNames[i] + '=' + data[specNames[i]] );
						}
						return window.open( data.url, data.name, specs.join(',') );
					}
				};

				sharer.init();
			}

			GFS_Sharing( jQuery );
		</script>

		<?php
	}
}
