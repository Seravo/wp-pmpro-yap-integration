<?php
/**
 * Plugin Name: PMPro YAP Integration
 * Plugin URI: 
 * Description: Integrate YAP with Paid Memberships Pro
 * Version: 0.1
 * Author: Seravo / Onni Hakala
 * Author URI: http://seravo.fi
 * License: GPLv2
 */

class PMProYapIntegration {
  private static $_single; // Let's make this a singleton.
  function __construct() {
    if (isset(self::$_single)) { return; }
    self::$_single = $this; // Singleton set.
    $this->init();
  }

  /**
   * Make initial preparation
   */
  public function init() {
    // Set adminpage and capabilities
    // Use lower priority than pmpro
    add_action('admin_menu',array(&$this,'pmpro_yap_admin'),100);

    // Add address field for users
    add_action( 'show_user_profile', array(&$this,'extra_user_profile_fields') );
    add_action( 'edit_user_profile', array(&$this,'extra_user_profile_fields') );

    // Allow saving the address field?
    add_action( 'personal_options_update', array(&$this,'save_extra_user_profile_fields') );
    add_action( 'edit_user_profile_update', array(&$this,'save_extra_user_profile_fields') );

    // Redirect subscribers away from wp-admin to home
    add_action( 'admin_init', array(&$this,'redirect_non_admin_users'));
    add_filter( 'show_admin_bar' , array(&$this,'hide_admin_bar_non_admin_users'));
  }

  /**
   * Show actions in user edit mode.
   */
  public function extra_user_profile_fields($user) {
    //only admins can see this
    if ( !current_user_can( 'edit_user', $user_id ) ) { return false; }

    require_once(dirname(__FILE__) . "/includes/user-address-fields.php");
  }

  /**
   * Allow editing of address from wp-admin
   *
   * This function is attached to 'personal_options_update' and 'edit_user_profile_update' action hooks.
   */
  public function save_extra_user_profile_fields( $user_id ) {

    if ( !current_user_can( 'edit_user', $user_id ) ) { return false; }

    update_user_meta( $user_id, 'address', $_POST['address'] );
    update_user_meta( $user_id, 'city', $_POST['city'] );
    update_user_meta( $user_id, 'postalcode', $_POST['postalcode'] );
  }

  /**
   * Redirect non-admin users to home page
   *
   * This function is attached to the 'admin_init' action hook.
   */
  public static function hide_admin_bar_non_admin_users() {
    if ( ! current_user_can('manage_options') ) {
      return false;
    }
  }

  /**
   * Redirect non-admin users to home page
   *
   * This function is attached to the 'admin_init' action hook.
   */
  public static function redirect_non_admin_users() {
    if ( ! current_user_can( 'manage_options' ) && '/wp-admin/admin-ajax.php' != $_SERVER['PHP_SELF'] ) {
      wp_redirect( home_url() );
      exit;
    }
  }

  /**
   * Add admin page for yap-settings
   */
  public function pmpro_yap_admin() {
    add_submenu_page('pmpro-membershiplevels', __('YAP-asetukset', 'pmpro'), __('YAP-asetukset', 'pmpro'), 'pmpro_paymentsettings', 'pmpro-yap-settings', array(&$this,'pmpro_yap_settings'));
  }

  /**
   * Contents of yap settings page
   */
  public function pmpro_yap_settings() {
    require_once(dirname(__FILE__) . "/includes/adminpage.php");
  }

  /**
   * Try to Login with user created earlier using Firstname+Lastname
   */

  public function login_with_real_name($username,$password) {
    // Split firstname+lastname from username
    // This way user can use real name in wordpress login
    $names = explode(' ',$username);
    $first_name = reset($names);
    $last_name = end($names);

    if(count($names) > 1) {
      //Search for given name
      $users = new WP_User_Query( array(
        'meta_query' => array(
          'relation' => 'AND',
          array(
            'key'     => 'first_name',
            'value'   => $first_name,
            'compare' => 'LIKE'
          ),
          array(
            'key'     => 'last_name',
            'value'   => $last_name,
            'compare' => 'LIKE'
          )
        )
      ));

      //if Firstname and Lastname matches try to login if one of them matches
      foreach ($users->get_results() as $u) {
        $user = apply_filters( 'authenticate', null, $u->user_login, $password );
        if ( $user != null ) {
          return $user;
        }
      }
      return new WP_Error('authentication_failed', __('<strong>ERROR</strong>: Invalid username or incorrect password.'));
    }
  }

  /**
   * Create/Update user from the information of YAP
   */
  public function create_or_update_user_if_found($username,$password,$old_user = NULL) {

    //Return error if settings are not set
    if (!$this->getSoapUrl()) {
      return new WP_Error('yap_settings_not_set', __('<strong>VIRHE</strong>: Aseta YAP-integraation asetukset ylläpidosta. Tilaajat -> YAP-asetukset','pmpro'));
    }
    $client = new SoapClient($this->getSoapUrl(), array('connection_timeout' => 600,'trace' => 1));

    //SOAP Namespace for request
    $ns = "http://YAPSolutions.fi/";

    //Authentication is done in request body AuthHeader-section
    //Body for AuthHeader
    $authHeaderBody = array(
        'Username' => $this->getOption("api_user"),
        'Password' => $this->getOption("api_password"),
    );

    //Create request as YAP model demands.
    $header = new SOAPHeader($ns, 'AuthHeader', $authHeaderBody);
    $client->__setSoapHeaders($header);     

    //Create params for request from user details
    $params = new stdClass;

    //In YAP you can use either 'FIRSTNAME LASTNAME' or emailaddress in $username
    $params->userId = $username;
    $params->password = $password;

    try {
      //Ask user from yap and save it
      $result = $client->LoginAndGetMagazineSubscriperDetails($params)->LoginAndGetMagazineSubscriperDetailsResult;
    } catch(SoapFault $e) {
      if (strpos($e->faultstring,'<faultstring>Login error</faultstring>') !== false) {
        //Change subscription status to none
        pmpro_changeMembershipLevel(NULL,$old_user->ID);
        return $old_user;
      }
      //Yap is down or user doesn't exist.
      return new WP_Error('authentication_failed', __('<strong>ERROR</strong>: Invalid username or incorrect password.'));
    }

    //Check if user exists but the password has just changed
    $user = reset(get_users(
      array(
       'meta_key' => 'yap_person_id',
       'meta_value' => $result->PersonId,
       'number' => 1,
       'count_total' => false
      )
    ));

    error_log("users found with:".$result->PersonId." :".print_r($user,true));
    if (!empty($user)) {

      //PersonId was found
      $user_id = $user->ID;
      error_log("UsedID:".$user_id);
      //User was in the records but password was wrong
      wp_set_password($password,$user_id);

    } elseif (email_exists($result->Email)) {

      //Email was found
      $user = get_user_by('email', $result->Email );
      error_log("User by email:".$user);

      wp_set_password($password,$user->ID);

    } else {
      error_log("Creating new user");
      //Create new user
      $new_username = $this->createUsername($result);
      if (empty($result->Email)) {
        $user_id = wp_create_user( $new_username, $password );
      } else {
        $user_id = wp_create_user( $new_username, $password, $result->Email );
      }
    }
    //Save all information from yap => wordpress.
    $firstname = $this->capitalize($result->FirstName);
    $lastname = $this->capitalize($result->LastName);
    update_user_meta( $user_id, 'first_name', $firstname );
    update_user_meta( $user_id, 'last_name', $lastname );
    update_user_meta( $user_id, 'address', $this->capitalize($result->Address) );
    update_user_meta( $user_id, 'city', $this->capitalize($result->City) );
    update_user_meta( $user_id, 'postalcode', $result->PostalCode );
    update_user_meta( $user_id, 'yap_person_id', $result->PersonId );
    update_user_meta( $user_id, 'nickname', "{$firstname} {$lastname}" );

    //Add subscription into pmpro
    $membership_id = $this->getOption('membership');
    pmpro_changeMembershipLevel($membership_id,$user_id);

    //Hide admin bar
    update_user_meta( $user_id, 'show_admin_bar_front', false );

    //Return created/updated user
    return get_user_by('id',$user_id);
  }

  /*
   * Get soap url for yap services
   */
  public function getSoapUrl() {
    if($this->getOption("user")){
      $soap_url = 'https://secure.yap.fi/webservices/'.$this->getOption("user").'/genericservice.asmx?wsdl';
      return $soap_url;
    } else {
      return false;
    }
  }

  /*
   * Get singleton
   */
  public static function getSingleton() {
    return PMProYapIntegration::$_single;
  }

  /*
   * Get options from this the namespace of this plugin
   */
  private static function getOption($s){
    return get_option("pmpro_yap_" . $s);
  }

  private static function createUsername($soapresult) {
    //Get latest user for latest ID
    $newest_user = get_users( '&orderby=ID&number=1&order=DESC' )[0];
    $uid = (int)$newest_user->ID + 1;

    //Create user with 3 chars of first_name + 3 chars last_name + uid
    $username = substr(PMProYapIntegration::capitalize($soapresult->FirstName),0,3).
                    substr(PMProYapIntegration::capitalize($soapresult->LastName),0,3)."{$uid}";
    return $username;
  }

  /*
   * Check if this is normal wordpress user or yap user
   */
  public static function userWasCreatedByYap($user) {
    if(get_user_meta( $user->ID, 'yap_person_id')) {
      return true;
    }
    return false;
  }

  /*
   * Capitalize scandinavian names
   */
  public static function capitalize($str) {
    return mb_convert_case($str, MB_CASE_TITLE, "UTF-8");
  }

}

$yapApi = new PMProYapIntegration();

//Plug into authentication

if ( !function_exists('wp_authenticate') ) :
 /**
  * This is pluggable function and we overwrite it with custom authentication
  * 1. First check if user can login normally
  * 2. Try to login with FIRSTNAME+LASTNAME as username
  * 3. If first ones fail fallback into yap
  *
  * @param string $username User's username
  * @param string $password User's password
  * @return WP_User|WP_Error WP_User object if login successful, otherwise WP_Error object.
  */
 function wp_authenticate($username, $password) {
  error_log("START LOGIN with USER:{$username} PASSWORD:{$password}");
   $username = sanitize_user($username);
   $password = trim($password);

   /**
    * Filter the user to authenticate.
    *
    * @param null|WP_User $user     User to authenticate.
    * @param string       $username User login.
    * @param string       $password User password
    */

   //Try default username/email
   $user = apply_filters( 'authenticate', null, $username, $password );

   //Stop now if empty fields
   $ignore_codes = array('empty_username', 'empty_password');
   if (is_wp_error($user) && in_array($user->get_error_code(), $ignore_codes) ) {
     /**
      * Fires after a user login has failed.
      * @param string $username User login.
      */
     do_action('wp_login_failed', $username);
     return $user;
   }

   error_log('$user after normal login:'.print_r($user,true));

   $yapApi = PMProYapIntegration::getSingleton();

   //Try Firstname+Lastname
   if (is_wp_error($user) || $user == null) {
    $user = $yapApi->login_with_real_name($username,$password);
   }
   error_log('$user after firstname+lastname login:'.print_r($user,true));

   
   error_log('$user was created by yap:'.print_r($yapApi->userWasCreatedByYap($user),true));
   //If User wasn't authenticated from wordpress try to login through yap
   if ( is_wp_error($user) || $user == null ) {
    error_log('$user was created by yap:'.print_r($yapApi->userWasCreatedByYap($user),true));
    $user = $yapApi->create_or_update_user_if_found($username,$password);
   } elseif ($yapApi->userWasCreatedByYap($user)) {
    //User was already identified. Check if the subscription is still going.
    $user = $yapApi->create_or_update_user_if_found($username,$password,$user);
   }
   error_log('$user after yap login:'.print_r($user,true));

   if (is_wp_error($user)) {
     /**
      * Fires after a user login has failed.
      * @param string $username User login.
      */
     do_action( 'wp_login_failed', $username );
   }

   return $user;
 }
 endif;