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
        add_action('wp_ajax_nopriv_wr_register_user', [$this, 'handle_registration']);
        add_action('wp_ajax_wr_register_user', [$this, 'handle_registration']);
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
            $form.on('submit', function(e){
                var phone = $('#phone_number').val();
                if (!/^\+\d+/.test(phone)) { alert('WhatsApp Number must include country code'); e.preventDefault(); return; }
                if (!$('#country').val()) { alert('Please select a country'); e.preventDefault(); return; }
                if (!$('#referral').val()) { alert('Please select how you heard about us'); e.preventDefault(); return; }
                if (!$('input[name="mailpoet_list[]"]:checked').length) { alert('Please select at least one mailing list'); e.preventDefault(); return; }
            });
        });
        </script>
        <?php
    }

    public function handle_registration() {
        $errors=[]; $d = $_POST['user_registration'] ?? [];
        if (empty($d['phone_number'])||!preg_match('/^\+\d+/',$d['phone_number'])) $errors[]='WhatsApp Number must include country code';
        if (empty($d['country'])) $errors[]='Please select a country';
        if (empty($d['referral'])) $errors[]='Please select how you heard about us';
        $lists = $_POST['mailpoet_list'] ?? [];
        if (empty($lists)) $errors[]='Please select at least one mailing list';
        if ($errors) wp_send_json_error($errors);
        $uid=wp_create_user($d['user_login'],$d['user_pass'],$d['user_email']);
        if (is_wp_error($uid)) wp_send_json_error($uid->get_error_messages());
        update_user_meta($uid,'phone_number',sanitize_text_field($d['phone_number']));
        update_user_meta($uid,'country',sanitize_text_field($d['country']));
        update_user_meta($uid,'referral',sanitize_text_field($d['referral']));
        if (class_exists('\MailPoet\API\API')) {
            \MailPoet\API\API::MP('v1')->addSubscriber([
                'email'=>$d['user_email'],'first_name'=>$d['user_first'],'last_name'=>$d['user_last'],
                'lists'=>array_map('intval',$lists),'send_confirmation_email'=>false,
            ]);
        }
        wp_send_json_success();
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
