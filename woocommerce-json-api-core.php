<?php 
/**
 * Add fields to the user profile that allow the addition 
 * of a "token" that can be used by the API to log that
 * user into the system for performing actions.
 * 
 * The API is not limited to admins, in fact, the general idea
 * is to limit the API by applying whatever limits apply
 * to the user.
 * 
 * @param $user the user
*/
require_once( plugin_dir_path(__FILE__) . 'classes/class-wc-json-api.php' );
function woocommerce_json_api_show_user_profile( $user ) {
  $helpers = new JSONAPIHelpers();
  // We use PluginPrefic, which is just the plugin name
  // with - replaced with _, easier to type and more
  // extensible. 
  $key = $helpers->getPluginPrefix() . '_settings';
  $meta = maybe_unserialize( get_user_meta( $user->ID, $key, true ) );
  /*
    The general format at this point should be something like this:
    {
      'token': 1234blahblah
    }
  */
  $attrs = array (
	  'json_api_settings' => array(
		  'title' => __( 'WooCommerce JSON API Settings', 'woocommerce_json_api' ),
		  'fields' => array(
		      array(
		        'name'          => $helpers->getPluginPrefix() . '_settings[token]',
		        'id'            => 'json_api_token_id',
		        'value'         => $helpers->orEq($meta,'token',''),
		        'type'          => 'text',
				    'label'         => __( 'API Token', 'woocommerce_json_api' ),
				    'description'   => __('A large string of letters and numbers, mixed case, that will be used to authenticate requests', 'woocommerce_json_api' )
			    ),
			    array(
		        'name'          => $helpers->getPluginPrefix() . '_settings[ips_allowed]',
		        'id'            => 'json_api_ips_allowed_id',
		        'value'         => $helpers->orEq($meta,'ips_allowed',''),
		        'type'          => 'textarea',
				    'label'         => __( 'IPs Allowed', 'woocommerce_json_api' ),
				    'description'   => __('What ips are permitted to connect with this user...', 'woocommerce_json_api' )
			    ),
		  ),
	  ),
	);
  // Here we implement some permissions, a simple yes/no.
    $method = 'access_the_api';
    $field = array(
      'name'          => $helpers->getPluginPrefix() . '_settings[can_' . $method . ']',
      'id'            => 'json_api_can_' . $method . '_id',
      'value'         => $helpers->orEq($meta,'can_' . $method, 'yes'),
      'type'          => 'select',
      'options'       => array(
          array( 'value' => 'yes', 'content' => __('Yes','woocommerce_json_api') ),
          array( 'value' => 'no', 'content' => __('No','woocommerce_json_api') ),
        ),
      'label'         => __( 'Can access ', 'woocommerce_json_api' ) . ucwords(str_replace('_',' ', $method)),
      'description'   => __('Whether or not this user can access this method', 'woocommerce_json_api' )
    );
    $attrs['json_api_settings']['fields'][] = $field;
  foreach (WooCommerce_JSON_API::getImplementedMethods() as $method) {
    $field = array(
      'name'          => $helpers->getPluginPrefix() . '_settings[can_' . $method . ']',
      'id'            => 'json_api_can_' . $method . '_id',
      'value'         => $helpers->orEq($meta,'can_' . $method, 'yes'),
      'type'          => 'select',
      'options'       => array(
          array( 'value' => 'yes', 'content' => __('Yes','woocommerce_json_api') ),
          array( 'value' => 'no', 'content' => __('No','woocommerce_json_api') ),
        ),
      'label'         => __( 'Can access ', 'woocommerce_json_api' ) . ucwords(str_replace('_',' ', $method)),
      'description'   => __('Whether or not this user can access this method', 'woocommerce_json_api' )
    );
    $attrs['json_api_settings']['fields'][] = $field;
  }

  $attrs = apply_filters('woocommerce_json_api_settings_fields', $attrs);
                                                                              // The second argument puts this var in scope, similar to a
                                                                              // "binding" in Ruby
  $content = $helpers->renderTemplate( 'user-fields.php', array( 'attrs' => $attrs) );
  // At this point, content is being rendered in an output buffer for absolute control.
  // You can still overwrite the templates in the usual way, as the current theme is scanned
  // first. There are also hooks defined before and after, that will allow you to alter, replace,
  // or extend the content.
  
  echo $content;
}
/**
  *  Here we just pass this off to the above function: woocommerce_json_api_show_user_profile( $user )
*/
function woocommerce_json_api_edit_user_profile( $user ) {
  woocommerce_json_api_show_user_profile( $user );
}
/**
 * Here we edit the key, which should only be woocommerce_json_api_settings
 * at this point, though more info and keys could be added.
 * 
 * Here we are trying to simply use one key that is a serialized array
 * of all the little bits of info we need.
*/
function woocommerce_json_api_update_user_profile( $user_id ) {
  $helpers = new JSONAPIHelpers();
  $key = $helpers->getPluginPrefix() . '_settings';
  $params = serialize($_POST[$key]);
  update_user_meta($user_id,$key,$params);
}

/*
  Prevent template code from loading :)
*/
function woocommerce_json_api_template_redirect() {
  global $wpdb;
  $helpers = new JSONAPIHelpers();

  $headers = woocommerce_json_api_parse_headers();

  if ( isset($headers['Content-Type']) && $headers['Content-Type'] == 'application/json') {
    $fp = @fopen('php://input','r');
    $body = '';
    if ($fp) {
      while ( !feof($fp) ) {
        $buf = fread( $fp,1024 );
        if (is_string( $buf )) {
          $body .= $buf;
        }
      }
      fclose( $fp );
    }
    $hash = json_decode( $body, true );
    foreach ( $hash as $key => $value ) {
      $_REQUEST[$key] = $value;
    }
  }
  if (!isset($_REQUEST['action']) || $_REQUEST['action'] != 'woocommerce_json_api') {
    //die("action not set");
    return;
  }
  if (is_user_logged_in()) {
    //die("user is logged in");
    return;
  }
  

  JSONAPIHelpers::debug( var_export( $headers, true) );
  if ( isset( $_REQUEST['action'] ) && 'woocommerce_json_api' == $_REQUEST['action']) {
    $enabled = get_option( $helpers->getPluginPrefix() . '_enabled');
    $require_https = get_option( $helpers->getPluginPrefix() . '_require_https' );
    if ( $enabled != 'no') {
      if ( $require_https == 'yes' && $helpers->isHTTPS() == false ) {
        JSONAPIHelpers::debug("Cannot continue, HTTPS is required.");
        return;
      }
      $api = new WooCommerce_JSON_API();
      $api->setOut('HTTP');
      $api->setUser(null);
      $api->route($_REQUEST);

    } else {
      JSONAPIHelpers::debug("JSON API is not set to enabled.");
    }
  }
}
function woocommerce_json_api_admin_menu() {
  global $menu;
  $json_api_page = add_submenu_page( 
                                      'woocommerce', 
                                      __( 'JSON API', 'woocommerce' ),  
                                      __( 'JSON API', 'woocommerce' ) , 
                                      'manage_woocommerce', 
                                      'woocommerce_json_api_settings_page', 
                                      'woocommerce_json_api_settings_page' 
  );
}
function woocommerce_json_api_settings_page() {
  $helpers = new JSONAPIHelpers();
  $params = $_POST;
  $nonce = $helpers->orEq( $params, '_wpnonce',false);
  $key = $helpers->getPluginPrefix() . '_sitewide_settings';
  if ( $nonce  && wp_verify_nonce( $nonce, $helpers->getPluginPrefix() . '_sitewide_settings' ) && isset($params[$key]) ) { 
    foreach ($params[$key] as $key2=>$value) {
      update_option($helpers->getPluginPrefix() . '_' . $key2, maybe_serialize($value));
    }
    
  }
  
  
  $json_api_slug = get_option( $helpers->getPluginPrefix() . '_slug' );
  $pages = get_pages( array('post_type' => 'page') );
  $options = array();
  foreach ( $pages as $page ) {
    $options[] = array('value' => $page->post_name, 'content' => $page->post_title);
  }
  $attrs = array (
	  'json_api_sitewide_settings' => array(
		  'title' => __( 'WooCommerce JSON API Settings', 'woocommerce_json_api' ),
		  'fields' => array(
          array(
            'name'          => $helpers->getPluginPrefix() . '_sitewide_settings[enabled]',
            'id'            => 'json_api_enabled_id',
            'value'         => get_option( $helpers->getPluginPrefix() . '_enabled' ),
            'options'       => array(
                array( 'value' => 'yes', 'content' => __('Yes','woocommerce_json_api')),
                array( 'value' => 'no', 'content' => __('No','woocommerce_json_api')),
            ),
            'type'          => 'select',
            'label'         => __( 'API Enabled?', 'woocommerce_json_api' ),
            'description'   => __('Quickly enable/disable The API', 'woocommerce_json_api' ),
          ),
          array(
            'name'          => $helpers->getPluginPrefix() . '_sitewide_settings[require_https]',
            'id'            => 'json_api_require_https_id',
            'value'         => get_option( $helpers->getPluginPrefix() . '_require_https' ),
            'options'       => array(
                array( 'value' => 'yes', 'content' => __('Yes','woocommerce_json_api')),
                array( 'value' => 'no', 'content' => __('No','woocommerce_json_api')),
            ),
            'type'          => 'select',
            'label'         => __( 'Require HTTPS', 'woocommerce_json_api' ),
            'description'   => __('Only serve HTTPS requests?', 'woocommerce_json_api' ),
          )
		  ),
	  ),
	);
  $attrs = apply_filters('woocommerce_json_api_sitewide_settings_fields', $attrs);
  
  echo $helpers->renderTemplate('admin-settings-page.php', array( 'attrs' => $attrs, 'json_api_slug' => $json_api_slug) );
}

function woocommerce_json_api_parse_headers() {
  $headers = array();
  foreach ( $_SERVER as $key=>$value) {
    if ( substr($key,0,5) == 'HTTP_' ) {
      continue;
    }
    $h = str_replace(' ', '-', ucwords( str_replace('_', ' ', strtolower( $key ) ) ) );
    $headers[$h] = $value;
  }
  return $headers;
}

