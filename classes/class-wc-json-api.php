<?php
/**
 * Core JSON API
*/


// Error Codes are negative, Warning codes are positive
define('WCAPI_EXPECTED_ARGUMENT',             -1);
define('WCAPI_NOT_IMPLEMENTED',               -2);
define('WCAPI_UNEXPECTED_ERROR',              -3);
define('WCAPI_INVALID_CREDENTIALS',           -4);
define('WCAPI_BAD_ARGUMENT',                  -5);
define('WCAPI_CANNOT_INSERT_RECORD',          -6);
define('WCAPI_PERMSNOTSET',                   -7);
define('WCAPI_PERMSINSUFF',                   -8);
define('WCAPI_INTERNAL_ERROR',                -9);

define('WCAPI_PRODUCT_NOT_EXISTS', 1);
define('WCAPI_ORDER_NOT_EXISTS', 2);

require_once( plugin_dir_path(__FILE__) . '/class-rede-helpers.php' );
require_once( plugin_dir_path(__FILE__) . '/class-wc-json-api-result.php' );
require_once( dirname(__FILE__) . '/WCAPI/includes.php' );

use WCAPI as API;

if ( !defined('PHP_VERSION_ID')) {
  $version = explode('.',PHP_VERSION);
  if ( PHP_VERSION_ID < 50207 ) {
    define('PHP_MAJOR_VERSION',$version[0]);
    define('PHP_MINOR_VERSION',$version[1]);
    define('PHP_RELEASE_VERSION',$version[2]);
  }
}
class WooCommerce_JSON_API extends JSONAPIHelpers {
    // Call this function to setup a new response
  public $helpers;
  public $result;
  public $return_type;
  public $the_user;
  public static $implemented_methods;

  public function setOut($t) {
    $this->return_type = $t;
  }
  public function setUser($user) {
    $this->the_user = $user;
  }
  public function getUser() {
    return $this->the_user;
  }
  public static function getImplementedMethods() {
    if (self::$implemented_methods) {
      return self::$implemented_methods;
    }
    self::$implemented_methods = array(
      'get_system_time',
      'get_supported_attributes',
      'get_products',
      'get_categories',
      'get_taxes',
      'get_shipping_methods',
      'get_payment_gateways',
      'get_tags',
      'get_products_by_tags',
      'get_customers',
      'get_orders', // New Method
      'get_store_settings',
      'get_site_settings',
      'get_api_methods',
      
      // Write capable methods
      
      'set_products',
      'set_categories',
      'set_orders',
      'set_store_settings',
      'set_site_settings',

    );
    return self::$implemented_methods;
  }
  public function __construct() {
    //$this = new JSONAPIHelpers();
    $this->result = null;
    // We will use this to set perms
    self::getImplementedMethods();
  }
  /**
  *  This function is the single entry point into the API.
  *  
  *  The order of operations goes like this:
  *  
  *  1) A new result object is created.
  *  2) Check to see if it's a valid API User, if not, do stuff and quit
  *  3) Check to see if the method requested has been implemented
  *  4) If it's implemented, call and turn over control to the method
  *  
  *  This function takes a single hash,  usually $_REQUEST
  *  
  *  WHY? 
  *  
  *  Well, as you will notice with WooCommerce, there is an irritatingly large
  *  dependence on _defined_ and $_GET/$_POST variables, throughout their plugin,
  *  each function "depends" on request state, which is fine, except this
  *  violates 'dependency injection'. We don't know where data might come from
  *  in the future, what if another plugin wants to call this one inside of PHP
  *  within a request, multiple times? 
  *  
  *  No module should ever 'depend' on objects outside of itself, they should be
  *  provided with operating data, or 'injected' with it.
  *  
  *  There is nothing 'wrong' with the way WooCommerce does things, only it leads
  *  to a certain inflexibility in what you can do with it.
  */
  public function route( $params ) {
    $this->createNewResult( $params );

    JSONAPIHelpers::debug( "Beggining request" );
    JSONAPIHelpers::debug( var_export($params,true));

    if ( ! $this->isValidAPIUser( $params ) ) {

      $this->result->addError( 
        __('Not a valid API User', 'woocommerce_json_api' ), 
        WCAPI_INVALID_CREDENTIALS 
      );
      return $this->done();

    }
    if ( $this->isImplemented( $params ) ) {

      try {

        // The arguments are passed by reference here
        $this->validateParameters( $params['arguments'], $this->result);
        if ( $this->result->status() == false ) {
          JSONAPIHelpers::warn("Arguments did not pass validation");
          return $this->done();
          return;
        }
        $this->{ $params['proc'] }($params);

      } catch ( Exception $e ) {
        JSONAPIHelpers::error($e->getMessage());
        $this->unexpectedError( $params, $e);
      }
    } else {
      JSONAPIHelpers::warn("{$params['proc']} is not implemented...");
      $this->notImplemented( $params );
    }
  }
  
  public function isImplemented( $params ) {
    
    if ( isset($params['proc']) &&  
         $this->inArray( $params['proc'], self::$implemented_methods) 
    ) {

      return true;

    } else {

      return false;
    }
  }
  
  public function notImplemented( $params ) {
    $this->createNewResult( $params );

    if ( !isset($params['proc']) ) {

      $this->result->addError( 
          __('Expected argument was not present', 'woocommerce_json_api') . ' `proc`',
           WCAPI_EXPECTED_ARGUMENT 
      );
    }

    $this->result->addError( 
      __('That API method has not been implemented', 'woocommerce_json_api' ), 
      WCAPI_NOT_IMPLEMENTED 
    );

    return $this->done();
  }
  
  
  public function unexpectedError( $params, $error ) {
    $this->createNewResult( $params );

    $this->result->addError( 
      __('An unexpected error has occured', 'woocommerce_json_api' ) . $error->getMessage(), 
      WCAPI_UNEXPECTED_ERROR 
    );

    return $this->done();
  }
  
  
  public function createNewResult($params) {
    if ( ! $this->result ) {

      $this->result = new WooCommerce_JSON_API_Result();
      $this->result->setParams( $params );

    }
  }
  
  public function done() {
    wp_logout();
    if ( $this->return_type == 'HTTP') {
      header("Content-type: application/json");
      echo( $this->result->asJSON() );
      die;
    } else if ( $this->return_type == "ARRAY") {

      return $this->result->getParams();

    } else if ( $this->return_type == "JSON") {

      return $this->result->asJSON();

    } else if ( $this->return_type == "OBJECT") {

      return $this->result;

    } 
  }
  
  public function isValidAPIUser( $params ) {
    if ( $this->the_user ) {
      return true;
    }
    if ( ! isset($params['arguments']) ) {
      $this->result->addError( __( 'Missing `arguments` key','woocommerce_json_api' ),WCAPI_EXPECTED_ARGUMENT );
      return false;
    }
    $by_token = true;
    if ( ! isset( $params['arguments']['token'] ) ) {
      
      if ( 
        isset( $params['arguments']['username'] ) && 
        isset( $params['arguments']['password']) 
      ) {

        $by_token = false;

      } else {
        $this->result->addError( __( 'Missing `token` in `arguments`','woocommerce_json_api' ),WCAPI_EXPECTED_ARGUMENT );
        return false;
      }
      
    }
    $key = $this->getPluginPrefix() . '_settings';
    
    $args = array(
      'blog_id' => $GLOBALS['blog_id'],
      'meta_key' => $key
    );

    API\Base::setBlogId($GLOBALS['blog_id']);

    $users = get_users( $args );

    if (! $by_token ) {

        $user = wp_authenticate_username_password( null, $params['arguments']['username'],$params['arguments']['password']);
        
        if ( is_a($user,'WP_Error') ) {
          foreach( $user->get_error_messages() as $msg) {
            $this->result->addError( $msg ,WCAPI_INTERNAL_ERROR );
          }
          return false;
        }
        $this->logUserIn($user);
        return true;

    }
    foreach ($users as $user) {
      
      $meta = maybe_unserialize( get_user_meta( $user->ID, $key, true ) );

      if (isset( $meta['token']) &&  $params['arguments']['token'] == $meta['token']) {
        if (
          !isset($meta[ 'can_' . $params['proc'] ]) || 
          !isset($meta[ 'can_access_the_api' ])
        ) {

          $this->result->addError( __( 'Permissions for this user have not been set','woocommerce_json_api' ),WCAPI_PERMSNOTSET );
          return false;

        }
        if ( $meta[ 'can_access_the_api' ] == 'no' ) {

          $this->result->addError( __( 'You have been banned.','woocommerce_json_api' ), WCAPI_PERMSINSUFF );
          
          return false;
        }
        if ( $meta[ 'can_' . $params['proc'] ] == 'no' ) {

          $this->result->addError( __( 'You do not have sufficient permissions.','woocommerce_json_api' ), WCAPI_PERMSINSUFF );
          
          return false;

        }
        
        $this->logUserIn($user);
        return true;

      }

    }

    return false;
  }
  public function logUserIn( $user ) {

    wp_set_current_user($user->ID);
    wp_set_auth_cookie( $user->ID, false, is_ssl() );
    $this->setUser($user);

  }
   public function translateTaxRateAttributes( $rate ) {

    $attrs = array();
    foreach ( $rate as $k=>$v ) {
      $attrs[ str_replace('tax_rate_','',$k) ] = $v;
    }
    return $attrs;
  }

  public function missingArgument( $name ) {
    $this->result->addError( sprintf(__( 'Missing `%s` in `arguments`','woocommerce_json_api' ), $name),WCAPI_EXPECTED_ARGUMENT );
  }
  public function badArgument( $name, $values='' ) {
    $this->result->addError( sprintf(__( 'Value of `%s` is not valid, only %s accepted.','woocommerce_json_api' ), $values),WCAPI_BAD_ARGUMENT );
  }
  /*******************************************************************
  *                         Core API Functions                       *
  ********************************************************************
  * These functions are called as a result of what was set in the
  * JSON Object for `proc`.
  ********************************************************************/
  
  public function get_system_time( $params ) {
    
    $data = array(
      'timezone'  => date_default_timezone_get(),
      'date'      => date("Y-m-d"),
      'time'      => date("h:i:s",time())
    );
    $this->result->addPayload($data);
    return $this->done();
  }
  
  /**
  * This is the single entry point for fetching products, ordering, paging, as well
  * as "finding" by ID or SKU.
  */
  public function get_products( $params ) {
    global $wpdb;
    $allowed_order_bys = array('ID','post_title','post_date','post_author','post_modified');
    /**
    *  Read this section to get familiar with the arguments of this method.
    */
    $posts_per_page = $this->orEq( $params['arguments'], 'per_page', 15 ); 
    $paged          = $this->orEq( $params['arguments'], 'page', 0 );
    $order_by       = $this->orEq( $params['arguments'], 'order_by', 'ID');
    $order          = $this->orEq( $params['arguments'], 'order', 'ASC');
    $ids            = $this->orEq( $params['arguments'], 'ids', false);
    $parent_ids     = $this->orEq( $params['arguments'], 'parent_ids', false);
    $skus           = $this->orEq( $params['arguments'], 'skus', false);
    
    $by_ids = true;
    if ( ! $this->inArray($order_by,$allowed_order_bys) ) {
      $this->result->addError( __('order_by must be one of these:','woocommerce_json_api') . join( $allowed_order_bys, ','), WCAPI_BAD_ARGUMENT );
      return $this->done();
      return;
    }
    if ( ! $ids && ! $skus ) {
        if ($parent_ids) {
          $posts = API\Product::all('id', "`post_parent` IN (" . join(",",$parent_ids) . ")")->per($posts_per_page)->page($paged)->fetch(function ( $result) {
            return $result['id'];
          });
        } else {
          $posts = API\Product::all()->per($posts_per_page)->page($paged)->fetch(function ( $result) {
            return $result['id'];
          });
        }
        
      JSONAPIHelpers::debug( "IDs from all() are: " . var_export($posts,true) );
	  } else if ( $ids ) {
	  
	    $posts = $ids;
	    
	  } else if ( $skus ) {
	  
	    $posts = array();
	    foreach ($skus as $sku) {
	      $pid = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1",$sku) );
	      if ( ! $pid ) {
	        $this->result->addWarning( $sku . ': ' . __('Product does not exist','woocommerce_json_api'), WCAPI_PRODUCT_NOT_EXISTS, array( 'sku' => $sku) );
	      } else {
	        $posts[] = $pid;
	      }
	    }
	    
	  }

	  $products = array();
    foreach ( $posts as $post_id) {
      try {
        $post = API\Product::find($post_id);
      } catch (Exception $e) {
        JSONAPIHelpers::error("An exception occurred attempting to instantiate a Product object: " . $e->getMessage());
        $this->result->addError( __("Error occurred instantiating product object"),-99);
        return $this->done();
      }
      
      if ( !$post ) {
        $this->result->addWarning( $post_id. ': ' . __('Product does not exist','woocommerce_json_api'), WCAPI_PRODUCT_NOT_EXISTS, array( 'id' => $post_id) );
      } else {

        $products[] = $post->asApiArray();
      }
      
    }
    // We manage the array ourselves, so call setPayload, instead of addPayload
    $this->result->setPayload($products);

	  return $this->done();
  }
  public function get_supported_attributes( $params ) {
    $models = $this->orEq( $params['arguments'], 'resources', false );
    
    if ( ! $models ) {
      $this->missingArgument('resources');
      return $this->done();
    }
    if ( ! is_array($models) ) {
      $this->badArgument('resources','an Array of Strings');
    }
    $results = array();
    foreach ( $models as $m ) {
      if ( $m == 'Product' ) {
        $model = new API\Product();
      } else if ( $m == 'Category' ) {
        $model = new API\Category();
      } else if ( $m == 'Order' ) {
        $model = new API\Order();
      } else if ( $m == 'OrderItem' ) {
        $model = new API\OrderItem();
      } else if ( $m == 'Customer' ) {
        $model = new API\Customer();
      } else {
        $this->badArgument($m, "Product,Category,Order,OrderItem,Customer");
        return $this->done();
      }
      $results[$m] = $model->getSupportedAttributes() ;
    }
    $this->result->setPayload( $results );
    return $this->done();
  }
  public function get_products_by_tags($params) {
    global $wpdb;
    $allowed_order_bys = array('id','name','post_title');
    $terms = $this->orEq( $params['arguments'], 'tags', array());
    foreach ($terms as &$term) {
      $term = $wpdb->prepare("%s",$term);
    }
    if ( count($terms) < 1) {
      $this->result->addError( __('you must specify at least one term','woocommerce_json_api'), WCAPI_BAD_ARGUMENT );
      return $this->done();
    }
    $posts_per_page = $this->orEq( $params['arguments'], 'per_page', 15 ); 
    $paged          = $this->orEq( $params['arguments'], 'page', 0 );
    $order_by       = $this->orEq( $params['arguments'], 'order_by', 'id');
    if ( ! $this->inArray($order_by,$allowed_order_bys) ) {
      $this->result->addError( __('order_by must be one of these:','woocommerce_json_api') . join( $allowed_order_bys, ','), WCAPI_BAD_ARGUMENT );
      return $this->done();
      return;
    }
    $order          = $this->orEq( $params['arguments'], 'order', 'ASC');
    
    // It would be nice to use WP_Query here, but it seems to be semi-broken when working
    // with custom taxonomies like product_tag...
    // We don't really care about the distinctions here anyway, it's mostly superfluous, because
    // we only want posts of type product so we can just select against the terms and not care.

    $sql = "
              SELECT
                p.id 
              FROM 
                {$wpdb->posts} AS p, 
                {$wpdb->terms} AS t, 
                {$wpdb->term_taxonomy} AS tt, 
                {$wpdb->term_relationships} AS tr 
              WHERE 
                t.slug IN (" . join(',',$terms) . ") AND 
                tt.term_id = t.term_id AND 
                tr.term_taxonomy_id = tt.term_taxonomy_id AND 
                p.id = tr.object_id
            ";
    $ids = $wpdb->get_col( $sql );
    $params['arguments']['ids'] = $ids;
    return $this->get_products( $params );
  }
  /*
    Similar to get products, in fact, we should be able to resuse te response
    for that call to edit the products thate were returned.
    
    WooCom has as kind of disconnected way of saving a product, coming from Rails,
    it's a bit jarring. Most of this function is taken from woocommerce_admin_product_quick_edit_save()
    
    It seems that Product objects don't know how to save themselves? This may not be the
    case but a cursory search didn't find out exactly how products are really
    being saved. That's no matter because they are mainly a custom post type anyway,
    and most fields attached to them are just post_meta fields that are easy enough
    to find in the DB.
    
    There's certainly a more elegant solution to be found, but this has to get
    up and working, and be pretty straightforward/explicit. If I had the time,
    I'd write a custom Product class that knows how to save itself,
    and then just make setter methods modify internal state and then abstract out.
  */
    // FIXME: We need some way to ensure that adding of products is not
    // exploited. we need to track errors, and temporarily ban users with
    // too many. We need a way to lift the ban in the interface and so on.
  public function set_products( $params ) {
    $products = $this->orEq( $params, 'payload', array() );
    foreach ( $products as &$attrs) {
      $product = null;
      if (isset($attrs['id'])) {
        $product = API\Product::find($attrs['id']);
      } else if ( isset($attrs['sku'])) {
        $product = API\Product::find_by_sku($attrs['sku']);
      }
      if ($product && is_object($product) && $product->isValid()) {
        $product->fromApiArray( $attrs );
        $product->update()->done();
        $attrs = $product->asApiArray();
      } else {
        $this->result->addWarning( 
          __(
              'Product does not exist.',
              'woocommerce_json_api'
            ),
          WCAPI_PRODUCT_NOT_EXISTS, 
          array( 
            'id' => isset($attrs['id']) ? $attrs['id'] : 'none',
            'sku' => isset($attrs['sku']) ? $attrs['sku'] : 'none',
          )
        );
        // Let's create the product if it doesn't exist.
        $product = new API\Product();
        $product->create( $attrs );
        if ( ! $product->isValid() ) {
          return $this->done();
        }
        $attrs = $product->asApiArray();
      }
    }
    $this->result->setPayload( $products );
    return $this->done();
  }

  
  /**
   *  Get product categories
  */
  public function get_categories( $params ) {
  
    $allowed_order_bys = array('id','count','name','slug');
    
    $order_by       = $this->orEq( $params['arguments'], 'order_by', 'name');
    if ( ! $this->inArray($order_by,$allowed_order_bys) ) {
      $this->result->addError( __('order_by must be one of these:','woocommerce_json_api') . join( $allowed_order_bys, ','), WCAPI_BAD_ARGUMENT );
      return $this->done();
      return;
    }
    $order          = $this->orEq( $params['arguments'], 'order', 'ASC');
    $ids            = $this->orEq( $params['arguments'], 'ids', false);
    
    $hide_empty     = $this->orEq( $params['arguments'], 'hide_empty', false);
    
    $args = array(
  	  'fields'         => 'ids',
      'order_by'       => $order_by,
      'order'          => $order,
    );
    
    if ($ids) {
      $args['include'] = $ids;
    }
    
    $categories = get_terms('product_cat', $args);
    foreach ( $categories as $id ) {
      $category = API\Category::find( $id );
      $this->result->addPayload( $category->asApiArray() );
    }

    return $this->done();
  }
  
  public function set_categories( $params ) {
    $categories = $this->orEq( $params, 'payload', array());
    foreach ( $categories as $category ) {
      $actual = API\Category::find_by_name( $category['name'] );
      //print_r($actual->asApiArray());
    }
    return $this->done();
  }
  /**
   * Get tax rates defined for store
  */
  public function get_taxes( $params ) {
    global $wpdb;
    
    $tax_classes = explode("\n",get_option('woocommerce_tax_classes'));
    $tax_classes = array_merge($tax_classes, array(''));
    
    $tax_rates = array();
    
    foreach ( $tax_classes as $tax) {
      $name = $tax;
      if ( $name == '' ) {
        $name = "DefaultRate";
      } 
      // Never have a select * without a limit statement.
      $found_rates = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates where tax_rate_class = %s LIMIT %d",$tax,100) );
      $rates = array();
      foreach ( $found_rates as $rate ) {
       
        $rates[] = $this->translateTaxRateAttributes($rate);
      }
      $tax_rates[] = array(
        'name' => $name,
        'rates' => $rates
      );
    }
    $this->result->setPayload($tax_rates); 
    return $this->done();   
  }
  /**
  * WooCommerce handles shipping methods on a per class/instance basis. So in order to have a
  * shipping method, we must have a class file that registers itself with 'woocommerce_shipping_methods'.
  */
  public function get_shipping_methods( $params ) {
    $klass = new WC_Shipping();
    $klass->load_shipping_methods();
    $methods = array();
    foreach ( $klass->shipping_methods as $sm ) {
      $methods[] = array(
        'id' => $sm->id,
        'name' => $sm->title,
        'display_name' => $sm->method_title,
        'enabled' => $sm->enabled,
        'settings' => $sm->settings,
        'plugin_id' => $sm->plugin_id,
      );
    }
    $this->result->setPayload( $methods );
    return $this->done();
  }
  
  /**
  *  Get info on Payment Gateways
  */
  public function get_payment_gateways( $params ) {
    $klass = new WC_Payment_Gateways();
    foreach ( $klass->payment_gateways as $sm ) {
      $methods[] = array(
        'id' => $sm->id,
        'name' => $sm->title,
        'display_name' => $sm->method_title,
        'enabled' => $sm->enabled,
        'settings' => $sm->settings,
        'plugin_id' => $sm->plugin_id,
      );
    }
    $this->result->setPayload( $methods );
    return $this->done();
  }
  public function get_tags( $params ) {
    $allowed_order_bys = array('name','count','term_id');
    $allowed_orders = array('DESC','ASC');
    $args['order']                = $this->orEq( $params['arguments'], 'order', 'DESC');
    $args['order_by']             = $this->orEq( $params['arguments'], 'order_by', 'name');

    if ( ! $this->inArray($args['order_by'],$allowed_order_bys) ) {
      $this->result->addError( __('order_by must be one of these:','woocommerce_json_api') . join( $allowed_order_bys, ','), WCAPI_BAD_ARGUMENT, $args );
      return $this->done();
      return;
    }

    if ( ! $this->inArray($args['order'],$allowed_orders) ) {
      $this->result->addError( __('order must be one of these:','woocommerce_json_api') . join( $allowed_orders, ','), WCAPI_BAD_ARGUMENT );
      return $this->done();
      return;
    }

    $args['hide_empty']           = $this->orEq( $params['arguments'], 'hide_empty', true);
    $include                      = $this->orEq( $params['arguments'], 'include', false);
    if ( $include ) {
      $args['include'] = $include;
    }
    $number                       = $this->orEq( $params['arguments'], 'per_page', false);
    if ( $number ) {
      $args['number'] = $number;
    }
    $like                         = $this->orEq( $params['arguments'], 'like', false);
    if ( $like ) {
      $args['name__like'] = $like;
    }
    $tags = get_terms('product_tag', $args);
    $this->result->setPayload($tags);
    return $this->done();
  }
  public function get_customers( $params ) {
    global $wpdb;
    $customer_ids = $wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'wp_capabilities' AND meta_value LIKE '%customer%'");
    $customers = array();
    foreach ( $customer_ids as $id ) {
      $c = API\Customer::find( $id );
      $customers[] = $c->asApiArray();
    }
    $this->result->setPayload($customers);
    return $this->done();
  }

  public function get_orders( $params ) {
    $posts_per_page = $this->orEq( $params['arguments'], 'per_page', 15 ); 
    $paged          = $this->orEq( $params['arguments'], 'page', 0 );
    $ids            = $this->orEq( $params['arguments'], 'ids', false);

    if ( ! $ids ) {
      $orders = array();
      $models = API\Order::all("*")->per($posts_per_page)->page($paged)->fetch();
      foreach ( $models as $model ) {
        $orders[] = $model->asApiArray();
      }
    } else if ( $ids ) {
    
      $posts = $ids;
      $orders = array();
      foreach ( $posts as $post_id) {
        try {
          $post = API\Order::find($post_id);
        } catch (Exception $e) {
          JSONAPIHelpers::error("An exception occurred attempting to instantiate a Order object: " . $e->getMessage());
          $this->result->addError( __("Error occurred instantiating Order object"),-99);
          return $this->done();
        }
        
        if ( !$post ) {
          $this->result->addWarning( $post_id. ': ' . __('Order does not exist','woocommerce_json_api'), WCAPI_ORDER_NOT_EXISTS, array( 'id' => $post_id) );
        } else {
          $orders[] = $post->asApiArray();
        }
        
      }
    }
    
    $this->result->setPayload($orders);
    return $this->done();
  }

  public function set_orders( $params ) {
    
  }

  public function get_store_settings( $params ) {
    global $wpdb;
    $filter = $this->orEq( $params['arguments'],'filter', '');
    $filter = $wpdb->prepare("%s",$filter);
    $filter = substr($filter, 1,strlen($filter) - 2);
    $sql = "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_{$filter}%' AND LENGTH(option_value) < 1024";
    $results = $wpdb->get_results( $sql, 'ARRAY_A');
    $payload = array();
    foreach ( $results as $result ) {
      $key = str_replace('woocommerce_','',$result['option_name']);
      $payload[$key] = maybe_unserialize( $result['option_value']);
    }
    $this->result->setPayload( $payload );
    return $this->done();
  }
  public function set_store_settings( $params ) {
    global $wpdb;
    $filter = $this->orEq( $params['arguments'],'filter', '');
    $payload = $this->orEq( $params,'payload', false);
    if ( ! $payload || ! is_array($payload)) {
      $this->result->addError( __('Missing payload','woocommerce_json_api'), WCAPI_BAD_ARGUMENT );
      return $this->done();
    }
    $filter = $wpdb->prepare("%s",$filter);
    $filter = substr($filter, 1,strlen($filter) - 2);
    $sql = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'woocommerce_{$filter}%' AND LENGTH(option_value) < 1024";
    $results = $wpdb->get_results( $sql, 'ARRAY_A');
    $new_settings = array();
    $meta_sql = "
        UPDATE {$wpdb->options}
          SET `option_value` = CASE `option_name`
            ";
    $option_keys = array();
    foreach ( $results as $result ) {
      $key = str_replace('woocommerce_','',$result['option_name']);
      if ( isset( $payload[$key])) {
       //$option_keys[] = $wpdb->prepare("%s",$result['option_name']);
        $meta_sql .= $wpdb->prepare( "\t\tWHEN '{$result['option_name']}' THEN %s\n ", $payload[$key]);
      }
    }
    $meta_sql .= "
        ELSE `option_value`
        END
    ";
    $wpdb->query($meta_sql);
    return $this->get_store_settings( $params );
  }

  public function get_site_settings( $params ) {
    global $wpdb;
    $filter = $this->orEq( $params['arguments'],'filter', '');
    $filter = $wpdb->prepare("%s",$filter);
    $filter = substr($filter, 1,strlen($filter) - 2);
    if ( strlen($filter) > 1) {
      $sql = "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE '{$filter}%' AND LENGTH(option_value) < 1024";
    } else {
      $sql = "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE '{$filter}%' AND option_name NOT LIKE 'woocommerce_%' AND LENGTH(option_value) < 1024";
    }
    
    $results = $wpdb->get_results( $sql, 'ARRAY_A');
    $payload = array();
    foreach ( $results as $result ) {
      $key = $result['option_name'];
      $payload[$key] = maybe_unserialize( $result['option_value']);
    }
    $this->result->setPayload( $payload );
    return $this->done();
  }
  public function set_site_settings( $params ) {
    global $wpdb;
    $filter = $this->orEq( $params['arguments'],'filter', '');
    $payload = $this->orEq( $params,'payload', false);
    if ( ! $payload || ! is_array($payload)) {
      $this->result->addError( __('Missing payload','woocommerce_json_api'), WCAPI_BAD_ARGUMENT );
      return $this->done();
    }
    $filter = $wpdb->prepare("%s",$filter);
    $filter = substr($filter, 1,strlen($filter) - 2);
    if ( strlen($filter) > 1) {
      $sql = "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE '{$filter}%' AND LENGTH(option_value) < 1024";
    } else {
      $sql = "SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE '{$filter}%' AND option_name NOT LIKE 'woocommerce_%' AND LENGTH(option_value) < 1024";
    }
    $results = $wpdb->get_results( $sql, 'ARRAY_A');
    $new_settings = array();
    $meta_sql = "
        UPDATE {$wpdb->options}
          SET `option_value` = CASE `option_name`
            ";
    $option_keys = array();
    foreach ( $results as $result ) {
      $key = $result['option_name'];
      if ( isset( $payload[$key] ) ) {
       //$option_keys[] = $wpdb->prepare("%s",$result['option_name']);
        $meta_sql .= $wpdb->prepare( "\t\tWHEN '{$result['option_name']}' THEN %s\n ", $payload[$key]);
      }
    }
    $meta_sql .= "
        ELSE `option_value`
        END
    ";
    $wpdb->query($meta_sql);
    return $this->get_store_settings( $params );
  }
  public function get_api_methods( $params ) {
    $m = self::getImplementedMethods();
    $this->result->setPayload($m);
    return $this->done();
  }
}
