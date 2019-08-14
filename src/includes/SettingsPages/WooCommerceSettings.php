<?php

namespace MailjetPlugin\Includes\SettingsPages;

use MailjetPlugin\Includes\MailjetApi;
use MailjetPlugin\Includes\MailjetLogger;



/**
 * Register all actions and filters for the plugin.
 *
 * Maintain a list of all hooks that are registered throughout
 * the plugin, and register them with the WordPress API. Call the
 * run function to execute the list of actions and filters.
 *
 * @package    Mailjet
 * @subpackage Mailjet/includes
 * @author     Your Name <email@example.com>
 */
class WooCommerceSettings
{

    const ERROR_REPORTING_MAIL_ADRESS = 'egicquel@mailjet.com';

    public function __construct()
    {
        $this->enqueueScripts();
        add_action('wp_ajax_get_contact_lists', [$this, 'subscribeViaAjax']);
        // cron settings for abandoned cart feature
        add_filter('cron_schedules', [$this, 'add_cron_schedule']);
    }

    public function add_cron_schedule($schedules) {
        $schedules['one_minute'] = array(
            'interval'  => 60,
            'display'   => __('Once Every Minute'),
        );
        return $schedules;
    }

    public function mailjet_show_extra_woo_fields($checkout)
    {
        $user = wp_get_current_user();
        $chaeckoutBox = get_option('mailjet_woo_checkout_checkbox');
        $chaeckoutText = get_option('mailjet_woo_checkout_box_text');
        $contactList = $this->getWooContactList();

        // Display the checkbox only for NOT-logged in users or for logged-in but not subscribed to the Woo list
//        if (get_option('activate_mailjet_woo_integration') && get_option('mailjet_woo_list')){
        if ($contactList !== false) {

            // Check if user is logged-in and already Subscribed to the contact list
            $contactAlreadySubscribedToList = false;
            if ($user->exists()) {
                $contactAlreadySubscribedToList = MailjetApi::checkContactSubscribedToList($user->data->user_email, $contactList);
            }
            if (!$contactAlreadySubscribedToList) {
                if (!function_exists('woocommerce_form_field')) {
                    return;
                }
                $boxMsg = get_option('mailjet_woo_checkout_box_text') ?: 'Subscribe to our newsletter';

                woocommerce_form_field('mailjet_woo_subscribe_ok', array(
                    'type' => 'checkbox',
                    'label' => __($boxMsg, 'mailjet-for-wordpress'),
                    'required' => false,
                ), $checkout->get_value('mailjet_woo_subscribe_ok'));
            }
        }
    }

    public function mailjet_subscribe_woo($order, $data)
    {
        $wooUserEmail = filter_var($order->get_billing_email(), FILTER_SANITIZE_EMAIL);
        $firstName = $order->get_billing_first_name();
        $lastName = $order->get_billing_last_name();

        if (!is_email($wooUserEmail)) {
            _e('Invalid email', 'mailjet-for-wordpress');
            die;
        }

        if (isset($_POST['_my_field_name']) && !empty($_POST['_my_field_name']))
            $order->update_meta_data('_my_field_name', sanitize_text_field($_POST['_my_field_name']));


        $subscribe = filter_var($_POST['mailjet_woo_subscribe_ok'], FILTER_SANITIZE_NUMBER_INT);
        if ($subscribe) {
            $order->update_meta_data('mailjet_woo_subscribe_ok', sanitize_text_field($_POST['mailjet_woo_subscribe_ok']));
            $this->mailjet_subscribe_confirmation_from_woo_form($subscribe, $wooUserEmail, $firstName, $lastName);
        }
    }

    /**
     *  Subscribe or unsubscribe a wordpress comment author in/from a Mailjet's contact list when the comment is saved
     */
    public function mailjet_subscribe_unsub_woo_to_list($subscribe, $user_email, $first_name, $last_name)
    {
        $action = intval($subscribe) === 1 ? 'addforce' : 'remove';
        $contactproperties = [];
        if (!empty($first_name)) {
            MailjetApi::createMailjetContactProperty('firstname');
            $contactproperties['firstname'] = $first_name;
        }
        if (!empty($last_name)) {
            MailjetApi::createMailjetContactProperty('lastname');
            $contactproperties['lastname'] = $last_name;
        }

        // Add the user to a contact list
        return SubscriptionOptionsSettings::syncSingleContactEmailToMailjetList($this->getWooContactList(), $user_email, $action, $contactproperties);
    }

    /**
     * Email the collected widget data to the customer with a verification token
     * @param void
     * @return void
     */
    public function mailjet_subscribe_confirmation_from_woo_form($subscribe, $user_email, $first_name, $last_name)
    {
        $error = empty($user_email) ? 'Email field is empty' : false;
        if (false !== $error) {
            _e($error, 'mailjet-for-wordpress');
            die;
        }

        if (!is_email($user_email)) {
            _e('Invalid email', 'mailjet-for-wordpress');
            die;
        }
        $wpUrl = sprintf('<a href="%s" target="_blank">%s</a>', get_home_url(), get_home_url());

        $message = file_get_contents(dirname(dirname(dirname(__FILE__))) . '/templates/confirm-subscription-email.php');
        $emailParams = array(
            '__EMAIL_TITLE__' => __('Please confirm your subscription', 'mailjet-for-wordpress'),
            '__EMAIL_HEADER__' => sprintf(__('To receive newsletters from %s please confirm your subscription by clicking the following button:', 'mailjet-for-wordpress'), $wpUrl),
            '__WP_URL__' => $wpUrl,
            '__CONFIRM_URL__' => get_home_url() . '?subscribe=' . $subscribe . '&user_email=' . $user_email . '&first_name=' . $first_name . '&last_name=' . $last_name . '&mj_sub_woo_token=' . sha1($subscribe . $user_email . $first_name . $last_name),
            '__CLICK_HERE__' => __('Yes, subscribe me to this list', 'mailjet-for-wordpress'),
            '__FROM_NAME__' => get_option('blogname'),
            '__IGNORE__' => __('If you received this email by mistake or don\'t wish to subscribe anymore, simply ignore this message.', 'mailjet-for-wordpress'),
        );
        foreach ($emailParams as $key => $value) {
            $message = str_replace($key, $value, $message);
        }

        $email_subject = __('Subscription Confirmation', 'mailjet');
        add_filter('wp_mail_content_type', array(new SubscriptionOptionsSettings(), 'set_html_content_type'));
        $res = wp_mail($user_email, $email_subject, $message,
            array('From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'));
    }

    /**
     * Function to change the "Thank you" text for WooCommerce order processed page - to add message that user has received subscription confirmation email
     *
     * @param $str
     * @param $order
     * @return string
     */
    public function woo_change_order_received_text($str, $order)
    {
        if (!empty($order)) {
            $subscribe = get_post_meta($order->get_id(), 'mailjet_woo_subscribe_ok', true);
            if ($subscribe == '1') {
                $str .= ' <br /><br /><i><b>We have sent the newsletter subscription confirmation link to you (<b>' . $order->get_billing_email() . '</b>). To confirm your subscription you have to click on the provided link.</i></b>';
            } elseif (get_option('mailjet_woo_banner_checkbox') === '1') {
                $str = $this->addThankYouSubscription($order);
            }
        }
        return $str;
    }

    private function getWooContactList()
    {
        $wooActiv = get_option('activate_mailjet_woo_integration');
        if (!$wooActiv) {

            return false;
        }
        $checkoutBox = get_option('mailjet_woo_checkout_checkbox');
        $mainList = get_option('mailjet_sync_list');
        $wooList = get_option('mailjet_woo_list');
        if (!empty($wooList)) {

            return $wooList;
        } elseif (!empty($mainList) && !empty($checkoutBox)) {

            return $mainList;
        }

        return false;
    }

    public static function getWooTemplate($templateType)
    {
        $templateId = get_option($templateType);

        if (!$templateId || empty($templateId)) {
            return false;
        }
        $templateDetails = MailjetApi::getTemplateDetails($templateId);

        if (!$templateDetails || empty($templateDetails)) {
            return false;
        }

        $templateDetails['Headers']['ID'] = $templateId;

        return $templateDetails;
    }

    public function toggleWooSettings($activeHooks)
    {

        if (get_option('mailjet_enabled') !== '1'){
            return false;
        }

        $avaliableActions = [
            'woocommerce_order_status_processing' => 'woocommerce_customer_processing_order_settings',
            'woocommerce_order_status_completed' => 'woocommerce_customer_completed_order_settings',
            'woocommerce_order_status_refunded' => 'woocommerce_customer_refunded_order_settings'
        ];

        $hooks = [];

        foreach ($activeHooks as $activeHook){
            $hooks[] = $activeHook['hook'];
        }

        $defaultSettings = [
            'enabled' => 'yes',
            'subject' => '',
            'heading' => '',
            'email_type' => 'html',
        ];

        foreach ($avaliableActions as $key => $hook) {
            $wooSettings = get_option($hook);
            $setting = $defaultSettings;
            if ($wooSettings) {
                $setting = $wooSettings;
                $setting['enabled'] = 'yes';
            }
            if (in_array($key, $hooks)){
                $setting['enabled'] = 'no';
            }
            update_option($hook, $setting);
        }

        return true;
    }

    private function getTemplateContent($callable)
    {

        if (!method_exists($this, $callable)){
            MailjetLogger::error('[ Mailjet ] [ ' . __METHOD__ . ' ] [ Line #' . __LINE__ . ' ] [ Method (' . $callable . ') can\'t be found!]');
            return [];
        }

        $fileTemp = call_user_func([$this, $callable]);

        if (!$fileTemp || empty($fileTemp)) {
            MailjetLogger::error('[ Mailjet ] [ ' . __METHOD__ . ' ] [ Line #' . __LINE__ . ' ] [ Method (' . $callable . ') has error!]');
            return [];
        }

        //Add sender Email to headers
        $fromEmail = get_option('mailjet_from_email');
        $fileTemp['Headers']['SenderEmail'] = $fromEmail;

        return $fileTemp;

    }

    public function activateWoocommerce($data)
    {
        $result['success'] = true;

        $activate = true;
        if (!isset($data->activate_mailjet_woo_integration) || $data->activate_mailjet_woo_integration !== '1') {
            update_option('activate_mailjet_woo_integration', '');
            $activate = false;
        }
        foreach ($data as $key => $val) {
            $optionVal = $activate ? $val : '';
            update_option($key, sanitize_text_field($optionVal));
        }

        if ($activate) {
            $templates['woocommerce_abandoned_cart'] = ['id' => get_option('mailjet_woocommerce_abandoned_cart'), 'callable' => 'abandonedCartTemplateContent'];
            $templates['woocommerce_order_confirmation'] = ['id' => get_option('mailjet_woocommerce_order_confirmation'), 'callable' => 'orderCreatedTemplateContent'];
            $templates['woocommerce_refund_confirmation'] = ['id' => get_option('mailjet_woocommerce_refund_confirmation'), 'callable' => 'orderRefundTemplateContent'];
            $templates['woocommerce_shipping_confirmation'] = ['id' => get_option('mailjet_woocommerce_shipping_confirmation'), 'callable' => 'shippingConfirmationTemplateContent'];

            foreach ($templates as $name => $value) {
                if (!$value['id'] || empty($value['id'])) {
                    $templateArgs = [
                        "Author" => "Mailjet WC integration",
                        "Categories" => ['e-commerce'],
                        "Copyright" => "Mailjet",
                        "Description" => "Used to send automation emails.",
                        "EditMode" => 1,
                        "IsStarred" => false,
                        "IsTextPartGenerationEnabled" => true,
                        "Locale" => "en_US",
                        "Name" => ucwords(str_replace('_', ' ', $name)),
                        "OwnerType" => "user",
                        "Presets" => "string",
                        "Purposes" => ['automation']
                    ];

                    $template = MailjetApi::createAutomationTemplate(['body' => $templateArgs, 'filters' => []]);

                    if ($template && !empty($template)) {
                        $templateContent = [];
                        $templateContent['id'] = $template['ID'];
                        $templateContent['body'] = $this->getTemplateContent($value['callable']);
                        $templateContent['filters'] = [];
                        add_option('mailjet_' . $name, $template['ID']);
                        $contentCreation = MailjetApi::createAutomationTemplateContent($templateContent);
                        if (!$contentCreation || empty($contentCreation)) {
                            $result['success'] = false;
                        }
                    } else {
                        $result['success'] = false;
                    }
                }
            }

            // Abandoned cart default data
            update_option('mailjet_woo_abandoned_cart_activate', 0);
            add_option('mailjet_woo_abandoned_cart_sending_time', 1200); // 20 * 60 = 1200s

            //Abandoned carts DB table
            global $wpdb;
            $wcap_collate = '';
            if ( $wpdb->has_cap( 'collation' ) ) {
                $wcap_collate = $wpdb->get_charset_collate();
            }
            $table_name = $wpdb->prefix . 'mailjet_wc_abandoned_carts';
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `user_id` int(11) NOT NULL,
                    `abandoned_cart_info` text NOT NULL,
                    `abandoned_cart_time` int(11) NOT NULL,
                    `cart_ignored` boolean NOT NULL,
                    `user_type` text NOT NULL,
                    PRIMARY KEY (`id`)
                    ) $wcap_collate AUTO_INCREMENT=1 ";

            require_once ( ABSPATH . 'wp-admin/includes/upgrade.php' );
            dbDelta( $sql );

            $table_name = $wpdb->prefix . 'mailjet_wc_abandoned_cart_emails';
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                    `id` int(11) NOT NULL AUTO_INCREMENT,
                    `abandoned_cart_id` text NOT NULL,
                    PRIMARY KEY (`id`)
                    ) $wcap_collate AUTO_INCREMENT=1 ";
            dbDelta( $sql );
        }
        $this->toggleAbandonedCart();

        $result['message'] = $result['success'] === true ? 'Integrations updated successfully.' : 'Something went wrong! Please try again later.';

        return $result;

    }

    public function send_order_status_refunded($orderId)
    {
        $order = wc_get_order( $orderId );

        if (!$order || empty($order)){
            return false;
        }

        $vars = [
            'first_name' => $order->get_billing_first_name(),
            'order_number' => $orderId,
            'order_total' => $order->get_total(),
            'store_email' => get_option('mailjet_from_email'),
            'store_phone' => '',
            'store_name' => get_bloginfo(),
            'store_address' => get_option('woocommerce_store_address'),
            'order_link' => $order->get_view_order_url(),
        ];

        $templateId = get_option('mailjet_woocommerce_refund_confirmation');
        $data = $this->getFormattedEmailData($this->getOrderRecipients($order, $vars), $templateId);
        $response = MailjetApi::sendEmail($data);
        if ($response === false){
            MailjetLogger::error('[ Mailjet ] [ ' . __METHOD__ . ' ] [ Line #' . __LINE__ . ' ] [ Automation email fails ][Request:]' . json_encode($data));
            return false;
        }

        return true;
    }

    public function send_order_status_processing($orderId)
    {
        $order = wc_get_order( $orderId );
        $templateId = get_option('mailjet_woocommerce_order_confirmation');
        if (!$order || empty($order) || !$templateId || empty($templateId)){
            return false;
        }
        if (!$order || empty($order)){
            return false;
        }


        $items = $order->get_items();
        $products = [];
        foreach ($items as $item){
            $itemData = $item->get_data();
            $data['variant_title'] = $itemData['name'];
            $data['price'] = $itemData['total'];
            $data['title'] = $itemData['name'];
            $data['quantity'] = $itemData['quantity'];
            $product = wc_get_product( $item['product_id'] );
            $data['image'] =  $product->get_image();
            $products[] = $data;
        }

        $vars = [
            'first_name' => $order->get_billing_first_name(),
            'order_number' => $orderId,
            'order_subtotal' => $order->get_subtotal(),
            'order_discount_total' => $order->get_discount_total(),
            'order_total_tax' => $order->get_tax_totals(),
            'order_shipping_total' => $order->get_shipping_total(),
            'order_shipping_address' => $order->get_shipping_address_1(),
            'order_billing_address' => $order->get_billing_address_1(),
            'order_total' => $order->get_formatted_order_total(),
            'order_link' => $order->get_view_order_url(),
            'store_email' => get_option('mailjet_from_email'),
            'store_phone' => '',
            'store_name' => get_bloginfo(),
            'store_address' => get_option('woocommerce_store_address'),
            'products' => $products,
        ];

        $data = $this->getFormattedEmailData($this->getOrderRecipients($order, $vars), $templateId);
        $response = MailjetApi::sendEmail($data);



        if ($response === false){
            MailjetLogger::error('[ Mailjet ] [ ' . __METHOD__ . ' ] [ Line #' . __LINE__ . ' ] [ Automation email fails ][Request:]' . json_encode($data));
            return false;
        }

        return true;

    }

    public function cart_change_timestamp() {
        global $wpdb, $woocommerce;

        $currentTime = current_time('timestamp');
        $sendingDelay = get_option( 'mailjet_woo_abandoned_cart_sending_time' );
        $ignoreCart = false;

        if ( is_user_logged_in() ) {
            $userType = 'REGISTERED';
            $user_id = get_current_user_id();
            $query = 'SELECT * FROM `' . $wpdb->prefix . 'mailjet_wc_abandoned_carts`
                            WHERE user_id = %d
                            AND cart_ignored = %d
                            AND user_type = %s';
            $results = $wpdb->get_results($wpdb->prepare($query, $user_id, $ignoreCart, $userType));

            $cart = json_encode($woocommerce->cart->get_cart());

            if ( 0 === count( $results ) ) {
                if (isset($cart) && !empty($cart) && $cart !== '[]') {
                    $insert_query = 'INSERT INTO `' . $wpdb->prefix . 'mailjet_wc_abandoned_carts`
                                     (user_id, abandoned_cart_info, abandoned_cart_time, cart_ignored, user_type)
                                     VALUES (%d, %s, %d, %d, %s)';
                    $wpdb->query($wpdb->prepare($insert_query, $user_id, $cart, $currentTime, $ignoreCart, $userType));
                }
            }
            elseif (isset( $results[0]->abandoned_cart_time ) && $results[0]->abandoned_cart_time + $sendingDelay > $currentTime) {
                if (isset($cart) && !empty($cart) && $cart !== '[]') {
                    $query_update = 'UPDATE `' . $wpdb->prefix . 'mailjet_wc_abandoned_carts`
                                     SET abandoned_cart_info = %s,
                                         abandoned_cart_time = %d
                                     WHERE user_id  = %d 
                                     AND user_type = %s
                                     AND cart_ignored = %s';
                    $wpdb->query($wpdb->prepare($query_update, $cart, $currentTime, $user_id, $userType, $ignoreCart));
                }
                else { // ignore cart if empty
                    $query_update = 'UPDATE `' . $wpdb->prefix . 'mailjet_wc_abandoned_carts`
                                     SET abandoned_cart_info = %s,
                                         abandoned_cart_time = %d,
                                         cart_ignored = %d
                                     WHERE user_id  = %d
                                     AND user_type = %s
                                     AND cart_ignored = %s';
                    $wpdb->query($wpdb->prepare($query_update, $cart, $currentTime, !$ignoreCart, $user_id, $userType, $ignoreCart));
                }
            }
            else {
                $query_update = 'UPDATE `' . $wpdb->prefix . 'mailjet_wc_abandoned_carts`
                                     SET cart_ignored = %d
                                     WHERE user_id  = %d
                                     AND user_type = %s';
                $wpdb->query($wpdb->prepare($query_update, !$ignoreCart, $user_id, $userType));

                if (isset($cart) && !empty($cart) && $cart !== '[]') {
                    $insert_query = 'INSERT INTO `' . $wpdb->prefix . 'mailjet_wc_abandoned_carts`
                                     (user_id, abandoned_cart_info, abandoned_cart_time, cart_ignored, user_type)
                                     VALUES (%d, %s, %d, %d, %s)';
                    $wpdb->query($wpdb->prepare($insert_query, $user_id, $cart, $currentTime, $ignoreCart, $userType));
                }
            }
        }
    }

    public function send_abandoned_cart_emails() {

    }

    private function send_abandoned_cart($cart) {
        $templateId = get_option('mailjet_woocommerce_abandoned_cart');
        if (!$cart || empty($cart) || !$templateId || empty($templateId)){
            return false;
        }
        $cartProducts = json_decode($cart->abandoned_cart_info, true);
        if (!is_array($cartProducts) || count($cartProducts) <= 0) {
            return false;
        }

        $products = [];
        foreach ($cartProducts as $key => $cartProduct) {
            $productDetails = wc_get_product($cartProduct['product_id']);
            $productImgUrl = wp_get_attachment_url(get_post_thumbnail_id($cartProduct['product_id']));
            $product = [];
            $product['title'] = $productDetails->get_title();
            $product['variant_title'] = '';
            $product['image'] = $productImgUrl ?: '';
            $product['quantity'] = $cartProduct['quantity'];
            $product['price'] = wc_price($productDetails->get_price());
            array_push($products, $product);
        }

        $vars = [
            'store_name' => get_bloginfo(),
            'store_address' => get_option('woocommerce_store_address'),
            'abandoned_cart_link' => get_permalink(wc_get_page_id('cart')),
            'products' => $products
        ];


        $recipients = $this->getAbandonedCartRecipients($cart, $vars);
        if (!isset($recipients) || empty($recipients)) {
            return false;
        }
        $data = $this->getFormattedEmailData($recipients, $templateId);
        $response = MailjetApi::sendEmail($data);
        if ($response === false){
            MailjetLogger::error('[ Mailjet ] [ ' . __METHOD__ . ' ] [ Line #' . __LINE__ . ' ] [ Automation email fails ][Request:]' . json_encode($data));
            return false;
        }

        return true;
    }

    public function send_order_status_completed($orderId)
    {
        $order = wc_get_order( $orderId );
        $templateId = get_option('mailjet_woocommerce_shipping_confirmation');
        if (!$order || empty($order) || !$templateId || empty($templateId)){
            return false;
        }

        $vars = [
            'first_name' => $order->get_billing_first_name(),
            'order_number' => $orderId,
            'order_shipping_address' => $order->get_shipping_address_1(),
            'tracking_number' => $order->get_shipping_state(),
            'order_total' => $order->get_total(),
            'order_link' => $order->get_view_order_url(),
            'tracking_url' => $order->get_shipping_state(),
            'store_email' => get_option('mailjet_from_email'),
            'store_phone' => '',
            'store_name' => get_bloginfo(),
            'store_address' => get_option('woocommerce_store_address'),
        ];

        $data = $this->getFormattedEmailData($this->getOrderRecipients($order, $vars), $templateId);
        $response = MailjetApi::sendEmail($data);

        if ($response === false){
            MailjetLogger::error('[ Mailjet ] [ ' . __METHOD__ . ' ] [ Line #' . __LINE__ . ' ] [ Automation email fails ][Request:]' . json_encode($data));
            return false;
        }

        return true;
    }

    public function orders_automation_settings_post()
    {
        $data = $_POST;

        if (!wp_verify_nonce($data['custom_nonce'],'mailjet_order_notifications_settings_page_html')){
            update_option('mailjet_post_update_message', ['success' => false, 'message' => 'Invalid credentials!']);
            wp_redirect(add_query_arg(array('page' => 'mailjet_order_notifications_page'), admin_url('admin.php')));
        }

        $activeHooks = $this->prepareAutomationHooks($data);

        $this->toggleWooSettings($activeHooks);

        $notifications = isset($data['mailjet_wc_active_hooks']) ? $data['mailjet_wc_active_hooks'] : [];

        update_option('mailjet_wc_active_hooks', $activeHooks);
        update_option('mailjet_order_notifications', $notifications);

        update_option('mailjet_post_update_message', ['success' => true, 'message' => 'Automation settings updated!']);
        wp_redirect(add_query_arg(array('page' => 'mailjet_order_notifications_page'), admin_url('admin.php')));
    }

    private function prepareAutomationHooks($data)
    {
        if (!isset($data['mailjet_wc_active_hooks'])){
           return [];
        }

        $actions = [
            'mailjet_order_confirmation' => ['hook' => 'woocommerce_order_status_processing', 'callable' => 'send_order_status_processing'],
            'mailjet_shipping_confirmation' =>  ['hook' => 'woocommerce_order_status_completed', 'callable' => 'send_order_status_completed'],
            'mailjet_refund_confirmation' =>  ['hook' => 'woocommerce_order_status_refunded', 'callable' => 'send_order_status_refunded']
        ];
        $result = [];
        foreach ($data['mailjet_wc_active_hooks'] as $key => $val){
            if ($val === '1'){

                $result[] = $actions[$key];
            }
        }

        return $result;

    }

    public function abandoned_cart_settings_post()
    {
        $data = $_POST;

        if (!wp_verify_nonce($data['custom_nonce'],'mailjet_order_notifications_settings_page_html')){
            update_option('mailjet_post_update_message', ['success' => false, 'message' => 'Invalid credentials!']);
            wp_redirect(add_query_arg(array('page' => 'mailjet_abandoned_cart_page'), admin_url('admin.php')));
        }

        $wasActivated = false;
        if (isset($data['activate_ac'])) {
            update_option('mailjet_woo_abandoned_cart_activate', $data['activate_ac']);
            $wasActivated = $data['activate_ac'] === '1';
            $this->toggleAbandonedCart();
        }
        if (isset($data['abandonedCartTimeScale']) && isset($data['abandonedCartSendingTime']) && is_numeric($data['abandonedCartSendingTime'])) {
            if ($data['abandonedCartTimeScale'] === 'HOURS') {
                $sendingTimeInSeconds = (int)$data['abandonedCartSendingTime'] * 3600; // 1h == 3600s
            }
            else {
                $sendingTimeInSeconds = (int)$data['abandonedCartSendingTime'] * 60;
            }
            update_option('mailjet_woo_abandoned_cart_sending_time', $sendingTimeInSeconds);
        }

        update_option('mailjet_post_update_message', ['success' => true, 'message' => 'Abandoned cart settings updated!', 'mjACWasActivated' => $wasActivated]);
        wp_redirect(add_query_arg(array('page' => 'mailjet_abandoned_cart_page'), admin_url('admin.php')));
    }

    private function toggleAbandonedCart() {
        $activeHooks = [];

        if (get_option('mailjet_woo_abandoned_cart_activate') === '1') {
            if ( ! wp_next_scheduled( 'abandoned_cart_cron_hook' ) ) {
                wp_schedule_event( time(), 'one_minute', 'abandoned_cart_cron_hook' );
            }
            $activeHooks = [
                ['hook' => 'woocommerce_add_to_cart', 'callable' => 'cart_change_timestamp'],
                ['hook' => 'woocommerce_cart_item_removed', 'callable' => 'cart_change_timestamp'],
                ['hook' => 'woocommerce_cart_item_restored', 'callable' => 'cart_change_timestamp'],
                ['hook' => 'woocommerce_after_cart_item_quantity_update', 'callable' => 'cart_change_timestamp'],
                ['hook' => 'woocommerce_calculate_totals', 'callable' => 'cart_change_timestamp'],
                ['hook' => 'woocommerce_cart_is_empty', 'callable' => 'cart_change_timestamp'],
                ['hook' => 'woocommerce_order_status_changed', 'callable' => 'update_status_on_order'],
                ['hook' => 'abandoned_cart_cron_hook', 'callable' => 'send_abandoned_cart_emails']

            ];
        }
        else {
            global $wpdb;
            $timestamp = wp_next_scheduled( 'abandoned_cart_cron_hook' );
            wp_unschedule_event( $timestamp, 'abandoned_cart_cron_hook' );
            // empty tables to not send irrelevant emails when reactivating
            $table_name = $wpdb->prefix . 'mailjet_wc_abandoned_cart_emails';
            $sql_delete = "TRUNCATE " . $table_name ;
            $wpdb->get_results( $sql_delete );
            $table_name = $wpdb->prefix . 'mailjet_wc_abandoned_carts';
            $sql_delete = "TRUNCATE " . $table_name ;
            $wpdb->get_results( $sql_delete );
        }
        update_option('mailjet_wc_abandoned_cart_active_hooks', $activeHooks);
    }

    public function update_status_on_order($order_id) {
        global $wpdb, $woocommerce;
        $order = wc_get_order( $order_id );
        if ($order->get_status() == 'processing' || $order->get_status() == 'completed') {
            if (is_user_logged_in()) {
                $userType = 'REGISTERED';
                $user_id = get_current_user_id();
                $query_update = 'UPDATE `' . $wpdb->prefix . 'mailjet_wc_abandoned_carts`
                                     SET cart_ignored = %d
                                     WHERE user_id  = %d
                                     AND user_type = %s
                                     AND cart_ignored = %d';
                $wpdb->query($wpdb->prepare($query_update, 1, $user_id, $userType, 0));
            }
        }
        else if ($order->get_status() !== 'refunded') {
            $this->cart_change_timestamp();
        }
    }

    private function getAbandonedCartRecipients($cart, $vars) {
        $recipients = [];
        if ($cart->user_type === 'REGISTERED') {
            $recipients = [
                'Email' => $cart->user_email,
                'Name' => $cart->user_name,
                'Vars' => $vars
            ];
        }

        return $recipients;
    }

    private function getOrderRecipients($order, $vars) {
        $recipients = [
            'Email' => $order->get_billing_email(),
            'Name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'Vars' => $vars
        ];
        return $recipients;
    }

    private function getFormattedEmailData($recipients, $templateId)
    {
        $data = [];
        $data['FromEmail'] = get_option('mailjet_from_email');
        $data['FromName'] = get_option('mailjet_from_name');
        $data['Recipients'][] = $recipients;
        $data['Mj-TemplateID'] = $templateId;
        $data['Mj-TemplateLanguage'] = true;
        $data['Mj-TemplateErrorReporting'] = $this::ERROR_REPORTING_MAIL_ADRESS;
        $data['Mj-TemplateErrorDeliver'] = true;
        $data['body'] = $data;
        return $data;
    }

    private function abandonedCartTemplateContent()
    {
        $templateDetail['MJMLContent'] = require_once(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceAbandonedCartArray.php');
        $templateDetail['Html-part'] = file_get_contents(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceAbandonedCart.html');
        $senderName = option('mailjet_from_name');
        $senderEmail = option('mailjet_from_email');
        $templateDetail['Headers']= [
            'Subject' => 'There\'s something in your cart',
            'SenderName' => $senderName,
            'From' => $senderName . ' <' . $senderEmail . '>'
        ];

        return $templateDetail;
    }

    private function orderRefundTemplateContent()
    {
        $templateDetail['MJMLContent'] = require_once(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceRefundArray.php');
        $templateDetail['Html-part'] = file_get_contents(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceRefundConfirmation.html');
        $templateDetail['Headers']= [
            'Subject' => 'Your refund from {{var:store_name}}',
            'SenderName' => '{{var:store_name}}',
            'From' => '{{var:store_name:""}} <{{var:store_email:""}}>'
        ];

        return $templateDetail;
    }

    private function shippingConfirmationTemplateContent()
    {
        $templateDetail['MJMLContent'] =  require_once(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceShippingConfArray.php');
        $templateDetail["Html-part"] = file_get_contents(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceShippingConfirmation.html');
        $templateDetail['Headers']= [
            'Subject' => 'Your order from {{var:store_name}} has been shipped',
            'SenderName' => '{{var:store_name}}',
            'From' => '{{var:store_name:""}} <{{var:store_email:""}}>'
        ];

        return $templateDetail;
    }

    private function orderCreatedTemplateContent()
    {
        $templateDetail['MJMLContent'] = require_once(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceOrderConfArray.php');
        $templateDetail['Html-part'] = file_get_contents(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceOrderConfirmation.html');
        $templateDetail['Headers']= [
            'Subject' => 'We just received your order from {{var:store_name}} - {{var:order_number}}',
            'SenderName' => '{{var:store_name}}',
            'From' => '{{var:store_name:""}} <{{var:store_email:""}}>'
        ];

        return $templateDetail;
    }

    private function addThankYouSubscription($order)
    {
        $text = get_option('mailjet_woo_banner_text');
        $label = get_option('mailjet_woo_banner_label');
        set_query_var('orderId', $order->get_id());
        set_query_var('text', !empty($text) ? $text : 'Subscribe to our newsletter to get product updates.');
        set_query_var('btnLabel', !empty($label) ? $label : 'Subscribe now!');
        return load_template(MAILJET_FRONT_TEMPLATE_DIR . '/Subscription/subscriptionForm.php');
    }


    public function enqueueScripts()
    {
        $cssPath = plugins_url('/src/front/css/mailjet-front.css', MAILJET_PLUGIN_DIR . 'src');
        $scryptPath = plugins_url('/src/front/js/mailjet-front.js', MAILJET_PLUGIN_DIR . 'src');
        wp_register_style('mailjet-front', $cssPath);
        wp_register_script('ajaxHandle', $scryptPath, array('jquery'), false, true);
        wp_localize_script('ajaxHandle', 'mailjet', ['url' => admin_url( 'admin-ajax.php' )]);
        wp_enqueue_style('mailjet-front');
        wp_enqueue_script('ajaxHandle');
    }

    public function subscribeViaAjax()
    {
        $post = $_POST;

        if (isset($post['orderId'])) {
            $order = wc_get_order($post['orderId']);
            $message = 'You\'v subscribed successfully to our mail list.';
            $success = true;

            if (empty($order)){
                $message = 'Something went wrong.';
                $success = false;
            }else{
                $subscribe = $this->ajaxSubscription($order->get_billing_email(), $order->get_billing_first_name(), $order->get_billing_last_name());
                wp_send_json_success($subscribe);
            }

            wp_send_json_success([
                'message' => $message,
                'success' => $success
            ]);
        } else {
            wp_send_json_error();
        }
    }

    private function ajaxSubscription($email, $fName, $lName)
    {
       $listId = $this->getWooContactList();

       if (!$listId){
           return ['success' => false, 'message' => 'You can\'t be subscribed at this moment.'];
       }

       if (MailjetApi::checkContactSubscribedToList($email, $listId)){
           return ['success' => true, 'message' => 'You are already subscribed.'];
       }

       if ($this->mailjet_subscribe_unsub_woo_to_list(1, $email, $fName, $lName)){
           return ['success' => true, 'message' => 'You\'re successfully subscribed to our E-mail list.'];
       }

       return ['success' => false, 'message' => 'Something went wrong.'];
    }
}
