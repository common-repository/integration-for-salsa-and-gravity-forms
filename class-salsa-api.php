<?php
/**
 * Connects to the DIA API to get information about configured actions.
 *
 * @author Andrew Marcus
 * @since May 26, 2010
 */
class SalsaConnector {

	protected static $instance = false;

	/**
	 * Gets the singleton instance of the Salsa connector.  You must call
	 * initialize() before you can call this function.
	 *
	 * @return GFSalsaConnector The singleton instance, or false if it has not
	 *   yet been initialized.
	 */
	public static function instance() {
		return self::$instance;
	}

	/**
	 * Creates a new API connection and authenticates with the Salsa server.
	 * If everything is successful, the organization_KEY will be automatically
	 * retrieved.
	 *
	 * You must do this before calling
	 * any Salsa functions.
	 *
	 * @param string $host The URL of the Salsa server.
	 * @param string $user The username.
	 * @param string $pass The password.
	 * @param string $organization_key The organization key used to pull and push information. Only needed if the API user has admin access to more than one Salsa account
	 * @param string $chapter_key The chapter key used to log into Salsa Labs CRM. Only needed if an organization has more than one chapter connected to the Salsa account. Only applicable if also using an organization key.
	 * @return GFSalsaConnector The newly-created GFSalsaConnector singleton.
	 */
	public static function initialize( $host, $user, $pass, $organization_key = '', $chapter_key = '' ) {
		self::$instance = new SalsaConnector( $host, $user, $pass, $organization_key, $chapter_key  );
		return self::$instance;
	}

	/** @var reference $ch The open CURL HTTP connection */
	protected $ch = null;

	/** @var string $host The URL of the DIA server */
	public $host;

	/** @var string $organization_KEY The key of the organization */
	public $organization_KEY;

	/** @var array $errors A list of connection errors */
	protected $errors = array();

	/**
	 * Creates a new connection with the Salsa API.  You should use initialize()
	 * to create a singleton instead of calling this function directly.
	 */
	protected function __construct( $host, $user, $pass, $organization_key = '', $chapter_key = '' ) {

		if ( empty( $host )  || empty( $user ) || empty( $pass ) ) {
			$this->errors[] = 'This page is not configured correctly.';
			return;
		}
		if ( ! self::isValidHostname( $host ) ) {
			$this->errors[] = 'Invalid hostname provided.';
			return;
		}

		$this->host = str_replace( 'https://', 'http://', $host );

		// Configure the HTTP connection (always POST, maintain cookies)
		$this->ch = curl_init();
		curl_setopt( $this->ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $this->ch, CURLOPT_TIMEOUT, 5 );
		curl_setopt( $this->ch, CURLOPT_COOKIESESSION, true );
		curl_setopt( $this->ch, CURLOPT_COOKIEFILE, '/tmp/cookies_file' );
		curl_setopt( $this->ch, CURLOPT_COOKIEJAR, '/tmp/cookies_file' );

		$params = array(
			'email' => $user,
			'password' => $pass
			);

		// Authenticate
		$auth = $this->postXML('/api/authenticate.sjs', array(
			'email' => $user,
			'password' => $pass,
		), true);

		if ( ! isset( $auth ) ) {
			return;
		}
		if ( isset( $auth->error ) ) {
			$this->errors[] = 'We were unable to authenticate with the server.';
			return;
		}

		// set organization_KEY
		$attrs = $auth->attributes();
		if (!empty($attrs)) {
			if ( !empty($organization_key) ) {
				$this->organization_KEY = $organization_key;

				if ( !empty($chapter_key) ) {
					$this->chapter_KEY = $chapter_key;
				}

			} else {
				$this->organization_KEY = strval($attrs['organization_KEY']);
			}
		}

	}

	/**
	 * Convenience method to tacking on errors. Not called within object anywhere
	 */
	public function addErrors( $errors ) {
		if ( is_string( $errors ) ) {
			$errors = array( $errors );
		}
		$this->errors = array_merge( $this->errors, $errors );
	}

	/**
	 * Gets a list of all the errors that have accumulated so far.
	 *
	 * @param boolean $reset If this set to false, the errors will be preserved
	 *   after this call.  Otherwise, they will be cleared.
	 * @return array<string> A list of error messages, or an empty list if there
	 *   have been no errors since the last time the list was reset.
	 */
	public function getErrors( $reset = true ) {
		$out = $this->errors;
		if ( $reset ) {
			$this->errors = array();
		}
		return $out;
	}

	/**
	 * Return true if the given XML is valid and has no errors.
	 *
	 * @param SimpleXMLElement $xml The XML document to check.
	 * @return boolean True if the XML exists and has no errors.
	 */
	public function success( $xml ) {
		return ! empty( $xml ) && ! isset( $xml->error );
	}

	/**
	 * Issues an API request to the server using XML.
	 * Don't use this if you can avoid it, JSON is preferred
	 *
	 * @param string  $path The path on the server, starting with /
	 * @param array   $params An array of parameters to send in the request
	 * @param boolean $ssh If true, SSH will be used in the connection.
	 * @return SimpleXMLElement The XML object returned by the API call.
	 */
	function postXML( $path, $params, $ssh = true ) {
		$url = $path;
		// build an absolute URL if we have to
		if ( strpos( $path, 'http' ) === 0 ) {
			$url = $path;
		} else {
			$url = $this->host . $path;
		}

		try {
			// Make curl use SSH properly
			if ( $ssh ) {
				$url = str_replace( 'http://', 'https://', $url );
				curl_setopt( $this->ch, CURLOPT_SSL_VERIFYPEER, false );
				curl_setopt( $this->ch, CURLOPT_SSL_VERIFYHOST, 2 );
			}
			// Build a salsa-friendly quesrystring
			$q = $this->serializeParams( $params );

			curl_setopt( $this->ch, CURLOPT_POST, 1 );
			curl_setopt( $this->ch, CURLOPT_URL, $url );
			curl_setopt( $this->ch, CURLOPT_POSTFIELDS, 'xml&' . $q );
			curl_setopt( $this->ch, CURLOPT_HEADER, false );
			curl_setopt( $this->ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded') );

			// perform the API call
			$res = curl_exec( $this->ch );
			if (FALSE === $res) {
	        	throw new \Exception(curl_error($this->ch), curl_errno($this->ch));
			}

			if ( empty( $res ) ) {
				$this->errors[] = 'We were unable to connect to the server.';
				return null;
			}

			// Convert to an XML object
			$xml = @simplexml_load_string( $res );
			if ( ! isset( $xml ) ) {
				$this->errors[] = 'We got invalid results from the server.';
			} elseif ( isset( $xml->error ) ) {
				$this->errors[] = $xml->error;
			} elseif ( $xml === false ) {
				$xml = null;
			}

			return $xml;
		} catch (\Exception $e) {
			trigger_error(sprintf('cURL failed with error code %s and error message %s', $e->getCode(), $e->getMessage()), E_USER_ERROR);
		}
	}

	/**
	 * Posts values to a URL and parses the resulting JSON string into
	 * an object or array of objects.
	 *
	 * @param string $path The path on the server, starting with /
	 * @param array  $params An array of parameters to send in the request
	 * @return array The response, parsed into arrays and hashes
	 */
	public function post( $path, $params, $ssh = true ) {

		// build an absolute URL if we have to
		if ( strpos( $path, 'http' ) === 0 ) {
			$url = $path;
		} else {
			$url = $this->host . $path;
		}

		try {
			// Make curl use SSH properly
			if ( $ssh ) {
				$url = str_replace( 'http://', 'https://', $url );
				curl_setopt( $this->ch, CURLOPT_SSL_VERIFYPEER, false );
				curl_setopt( $this->ch, CURLOPT_SSL_VERIFYHOST, 2 );
			}

			// Build a salsa-friendly quesrystring
			$q = $this->serializeParams( $params );

			curl_setopt( $this->ch, CURLOPT_POST, 1 );
			curl_setopt( $this->ch, CURLOPT_URL, $url );
			curl_setopt( $this->ch, CURLOPT_POSTFIELDS, 'json&' . $q );
			curl_setopt( $this->ch, CURLOPT_HEADER, false );
			curl_setopt( $this->ch, CURLOPT_HTTPHEADER, array('Content-Type:application/x-www-form-urlencoded') );

			// perform the API call
			$res = curl_exec( $this->ch );

			if (FALSE === $res) {
	        	throw new \Exception(curl_error($this->ch), curl_errno($this->ch));
			}

			// if the API returned back empty data like an array []. This happened when I wanted to get the ID of a Salsa supporter that didn't exist in the user's supporter list. Salsa's API just returned []
			// Perform a basic check
			if ( empty( $res ) ) {
				$this->errors[] = __( 'Unable to connect to the server and receive a response.', 'wp-paypal-salsa' );
				return null;
			}

			// Convert from a JSON object
			$obj = json_decode( $res );

			if ( ! isset( $obj ) ) {
				$this->errors[] = __( 'Server provided invalid JSON', 'wp-paypal-salsa' );
			}

			// give back an object of the response
			return $obj;
		} catch (\Exception $e) {
			trigger_error(sprintf('cURL failed with error code %s and error message %s', $e->getCode(), $e->getMessage()), E_USER_ERROR);
		}
	}

	/**
	 * Serializes the given array of parameters into a valid query string.
	 * Use this function instead of http_query_params() when submitting to the
	 * Salsa framework, because keys with multiple values should not have array
	 * brackets added to them.
	 *
	 * The parameters can either be an array of arrays where each inner array
	 * contains a single key and value, or it can be an array of key/value pairs
	 * where a key with multiple values stores its values in an array.
	 *
	 * @param array $params An array of key/value pairs to post.
	 * @return string A url-encoded query string.
	 */
	function serializeParams( $params ) {
		// Serialize the parameters ourselves so that multiple values are not wrapped
		$q = array();
		$params['organization_KEY'] = $this->organization_KEY;

		if ( !empty($this->chapter_KEY) ) {
			$params['chapter_KEY'] = $this->chapter_KEY;
		}

		foreach ( $params as $key => $val ) {
			if ( is_array( $val ) ) {
				foreach ( $val as $k => $v ) {
					// If the array is numerically indexed, use the parent key
					if ( is_int( $k ) ) {
						$k = $key;
					}
					$q[] = "$k=" . urlencode( $v );
				}
			} else {
				$q[] = "$key=" . urlencode( $val );
			}
		}
		return implode( '&', $q );
	}

	/**
	 * Submits a form to the Salsa framework.
	 *
	 * @param string $path The path on the server, starting with /
	 * @param array  $params An array of parameters to send in the request
	 * @return string The HTML that was returned from the form submission
	 */
	function submitData( $path, $params ) {
		$url = $this->host . $path;
		$q = $this->serializeParams( $params );

		curl_setopt( $this->ch, CURLOPT_POST, 1 );
		curl_setopt( $this->ch, CURLOPT_URL, $url );
		curl_setopt( $this->ch, CURLOPT_POSTFIELDS, $q );
		$res = curl_exec( $this->ch );
		return $res;
	}

	/**
	 * Counts the number of database objects matching the given query.
	 *
	 * @param string       $table The name of the table (action, action_content, etc.)
	 * @param string|array $conditions A query condition or array of query
	 *   conditions, of the form "action_KEY=355"
	 *
	 * @param array        $params Any additional parameters to include.
	 *          - orderBy: An array of fields to sort by
	 *          - limit: The max number of results to return
	 *          - offset: The starting offset of the results
	 *          - includes: An array of fields to include in the results
	 *
	 * @return integer The number of matching objects, or NULL if there was an error.
	 */
	public function getCount( $table, $conditions = array(), $params = array() ) {
		$p = array( 'object' => $table );
		if ( ! empty( $conditions ) ) {
			$p['condition'] = $conditions;
		}
		if ( ! empty( $params ) ) {
			$p = array_merge( $p, $params );
		}
		$xml = $this->post( '/api/getCount.sjs', $p );

		if ( $this->success( $xml ) ) {
			return (integer) $this->$table->count;
		}
		return null;
	}


	/**
	 * Gets one or more database objects.
	 *
	 * @param string       $table The name of the table (action, action_content, etc.)
	 * @param string|array $conditions A query condition or array of query
	 *   conditions, of the form "action_KEY=355"
	 *
	 * @param array        $params Any additional parameters to include.
	 *          - orderBy: An array of fields to sort by
	 *          - limit: The max number of results to return
	 *          - offset: The starting offset of the results
	 *          - includes: An array of fields to include in the results
	 *
	 * @param string       $className The name of the GFSalsaObject subclass to output.
	 *         If this is null, the root SimpleXMLElement will be returned instead.
	 *
	 * @return array<GFSalsaObject> The response objects, or null if there was
	 *   an error.
	 */
	public function getObjects( $table, $conditions = array(), $params = array(), $className = null ) {
		$p = array( 'object' => $table );
		if ( ! empty( $conditions ) ) {
			$p['condition'] = $conditions;
		}
		if ( ! empty( $params ) ) {
			$p = array_merge( $p, $params );
		}
		$xml = $this->post( '/api/getObjects.sjs', $p );

		if ( $this->success( $xml ) ) {
			if ( empty( $className ) ) {
				return $xml;
			} else {
				$out = array();
				if ( $xml->$table->item ) {
					foreach ( $xml->$table->item as $item ) {
						$out[] = new $className($item);
					}
				}
				return $out;
			}
		}
		return null;
	}

	/**
	 * Gets a database object by its key.
	 *
	 * @param string  $table The name of the table (action, action_content, etc.)
	 * @param integer $key The unique key of the object within the table.
	 *
	 * @param string  $className The name of the GFSalsaObject subclass to output.
	 *    If this is null, the root SimpleXMLElement will be returned instead.
	 *
	 * @return GFSalsaObject|SimpleXMLElement The response object, or null if
	 *   there was an error.
	 */
	public function getObject( $table, $key, $className = null ) {
		$p = array( 'object' => $table, 'key' => $key );
		$xml = $this->post( '/api/getObject.sjs', $p );
		if ( $this->success( $xml ) ) {
			if ( empty( $className ) ) {
				return $xml;
			}
			return new $className($xml->$table->item[0]);
		}
		return null;
	}

	/**
	 * Saves an object to the Salsa database.
	 *
	 * @param string              $table The name of the table (action, action_content, etc.)
	 * @param GFSalsaObject|array $object The object to save.  If it has a key parameter, an
	 *   existing record will be updated.
	 */
	public function saveObject( $table, $object ) {
		if ( is_object( $object ) ) {
			$object = (array) $object;
		}
		$p = array( 'object' => $table );
		$p = array_merge( $p, $object );
		return $this->post( '/save', $p );
	}

	/**
	 * When the object is destroyed, close the HTTP connection.
	 */
	public function __destruct() {
		if ( isset( $this->ch ) ) {
			curl_close( $this->ch );
		}
	}

	/**
	 * Get and return a list of Groups from this Salsa account
	 *
	 * @return array of name|group_KEY arrays
	 */
	public function getGroups() {

		$groups_array = array();
		$path = '/api/getObjects.sjs';

		$count = 500;
		$offset = 0;
		$params = array(
			'object'  => 'groups',
			'include' => 'groups_KEY,Group_Name',
		);

		// Loop through until we stop getting groups.
		do {
			$count = min( $count, 500 );
			$params['limit'] = $offset . ',' . $count;
			$groups = $this->post( $path, $params );

			if ( is_array( $groups ) ) {
				foreach ( $groups as $group ) {
					$groups_array[] = array(
						'name'       => $group->Group_Name,
						'groups_KEY' => (int) $group->groups_KEY,
					);
				}
				$count = count( $groups );
				$offset += $count;
			} else {
				$this->errors[] = __( 'Invalid groups response received', 'gravityforms-salsa' );
				$count = 0;
			}

		} while ( $count > 0 );

		return $groups_array;
	}

	/**
	 * Checks user-provided hostname against likely/known salsa hostnames
	 *
	 * @return boolean
	 */
	public static function isValidHostname( $host ) {

		$host = str_replace( array( 'https://', 'http://' ), '', $host );

		$valid_hosts = array(
			'org.salsalabs.com',
			'org2.salsalabs.com',
			'salsa3.salsalabs.com',
			'salsa4.salsalabs.com',
			'wfc.salsalabs.com',
			'wfc2.salsalabs.com',
		);

		return in_array( $host, $valid_hosts );
	}

	/**
	 * Get and return a list of tags and their keys, prefixes, etc.
	 * responses look like {"tag_KEY":"298240","tag":"added","prefix":"","key":"298240","object":"tag"}
	 */
	public function getTags() {
		$path = '/api/getObjects.sjs';
		$params = array(
			'object'  => 'tag',
			'include' => 'tag_KEY,tag,prefix',
		);

		$tags = $this->post( $path, $params );

		if ( is_array( $tags ) && count( $tags ) ) {
			foreach ( $tags as $tag ) {
				$tags_array[] = array(
					'tag_KEY' => (int) $tag->tag_KEY,
					'type'    => $tag->prefix,
					'tag'     => $tag->tag
				);
			}
		} else {
			$this->errors[] = __( 'Invalid tags response received', 'gravityforms-salsa' );
		}
		return $tags_array;
	}

	static function abbreviate( $value, $field_name ) {

		$states = array(
			'AL' => 'Alabama',
			'AK' => 'Alaska',
			'AZ' => 'Arizona',
			'AR' => 'Arkansas',
			'CA' => 'California',
			'CO' => 'Colorado',
			'CT' => 'Connecticut',
			'DE' => 'Delaware',
			'DC' => 'District of Columbia',
			'FL' => 'Florida',
			'GA' => 'Georgia',
			'HI' => 'Hawaii',
			'ID' => 'Idaho',
			'IL' => 'Illinois',
			'IN' => 'Indiana',
			'IA' => 'Iowa',
			'KS' => 'Kansas',
			'KY' => 'Kentucky',
			'LA' => 'Louisiana',
			'ME' => 'Maine',
			'MD' => 'Maryland',
			'MA' => 'Massachusetts',
			'MI' => 'Michigan',
			'MN' => 'Minnesota',
			'MS' => 'Mississippi',
			'MO' => 'Missouri',
			'MT' => 'Montana',
			'NE' => 'Nebraska',
			'NV' => 'Nevada',
			'NH' => 'New Hampshire',
			'NJ' => 'New Jersey',
			'NM' => 'New Mexico',
			'NY' => 'New York',
			'NC' => 'North Carolina',
			'ND' => 'North Dakota',
			'OH' => 'Ohio',
			'OK' => 'Oklahoma',
			'OR' => 'Oregon',
			'PA' => 'Pennsylvania',
			'RI' => 'Rhode Island',
			'SC' => 'South Carolina',
			'SD' => 'South Dakota',
			'TN' => 'Tennessee',
			'TX' => 'Texas',
			'UT' => 'Utah',
			'VT' => 'Vermont',
			'VA' => 'Virginia',
			'WA' => 'Washington',
			'WV' => 'West Virginia',
			'WI' => 'Wisconsin',
			'WY' => 'Wyoming',
			'AS' => 'America Samoa',
			'MP' => 'Northern Mariana Islands',
			'PR' => 'Puerto Rico',
			'VI' => 'Virgin Islands',
			'GU' => 'Guam',
			'AA' => 'Armed Forces Americas',
			'AE' => 'Armed Forces Europe',
			'AP' => 'Armed Forces Pacific',
			'AB' => 'Alberta',
			'BC' => 'British Columbia',
			'MB' => 'Manitoba',
			'NL' => 'Newfoundland and Labrador',
			'NB' => 'New Brunswick',
			'NS' => 'Nova Scotia',
			'NT' => 'Northwest Territories',
			'NU' => 'Nunavut',
			'ON' => 'Ontario',
			'PE' => 'Prince Edward Island',
			'QC' => 'Quebec',
			'SK' => 'Saskatchewan',
			'YT' => 'Yukon Territory',
			'ot' => 'Other',
		);

		$countries = array(
			'US' => 'United States',
			'AF' => 'Afghanistan',
			'AL' => 'Albania',
			'DZ' => 'Algeria',
			'AS' => 'American Samoa',
			'AD' => 'Andorra',
			'AO' => 'Angola',
			'AI' => 'Anguilla',
			'AQ' => 'Antarctica',
			'AG' => 'Antigua and Barbuda',
			'AR' => 'Argentina',
			'AM' => 'Armenia',
			'AW' => 'Aruba',
			'AU' => 'Australia',
			'AT' => 'Austria',
			'AZ' => 'Azerbaijan',
			'BS' => 'Bahamas',
			'BH' => 'Bahrain',
			'BD' => 'Bangladesh',
			'BB' => 'Barbados',
			'BY' => 'Belarus',
			'BE' => 'Belgium',
			'BZ' => 'Belize',
			'BJ' => 'Benin',
			'BM' => 'Bermuda',
			'BT' => 'Bhutan',
			'BO' => 'Bolivia',
			'BA' => 'Bosnia and Herzegovina',
			'BW' => 'Botswana',
			'BV' => 'Bouvet Island',
			'BR' => 'Brazil',
			'IO' => 'British Indian Ocean Territory',
			'BN' => 'Brunei Darussalam',
			'BG' => 'Bulgaria',
			'BF' => 'Burkina Faso',
			'BI' => 'Burundi',
			'KH' => 'Cambodia',
			'CM' => 'Cameroon',
			'CA' => 'Canada',
			'CV' => 'Cape Verde',
			'KY' => 'Cayman Islands',
			'CF' => 'Central African Republic',
			'TD' => 'Chad',
			'CL' => 'Chile',
			'CN' => 'China',
			'CX' => 'Christmas Island',
			'CC' => 'Cocos (Keeling) Islands',
			'CO' => 'Colombia',
			'KM' => 'Comoros',
			'CG' => 'Congo',
			'CD' => 'Congo, The Democratic Republic of the',
			'CK' => 'Cook Islands',
			'CR' => 'Costa Rica',
			'CI' => "Cote D'Ivoire",
			'HR' => 'Croatia',
			'CU' => 'Cuba',
			'CW' => 'Curacao',
			'CY' => 'Cyprus',
			'CZ' => 'Czech Republic',
			'DK' => 'Denmark',
			'DJ' => 'Djibouti',
			'DM' => 'Dominica',
			'DO' => 'Dominican Republic',
			'TL' => 'East Timor',
			'EC' => 'Ecuador',
			'EG' => 'Egypt',
			'SV' => 'El Salvador',
			'GQ' => 'Equatorial Guinea',
			'ER' => 'Eritrea',
			'EE' => 'Estonia',
			'ET' => 'Ethiopia',
			'FK' => 'Falkland Islands (Malvinas)',
			'FO' => 'Faroe Islands',
			'FJ' => 'Fiji',
			'FI' => 'Finland',
			'FR' => 'France',
			'FX' => 'France, Metropolitan',
			'GF' => 'French Guiana',
			'PF' => 'French Polynesia',
			'TF' => 'French Southern Territories',
			'GA' => 'Gabon',
			'GM' => 'Gambia',
			'GE' => 'Georgia',
			'DE' => 'Germany',
			'GH' => 'Ghana',
			'GI' => 'Gibraltar',
			'GR' => 'Greece',
			'GL' => 'Greenland',
			'GD' => 'Grenada',
			'GP' => 'Guadeloupe',
			'GU' => 'Guam',
			'GT' => 'Guatemala',
			'GN' => 'Guinea',
			'GW' => 'Guinea-Bissau',
			'GY' => 'Guyana',
			'HT' => 'Haiti',
			'HM' => 'Heard and McDonald Islands',
			'VA' => 'Holy See (Vatican City State)',
			'HN' => 'Honduras',
			'HK' => 'Hong Kong',
			'HU' => 'Hungary',
			'IS' => 'Iceland',
			'IN' => 'India',
			'ID' => 'Indonesia',
			'IR' => 'Iran, Islamic Republic of',
			'IQ' => 'Iraq',
			'IE' => 'Ireland',
			'IL' => 'Israel',
			'IT' => 'Italy',
			'JM' => 'Jamaica',
			'JP' => 'Japan',
			'JO' => 'Jordan',
			'KZ' => 'Kazakhstan',
			'KE' => 'Kenya',
			'KI' => 'Kiribati',
			'KP' => "Korea, Dem. People's Republic of",
			'KR' => 'Korea, Republic of',
			'KW' => 'Kuwait',
			'KG' => 'Kyrgyzstan',
			'LA' => "Lao People's Democratic Republic",
			'LV' => 'Latvia',
			'LB' => 'Lebanon',
			'LS' => 'Lesotho',
			'LR' => 'Liberia',
			'LY' => 'Libyan Arab Jamahiriya',
			'LI' => 'Liechtenstein',
			'LT' => 'Lithuania',
			'LU' => 'Luxembourg',
			'MO' => 'Macao',
			'MK' => 'Macedonia, Former Yugoslav Republic',
			'MG' => 'Madagascar',
			'MW' => 'Malawi',
			'MY' => 'Malaysia',
			'MV' => 'Maldives',
			'ML' => 'Mali',
			'MT' => 'Malta',
			'MH' => 'Marshall Islands',
			'MQ' => 'Martinique',
			'MR' => 'Mauritania',
			'MU' => 'Mauritius',
			'YT' => 'Mayotte',
			'MX' => 'Mexico',
			'FM' => 'Micronesia, Federated States of',
			'MD' => 'Moldova, Republic of',
			'MC' => 'Monaco',
			'MN' => 'Mongolia',
			'MS' => 'Montserrat',
			'MA' => 'Morocco',
			'MZ' => 'Mozambique',
			'MM' => 'Myanmar',
			'NA' => 'Namibia',
			'NR' => 'Nauru',
			'NP' => 'Nepal',
			'NL' => 'Netherlands',
			'NC' => 'New Caledonia',
			'NZ' => 'New Zealand',
			'NI' => 'Nicaragua',
			'NE' => 'Niger',
			'NG' => 'Nigeria',
			'NU' => 'Niue',
			'NF' => 'Norfolk Island',
			'MP' => 'Northern Mariana Islands',
			'NO' => 'Norway',
			'OM' => 'Oman',
			'PK' => 'Pakistan',
			'PW' => 'Palau',
			'PS' => 'Palestinian Territory, Occupied',
			'PA' => 'Panama',
			'PG' => 'Papua New Guinea',
			'PY' => 'Paraguay',
			'PE' => 'Peru',
			'PH' => 'Philippines',
			'PN' => 'Pitcairn',
			'PL' => 'Poland',
			'PT' => 'Portugal',
			'PR' => 'Puerto Rico',
			'QA' => 'Qatar',
			'RE' => 'Reunion',
			'RO' => 'Romania',
			'RU' => 'Russian Federation',
			'RW' => 'Rwanda',
			'SH' => 'Saint Helena',
			'KN' => 'Saint Kitts and Nevis',
			'LC' => 'Saint Lucia',
			'PM' => 'Saint Pierre and Miquelon',
			'VC' => 'Saint Vincent and the Grenadines',
			'WS' => 'Samoa',
			'SM' => 'San Marino',
			'ST' => 'Sao Tome and Principe',
			'SA' => 'Saudi Arabia',
			'SN' => 'Senegal',
			'SP' => 'Serbia',
			'SC' => 'Seychelles',
			'SL' => 'Sierra Leone',
			'SG' => 'Singapore',
			'SX' => 'Sint Maarten',
			'SK' => 'Slovakia',
			'SI' => 'Slovenia',
			'SB' => 'Solomon Islands',
			'SO' => 'Somalia',
			'ZA' => 'South Africa',
			'SS' => 'South Sudan',
			'GS' => 'S. Georgia and S. Sandwich Islands',
			'ES' => 'Spain',
			'LK' => 'Sri Lanka',
			'SD' => 'Sudan',
			'SR' => 'Suriname',
			'SJ' => 'Svalbard and Jan Mayen',
			'SZ' => 'Swaziland',
			'SE' => 'Sweden',
			'CH' => 'Switzerland',
			'SY' => 'Syrian Arab Republic',
			'TW' => 'Taiwan',
			'TJ' => 'Tajikistan',
			'TZ' => 'Tanzania, United Republic of',
			'TH' => 'Thailand',
			'TG' => 'Togo',
			'TK' => 'Tokelau',
			'TO' => 'Tonga',
			'TT' => 'Trinidad and Tobago',
			'TN' => 'Tunisia',
			'TR' => 'Turkey',
			'TM' => 'Turkmenistan',
			'TC' => 'Turks and Caicos Islands',
			'TV' => 'Tuvalu',
			'UG' => 'Uganda',
			'UA' => 'Ukraine',
			'AE' => 'United Arab Emirates',
			'GB' => 'United Kingdom',
			'UM' => 'United States Outlying Islands',
			'UY' => 'Uruguay',
			'UZ' => 'Uzbekistan',
			'VU' => 'Vanuatu',
			'VE' => 'Venezuela',
			'VN' => 'Vietnam',
			'VG' => 'Virgin Islands, British',
			'VI' => 'Virgin Islands, U.S.',
			'WF' => 'Wallis and Futuna',
			'EH' => 'Western Sahara',
			'YE' => 'Yemen',
			'YU' => 'Yugoslavia',
			'ZR' => 'Zaire',
			'ZM' => 'Zambia',
			'ZW' => 'Zimbabwe',
		); // end countries

		if ( "Country" === $field_name ) {
			$reference_array = $countries;
		} else {
			$reference_array = $states;
		}

		$abbreviation = array_search( $value, $reference_array );
		if ( $abbreviation ) return $abbreviation;

		return $value;

	}

}

/**
 * An abstract parent class for all Salsa Objects to extend.
 */
abstract class GFSalsaObject {
	public $object;
	public $key = 0;

	function __construct( $obj ) {
		if ( $obj instanceof SimpleXMLElement ) {
			foreach ( $obj as $tag ) {
				$name = $tag->getName();
				$val = (string) $tag;
				$this->$name = $val;
			}
		} else {
			foreach ( $obj as $k => $v ) {
				$this->$k = $v;
			}
		}
	}

	/**
	 * Saves this object to Salsa.
	 */
	public function save() {
		$conn = GFSalsaConnector::instance();
		if ( $conn ) {
			$conn->saveObject( $this->object, $this );
		}
	}
}
