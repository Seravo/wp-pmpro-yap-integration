<?php
  //only admins can get this
  if(!function_exists("current_user_can") || (!current_user_can("manage_options") && !current_user_can("pmpro_paymentsettings")))
  {
    die(__("You do not have permissions to perform this action.", "pmpro"));
  } 
  
  global $wpdb, $msg, $msgt;
  
  //get/set settings  
  if(!empty($_REQUEST['savesettings']))
  {                   
      
    //yap options
    pmpro_setOption("yap_user");
    pmpro_setOption("yap_api_user");         
    pmpro_setOption("yap_api_password");
    pmpro_setOption("yap_membership");

    //assume success
    $msg = true;
    $msgt = __("Your payment settings have been updated.", "pmpro");      
  }

  $yap_user = pmpro_getOption("yap_user"); //user for url
  $yap_api_user = pmpro_getOption("yap_api_user"); //user for AuthHeader
  $yap_api_password = pmpro_getOption("yap_api_password"); //password for AuthHeader
  $yap_membership = pmpro_getOption("yap_membership"); //the membership id for yap api
?>

  <form action="" method="post" enctype="multipart/form-data">         
    <h2><?php _e('YAP-asetukset', 'pmpro');?></h2>
    <p><?php _e('Lisäosa yhdistää Paid Memberships Pro lisäosan YAPin rajapintoihin ja huolehtii käyttäjien luomisesta ja synkronoinnista.', 'pmpro'); ?></p>
    
    <table class="form-table">
    <tbody>
      <tr>
        <th scope="row" valign="top"> 
          <label for="yap_user"><?php _e('YAP käyttäjätunnus', 'pmpro');?>:</label>
        </th>
        <td>
          <input type="text" id="yap_user" name="yap_user" value="<?php echo esc_attr($yap_user)?>" />
          <small><?php _e( 'YAP-rajapinnan osoitteen käyttäjätunnus. Esim: http://secure.yap.fi/webservices/<b>käyttäjätunnus</b>/genericservice.asmx?wsdl', 'pmpro' ); ?></small>
        </td>
      </tr>                 
      <tr>
        <th scope="row" valign="top"> 
          <label for="yap_api_user"><?php _e('YAP-api käyttäjätunnus', 'pmpro');?>:</label>
        </th>
        <td>
          <input type="text" id="yap_api_user" name="yap_api_user" value="<?php echo esc_attr($yap_api_user)?>" />
          <small><?php _e( 'YAP-soap-rajapinnan Authheader=>Username', 'pmpro' ); ?></small>
        </td>
      </tr>
      <tr>
        <th scope="row" valign="top"> 
          <label for="yap_api_password"><?php _e('YAP-api salasana', 'pmpro');?>:</label>
        </th>
        <td>
          <input type="text" id="yap_api_password" name="yap_api_password" value="<?php echo esc_attr($yap_api_password)?>"/>
          <small><?php _e( 'YAP-soap-rajapinnan Authheader=>Password', 'pmpro' ); ?></small>
        </td>
      </tr>
      <tr>
        <th scope="row" valign="top"> 
          <label for="yap_membership"><?php _e('YAP-tilauksen ID', 'pmpro');?>:</label>
        </th>
        <td>
          <input type="text" id="yap_membership" name="yap_membership" value="<?php echo esc_attr($yap_membership)?>"/>
          <small><?php _e( 'PMPro Tilaajatason ID', 'pmpro' ); ?></small>
        </td>
      </tr>
    </tbody>
    </table>            
    <p class="submit">            
      <input name="savesettings" type="submit" class="button-primary" value="<?php _e('Save Settings', 'pmpro');?>" />                          
    </p>             
  </form>
    
<?php
  require_once(dirname(__FILE__) . "/admin_footer.php");  
?>
