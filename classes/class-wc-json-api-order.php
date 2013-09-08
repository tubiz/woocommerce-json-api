<?php
/**
 * An Order class to insulate the API from the details of the
 * database representation
*/
require_once(dirname(__FILE__) . "/class-rede-base-record.php");
require_once(dirname(__FILE__) . "/class-wc-json-api-order-item.php");
class WC_JSON_API_Order extends JSONAPIBaseRecord {

  
  public $_status;
  

  public static function setupMetaAttributes() {
    // We only accept these attributes.
    self::$_meta_attributes_table = array(
      'order_key'				      => array('name' => '_order_key',                  'type' => 'string'), 
      'billing_first_name'	  => array('name' => '_billing_first_name',         'type' => 'string'), 
      'billing_last_name' 	  => array('name' => '_billing_last_name',          'type' => 'string'), 
      'billing_company'		    => array('name' => '_billing_company' ,           'type' => 'string'), 
      'billing_address_1'		  => array('name' => '_billing_address_1',          'type' => 'string'), 
      'billing_address_2'		  => array('name' => '_billing_address_2',          'type' => 'string'), 
      'billing_city'			    => array('name' => '_billing_city',               'type' => 'string'), 
      'billing_postcode'		  => array('name' => '_billing_postcode',           'type' => 'string'), 
      'billing_country'		    => array('name' => '_billing_country',            'type' => 'string'), 
      'billing_state' 		    => array('name' => '_billing_state',              'type' => 'string'), 
      'billing_email'			    => array('name' => '_billing_email',              'type' => 'string'), 
      'billing_phone'			    => array('name' => '_billing_phone',              'type' => 'string'), 
      'shipping_first_name'	  => array('name' => '_shipping_first_name',        'type' => 'string'), 
      'shipping_last_name'	  => array('name' => '_shipping_last_name' ,        'type' => 'string'), 
      'shipping_company'		  => array('name' => '_shipping_company',           'type' => 'string'), 
      'shipping_address_1'	  => array('name' => '_shipping_address_1' ,        'type' => 'string'), 
      'shipping_address_2'	  => array('name' => '_shipping_address_2',         'type' => 'string'), 
      'shipping_city'			    => array('name' => '_shipping_city',              'type' => 'string'), 
      'shipping_postcode'		  => array('name' => '_shipping_postcode',          'type' => 'string'), 
      'shipping_country'		  => array('name' => '_shipping_country',           'type' => 'string'), 
      'shipping_state'		    => array('name' => '_shipping_state',             'type' => 'string'), 
      'shipping_method'		    => array('name' => '_shipping_method' ,           'type' => 'string'), 
      'shipping_method_title'	=> array('name' => '_shipping_method_title',      'type' => 'string'), 
      'payment_method'		    => array('name' => '_payment_method',             'type' => 'string'), 
      'payment_method_title' 	=> array('name' => '_payment_method_title',       'type' => 'string'), 
      'order_discount'		    => array('name' => '_order_discount',             'type' => 'number'), 
      'cart_discount'			    => array('name' => '_cart_discount',              'type' => 'number'), 
      'order_tax'				      => array('name' => '_order_tax' ,                 'type' => 'number'), 
      'order_shipping'		    => array('name' => '_order_shipping' ,            'type' => 'number'), 
      'order_shipping_tax'	  => array('name' => '_order_shipping_tax' ,        'type' => 'number'), 
      'order_total'			      => array('name' => '_order_total',                'type' => 'number'), 
      'customer_user'			    => array('name' => '_customer_user',              'type' => 'number'), 
      'completed_date'		    => array('name' => '_completed_date',             'type' => 'datetime'), 
      'status'                => array(
                                        'name' => 'status', 
                                        'type' => 'string', 
                                        'getter' => 'getStatus',
                                        'setter' => 'setStatus',
                                        'updater' => 'updateStatus'
                                ),
      //'quantity'          => array('name' => '_stock',            'type' => 'number', 'filters' => array('woocommerce_stock_amount') ),
      
    );
    /*
      With this filter, plugins can extend this ones handling of meta attributes for a product,
      this helps to facilitate interoperability with other plugins that may be making arcane
      magic with a product, or want to expose their product extensions via the api.
    */
    self::$_meta_attributes_table = apply_filters( 'woocommerce_json_api_order_meta_attributes_table', self::$_meta_attributes_table );
  } // end setupMetaAttributes
  public static function setupModelAttributes() {
    self::$_model_settings = array(
      'model_conditions' => "WHERE post_type IN ('shop_order')",
      'has_many' => array(
        'order_items' => array('class_name' => 'WC_JSON_API_OrderItem', 'foreign_key' => 'order_id'),
      ),
    );
    self::$_model_attributes_table = array(
      'name'            => array('name' => 'post_title',  'type' => 'string'),
      'guid'            => array('name' => 'guid',        'type' => 'string'),

    );
    self::$_model_attributes_table = apply_filters( 'woocommerce_json_api_order_model_attributes_table', self::$_model_attributes_table );
  }
  public function getStatus() {
    if ( $this->_status ) {
      return $this->_status;
    }
    $terms = wp_get_object_terms( $this->id, 'shop_order_status', array('fields' => 'slugs') );
    $this->_status = (isset($terms[0])) ? $terms[0] : 'pending';
    return $this->_status;
  }

  public function setStatus( $s ) {
    $this->_status = $s;
  }
  public function asApiArray() {
    $attrs = parent::asApiArray();
    $attrs['order_items'] = $this->order_items;
    return $attrs;
  }

  

  // public static function find( $id ) {
  //   global $wpdb;
  //   self::setupModelAttributes();
  //   self::setupMetaAttributes();
  //   $order = new WC_JSON_API_Order();
  //   $order->setValid( false );
  //   $post = get_post( $id, 'ARRAY_A' );
  //   if ( $post ) {
  //     $order->setModelId( $id );
  //     foreach ( self::$_model_attributes_table as $name => $desc ) {
  //       $order->dynamic_set( $name, $desc,$post[$desc['name']] );
  //       //$order->{$name} = $post[$desc['name']];
  //     }
  //     foreach ( self::$_meta_attributes_table as $name => $desc ) {
  //       $value = get_post_meta( $id, $desc['name'], true );
  //       // We may want to do some "funny stuff" with setters and getters.
  //       // I know, I know, "no funny stuff" is generally the rule.
  //       // But WooCom or WP could change stuff that would break a lot
  //       // of code if we try to be explicity about each attribute.
  //       // Also, we may want other people to extend the objects via
  //       // filters.
  //       $order->dynamic_set( $name, $desc, $value, $order->getModelId() );
  //     }
  //     $order->setValid( true );
  //     $order->setNewRecord( false );
  //   }
  //   return $order;
  // }
  // /**
  // *  From here we have a dynamic getter. We return a special REDENOTSET variable.
  // */
  // public function __get( $name ) {
  //   if ( isset( self::$_meta_attributes_table[$name] ) ) {
  //     if ( isset(self::$_meta_attributes_table[$name]['getter'])) {
  //       return $this->{self::$_meta_attributes_table[$name]['getter']}();
  //     }
  //     if ( isset ( $this->_meta_attributes[$name] ) ) {
  //       return $this->_meta_attributes[$name];
  //     } else {
  //       return '';
  //     }
  //   } else if ( isset( self::$_model_attributes_table[$name] ) ) {
  //     if ( isset( $this->_model_attributes[$name] ) ) {
  //       return $this->_model_attributes[$name];
  //     } else {
  //       return '';
  //     }
  //   }
  // } // end __get
  
  // // Dynamic setter
  // public function __set( $name, $value ) {
  //   if ( isset( self::$_meta_attributes_table[$name] ) ) {
  //     if ( isset(self::$_meta_attributes_table[$name]['setter'])) {
  //       $this->{self::$_meta_attributes_table[$name]['setter']}( $value );
  //     }
  //     $this->_meta_attributes[$name] = $value;
  //   } else if ( isset( self::$_model_attributes_table[$name] ) ) {
  //     $this->_model_attributes[$name] = $value;
  //   } else {
  //     throw new Exception( __('That attribute does not exist to be set.','woocommerce_json_api') . " `$name`");
  //   }
  // } 
  // public static function all($fields = 'id') {
  //   global $wpdb;
  //   $sql = "SELECT $fields from {$wpdb->posts} WHERE post_type IN ('shop_order')";
  //   $order = new WC_JSON_API_Product();
  //   $order->addQuery($sql);
  //   return $order;
  // }
}