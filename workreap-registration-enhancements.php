<?php
/**
 * Plugin Name: Workreap Registration Enhancements
 * Description: Enhances the Workreap registration form with MailPoet lists, additional fields, validation, and admin interface.
 * Version: 1.5
 * Author: Fameidols
 */
if (!defined('ABSPATH')) exit;

class WRep_Registration_Enhancements {
    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'inject_fields'], 20);
        add_action('wp_ajax_nopriv_workreap_registeration', [$this, 'workreap_registeration']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function enqueue_assets() {
        if (!is_admin() && is_page('register')) {
            wp_enqueue_script('jquery');
            wp_add_inline_style('wp-block-library', "
                table.wrep-table { border-collapse: collapse; width: 100%; }
                table.wrep-table th, table.wrep-table td { border: 1px solid #ccc; padding: 6px; }
            ");
        }
    }

    public function inject_fields() {
        if (is_admin() || !is_page('register')) return;
        ?>
        <script>
        jQuery(function($){
            var $form = $('form.wr-themeform.user-registration-form, form#userregistration-from');
            if (!$form.length) return;
            var $terms = $form.find('div.wr-checkterm');
            if (!$terms.length) return;
            var html = '<div class="form-group">  <label for="phone_number">WhatsApp Number</label>  <input type="text" id="phone_number" name="user_registration[phone_number]" class="form-control" required placeholder="+2348055405462" pattern="^\\+\\d+" /></div><div class="form-group">  <label for="country">Country</label>  <select id="country" name="user_registration[country]" class="form-control" required>    <option value="">Select country</option>  </select></div><div class="form-group">  <label for="referral">How did you hear about us?</label>  <select id="referral" name="user_registration[referral]" class="form-control" required>    <option value="">Select option</option>  </select></div><div class="form-group">  <label>Subscribe to MailPoet lists</label>  <div><label><input type="checkbox" name="mailpoet_list[]" value="5" /> List #5</label></div>  <div><label><input type="checkbox" name="mailpoet_list[]" value="3" /> List #3</label></div></div>';
            $terms.before(html);
            $.getJSON('<?php echo esc_url(rest_url("wrep/v1/settings")); ?>', function(data){
                data.countries.forEach(function(c){
                    $('#country').append($('<option>').val(c).text(c));
                });
                data.referrals.forEach(function(r){
                    $('#referral').append($('<option>').val(r).text(r));
                });
            });
        });
        </script>
        <?php
    }

    public function workreap_registeration() {
        global $workreap_settings;
    
        if (function_exists('workreap_is_demo_site')) {
            workreap_is_demo_site();
        }
    
        $json = array();
        $message = esc_html__('Oops!', 'workreap');
    
        // Check POST data
        $post_data = !empty($_POST['data']) ? $_POST['data'] : '';
        parse_str($post_data, $output);
    
        // Security nonce check
        $do_check = check_ajax_referer('ajax_nonce', 'security', false);
        if ($do_check == false) {
            wp_send_json(array(
                'type' => 'error',
                'message' => esc_html__('Registration', 'workreap'),
                'message_desc' => esc_html__('Security checks failed', 'workreap'),
            ));
        }
    
        // Custom validation: one-by-one
        $first_name     = trim($output['user_registration']['first_name'] ?? '');
        $last_name      = trim($output['user_registration']['last_name'] ?? '');
        $username       = trim($output['user_registration']['user_name'] ?? '');
        $email          = trim($output['user_registration']['user_email'] ?? '');
        $password       = $output['user_registration']['user_password'] ?? '';
        $user_type      = $output['user_registration']['user_type'] ?? '';
        $phone          = trim($output['user_registration']['phone_number'] ?? '');
        $country        = trim($output['user_registration']['country'] ?? '');
        $referral       = trim($output['user_registration']['referral'] ?? '');
        $mailpoet       = $output['mailpoet_list'] ?? array();
        $terms          = $output['user_registration']['user_agree_terms'] ?? '';
    
        if (empty($first_name)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please enter your first name.'));
        }
    
        if (empty($last_name)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please enter your last name.'));
        }
    
        if (empty($username)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please choose a username.'));
        }
    
        if (empty($email) || !is_email($email)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please enter a valid email address.'));
        }
    
        if (empty($password) || strlen($password) < 6) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Password must be at least 6 characters long.'));
        }
    
        if (empty($user_type)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please select a user type.'));
        }
    
        if (empty($phone)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please enter your WhatsApp number.'));
        } elseif (!preg_match('/^\+\d{6,}$/', $phone)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'WhatsApp number must include country code (e.g. +234...)'));
        }
    
        if (empty($country)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please select your country.'));
        }
    
        if (empty($referral)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please select how you heard about us.'));
        }
    
        if (empty($mailpoet) || !is_array($mailpoet)) {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'Please select at least one MailPoet list.'));
        }
    
        if (empty($terms) || $terms !== 'yes') {
            wp_send_json(array('type' => 'error', 'message' => 'Validation Failed', 'message_desc' => 'You must agree to the Terms and Conditions.'));
        }
    
        // reCAPTCHA Check
        if (!empty($workreap_settings['enable_recaptcha'])) {
            if (!empty($output['recaptcha_response'])) {
                $recaptcha_secret = $workreap_settings['recaptcha_secret_key'] ?? '';
                $recaptcha_response = sanitize_text_field($output['recaptcha_response']);
    
                $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
                    'body' => array(
                        'secret'   => $recaptcha_secret,
                        'response' => $recaptcha_response,
                        'remoteip' => $_SERVER['REMOTE_ADDR'],
                    )
                ));
    
                $response_body = wp_remote_retrieve_body($response);
                $result = json_decode($response_body);
    
                if (empty($result->success) || !$result->success || $result->score < 0.5) {
                    wp_send_json(array(
                        'type' => 'error',
                        'loggedin' => false,
                        'message' => $message,
                        'message_desc' => esc_html__('reCAPTCHA verification failed. Please try again.', 'workreap'),
                    ));
                }
            } else {
                wp_send_json(array(
                    'type' => 'error',
                    'loggedin' => false,
                    'message' => $message,
                    'message_desc' => esc_html__('reCAPTCHA verification failed. Please try again.', 'workreap'),
                ));
            }
        }
    
        workreapRegistration($output);
    }
    
    

    public function add_admin_menu() {
        add_menu_page('Registration Data','Registration Data','manage_options','wrep-registration-data',[$this,'settings_page'],'dashicons-admin-users');
    }

    public function register_settings() {
        register_setting('wrep_settings','wrep_countries');
        register_setting('wrep_settings','wrep_referrals');
    }

    public function settings_page() {
        if (isset($_POST['wrep_countries'])) update_option('wrep_countries',array_map('sanitize_text_field',explode("\n",trim($_POST['wrep_countries']))));
        if (isset($_POST['wrep_referrals'])) update_option('wrep_referrals',array_map('sanitize_text_field',explode("\n",trim($_POST['wrep_referrals']))));
        $countries=get_option('wrep_countries',[]); $referrals=get_option('wrep_referrals',[]);
        global $wpdb;
        $search = $_GET['s_email']??''; $paged = max(1,intval($_GET['paged']??1));
        $args=['orderby'=>'registered','order'=>'DESC','number'=>25,'paged'=>$paged];
        if ($search) $args['search']='*'.esc_attr($search).'*';
        $uq=new WP_User_Query($args);
        ?>
        <div class="wrap"><h1>Registration Data (v1.5)</h1>
        <form method="post"><h2>Country Options</h2><textarea name="wrep_countries" rows="5" cols="50"><?php echo esc_textarea(implode("\n",$countries));?></textarea>
        <h2>Referral Options</h2><textarea name="wrep_referrals" rows="5" cols="50"><?php echo esc_textarea(implode("\n",$referrals));?></textarea>
        <p><button class="button button-primary">Save Settings</button></p></form><hr>
        <h2>Summary</h2><h3>By Country</h3><ul><?php foreach($countries as $c){$cnt=$wpdb->get_var($wpdb->prepare("SELECT COUNT(user_id) FROM $wpdb->usermeta WHERE meta_key='country' AND meta_value=%s",$c)); echo "<li>".esc_html($c).": $cnt</li>";}?></ul>
        <h3>By Referral</h3><ul><?php foreach($referrals as $r){$cnt=$wpdb->get_var($wpdb->prepare("SELECT COUNT(user_id) FROM $wpdb->usermeta WHERE meta_key='referral' AND meta_value=%s",$r)); echo "<li>".esc_html($r).": $cnt</li>";}?></ul>
        <h2>User Entries</h2><form method="get"><input type="hidden" name="page" value="wrep-registration-data"/><label>Search by email:<input type="email" name="s_email" value="<?php echo esc_attr($search);?>"/></label><button class="button">Search</button></form>
        <table class="wrep-table"><thead><tr><th>#</th><th>Email</th><th>Country</th><th>Referral</th><th>WhatsApp</th></tr></thead><tbody>
        <?php $start=($paged-1)*25; $i=0; foreach($uq->get_results() as $user){ $i++; $sn=$start+$i; $email=$user->user_email; $co=get_user_meta($user->ID,'country',true); $ref=get_user_meta($user->ID,'referral',true); $ph=get_user_meta($user->ID,'phone_number',true); $clean=preg_replace('/\D/','',$ph); $link="<a href='https://wa.me/".$clean."' target='_blank'>".esc_html($ph)."</a>"; echo "<tr><td>$sn</td><td>".esc_html($email)."</td><td>".esc_html($co)."</td><td>".esc_html($ref)."</td><td>$link</td></tr>";}?>
        </tbody></table>
        <?php echo paginate_links(['base'=>add_query_arg('paged','%#%'),'format'=>'?paged=%#%','current'=>$paged,'total'=>$uq->max_num_pages,'prev_text'=>'«','next_text'=>'»']);?>
        </div>
        <?php
    }
}
new WRep_Registration_Enhancements();

add_action('rest_api_init', function(){
    register_rest_route('wrep/v1','/settings',['methods'=>'GET','callback'=>function(){
        return ['countries'=>get_option('wrep_countries',[]),'referrals'=>get_option('wrep_referrals',[])];
    }]);
});
