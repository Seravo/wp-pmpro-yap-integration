<?php
/**
 * Plugin Name: PMPro YAP Integration
 * Plugin URI: 
 * Description: Integrate YAP with Paid Memberships Pro
 * Version: 0.2
 * Author: Seravo / Onni Hakala
 * Author URI: http://seravo.fi
 * License: GPLv2
 */

class PMProYapIntegration {
  private static $_single; // Let's make this a singleton.
  public static $page_id; // Wordpress adminpage id
  public static $name; // Wordpress adminpage name

  function __construct() {
    if (isset(self::$_single)) { return; }
    self::$_single = $this; // Singleton set.
    self::$page_id = 'pmpro-yap-settings';
    self::$name = __('YAP-asetukset', 'pmpro-yap');
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
    // This fires on every login and redirects users from /wp-admin to the front page
    add_action( 'admin_init', array(&$this,'redirect_non_admin_users'));
  }

  /**
   * Show actions in user edit mode.
   */
  public function extra_user_profile_fields($user_id) {
    //only admins can see this
    if ( !isset($user_id) ||  !user_can($user_id, 'edit_user' ) ) { return false; }

    require_once(dirname(__FILE__) . "/includes/user-address-fields.php");
  }

  /**
   * Allow editing of address from wp-admin
   *
   * This function is attached to 'personal_options_update' and 'edit_user_profile_update' action hooks.
   */
  public function save_extra_user_profile_fields( $user_id ) {

    if ( !isset($user_id) || !user_can($user_id, 'edit_user' ) ) { return false; }

    update_user_meta( $user_id, 'address', $_POST['address'] );
    update_user_meta( $user_id, 'city', $_POST['city'] );
    update_user_meta( $user_id, 'postalcode', $_POST['postalcode'] );
  }

  /**
   * Redirect non-admin users to home page
   *
   * This function is attached to the 'admin_init' action hook.
   */
  public static function redirect_non_admin_users() {
    if ( !current_user_can('edit_posts') && !( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
      error_log("Redirect non-admin user");
      wp_redirect( home_url() );
      exit;
    }
  }

  /**
   * Add admin page for yap-settings
   */
  public function pmpro_yap_admin() {
    add_submenu_page('pmpro-membershiplevels', self::$name, self::$name, 'pmpro_paymentsettings', self::$page_id, array(&$this,'pmpro_yap_settings'));
    add_filter( 'pmpro_admin_settings_tabs', array(&$this,'add_pmpro_settings_tab'), 100, 1);
  }

  public function add_pmpro_settings_tab($settings_tabs) {
    $settings_tabs[self::$page_id] = self::$name;
    return $settings_tabs;
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
    }
    return new WP_Error('authentication_failed', __('<strong>ERROR</strong>: Invalid username or incorrect password.'));
  }

  /**
   * Create/Update user from the information of YAP
   */
  public function create_or_update_user_if_found($username,$password,$old_user = NULL) {

    //Return error if settings are not set
    if (!$this->getSoapUrl()) {
      return new WP_Error('yap_settings_not_set', __('<strong>VIRHE</strong>: Aseta YAP-integraation asetukset ylläpidosta. Tilaajat -> YAP-asetukset','pmpro-yap'));
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

    //Logfile
    $logfile = dirname(ini_get('error_log')).'/yap-debug.log';

    try {
      // Ask user from yap and save it
      PMProYapIntegration::log('Requested from YAP',$params);
      $result = $client->LoginAndGetMagazineSubscriperDetails($params)->LoginAndGetMagazineSubscriperDetailsResult;
      PMProYapIntegration::log('SUCCESS, YAP returned',$result);
    } catch(SoapFault $e) {
      PMProYapIntegration::log("FAILURE, YAP returned Soap error",array('faultstring' => $e->faultstring,'faultcode' => $e->faultcode, 'faultactor' => $e->faultactor));
      if (strpos($e->faultstring,'<faultstring>Login error</faultstring>') !== false) {
        //Change subscription status to none
        PMProYapIntegration::deactivateUserSubscription($old_user->ID);
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

    if (!empty($user)) {

      //PersonId was found
      $user_id = $user->ID;
      //User was in the records but password was wrong
      wp_set_password($password,$user_id);

    } elseif (email_exists($result->Email)) {

      //Email was found
      $user = get_user_by('email', $result->Email );
      $user_id = $user->ID;

      wp_set_password($password,$user->ID);

    } else {
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

    // Set Display name too
    update_user_meta($current_user->ID, 'display_name', "{$firstname} {$lastname}");

    //Add subscription into pmpro if it is still valid in yap
    if (self::is_subscription_valid($result)) {
      PMProYapIntegration::activateUserSubscription($user_id);
    } else {
      PMProYapIntegration::deactivateUserSubscription($user_id);
    }

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
   * Activates subscription so user can read everything
   */
  public static function activateUserSubscription($user_id) {
    $membership_id = PMProYapIntegration::getOption('membership');
    PMProYapIntegration::log('Activate subscription for user_id', $user_id);
    PMProYapIntegration::log('Using membership_id', $membership_id);
    pmpro_changeMembershipLevel($membership_id,$user_id);
  }

  /*
   * Ends subscripton
   */
  public static function deactivateUserSubscription($user_id) {
    PMProYapIntegration::log('Deactivate subscription for user_id', $user_id);
    pmpro_changeMembershipLevel(NULL,$user_id);
  }

  /*
   * Capitalize scandinavian names
   */
  public static function capitalize($str) {
    return mb_convert_case($str, MB_CASE_TITLE, "UTF-8");
  }

  public static function log($message,$object) {
    $logfile = dirname(ini_get('error_log')).'/yap-debug.log';
    error_log(date('m/d/Y @ g:i:sA',time()).': '.$message.':',3,$logfile);
    error_log(print_r($object,true)."\n",3,$logfile);
  }

  /**
   * Check if subscription is still valid
   *
   * @param $response - soap response from yap
   *
   * @return bool
   */
  public static function is_subscription_valid($result){
    // Check that the object has correct information set
    if ( ! isset($result) || ! isset($result->Subscriptions) || ! isset($result->Subscriptions->Subscription) ) { return false; }

    foreach ($result->Subscriptions->Subscription as $subscription) {
      $date = date_parse_from_format('Y-m-d', $subscription->endDate);

      $timestamp = mktime(0, 0, 0, $date['month'], $date['day'], $date['year']);
      // Add one day so that subscription will end when the last day is finished
      $timestamp = strtotime('+1 days', $timestamp);

      // Check if the subscription is still valid
      if($timestamp > time()) {
        PMProYapIntegration::log('Subscription is valid. End ts', $timestamp);
        return true;
      }
    }
    PMProYapIntegration::log('Subscription might be expired. End ts', $timestamp);
    return false;
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
   $username = trim($username);
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

   $yapApi = PMProYapIntegration::getSingleton();

   // Try Firstname and Lastname from WordPress (with function from YAP library)
   if (is_wp_error($user) || $user == null) {
    $user = $yapApi->login_with_real_name($username,$password);
   }

   // If user wasn't authenticated from WordPress, try to login through YAP
   if ( is_wp_error($user) || $user == null ) {
    // Username must be either firstname+lastname or email
    // Don't bother YAP if this is just bruteforce
    if (strpos($username, ' ') !== FALSE || strpos($username, '@') !== FALSE ) {
      error_log("Try to Create user with YAP USER:{$username} PASSWORD:{$password}");
      $user = $yapApi->create_or_update_user_if_found($username,$password);
    }
   } elseif (!user_can($user,'edit_posts')) {
    // Update membership
    error_log("Updating earlier subscriber from YAP:{$username}");
    $user = $yapApi->create_or_update_user_if_found($username,$password,$user);
    if (is_wp_error($user)) {
      error_log("{$username} doesn't have subscription..");
    } else {
      error_log("{$username} has valid subscription!");
    }
   } else {
    //Activate subscription if the user is admin user (can edit posts)
    error_log("Updating admin user:{$username}");
    PMProYapIntegration::activateUserSubscription($user->ID);
   }

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
