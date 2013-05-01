<?php
/*
Plugin Name: Gravity Forms iContact Add-On
Plugin URI: http://www.seodenver.com/icontact/
Description: Integrates Gravity Forms with iContact allowing form submissions to be automatically sent to your iContact account
Version: 1.3.1
Author: Katz Web Services, Inc.
Author URI: http://www.katzwebservices.com

------------------------------------------------------------------------
Copyright 2011 Katz Web Services, Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

add_action('init',  array('GFiContact', 'init'));
register_activation_hook( __FILE__, array("GFiContact", "add_permissions"));

class GFiContact {

    private static $path = "gravity-forms-icontact/icontact.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravity-forms-icontact";
    private static $version = "1.3.1";
    private static $min_gravityforms_version = "1.3.9";

    //Plugin starting point. Will load appropriate files
    public static function init(){
        global $pagenow;
        if($pagenow == 'plugins.php' || defined('RG_CURRENT_PAGE') && RG_CURRENT_PAGE == "plugins.php"){
            //loading translations
            load_plugin_textdomain('gravity-forms-icontact', FALSE, '/gravity-forms-icontact/languages' );

            add_action('after_plugin_row_' . self::$path, array('GFiContact', 'plugin_row') );

            add_filter('plugin_action_links', array('GFiContact', 'settings_link'), 10, 2 );

        }

        if(!self::is_gravityforms_supported()){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('gravity-forms-icontact', FALSE, '/gravity-forms-icontact/languages' );

            add_filter("transient_update_plugins", array('GFiContact', 'check_update'));
            #add_filter("site_transient_update_plugins", array('GFiContact', 'check_update'));

            //creates a new Settings page on Gravity Forms' settings screen
            if(self::has_access("gravityforms_icontact")){
                RGForms::add_settings_page("iContact", array("GFiContact", "settings_page"), self::get_base_url() . "/images/icontact_wordpress_icon_32.png");
            }
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFiContact", "members_get_capabilities"));

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFiContact', 'create_menu'));

        if(self::is_icontact_page()){

            //enqueueing sack for AJAX requests
            wp_enqueue_script(array("sack"));

            //loading data lib
            require_once(self::get_base_path() . "/data.php");


            //loading Gravity Forms tooltips
            require_once(GFCommon::get_base_path() . "/tooltips.php");
            add_filter('gform_tooltips', array('GFiContact', 'tooltips'));

            //runs the setup when version changes
            self::setup();

         }
         else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

            //loading data class
            require_once(self::get_base_path() . "/data.php");

            add_action('wp_ajax_rg_update_feed_active', array('GFiContact', 'update_feed_active'));
            add_action('wp_ajax_gf_select_icontact_form', array('GFiContact', 'select_icontact_form'));

        }
        else{
             //handling post submission.
            add_action("gform_post_submission", array('GFiContact', 'export'), 10, 2);
        }

        add_action('gform_entry_info', array('GFiContact', 'entry_info_link_to_icontact'), 10, 2);
    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFiContactData::get_feed($id);
        GFiContactData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //--------------   Automatic upgrade ---------------------------------------------------

    public static function plugin_row(){
        if(!self::is_gravityforms_supported()){
            $message = sprintf(__("%sGravity Forms%s is required. Activate it now or %spurchase it today!%s"), "<a href='http://wordpressformplugin.com/?r=icontact'>", "</a>", "<a href='http://wordpressformplugin.com/?r=icontact'>", "</a>");
            self::display_plugin_message($message, true);
        }
    }

    function settings_link( $links, $file ) {
        static $this_plugin;
        if( ! $this_plugin ) $this_plugin = self::get_base_url();
        if ( $file == $this_plugin ) {
            $settings_link = '<a href="' . admin_url( 'admin.php?page=gf_icontact' ) . '" title="' . __('Select the Gravity Form you would like to integrate with iContact. Contacts generated by this form will be automatically added to your iContact account.', 'gravity-forms-icontact') . '">' . __('Feeds', 'gravity-forms-icontact') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
            $settings_link = '<a href="' . admin_url( 'admin.php?page=gf_settings&addon=iContact' ) . '" title="' . __('Configure your iContact settings.', 'gravity-forms-icontact') . '">' . __('Settings', 'gravity-forms-icontact') . '</a>';
            array_unshift( $links, $settings_link ); // before other links
        }
        return $links;
    }

    public static function display_plugin_message($message, $is_error = false){
        $style = '';
        if($is_error)
            $style = 'style="background-color: #ffebe8;"';

        echo '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" ' . $style . '>' . $message . '</div></td>';
    }


    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_icontact_page(){
        global $plugin_page; $current_page = '';
        $icontact_pages = array("gf_icontact");

        if(isset($_GET['page'])) {
            $current_page = trim(strtolower($_GET["page"]));
        }

        return (in_array($plugin_page, $icontact_pages) || in_array($current_page, $icontact_pages));
    }


    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_option("gf_icontact_version") != self::$version)
            GFiContactData::update_table();

        update_option("gf_icontact_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $icontact_tooltips = array(
            "icontact_contact_list" => "<h6>" . __("iContact List", "gravity-forms-icontact") . "</h6>" . __("Select the iContact list you would like to add your contacts to.", "gravity-forms-icontact"),
            "icontact_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-icontact") . "</h6>" . __("Select the Gravity Form you would like to integrate with iContact. Contacts generated by this form will be automatically added to your iContact account.", "gravity-forms-icontact"),
            "icontact_map_fields" => "<h6>" . __("Map Standard Fields", "gravity-forms-icontact") . "</h6>" . __("Associate your iContact fields to the appropriate Gravity Form fields by selecting.", "gravity-forms-icontact"),
            "icontact_optin_condition" => "<h6>" . __("Opt-In Condition", "gravity-forms-icontact") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to iContact when the condition is met. When disabled all form submissions will be exported.", "gravity-forms-icontact"),

        );
        return array_merge($tooltips, $icontact_tooltips);
    }

    //Creates iContact left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_icontact");
        if(!empty($permission))
            $menus[] = array("name" => "gf_icontact", "label" => __("iContact", "gravity-forms-icontact"), "callback" =>  array("GFiContact", "icontact_page"), "permission" => $permission);

        return $menus;
    }

    public static function settings_page(){


        if(isset($_POST["uninstall"])){
            check_admin_referer("uninstall", "gf_icontact_uninstall");
            self::uninstall();

            ?>
            <div class="updated fade" style="padding:20px;"><?php _e(sprintf("Gravity Forms iContact Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "gravity-forms-icontact")?></div>
            <?php
            return;
        }
        else if(isset($_POST["gf_icontact_submit"])){
            check_admin_referer("update", "gf_icontact_update");
            $settings = array(
                "username" => stripslashes($_POST["gf_icontact_username"]),
                "password" => stripslashes($_POST["gf_icontact_password"]),
                "appid" => stripslashes($_POST["gf_icontact_appid"]),
                "debug" => isset($_POST["gf_icontact_debug"]),
                "sandbox" => isset($_POST["gf_icontact_sandbox"]),
                "sandbox-username" => stripslashes($_POST["gf_icontact_sandbox_username"]),
                "sandbox-password" => stripslashes($_POST["gf_icontact_sandbox_password"]),
                "sandbox-appid" => stripslashes($_POST["gf_icontact_sandbox_appid"]),
            );
            update_option("gf_icontact_settings", $settings);
        }
        else{
            $settings = get_option("gf_icontact_settings");
        }

        $settings = wp_parse_args($settings, array(
                "username" => '',
                "password" => '',
                'appid' => '',
                "debug" => false,
                "sandbox" => false,
                "sandbox-username" => '',
                "sandbox-password" => '',
                'sandbox-appid' => '',
            ));

        $api = self::get_api();

        $message = ''; $style = '';
        if(!empty($settings["username"]) && !empty($settings["password"]) && empty($api->lastError)){
            $message = sprintf(__("Valid username and API key. Now go %sconfigure form integration with iContact%s!", "gravity-forms-icontact"), '<a href="'.admin_url('admin.php?page=gf_icontact').'">', '</a>');
            $class = "updated valid_credentials";
            $valid = true;
        } else if(!empty($settings["username"]) || !empty($settings["password"])){
            $message = $api->lastError;
            $valid = false;
            $class = "error invalid_credentials";
        } else if (empty($settings["username"]) && empty($settings["password"])) {
            $message = sprintf(__('<div style="max-width: 800px; border-bottom:1px solid #ddd; margin-bottom:10px; padding-bottom:10px;" class="wrap"><h2><a href="http://katz.si/icontact"><img src="'.plugins_url('images/icontact-logo.gif', __FILE__).'" width="165" height="67" class="alignright" /></a>%s</h2>%s<div class="clear"></div></div><div class="clear"></div>', "gravity-forms-icontact"), __('This plugin requires an iContact account.', 'gravity-forms-icontact'), __('<p style="font-size:1.3em; line-height:1.3; font-weight:200;">In order to integrate this plugin with Gravity Forms, you need an iContact account.</p><p style="font-size:1.3em; line-height:1.3; font-weight:200;">iContact is <em>the</em> email marketing solution to grow your business. Regardless of skill level, you can experience the difference. <a href="http://katz.si/icontact">Sign up for a free iContact account now!</a></p>', 'gravity-forms-icontact'));
            $valid = false;
            $class = '';
            $style = '';
        }

        if($message) {
            $message = str_replace('Api', 'API', $message);
            ?>
            <div id="message" class="<?php echo $class ?>" style="<?php echo $style ?>"><?php echo wpautop($message); ?></div>
            <?php
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                $('#gf_icontact_sandbox').live('ready click change', function(e) {
                    var time = 'normal';
                    // Show immediately on load; no fade-in
                    if(e.type === 'ready') { time = 0; }
                    if($(this).is(':checked')) {
                        $('tr.sandbox').fadeIn(time);
                    } else {
                        $('tr.sandbox').fadeOut(time);
                    }
                }).trigger('ready');
            });
        </script>
        <form method="post" action="<?php echo remove_query_arg('refresh'); ?>">
            <?php wp_nonce_field("update", "gf_icontact_update") ?>
            <?php if(!$valid)  { ?>
            <div class="delete-alert alert_gray">
                <h4><?php _e(sprintf('If you have issues with these steps, please %scontact iContact%s by calling (877) 820-7837 in the US or (919) 957-6150.', '<a href="http://www.icontact.com/contact">', '</a>'), "gravity-forms-icontact"); ?></h4>
                <h3><?php _e('How to set up integration:', "gravity-forms-icontact"); ?></h3>
                <ol class="ol-decimal" style="margin-top:1em;">
                    <li style="list-style:decimal outside;"><?php echo sprintf(__('%sFollow this link to Register an Application%s', "gravity-forms-icontact"), '<a href="https://app.icontact.com/icp/core/registerapp" target="_blank">', '</a>', "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e('If necessary, enter your iContact username and password to log in to iContact.', "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e(sprintf('Set the Application Name and Description to %siContact Gravity Forms Add-on%s. Submit the form by clicking the button "Get App ID."','<em>', '</em>'), "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e('Click the link on the bottom of the next page that says "To authenticate to the API, you must enable this AppId for your account."', "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e('Copy the Application ID - you&rsquo;ll be entering it on this page.', "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e(sprintf('Enter a password that is not your iContact password. %sCopy this password%s - you&rsquo;ll be entering it on this page.','<strong>','</strong>'), "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e('Click Save.', "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e('You should see the message \'The application "iContact Gravity Forms Add-On" can now access your account, using the password you provided.\'', "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e('Come back to this settings page and enter your Application ID and Application Password that you copied from the steps above.', "gravity-forms-icontact") ?></li>
                    <li style="list-style:decimal outside;"><?php _e('Save these settings, and you should be done!', "gravity-forms-icontact") ?></li>
                </ol>
            </div>
            <?php } ?>
            <h3><?php _e("iContact Account Information", "gravity-forms-icontact") ?></h3>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="gf_icontact_username"><?php _e("iContact Username", "gravity-forms-icontact"); ?></label> </th>
                    <td><input type="text" id="gf_icontact_username" name="gf_icontact_username" size="30" value="<?php echo empty($settings["username"]) ? '' : esc_attr($settings["username"]); ?>"/></td>
                </tr>

                <tr>
                    <th scope="row"><label for="gf_icontact_appid"><?php _e("Application ID", "gravity-forms-icontact"); ?></label> </th>
                    <td><input type="text" class="code" id="gf_icontact_appid" name="gf_icontact_appid" size="40" value="<?php echo !empty($settings["appid"]) ? esc_attr($settings["appid"]) : ''; ?>"/></td>
                </tr>

                <tr>
                    <th scope="row"><label for="gf_icontact_password"><?php _e("Application Password", "gravity-forms-icontact"); ?></label> </th>
                    <td><input type="password" class="code" id="gf_icontact_password" name="gf_icontact_password" size="40" value="<?php echo !empty($settings["password"]) ? esc_attr($settings["password"]) : ''; ?>"/></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_icontact_debug"><?php _e("Debug Form Submissions for Administrators", "gravity-forms-icontact"); ?></label> </th>
                    <td><input type="checkbox" id="gf_icontact_debug" name="gf_icontact_debug" size="40" value="1" <?php checked($settings["debug"], true); ?>/> <span class="howto"><?php _e('This will show you the information being passed to iContact when a form is submitted.', 'gravity-forms-icontact'); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><label for="gf_icontact_sandbox"><?php _e("Use the iContact Sandbox", "gravity-forms-icontact"); ?></label> </th>
                    <td><label for="gf_icontact_sandbox"><input type="checkbox" id="gf_icontact_sandbox" name="gf_icontact_sandbox" size="40" value="1" <?php checked($settings["sandbox"], true); ?>/> <span class="howto"><?php _e('The Sandbox is recommended as the starting place for new development projects (There&rsquo;s no chance of accidentally spamming your customers here).', 'gravity-forms-icontact'); ?></span></label>
</td>
                </tr>
                <tr class="sandbox">
                    <th scope="row"><label for="gf_icontact_sandbox_username"><?php _e("iContact Sandbox Username", "gravity-forms-icontact"); ?></label> </th>
                    <td><input type="text" id="gf_icontact_sandbox_username" name="gf_icontact_sandbox_username" size="30" value="<?php echo empty($settings["sandbox-username"]) ? '' : esc_attr($settings["sandbox-username"]); ?>"/></td>
                </tr>

                <tr class="sandbox">
                    <th scope="row"><label for="gf_icontact_sandbox_appid"><?php _e("Sandbox Application ID", "gravity-forms-icontact"); ?></label> </th>
                    <td><input type="text" class="code" id="gf_icontact_sandbox_appid" name="gf_icontact_sandbox_appid" size="40" value="<?php echo !empty($settings["sandbox-appid"]) ? esc_attr($settings["sandbox-appid"]) : ''; ?>"/></td>
                </tr>

                <tr class="sandbox">
                    <th scope="row"><label for="gf_icontact_sandbox_password"><?php _e("Sandbox Application Password", "gravity-forms-icontact"); ?></label> </th>
                    <td><input type="password" class="code" id="gf_icontact_sandbox_password" name="gf_icontact_sandbox_password" size="40" value="<?php echo !empty($settings["sandbox-password"]) ? esc_attr($settings["sandbox-password"]) : ''; ?>"/></td>
                </tr>
                <tr>
                    <td colspan="2" ><input type="submit" name="gf_icontact_submit" class="button-primary" value="<?php _e("Save Settings", "gravity-forms-icontact") ?>" /></td>
                </tr>

            </table>
            <div>

            </div>
        </form>

        <form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_icontact_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("gravityforms_icontact_uninstall")){ ?>
                <div class="hr-divider"></div>

                <h3><?php _e("Uninstall iContact Add-On", "gravity-forms-icontact") ?></h3>
                <div class="delete-alert"><?php _e("Warning! This operation deletes ALL iContact Feeds.", "gravity-forms-icontact") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . __("Uninstall iContact Add-On", "gravity-forms-icontact") . '" class="button" onclick="return confirm(\'' . __("Warning! ALL iContact Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "gravity-forms-icontact") . '\');"/>';
                    echo apply_filters("gform_icontact_uninstall_button", $uninstall_button);
                    ?>
                </div>
            <?php } ?>
        </form>
        <?php
    }

    public static function icontact_page(){
        $view = isset($_GET["view"]) ? $_GET["view"] : '';
        if($view == "edit")
            self::edit_page($_GET["id"]);
        else
            self::list_page();
    }

    //Displays the iContact feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("The iContact Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-icontact"));
        }

        if(isset($_POST["action"]) && $_POST["action"] == "delete"){
            check_admin_referer("list_action", "gf_icontact_list");

            $id = absint($_POST["action_argument"]);
            GFiContactData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-icontact") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_icontact_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFiContactData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-icontact") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            <img alt="<?php _e("iContact Feeds", "gravity-forms-icontact") ?>" src="<?php echo self::get_base_url()?>/images/icontact_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php _e("iContact Feeds", "gravity-forms-icontact"); ?>
            <a class="button add-new-h2" href="admin.php?page=gf_icontact&view=edit&id=0"><?php _e("Add New", "gravity-forms-icontact") ?></a>
            </h2>

            <div class="updated" id="message" style="margin-top:20px;">
                <p><?php _e('Do you like this free plugin? <a href="http://katz.si/rategfic">Please review it on WordPress.org</a>! <small class="description alignright">Note: You must be logged in to WordPress.org to leave a review!</small>', 'gravity-forms-icontact'); ?></p>
            </div>

            <ul class="subsubsub" style="margin-top:0;">
                <li><a href="<?php echo admin_url('admin.php?page=gf_settings&addon=iContact'); ?>"><?php _e('iContact Settings', 'gravity-forms-icontact'); ?></a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=gf_icontact'); ?>" class="current"><?php _e('iContact Feeds', 'gravity-forms-icontact'); ?></a></li>
            </ul>

            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_icontact_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px; 0">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravity-forms-icontact") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravity-forms-icontact") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravity-forms-icontact") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-icontact") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-icontact") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-icontact") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-icontact") ?></th>
                            <th scope="col" class="manage-column"><?php _e("iContact Lists", "gravity-forms-icontact") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-icontact") ?></th>
                            <th scope="col" class="manage-column"><?php _e("iContact Lists", "gravity-forms-icontact") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $settings = GFiContactData::get_feeds();
                        if(is_array($settings) && !empty($settings)){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-icontact") : __("Inactive", "gravity-forms-icontact");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-icontact") : __("Inactive", "gravity-forms-icontact");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_icontact&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-icontact") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a title="Edit this setting" href="admin.php?page=gf_icontact&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-icontact") ?>"><?php _e("Edit", "gravity-forms-icontact") ?></a>
                                            |
                                            </span>

                                            <span class="edit">
                                            <a title="<?php _e("Delete", "gravity-forms-icontact") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-icontact") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-icontact") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-icontact")?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-name" style="width:40%"><ul class="ul-disc"><li><?php echo implode('</li><li>', explode(',', esc_html($setting["meta"]["contact_list_name"]))) ?></li></ul></td>
                                </tr>
                                <?php
                            }
                        }
                        else {
                            $api = self::get_api();
                            if(!empty($api) && empty($api->lastError)){
                                ?>
                                <tr>
                                    <td colspan="4" style="padding:20px;">
                                        <?php _e(sprintf("You don't have any iContact feeds configured. Let's go %screate one%s!", '<a href="'.admin_url('admin.php?page=gf_icontact&view=edit&id=0').'">', "</a>"), "gravity-forms-icontact"); ?>
                                    </td>
                                </tr>
                                <?php
                            }
                            else{
                                ?>
                                <tr>
                                    <td colspan="4" style="padding:20px;">
                                        <?php _e(sprintf("To get started, please configure your %siContact Settings%s.", '<a href="admin.php?page=gf_settings&addon=iContact">', "</a>"), "gravity-forms-icontact"); ?>
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
        //<![CDATA[
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravity-forms-icontact") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-icontact") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravity-forms-icontact") ?>').attr('alt', '<?php _e("Active", "gravity-forms-icontact") ?>');
                }

                var mysack = new sack("<?php echo admin_url("admin-ajax.php")?>" );
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "rg_update_feed_active" );
                mysack.setVar( "rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravity-forms-icontact" ) ?>' )};
                mysack.runAJAX();

                return true;
            }
//]]>
</script>
        <?php
    }

    private static function get_api(){
        if(!class_exists("iContact"))
            require_once("api/iContact.class.php");

        return new iContact();
    }

    private static function edit_page(){
        ?>
        <style type="text/css">
            label span.howto { cursor: default; }
            .icontact_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold; width:50%;}
            #icontact_field_list table { width: 400px; border-collapse: collapse; margin-top: 1em; }
            .icontact_field_cell {padding: 6px 17px 0 0; margin-right:15px;}
            .gfield_required{color:red;}

            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px; padding-right: 20px;}
            #icontact_field_list .left_header { margin-top: 1em; }
            .margin_vertical_10{margin: 20px 0;}
            #gf_icontact_list_list { margin-left:220px; padding-top: 1px }
            #icontact_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <img alt="<?php _e("iContact Feeds", "gravity-forms-icontact") ?>" src="<?php echo self::get_base_url()?>/images/icontact_wordpress_icon_32.png" style="float:left; margin:15px 7px 0 0;"/>
            <h2><?php _e("iContact Feeds", "gravity-forms-icontact"); ?></h2>
            <ul class="subsubsub">
                <li><a href="<?php echo admin_url('admin.php?page=gf_settings&addon=iContact'); ?>">iContact Settings</a> |</li>
                <li><a href="<?php echo admin_url('admin.php?page=gf_icontact'); ?>">iContact Feeds</a></li>
            </ul>
        <div class="clear"></div>
        <?php
        //getting iContact API
        $api = self::get_api();

        //ensures valid credentials were entered in the settings page
        if(!empty($api->lastError)){
            ?>
            <div class="error" id="message" style="margin-top:20px;"><?php echo wpautop(sprintf(__("We are unable to login to iContact with the provided username and API key. Please make sure they are valid in the %sSettings Page%s", "gravity-forms-icontact"), "<a href='?page=gf_settings&addon=iContact'>", "</a>")); ?></div>
            <?php
            return;
        }

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["icontact_setting_id"]) ? $_POST["icontact_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFiContactData::get_feed($id);


        //getting merge vars
        $merge_vars = array();

        //updating meta information
        if(isset($_POST["gf_icontact_submit"])){
            $list_ids = $list_names = array();
            foreach($_POST["gf_icontact_list"] as $list){
                list($list_id, $list_name) = explode("|:|", stripslashes($list));
                $list_ids[] = $list_id;
                $list_names[] =  str_replace(',', '&#44;', $list_name); // To keep commas for explode, we convert them to HTML
            }

            $config["meta"]["contact_list_id"] = empty($list_ids) ? 0 : implode(',', $list_ids);
            $config["meta"]["contact_list_name"] = implode(',', $list_names);
            $config["form_id"] = absint($_POST["gf_icontact_form"]);

            $is_valid = true;
            $merge_vars = self::listMergeVars();

            $field_map = array();

            foreach($merge_vars as $var){
                $field_name = "icontact_map_field_" . $var['tag'];
                $mapped_field = isset($_POST[$field_name]) ? stripslashes($_POST[$field_name]) : '';
                if(!empty($mapped_field)){
                    $field_map[$var['tag']] = $mapped_field;
                }
                else{
                    unset($field_map[$var['tag']]);
                    if(!empty($var["req"])) {
                        $is_valid = false;
                    }
                }
                unset($_POST["{$field_name}"]);
            }

            // Go through the items that were not in the field map;
            // the Custom Fields
            foreach($_POST as $k => $v) {
                if(preg_match('/icontact\_map\_field\_/', $k)) {
                    $tag = str_replace('icontact_map_field_', '', $k);
                    $field_map[$tag] = stripslashes($_POST[$k]);
                }
            }

            $config["meta"]["field_map"] = $field_map;
            #$config["meta"]["double_optin"] = !empty($_POST["icontact_double_optin"]) ? true : false;
            #$config["meta"]["welcome_email"] = !empty($_POST["icontact_welcome_email"]) ? true : false;

            $config["meta"]["optin_enabled"] = !empty($_POST["icontact_optin_enable"]) ? true : false;
            $config["meta"]["optin_field_id"] = $config["meta"]["optin_enabled"] ? isset($_POST["icontact_optin_field_id"]) ? $_POST["icontact_optin_field_id"] : '' : "";
            $config["meta"]["optin_operator"] = $config["meta"]["optin_enabled"] ? isset($_POST["icontact_optin_operator"]) ? $_POST["icontact_optin_operator"] : '' : "";
            $config["meta"]["optin_value"] = $config["meta"]["optin_enabled"] ? $_POST["icontact_optin_value"] : "";



            if($is_valid){
                $id = GFiContactData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div id="message" class="updated fade" style="margin-top:10px;"><p><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-icontact"), "<a href='?page=gf_icontact'>", "</a>") ?></p>
                    <input type="hidden" name="icontact_setting_id" value="<?php echo $id ?>"/>
                </div>
                <?php
            }
            else{
                ?>
                <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravity-forms-icontact") ?></div>
                <?php
            }
        }
        if(!function_exists('gform_tooltip')) {
            require_once(GFCommon::get_base_path() . "/tooltips.php");
        }

        ?>
        <form method="post" action="<?php echo remove_query_arg('refresh'); ?>">
            <input type="hidden" name="icontact_setting_id" value="<?php echo $id ?>"/>
            <div class="margin_vertical_10">
                <h2><?php _e('1. Select the lists to merge with.', "gravity-forms-icontact"); ?></h2>
                <label for="gf_icontact_list" class="left_header"><?php _e("iContact List", "gravity-forms-icontact"); ?> <?php gform_tooltip("icontact_contact_list") ?> <span class="howto"><?php _e(sprintf("%sRefresh lists%s", '<a href="'.add_query_arg('refresh', 'lists').'">','</a>'), "gravity-forms-icontact"); ?></span></label>

                <?php
                $trans = get_transient('icgf_lists');
                if(!isset($_POST["gf_icontact_submit"]) && (!$trans || ($trans && isset($_REQUEST['refresh']) && $_REQUEST['refresh'] === 'lists'))) { ?>
                    <p id='lists_loading' class="hide-if-no-js" style='padding:5px;'><img src="<?php echo self::get_base_url() ?>/images/loading.gif" id="icontact_wait" style="padding-right:5px;" width="16" height="16" /> <?php _e('Lists are being loaded', 'gravity-forms-icontact'); ?></p>
               <?php
                   }

                //getting all contact lists
                $lists = $api->getLists();

                if (!$lists){
                    echo __("Could not load iContact contact lists. <br/>Error: ", "gravity-forms-icontact");
                    echo isset($api->errorMessage) ? $api->errorMessage : '';
                }
                else{

                    if(isset($config["meta"]["contact_list_id"])) {
                        $contact_lists = explode(',' , $config["meta"]["contact_list_id"]);
                    } else {
                        $contact_lists = array();
                    }
                    ?>
                 <ul id="gf_icontact_list_list" class="hide-if-js">
                    <?php
                    foreach ($lists as $key => $list){
                        $name = $list['name'];
                        $name .= empty($list['publicname']) ? '' : ' ('.$list['publicname'].')';
                        $selected = in_array($list['listId'], $contact_lists) ? "checked='checked'" : "";
                        ?>
                        <li><label style="display:block;" for="gf_icontact_list_<?php echo esc_html($list['listId']); ?>"><input type="checkbox" name="gf_icontact_list[]" id="gf_icontact_list_<?php echo esc_html($list['listId']); ?>" value="<?php echo esc_html($list['listId']) . "|:|" . esc_html($name) ?>" <?php echo $selected ?> /> <?php echo esc_html($name) ?></label></li>
                        <?php
                    }
                    ?>
                  </ul>
                  <script type="text/javascript">
                  //<![CDATA[
                    if(jQuery('#lists_loading').length && jQuery('#gf_icontact_list_list').length) {
                        jQuery('#lists_loading').fadeOut(function() { jQuery('#gf_icontact_list_list').fadeIn(); });
                     } else if(jQuery('#gf_icontact_list_list').length) {
                        jQuery('#gf_icontact_list_list').show();
                     }
                 //]]>
                 </script>
                <?php
                }
                ?>
                <div class="clear"></div>
            </div>
            <?php flush(); ?>
            <div id="icontact_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["contact_list_id"]) ? "style='display:none;'" : "" ?>>
                <h2><?php _e('2. Select the form to tap into.', "gravity-forms-icontact"); ?></h2>
                <?php
                $forms = RGFormsModel::get_forms();

                if(isset($config["form_id"])) {
                    foreach($forms as $form) {
                        if($form->id == $config["form_id"]) {
                            echo '<h3 style="margin:0; padding:0 0 1em 1.75em; font-weight:normal;">'.sprintf(__('(Currently linked with %s)', "gravity-forms-icontact"), $form->title).'</h3>';
                        }
                    }
                }
                ?>
                <label for="gf_icontact_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-icontact"); ?> <?php gform_tooltip("icontact_gravity_form") ?></label>

                <select id="gf_icontact_form" name="gf_icontact_form" onchange="SelectForm(jQuery('#gf_icontact_list_list input').serialize(), jQuery(this).val());">
                <option value=""><?php _e("Select a form", "gravity-forms-icontact"); ?> </option>
                <?php

                foreach($forms as $form){
                    $selected = absint($form->id) == $config["form_id"] ? "selected='selected'" : "";
                    ?>
                    <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                    <?php
                }
                ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFiContact::get_base_url() ?>/images/loading.gif" id="icontact_wait" style="display: none;"/>
            </div>
            <div class="clear"></div>
            <div id="icontact_field_group" valign="top" <?php echo empty($config["meta"]["contact_list_id"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                <div id="icontact_field_container" valign="top" class="margin_vertical_10" >
                    <h2><?php _e('3. Map form fields to iContact fields.', "gravity-forms-icontact"); ?></h2>
                    <h3 class="description"><?php _e('About field mapping:', "gravity-forms-icontact"); ?></h2>
                    <p class="description" style="margin-bottom:1em;"><?php _e(sprintf('%sIf you don&rsquo;t see a field listed, you need to create it in iContact first under %s[Your Name] > Custom Fields%s.%sOnly fields mapped below will be added to iContact.%sCustom Fields are defined inside the iContact application. %sLearn more about using Custom Fields in iContact%s.%s', '<li>', '<em style="font-style:normal;">', '</em>', '</li><li>','</li><li>', '<a href="http://blog.icontact.com/blog/effectively-using-custom-fields-and-segments-for-specific-interests/" target="_blank">', '</a>', '</li>'), "gravity-forms-icontact"); ?></p>
                    <label for="icontact_fields" class="left_header"><?php _e("Standard Fields", "gravity-forms-icontact"); ?> <?php gform_tooltip("icontact_map_fields") ?></label>
                    <div id="icontact_field_list">
                    <?php
                    if(!empty($config["form_id"])){

                        //getting list of all iContact merge variables for the selected contact list
                        if(empty($merge_vars))
                            $merge_vars = self::listMergeVars();

                        //getting field map UI
                        echo self::get_field_mapping($config, $config["form_id"], $merge_vars);

                        //getting list of selection fields to be used by the optin
                        $form_meta = RGFormsModel::get_form_meta($config["form_id"]);
                        $selection_fields = GFCommon::get_selection_fields($form_meta, $config["meta"]["optin_field_id"]);
                    }
                    ?>
                    </div>
                    <div class="clear"></div>
                </div>

                <div id="icontact_optin_container" valign="top" class="margin_vertical_10">
                    <label for="icontact_optin" class="left_header"><?php _e("Opt-In Condition", "gravity-forms-icontact"); ?> <?php gform_tooltip("icontact_optin_condition") ?></label>
                    <div id="icontact_optin">
                        <table>
                            <tr>
                                <td>
                                    <input type="checkbox" id="icontact_optin_enable" name="icontact_optin_enable" value="1" onclick="if(this.checked){jQuery('#icontact_optin_condition_field_container').show('slow');} else{jQuery('#icontact_optin_condition_field_container').hide('slow');}" <?php echo !empty($config["meta"]["optin_enabled"]) ? "checked='checked'" : ""?>/>
                                    <label for="icontact_optin_enable"><?php _e("Enable", "gravity-forms-icontact"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="icontact_optin_condition_field_container" <?php echo empty($config["meta"]["optin_enabled"]) ? "style='display:none'" : ""?>>
                                        <div id="icontact_optin_condition_fields" <?php echo empty($selection_fields) ? "style='display:none'" : ""?>>
                                            <?php _e("Export to iContact if ", "gravity-forms-icontact") ?>

                                            <select id="icontact_optin_field_id" name="icontact_optin_field_id" class='optin_select' onchange='jQuery("#icontact_optin_value").html(GetFieldValues(jQuery(this).val(), "", 20));'><?php echo $selection_fields ?></select>
                                            <select id="icontact_optin_operator" name="icontact_optin_operator" />
                                                <option value="is" <?php echo (isset($config["meta"]["optin_operator"]) && $config["meta"]["optin_operator"] == "is") ? "selected='selected'" : "" ?>><?php _e("is", "gravity-forms-icontact") ?></option>
                                                <option value="isnot" <?php echo (isset($config["meta"]["optin_operator"]) && $config["meta"]["optin_operator"] == "isnot") ? "selected='selected'" : "" ?>><?php _e("is not", "gravity-forms-icontact") ?></option>
                                            </select>
                                            <select id="icontact_optin_value" name="icontact_optin_value" class='optin_select'>
                                            </select>

                                        </div>
                                        <div id="icontact_optin_condition_message" <?php echo !empty($selection_fields) ? "style='display:none'" : ""?>>
                                            <?php _e("To create an Opt-In condition, your form must have a drop down, checkbox or multiple choice field.", "gravityform") ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <script type="text/javascript">
                    //<![CDATA[
                        <?php
                        if(!empty($config["form_id"])){
                            ?>
                            //creating Javascript form object
                            form = <?php echo GFCommon::json_encode($form_meta)?> ;

                            //initializing drop downs
                            jQuery(document).ready(function(){
                                var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["optin_field_id"])?>";
                                var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["optin_value"])?>";
                                SetOptin(selectedField, selectedValue);
                            });
                        <?php
                        }
                        ?>
                   //]]>
                   </script>
                </div>

                <div id="icontact_submit_container" class="margin_vertical_10">
                    <input type="submit" name="gf_icontact_submit" value="<?php echo empty($id) ? __("Save Feed", "gravity-forms-icontact") : __("Update Feed", "gravity-forms-icontact"); ?>" class="button-primary"/>
                </div>
            </div>
        </form>
        </div>

<script type="text/javascript">
//<![CDATA[
    jQuery(document).ready(function($) {
            $('#gf_icontact_list_list').live('load change', function() {
                $('#lists_loading').hide();
            });
            $("#gf_icontact_list_list input").bind('click change', function() {
                if($("#gf_icontact_list_list input:checked").length > 0) {
                    SelectList(1);
                } else {
                    SelectList(false);
                    jQuery("#gf_icontact_form").val("");
                }
            });

    <?php if(isset($_REQUEST['id'])) { ?>
        $('#icontact_field_list').live('load', function() {
            $('.icontact_field_cell select').each(function() {
                var $select = $(this);
                if($().prop) {
                    var label = $.trim($('label[for='+$(this).prop('name')+']').text());
                } else {
                    var label = $.trim($('label[for='+$(this).attr('name')+']').text());
                }
                label = label.replace(' *', '');

                if($select.val() === '') {
                    $('option', $select).each(function() {

                        if($(this).text() === label) {
                            if($().prop) {
                                $(this).prop('selected', true);
                            } else {
                                $(this).attr('selected', true);
                            }
                        }
                    });
                }
            });
        });
    <?php } ?>
    });

        function SelectList(listId){
            if(listId){
                jQuery("#icontact_form_container").slideDown();
               // jQuery("#gf_icontact_form").val("");
            }
            else{
                jQuery("#icontact_form_container").slideUp();
                EndSelectForm("");
            }
        }

        function SelectForm(listId, formId){
            if(!formId){
                jQuery("#icontact_field_group").slideUp();
                return;
            }

            jQuery("#icontact_wait").show();
            jQuery("#icontact_field_group").slideUp();

            var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php" );
            mysack.execute = 1;
            mysack.method = 'POST';
            mysack.setVar( "action", "gf_select_icontact_form" );
            mysack.setVar( "gf_select_icontact_form", "<?php echo wp_create_nonce("gf_select_icontact_form") ?>" );
            mysack.setVar( "list_ids", listId);
            mysack.setVar( "form_id", formId);
            mysack.encVar( "cookie", document.cookie, false );
            mysack.onError = function() {jQuery("#icontact_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravity-forms-icontact") ?>' )};
            mysack.runAJAX();
            return true;
        }

        function SetOptin(selectedField, selectedValue){

            //load form fields
            jQuery("#icontact_optin_field_id").html(GetSelectableFields(selectedField, 20));
            var optinConditionField = jQuery("#icontact_optin_field_id").val();

            if(optinConditionField){
                jQuery("#icontact_optin_condition_message").hide();
                jQuery("#icontact_optin_condition_fields").show();
                jQuery("#icontact_optin_value").html(GetFieldValues(optinConditionField, selectedValue, 20));
            }
            else{
                jQuery("#icontact_optin_condition_message").show();
                jQuery("#icontact_optin_condition_fields").hide();
            }
        }

        function EndSelectForm(fieldList, form_meta){
            //setting global form object
            form = form_meta;

            if(fieldList){

                SetOptin("","");

                jQuery("#icontact_field_list").html(fieldList);
                jQuery("#icontact_field_group").slideDown();
                jQuery('#icontact_field_list').trigger('load');
            }
            else{
                jQuery("#icontact_field_group").slideUp();
                jQuery("#icontact_field_list").html("");
            }
            jQuery("#icontact_wait").hide();
        }

        function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
            if(!fieldId)
                return "";

            var str = "";
            var field = GetFieldById(fieldId);
            if(!field || !field.choices)
                return "";

            var isAnySelected = false;

            for(var i=0; i<field.choices.length; i++){
                var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                var isSelected = fieldValue == selectedValue;
                var selected = isSelected ? "selected='selected'" : "";
                if(isSelected)
                    isAnySelected = true;

                str += "<option value='" + fieldValue.replace("'", "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
            }

            if(!isAnySelected && selectedValue){
                str += "<option value='" + selectedValue.replace("'", "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
            }

            return str;
        }

        function GetFieldById(fieldId){
            for(var i=0; i<form.fields.length; i++){
                if(form.fields[i].id == fieldId)
                    return form.fields[i];
            }
            return null;
        }

        function TruncateMiddle(text, maxCharacters){
            if(text.length <= maxCharacters)
                return text;
            var middle = parseInt(maxCharacters / 2);
            return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
        }

        function GetSelectableFields(selectedFieldId, labelMaxCharacters){
            var str = "";
            var inputType;
            for(var i=0; i<form.fields.length; i++){
                fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                if(inputType == "checkbox" || inputType == "radio" || inputType == "select"){
                    var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                    str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                }
            }
            return str;
        }

//]]>
</script>

        <?php

    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_icontact");
        $wp_roles->add_cap("administrator", "gravityforms_icontact_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_icontact", "gravityforms_icontact_uninstall"));
    }

    public static function disable_icontact(){
        delete_option("gf_icontact_settings");
    }

    public static function select_icontact_form(){
        check_ajax_referer("gf_select_icontact_form", "gf_select_icontact_form");

        $api = self::get_api();

        if(!empty($api->lastError) || !isset($_POST["list_ids"])) {
            die("EndSelectForm();");
        }

        parse_str($_POST["list_ids"], $lists);

        $form_id =  intval($_POST["form_id"]);

        $setting_id =  0;

        //getting list of all iContact merge variables for the selected contact list
        $merge_vars = self::listMergeVars();;

        //getting configuration
        $config = GFiContactData::get_feed($setting_id);

        //getting field map UI
        $str = self::get_field_mapping($config, $form_id, $merge_vars);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);
        //$fields = $form["fields"];
        die("EndSelectForm('" . str_replace("'", "\'", $str) . "', " . GFCommon::json_encode($form) . ");");
    }

    private function listMergeVars() {
        // From http://developer.icontact.com/documentation/contacts/

        $api = self::get_api();
        $custom_fields = $api->getCustomFields();

        $fields = array(
            array('tag'=>'email', 'req' => true, 'name' => __("Email")),
            array('tag'=>'prefix',    'req' => false, 'name' => __("Name (Prefix)")),
            array('tag'=>'firstName',     'req' => false, 'name' => __("Name (First)")),
            array('tag'=>'lastName',      'req' => false, 'name' => __("Name (Last)")),
            array('tag'=>'suffix',    'req' => false, 'name' => __("Name (Suffix)")),

            array('tag'=>'business', 'req' => false, 'name' => __("Company")),

            array('tag'=>'street','req' => false, 'name' => __("Address (Street Address)")),
            array('tag'=>'street2','req' => false, 'name' => __("Address (Address Line 2)")),
            array('tag'=>'city',      'req' => false, 'name' => __("Address (City)")),
            array('tag'=>'state', 'req' => false, 'name' => __("Address (State / Province)")),
            array('tag'=>'postalCode',    'req' => false, 'name' => __("Address (Zip / Postal Code)")),

            array('tag'=>'phone',   'req' => false, 'name' => __("Phone")),
            array('tag'=>'fax',   'req' => false, 'name' => __("Fax")),
            array('tag'=>'status', 'req' => false, 'name' => __("Subscription Status"))

        );

        if(!empty($custom_fields) && is_array($custom_fields)) {
            foreach($custom_fields as $field) {
                extract($field);
                $fields[] = array(
                    'tag' => $privateName,
                    'name' => $publicName,
                    'req' => false,
                    'custom' => true
                );
            }
        }

        return $fields;
    }

    private static function get_field_mapping($config = array(), $form_id, $merge_vars){

        $usedFields = array();
        $str = $custom = $standard = '';

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form_id);

        foreach($merge_vars as $var){
            $selected_field = isset($config["meta"]["field_map"][$var["tag"]]) ? $config["meta"]["field_map"][$var["tag"]] : false;
            $required = $var["req"] === true ? "<span class='gfield_required'>*</span>" : "";
            $error_class = $var["req"] === true && empty($selected_field) && !empty($_POST["gf_icontact_submit"]) ? " feeds_validation_error" : "";

            $row = "<tr class='$error_class'><td class='icontact_field_cell'><label for='icontact_map_field_{$var['tag']}'>" . $var["name"]  . " $required</label></td><td class='icontact_field_cell'>" . self::get_mapped_field_list($var["tag"], $selected_field, $form_fields) . "</td></tr>";

            if(isset($var['custom'])) {
                $custom .= $row;
            } else {
                $standard .= $row;
            }
        } // End foreach merge var.

        $head = "<table cellpadding='0' cellspacing='0'><thead><tr><th scope='col' class='icontact_col_heading'>" . __("List Fields", "gravity-forms-icontact") . "</th><th scope='col' class='icontact_col_heading'>" . __("Form Fields", "gravity-forms-icontact") . "</th></tr></thead><tbody>";

            $str = $head . $standard;

        if(!empty($custom)) {
            $str .= '</tbody></table>';
            $str .= '<label for="icontact_custom_fields" class="left_header">'.__("Custom Fields", "gravity-forms-icontact").'<span class="howto">'.__(sprintf("%sRefresh Custom Fields%s", '<a href="'.add_query_arg('refresh', 'customfields').'">','</a>'), "gravity-forms-icontact").'</span></label>'.$head.$custom;
        }

        $str .= "</tbody></table>";

        return $str;
    }

    private function getNewTag($tag, $used = array()) {
        if(isset($used[$tag])) {
            $i = 1;
            while($i < 1000) {
                if(!isset($used[$tag.'_'.$i])) {
                    return $tag.'_'.$i;
                }
                $i++;
            }
        }
        return $tag;
    }

    public static function get_form_fields($form_id){
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = array();

        //Adding default fields
        array_push($form["fields"],array("id" => "date_created" , "label" => __("Entry Date", "gravity-forms-icontact")));
        array_push($form["fields"],array("id" => "ip" , "label" => __("User IP", "gravity-forms-icontact")));
        array_push($form["fields"],array("id" => "source_url" , "label" => __("Source Url", "gravity-forms-icontact")));

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"]) && $field['type'] !== 'checkbox' && $field['type'] !== 'select'){

                    //If this is an address field, add full name to the list
                    if(RGFormsModel::get_input_type($field) == "address")
                        $fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Full" , "gravity-forms-icontact") . ")");

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(empty($field["displayOnly"])){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    /**
     * Convert an array of checkbox values into a string compatible with iContact
     *
     * Use the `icontact_checkbox_implode_glue` filter to modify the implode glue.
     *
     * @filter icontact_checkbox_implode_glue
     * @param  array $entry    Entry submission array
     * @param  integer $field_id ID of the field to get the values of
     * @return string           Imploded array
     */
    private static function get_checkbox_value($entry, $field_id) {
        $output = array();
        foreach($entry as $k => $v) {
            if(is_numeric($k) && floor($k) === floatval($field_id)) {
                $output[] = $v;
            }
        }

        return implode(apply_filters('icontact_checkbox_implode_glue', '; '), $output);
    }

    private static function get_address($entry, $field_id){
        $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
        $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));
        $city_value = str_replace("  ", " ", trim($entry[$field_id . ".3"]));
        $state_value = str_replace("  ", " ", trim($entry[$field_id . ".4"]));
        $zip_value = trim($entry[$field_id . ".5"]);
        $country_value = GFCommon::get_country_code(trim($entry[$field_id . ".6"]));

        $address = $street_value;
        $address .= !empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
        $address .= !empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
        $address .= !empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
        $address .= !empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
        $address .= !empty($address) && !empty($country_value) ? "  $country_value" : $country_value;

        return $address;
    }

    public static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "icontact_map_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''>" . __("", "gravity-forms-icontact") . "</option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = $field[1];

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    public static function get_mapped_field_checkbox($variable_name, $selected_field, $field){
        $field_name = "icontact_map_field_" . $variable_name;
        $field_id = $field[0];
        $str =  "<input name='$field_name' id='$field_name' type='checkbox' value='$field_id'";
        $selected = $field_id == $selected_field ? " checked='checked'" : false;
        if($selected) {
            $str .= $selected;
        }

        $str .= " />";
        return $str;
    }

    public static function export($entry, $form){
        //Login to iContact
        $api = self::get_api();
        if(!empty($api->lastError))
            return;

        //loading data class
        require_once(self::get_base_path() . "/data.php");

        //getting all active feeds
        $feeds = GFiContactData::get_feed_by_form($form["id"], true);

        foreach($feeds as $feed){
            //only export if user has opted in
            if(self::is_optin($form, $feed)) {
                self::export_feed($entry, $form, $feed, $api);
            }
        }
    }

    public static function export_feed($entry, $form, $feed, $api){
        $email = $entry[(int)$feed["meta"]["field_map"]["email"]];

        $merge_vars = array();
        foreach($feed["meta"]["field_map"] as $var_tag => $field_id){

            $field = RGFormsModel::get_field($form, $field_id);

            if($field['type'] === 'checkbox') {
                $merge_vars[$var_tag] = self::get_checkbox_value($entry, $field_id);
            } else if($var_tag == 'address_full') {
                $merge_vars[$var_tag] = self::get_address($entry, $field_id);
            } else if($var_tag  == 'country') {
                $merge_vars[$var_tag] = empty($entry[$field_id]) ? '' : GFCommon::get_country_code(trim($entry[$field_id]));
            } else if($var_tag != "email") {
                if(!empty($entry[$field_id])) {
                    /*
if($field['type'] == 'textarea') {
                        $merge_vars[$var_tag] = '<![CDATA['.$entry[$field_id].']]>';
                    } else{
*/
                        $merge_vars[$var_tag] = $entry[$field_id];
                    #}
                } else {
                    foreach($entry as $key => $value) {
                        if(floor($key) == floor($field_id) && !empty($value)) {
                            $merge_vars[$var_tag][] = $value;
                        }
                    }
                }
            }
        }

        if(empty($feed["meta"]["contact_list_id"])) { return false; }

        $lists = explode(',',$feed["meta"]["contact_list_id"]);

        // 1. Create Contact
            $contactId = $api->createContact($email, $merge_vars);

            if(empty($contactId)) {
                self::add_note($entry["id"], sprintf(__('There was an error adding the entry to iContact: %s', 'gravity-forms-icontact'), $api->lastError));
                return;
            }

        // 2. Subscribe contact to lists
            $subscriptions = array();
            foreach($lists as $listId) {
                $subscriptions[] = $api->subscribeContactsToList($listId, array($contactId));
            }

        // 3. Add custom fields for contact
        if($api->debugMode && !is_admin() && self::has_access('gravityforms_icontact')) {
            $api->dump(array(
                'entry' => $entry,
                /* 'form' => $form,  */
                'feed' => $feed,
                'POST' => $_POST,
                'lists' => $lists,
                'email' => $email,
                'merge vars' =>$merge_vars,
                'contact ID' => $contactId,
                '$subscriptions' => $subscriptions,
                '$api' => $api
            ), __('Post-submission summary',"gravity-forms-icontact"));
        }

        // 4. Add data to the entry for easy access.
        gform_update_meta($entry['id'], 'icontact_id', $contactId);
        self::add_note($entry["id"], sprintf(__('Successfully added to iContact with ID #%s . View entry at %s', 'gravity-forms-icontact'), $contactId, 'https://app.icontact.com/icp/core/mycontacts/contacts/edit/'.$contactId));

        return;
    }

    function entry_info_link_to_icontact($form_id, $lead) {
        $icontact_id = gform_get_meta($lead['id'], 'icontact_id');
        if(!empty($icontact_id)) {
            echo sprintf(__('iContact ID: <a href="https://app.icontact.com/icp/core/mycontacts/contacts/edit/'.$icontact_id.'">%s</a><br /><br />', 'gravity-forms-icontact'), $icontact_id);
        }
    }

    private function add_note($id, $note) {

        if(!apply_filters('gravityforms_icontact_add_notes_to_entries', true)) { return; }

        RGFormsModel::add_note($id, 0, __('Gravity Forms iContact Add-on'), $note);
    }

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/data.php");

        if(!GFiContact::has_access("gravityforms_icontact_uninstall"))
            die(__("You don't have adequate permission to uninstall iContact Add-On.", "gravity-forms-icontact"));

        //droping all tables
        GFiContactData::drop_tables();

        //removing options
        delete_option("gf_icontact_settings");
        delete_option("gf_icontact_version");

        //Deactivating plugin
        $plugin = "gravity-forms-icontact/icontact.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    public static function is_optin($form, $settings){
        $config = $settings["meta"];
        $operator = $config["optin_operator"];

        $field = RGFormsModel::get_field($form, $config["optin_field_id"]);
        $field_value = RGFormsModel::get_field_value($field, array());
        $is_value_match = is_array($field_value) ? in_array($config["optin_value"], $field_value) : $field_value == $config["optin_value"];

        return  !$config["optin_enabled"] || empty($field) || ($operator == "is" && $is_value_match) || ($operator == "isnot" && !$is_value_match);
    }


    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    private function simpleXMLToArray($xml,
                    $flattenValues=true,
                    $flattenAttributes = true,
                    $flattenChildren=true,
                    $valueKey='@value',
                    $attributesKey='@attributes',
                    $childrenKey='@children'){

        $return = array();
        if(!($xml instanceof SimpleXMLElement)){return $return;}
        $name = $xml->getName();
        $_value = trim((string)$xml);
        if(strlen($_value)==0){$_value = null;};

        if($_value!==null){
            if(!$flattenValues){$return[$valueKey] = $_value;}
            else{$return = $_value;}
        }

        $children = array();
        $first = true;
        foreach($xml->children() as $elementName => $child){
            $value = self::simpleXMLToArray($child, $flattenValues, $flattenAttributes, $flattenChildren, $valueKey, $attributesKey, $childrenKey);
            if(isset($children[$elementName])){
                if($first){
                    $temp = $children[$elementName];
                    unset($children[$elementName]);
                    $children[$elementName][] = $temp;
                    $first=false;
                }
                $children[$elementName][] = $value;
            }
            else{
                $children[$elementName] = $value;
            }
        }
        if(count($children)>0){
            if(!$flattenChildren){$return[$childrenKey] = $children;}
            else{$return = array_merge($return,$children);}
        }

        $attributes = array();
        foreach($xml->attributes() as $name=>$value){
            $attributes[$name] = trim($value);
        }
        if(count($attributes)>0){
            if(!$flattenAttributes){$return[$attributesKey] = $attributes;}
            else{$return = array_merge($return, $attributes);}
        }

        return $return;
    }

    private function convert_xml_to_object($response) {
        $response = @simplexml_load_string($response);  // Added @ 1.2.2
        if(is_object($response)) {
            return $response;
        } else {
            return false;
        }
    }

    private function convert_xml_to_array($response) {
        $response = self::convert_xml_to_object($response);
        $response = self::simpleXMLToArray($response);
        if(is_array($response)) {
            return $response;
        } else {
            return false;
        }
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    //Returns the url of the plugin's root folder
    protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }


}

?>