<?php

namespace MailjetPlugin\Includes\SettingsPages;

use MailjetPlugin\Includes\MailjetApi;
use MailjetPlugin\Includes\MailjetLoader;
use MailjetPlugin\Includes\MailjetLogger;
use MailjetPlugin\Includes\MailjetMail;
use MailjetPlugin\Includes\SettingsPages\SubscriptionOptionsSettings;

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

    private $loader;

    public function mailjet_show_extra_woo_fields($checkout)
    {
        option_create();
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


        echo '<pre>';
        var_dump($order);
        echo '</pre>';
        exit;

        if (get_option('mailjet_woo_edata_sync') === '1'){
            $this->edataSync($order);
        }

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
            if ('1' == get_post_meta($order->get_id(), 'mailjet_woo_subscribe_ok', true )) {
                $str .= '<p id="mj-woo-confirmation-msg"><b>' . __('We have sent the newsletter subscription confirmation link to you', 'mailjet-for-wordpress'). ' ' . $order->get_billing_email() . __('To confirm your subscription you have to click on the provided link.', 'mailjet-for-wordpress') . '</b></p>';
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
        }

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
        $data = $this->getFormattedEmailData($order, $vars, $templateId);
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

        $data = $this->getFormattedEmailData($order, $vars, $templateId);
        $response = MailjetApi::sendEmail($data);



        if ($response === false){
            MailjetLogger::error('[ Mailjet ] [ ' . __METHOD__ . ' ] [ Line #' . __LINE__ . ' ] [ Automation email fails ][Request:]' . json_encode($data));
            return false;
        }

        return true;

    }

    public function send_abandoned_cart($orderId)
    {
        $order = wc_get_order( $orderId );
        $templateId = get_option('mailjet_woocommerce_abandoned_cart');
        if (!$order || empty($order) || !$templateId || empty($templateId)){
            return false;
        }

        $vars = [
            'first_name' => $order->get_billing_first_name(),
            'order_number' => $orderId,
            'order_total' => $order->get_formatted_order_total(),
            'store_email' => '',
            'store_phone' => '',
            'store_name' => get_bloginfo(),
            'store_address' => get_option('woocommerce_store_address'),
        ];

        $data = $this->getFormattedEmailData($order, $vars, $templateId);
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

        $data = $this->getFormattedEmailData($order, $vars, $templateId);
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
        };

        return $result;

    }

    private function getFormattedEmailData($order, $vars, $templateId)
    {
        $recipients = [
            'Email' => $order->get_billing_email(),
            'Name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'Vars' => $vars
        ];

        $data = [];
        $data['FromEmail'] = get_option('mailjet_from_email');
        $data['FromName'] = get_option('mailjet_from_name');
        $data['Recipients'][] = $recipients;
        $data['Mj-TemplateID'] = $templateId;
        $data['Mj-TemplateLanguage'] = true;
        $data['Mj-TemplateErrorReporting'] = 'yangelov@mailjet.com';
        $data['Mj-TemplateErrorDeliver'] = true;
        $data['body'] = $data;
        return $data;
    }

    private function abandonedCartTemplateContent()
    {
        $json = '{"tagName":"mjml","children":[{"tagName":"mj-body","children":[{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"TB_O38KWKT"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 45px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><b><span style=\"font-size:25px\">Still thinking about it?</span></b></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"F_1VkT87As"},"padding-top":"0px","font-size":"13px"},"id":"ouXxSI7c5n2UE"},{"tagName":"mj-text","content":"<p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">We see you left something in your cart.</span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">You are just a few clicks away from completing your purchase.&nbsp;Would you like to do it now?</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"dtQ2S8a1Gh"},"padding-top":"0px","font-size":"13px"},"id":"uEUQOdhdigih7"},{"tagName":"mj-text","content":"<p style=\"margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><b><span style=\"font-size:18px\">Your cart</span></b></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"idMaOK4L7i"},"padding-top":"10px","font-size":"13px"},"id":"0SZ5QK-NnRhXJ"},{"tagName":"mj-dev","content":"{% for product in var:products %}","attributes":{"padding":"0","passport":{"id":"krSJSF1Df"}},"id":"oprlVKUwwOteG"},{"tagName":"mj-divider","attributes":{"border-color":"#e6e6e6","border-style":"solid","border-width":"1px","padding":"10px 25px","width":"100%","padding-top":"0px","passport":{"id":"9e7RJG7J8Qy"},"padding-bottom":"0px"},"id":"7hmF0f7MPlnDI"}],"id":"0EzBzwXcRDJf-"}],"attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","background-color":"#ffffff","padding-bottom":"0px","passport":{"name":"head","id":"S_5WPKa-Q-91"}},"id":"j8fIZGIZAOPzb"},{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"width":"25%","passport":{"id":"jYd6duS4ej"}},"children":[{"tagName":"mj-image","attributes":{"padding-right":"25px","padding-left":"25px","src":"{{product.image}}","align":"center","height":"auto","padding-bottom":"10px","alt":"{{product.title}}","href":"","border":"none","padding":"10px 25px 10px 25px","target":"_blank","passport":{"id":"RQAzPee4o","mode":"image"},"title":"","padding-top":"10px"},"id":"EVpQ-uV4a_3H8"}],"id":"2lAkhhMnb8lt_"},{"tagName":"mj-column","attributes":{"width":"50%","passport":{"id":"nXpkbakLwD"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><b><span style=\"font-size:14px\">{{product.title}}</span></b></span></p><p style=\"margin: 10px 0;\"><span style=\"font-size:14px\">{{product.variant_title}}</span></p><p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Quantity: {{product.quantity}}</span></span></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"qaynr6W5O"},"padding-top":"0px","font-size":"13px"},"id":"9BJnKTTRIbuOgZ"}],"id":"5Ocj_ok9mkQgqU"},{"tagName":"mj-column","attributes":{"width":"25%","passport":{"id":"bNk8beO8sH"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">{{product.price}}</span></span></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"5FqGXlB-6"},"padding-top":"0px","font-size":"13px"},"id":"rRssNM6kzGjCKv"}],"id":"ANBWbOALN29Vai"}],"attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","passport":{"id":"mOyoP86zT"},"padding-top":"10px","padding-bottom":"10px","background-color":"#ffffff"},"id":"dGLcjYGOkmnco"},{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"YZCgDkiHox"}},"children":[{"tagName":"mj-dev","content":"{% endfor %}","attributes":{"padding":"0","passport":{"id":"N0n2Bm398"}},"id":"jqqLgFhdU4xoN0"},{"tagName":"mj-divider","attributes":{"border-color":"#E6E6E6","border-style":"solid","border-width":"1px","padding":"10px 25px","width":"100%","passport":{"id":"U0_ClSDw9"},"padding-top":"0px","padding-bottom":"0px"},"id":"bcf0bbe0bO0Wip"}],"id":"NypxqeNXeXxEPg"}],"attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","passport":{"id":"Yj_5R_lwD"},"padding-top":"0px","padding-bottom":"0px","background-color":"#ffffff"},"id":"hgFSOd6SUimIPM"},{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"j0lmah361j"}},"children":[{"tagName":"mj-button","content":"<b>Resume your order &gt;</b>","attributes":{"font-family":"Arial, sans-serif","color":"#ffffff","background-color":"#555","align":"left","href":"{{var:abandoned_cart_link}}","border":"none","text-transform":"none","vertical-align":"middle","text-decoration":"none","padding":"10px 25px","passport":{"id":"U9JQFV8EK"},"border-radius":"3px","font-weight":"normal","inner-padding":"10px 25px","font-size":"13px"},"id":"KXDPvJ0M5oKoDx"},{"tagName":"mj-text","content":"<p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">Best regards,</span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">{{var:store_name}} staff</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"40px","padding":"10px 25px","passport":{"id":"kbOjkLqu2H"},"padding-top":"10px","font-size":"13px"},"id":"D1RkjwNgC12vW7"}],"id":"OVsbiRSqRLcVH-"}],"attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","background-color":"#ffffff","padding-bottom":"0px","passport":{"name":"head","id":"sfnVk4THm"},"padding-top":"10px"},"id":"8r8UEeiA_6kV9A"},{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"pa4S1gtc5v"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 25px; text-align: center; margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:12px\">This email was sent to you by {{var:store_name}} -&nbsp;{{var:store_address}}</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"10px","container-background-color":"transparent","padding":"10px 25px","passport":{"id":"IhNECkaGvg"},"padding-top":"10px","font-size":"13px"},"id":"js1iuJ1OUkhH0N"}],"id":"9evTrnw7JT2WUj"}],"attributes":{"background-color":"transparent","text-align":"center","padding-bottom":"0px","vertical-align":"top","padding":"20px 0","passport":{"name":"head","version":"4.3.0","id":"CjnsOa1Zs","savedSectionID":697324},"padding-top":"0px","background-repeat":"repeat"},"id":"M3HkNv_jgVt9dz"}],"attributes":{"background-color":"#f2f2f2","color":"#4e4e4e","font-family":"Arial, sans-serif","passport":{"id":"pu_i0pvjS"}},"id":"KGijqwV-KLHya"}],"attributes":{"version":"4.3.0","owa":"desktop"},"id":"aSfPlKGS_H-Tj"}';
        $templateDetail['MJMLContent'] = json_decode($json);
        $templateDetail['Html-part'] = file_get_contents(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceAbandonedCart.html');
        $templateDetail['Headers']= [
            'Subject' => 'There\'s something in your cart',
            'SenderName' => '{{var:store_name}}',
            'From' => '{{var:store_name:""}} <{{var:store_email:""}}>',
        ];

        return $templateDetail;
    }

    private function orderRefundTemplateContent()
    {
        $json = '{"tagName":"mjml","children":[{"tagName":"mj-body","children":[{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"TB_O38KWKT"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><b><span style=\"font-size:25px\">Your refund has been issued.</span></b></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"FF6fBnK6a"},"padding-top":"0px","font-size":"13px"},"id":"gZfZCVxLKrQF"},{"tagName":"mj-text","content":"<p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Dear {{var:first_name}},</span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Your cancellation for order {{var:order_number}} is now complete.</span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">A refund of {{var:order_total}} has been issued. Please note that it might take few days before it shows on your account, due to varying processing times between payment providers.</span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">To see refund information and status you can visit the following </span></span><span style=\"color:#555555\"><span style=\"font-size:14px\"><a target=\"_blank\" href=\"{{var:order_link}}\">link</a>. </span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">If you have any questions you can email us at {{var:store_email}} or give us a call at {{var:store_phone}}.</span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"> </p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">Best regards,</span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">{{var:store_name}} staff</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"40px","padding":"10px 25px","passport":{"id":"dtQ2S8a1Gh"},"padding-top":"0px","font-size":"13px"},"id":"tQqnAmiNmdPD"}],"id":"rtsd8kU03M-l"}],"attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","background-color":"#ffffff","padding-bottom":"0px","passport":{"name":"head","id":"S_5WPKa-Q-91"}},"id":"i1O5qN4zzb-K"},{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"7jM6ye-MsS"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 25px; text-align: center; margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:12px\">This email was sent to you by {{var:store_name}} -&nbsp;{{var:store_address}}</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"10px","container-background-color":"transparent","padding":"10px 25px","passport":{"id":"0GyuqsK3uX"},"padding-top":"10px","font-size":"13px"},"id":"x7-VExlgXChY"}],"id":"uRYZuAIGO6mG"}],"attributes":{"background-color":"transparent","text-align":"center","padding-bottom":"0px","vertical-align":"top","padding":"20px 0","passport":{"name":"head","version":"4.3.0","id":"Uejey13HT","savedSectionID":697324},"padding-top":"0px","background-repeat":"repeat"},"id":"cMEr0VFJUTJK"}],"attributes":{"background-color":"#f2f2f2","color":"#4e4e4e","font-family":"Arial, sans-serif","passport":{"id":"pu_i0pvjS"}},"id":"iATe1guFUblq"}],"attributes":{"version":"4.3.0","owa":"desktop"},"id":"VJxSXcSlIcxl"}';
        $templateDetail['MJMLContent'] = json_decode($json);
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
        $json = '{"tagName":"mjml","children":[{"tagName":"mj-body","children":[{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"TB_O38KWKT"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 45px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><b><span style=\"font-size:25px\">Your order has been shipped!</span></b></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"F_1VkT87As"},"padding-top":"0px","font-size":"13px"},"id":"v-lahappAQw2F"},{"tagName":"mj-text","content":"<p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Dear {{var:first_name}},</span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Your order<b> </b>{{var:order_number}} is now on its way to the following address:</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"dtQ2S8a1Gh"},"padding-top":"0px","font-size":"13px"},"id":"J8Dunn_AYhRj7"}],"id":"mX0L8PD3KcYEw"}],"attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","background-color":"#ffffff","padding-bottom":"0px","passport":{"name":"head","id":"S_5WPKa-Q-91"}},"id":"hveiC0BzBGeaw"},{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"kjc69OAf5kA"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"font-size:14px\"><b><span style=\"color:#555555\">Shipping address</span></b></span></p><p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:12px\">{{var:order_shipping_address}}</span></span></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"GklPgydnw"},"padding-top":"0px","font-size":"13px"},"id":"zJIJo8mGoAqim"}],"id":"BiFAZkOKdDqNR"}],"attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","passport":{"id":"rtUWaQVGUwN"},"padding-top":"5px","padding-bottom":"5px","background-color":"#ffffff"},"id":"ib-1HIU0cvCx3"},{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"j0lmah361j"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">To follow&nbsp;the&nbsp;progress of your delivery, click <a target=\"_blank\" href=\"{{var:tracking_url}}\">here</a>&nbsp;and enter your tracking number: {{var:tracking_number}}.&nbsp;In addition you can find all order information&nbsp;at the following&nbsp;<a target=\"_blank\" href=\"{{var:order_link}}\">link</a>.</span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">Make sure you’re available to sign for your parcel so you can enjoy your purchase when it arrives!</span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">If you have any questions you can email&nbsp;us at {{var:store_email}} or give us a call at {{var:store_phone}}.</span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"> </p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">Best regards,</span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"font-size:14px\">{{var:store_name}} staff</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"40px","padding":"10px 25px","passport":{"id":"kbOjkLqu2H"},"padding-top":"0px","font-size":"13px"},"id":"kVVtT_B6JnLEL"}],"id":"mcChUhRxft9sE"}],"attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","background-color":"#ffffff","padding-bottom":"0px","passport":{"name":"head","id":"sfnVk4THm"},"padding-top":"0px"},"id":"EIvCWgQV6YgPV"},{"tagName":"mj-section","children":[{"tagName":"mj-column","attributes":{"passport":{"id":"8pHPXhd1PQ"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 25px; text-align: center; margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:12px\">This email was sent to you by {{var:store_name}} -&nbsp;{{var:store_address}}</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"10px","container-background-color":"transparent","padding":"10px 25px","passport":{"id":"y2DKxozNPf"},"padding-top":"10px","font-size":"13px"},"id":"yHpxqcJ0N4uGu"}],"id":"k_1QxizMb_tCx"}],"attributes":{"background-color":"transparent","text-align":"center","padding-bottom":"0px","vertical-align":"top","padding":"20px 0","passport":{"name":"head","version":"4.3.0","id":"V-2rtS0Ku","savedSectionID":697324},"padding-top":"0px","background-repeat":"repeat"},"id":"rMRNJWuGcDyV0"}],"attributes":{"background-color":"#f2f2f2","color":"#4e4e4e","font-family":"Arial, sans-serif","passport":{"id":"pu_i0pvjS"}},"id":"uifKbV3rQ08f_"}],"attributes":{"version":"4.3.0","owa":"desktop"},"id":"DiHHPzB52xeD"}';
        $templateDetail['MJMLContent'] = json_decode($json);
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
        $json = '{"tagName":"mjml","children":[{"tagName":"mj-body","attributes":{"background-color":"#eaeaea","color":"#4e4e4e","font-family":"Arial, sans-serif","passport":{"id":"pu_i0pvjS"}},"children":[{"tagName":"mj-section","attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","background-color":"#ffffff","padding-bottom":"0px","passport":{"name":"head","id":"S_5WPKa-Q-91"}},"children":[{"tagName":"mj-column","attributes":{"passport":{"id":"TB_O38KWKT"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 45px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><b><span style=\"font-size:25px\">Thank you for your order!</span></b></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"F_1VkT87As"},"padding-top":"0px","font-size":"13px"},"id":"u95687kV1ORQY"},{"tagName":"mj-text","content":"<p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Dear {{var:first_name}},</span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Thank you for shopping at {{var:store_name}}.</span></span></p><p style=\"line-height: 21px; margin: 10px 0; text-align: left;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">We are processing your order and will email you once it has been shipped. </span></span><span style=\"color:#555555\"><span style=\"font-size:14px\">In the meanwhile you can check your order status online by following <a target=\"_blank\" href=\"{{var:order_link}}\">this link</a>. Please find below a summary of your purchase.</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"dtQ2S8a1Gh"},"padding-top":"0px","font-size":"13px"},"id":"J2BH-tIzziOsY"},{"tagName":"mj-text","content":"<p style=\"text-align: left; margin: 10px 0;\"><span style=\"color:#555555\"><b><span style=\"font-size:18px\">Your order details</span></b></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"idMaOK4L7i"},"padding-top":"10px","font-size":"13px"},"id":"phF50lWtQPBLH"},{"tagName":"mj-text","content":"<p style=\"line-height: 21px; margin: 10px 0;\"><span style=\"color:#555555\"><b><span style=\"font-size:14px\">Order number&nbsp;</span></b><span style=\"font-size:14px\">{{var:order_number}}</span></span></p><p style=\"line-height: 21px; margin: 10px 0;\"><b><font color=\"#555555\"><span style=\"font-size:14px\">Item list</span></font></b></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"XdO4lkpkl"},"padding-top":"0px","font-size":"13px"},"id":"5OKD_vR_hgTln"},{"tagName":"mj-divider","attributes":{"border-color":"#e6e6e6","border-style":"solid","border-width":"1px","padding":"10px 25px","width":"100%","padding-top":"NaNpx","passport":{"id":"9e7RJG7J8Qy"},"padding-bottom":"NaNpx"},"id":"fVsmHjwRWzNlK"},{"tagName":"mj-dev","content":"{% for product in var:products %}","attributes":{"padding":"0","passport":{"id":"ByJnKHXgC"}},"id":"hrHQmdPejGsXr"}],"id":"QHTr82sDoB5wc"}],"id":"9PECARjnPC_n6"},{"tagName":"mj-section","attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","passport":{"id":"mOyoP86zT"},"padding-top":"10px","padding-bottom":"10px","background-color":"#ffffff"},"children":[{"tagName":"mj-column","attributes":{"width":"25%","passport":{"id":"jYd6duS4ej"}},"children":[{"tagName":"mj-image","attributes":{"src":"{{product.image}}","align":"center","height":"auto","alt":"{{product.title}}","href":"","border":"none","padding":"10px 25px","target":"_blank","passport":{"id":"RQAzPee4o","mode":"image"},"title":""},"id":"XK4X8vkLqs5cM"}],"id":"2QDFF5o4Og1Lz"},{"tagName":"mj-column","attributes":{"width":"50%","passport":{"id":"nXpkbakLwD"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><b><span style=\"font-size:14px\">{{product.title}}</span></b></span></p><p style=\"margin: 10px 0;\"><span style=\"font-size:14px\">{{product.variant_title}}</span></p><p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Quantity:&nbsp;{{product.quantity}}</span></span></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"qaynr6W5O"},"padding-top":"0px","font-size":"13px"},"id":"-kJmziSpFWgp_2"}],"id":"6Xb91aMci8JmXv"},{"tagName":"mj-column","attributes":{"width":"25%","passport":{"id":"bNk8beO8sH"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">{{product.price}}</span></span></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"5FqGXlB-6"},"padding-top":"0px","font-size":"13px"},"id":"B3jT8C56bRJOlN"}],"id":"pY4zjW5muIABA1"}],"id":"qciLYKup_99g7"},{"tagName":"mj-section","attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","passport":{"id":"Yj_5R_lwD"},"padding-top":"0px","padding-bottom":"0px","background-color":"#ffffff"},"children":[{"tagName":"mj-column","attributes":{"passport":{"id":"YZCgDkiHox"}},"children":[{"tagName":"mj-dev","content":"{% endfor %}","attributes":{"padding":"0","passport":{"id":"yMFKuYKrA"}},"id":"T802tCZxYF3dYU"},{"tagName":"mj-divider","attributes":{"border-color":"#E6E6E6","border-style":"solid","border-width":"1px","padding":"10px 25px","width":"100%","passport":{"id":"U0_ClSDw9"}},"id":"r9LG2Z88jxGjdd"}],"id":"qq014Dd2IaWebk"}],"id":"-7Y1ggt_Dh9Dsy"},{"tagName":"mj-section","attributes":{"background-color":"#ffffff","text-align":"center","padding-bottom":"0px","vertical-align":"middle","padding":"20px 0","passport":{"name":"items","id":"-WsyZX3ODCey"},"padding-top":"0px","background-repeat":"repeat"},"children":[{"tagName":"mj-group","attributes":{"vertical-align":"middle","passport":{"id":"BJ72n-RUau"}},"children":[{"tagName":"mj-column","attributes":{"width":"66%","vertical-align":"middle","passport":{"id":"aXEcbGCeGp"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"font-size:12px\"><span style=\"color:#555555\">Order subtotal</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"z7QAxY8osJ"},"padding-top":"0px","font-size":"13px"},"id":"aAKR4nHnIyBLFL"}],"id":"8gXGyt5yyYKVM_"},{"tagName":"mj-column","attributes":{"width":"33%","vertical-align":"middle","passport":{"id":"ofDuz3ZhaC"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0; text-align: right;\"><span style=\"font-size:12px\">{{var:order_subtotal}}</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"BSHHZuuLf0"},"padding-top":"0px","font-size":"13px"},"id":"he17ZAr82IBxNt"}],"id":"-L6bsuLokFEPpU"}],"id":"YZa5PrfOkR0uBR"}],"id":"KM7rpwYVatjtb_"},{"tagName":"mj-section","attributes":{"background-color":"#ffffff","text-align":"center","padding-bottom":"0px","vertical-align":"middle","padding":"20px 0","passport":{"name":"items","id":"VAeeqKCQQ"},"padding-top":"0px","background-repeat":"repeat"},"children":[{"tagName":"mj-group","attributes":{"vertical-align":"middle","passport":{"id":"YvuAAfojY1"}},"children":[{"tagName":"mj-column","attributes":{"width":"66%","vertical-align":"middle","passport":{"id":"oC6QuJKIPm"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"font-size:12px\"><span style=\"color:#555555\">Discount</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"IHX2jC34-L"},"padding-top":"0px","font-size":"13px"},"id":"RxUKXKWZNy5zzj"}],"id":"72GTJCgEmxsTwN"},{"tagName":"mj-column","attributes":{"width":"33%","vertical-align":"middle","passport":{"id":"Rg8gYa74xP"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0; text-align: right;\"><span style=\"font-size:12px\">{{var:order_discount_total}}</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"ggv85ZFCuH"},"padding-top":"0px","font-size":"13px"},"id":"ArIJSPnD82iQMV"}],"id":"k8uHOduL8FRTcB"}],"id":"SnuO4zk4UUwOzp"}],"id":"43ZS2BlXK12lmL"},{"tagName":"mj-section","attributes":{"background-color":"#ffffff","text-align":"center","padding-bottom":"0px","vertical-align":"middle","padding":"20px 0","passport":{"name":"items","id":"C59fL8diw"},"padding-top":"0px","background-repeat":"repeat"},"children":[{"tagName":"mj-group","attributes":{"vertical-align":"middle","passport":{"id":"DQeZMZZZ8O"}},"children":[{"tagName":"mj-column","attributes":{"width":"66%","vertical-align":"middle","passport":{"id":"Y2sqq98eAz"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"font-size:12px\"><span style=\"color:#555555\">Taxes</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"6NvGTJS0aV"},"padding-top":"0px","font-size":"13px"},"id":"cxS2VEaVRr4U0v"}],"id":"5ycrgFSZLNlWS7"},{"tagName":"mj-column","attributes":{"width":"33%","vertical-align":"middle","passport":{"id":"p52vjYvGZo"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0; text-align: right;\"><span style=\"font-size:12px\">{{var:order_total_tax}}</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"2AKKnfKzWT"},"padding-top":"0px","font-size":"13px"},"id":"k80fg12xqOVlZW"}],"id":"3DKtjKLizHDjGn"}],"id":"Px_qBsbep47zHY"}],"id":"fff6I6RqnICHn6"},{"tagName":"mj-section","attributes":{"background-color":"#ffffff","text-align":"center","padding-bottom":"0px","vertical-align":"middle","padding":"20px 0","passport":{"name":"items","id":"IiBOzQUng"},"padding-top":"0px","background-repeat":"repeat"},"children":[{"tagName":"mj-group","attributes":{"vertical-align":"middle","passport":{"id":"lcGBrjt05J"}},"children":[{"tagName":"mj-column","attributes":{"width":"66%","vertical-align":"middle","passport":{"id":"Fmh1edHWG5"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"font-size:12px\"><span style=\"color:#555555\">Shipping fee</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"PmK_PlpKZ1"},"padding-top":"0px","font-size":"13px"},"id":"z0Xp8bek-bWL3h"}],"id":"ooCZXYJdieR1ct"},{"tagName":"mj-column","attributes":{"width":"33%","vertical-align":"middle","passport":{"id":"Z2RReDNM2M"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0; text-align: right;\"><span style=\"font-size:12px\">{{var:order_shipping_total}}</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"ZKCry38ZRf"},"padding-top":"0px","font-size":"13px"},"id":"xWBmn0zG99c-Mg"}],"id":"MKKVT_S_dL0UDv"}],"id":"sWYC1hzPlXeQQJ"}],"id":"ZJLyWUvAB4b7wr"},{"tagName":"mj-section","attributes":{"background-color":"#ffffff","text-align":"center","padding-bottom":"0px","vertical-align":"middle","padding":"20px 0","passport":{"name":"items","id":"R_oCDj_1s"},"padding-top":"0px","background-repeat":"repeat"},"children":[{"tagName":"mj-group","attributes":{"vertical-align":"middle","passport":{"id":"uX8Z3Z6MiS"}},"children":[{"tagName":"mj-column","attributes":{"width":"66%","vertical-align":"middle","passport":{"id":"s0MQ745Wuy"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><b><span style=\"color:#555555\"><span style=\"font-size:14px\">Order total</span></span></b></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"Aas66bPy-P"},"padding-top":"0px","font-size":"13px"},"id":"mXPfah962vQxCl"}],"id":"NcTa_VQNLxiJJS"},{"tagName":"mj-column","attributes":{"width":"33%","vertical-align":"middle","passport":{"id":"sA2syQWV8K"}},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0; text-align: right;\"><b><span style=\"font-size:14px\">{{var:order_total}}</span></b></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"mmq-qPXBh_"},"padding-top":"0px","font-size":"13px"},"id":"Qd5xUC1xxs4E4H"}],"id":"1Kkatpyf5Mo1ub"}],"id":"9YgSnoZt4kEk3S"}],"id":"mWyPtQbP5_H41V"},{"tagName":"mj-section","attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","passport":{"id":"9yAqAsebkmD"},"padding-top":"0px","padding-bottom":"0px","background-color":"#ffffff"},"children":[{"tagName":"mj-column","attributes":{"passport":{"id":"hJcyYTutA6O"}},"children":[{"tagName":"mj-divider","attributes":{"border-color":"#e6e6e6","border-style":"solid","border-width":"1px","padding":"10px 25px","width":"100%","padding-top":"10px","passport":{"id":"xuFyVftvMcA"}},"id":"da7zQZPwjPA7GA"}],"id":"_1urwZlh-rqESz"}],"id":"Jy8ai8VMERIGWu"},{"tagName":"mj-section","attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","passport":{"id":"rtUWaQVGUwN"},"padding-top":"0px","padding-bottom":"0px","background-color":"#ffffff"},"children":[{"tagName":"mj-column","attributes":{"passport":{"id":"kjc69OAf5kA"},"width":"50%"},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"font-size:14px\"><b><span style=\"color:#555555\">Shipping address</span></b></span></p><p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:12px\">{{var:order_shipping_address}}</span></span></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"GklPgydnw"},"padding-top":"0px","font-size":"13px"},"id":"cdT0j6tFABbQ5q"}],"id":"Dyn_YhWcrq1XUW"},{"tagName":"mj-column","attributes":{"passport":{"id":"rEqTYZ1RO"},"vertical-align":"top","width":"50%"},"children":[{"tagName":"mj-text","content":"<p style=\"margin: 10px 0;\"><span style=\"font-size:14px\"><b><span style=\"color:#555555\">Billing address</span></b></span></p><p style=\"margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:12px\">{{var:order_billing_address}}</span></span></p>","attributes":{"line-height":"24px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"0px","padding":"10px 25px","passport":{"id":"PXuTFbZQ7"},"padding-top":"0px","font-size":"13px"},"id":"83eOazjwNKfY5c"}],"id":"_KL1Nl5_wJwJ_n"}],"id":"fC98oIjhadCXrm"},{"tagName":"mj-section","attributes":{"background-repeat":"repeat","padding":"20px 0","text-align":"center","vertical-align":"top","passport":{"id":"MJg0hbaud"},"padding-top":"0px","padding-bottom":"0px","background-color":"#ffffff"},"children":[{"tagName":"mj-column","attributes":{"passport":{"id":"u5LuRMR9SG"}},"children":[{"tagName":"mj-divider","attributes":{"border-color":"#e6e6e6","border-style":"solid","border-width":"1px","padding":"10px 25px","width":"100%","padding-top":"10px","passport":{"id":"9-MRjG6uCc"}},"id":"r81OIqX-Fk7aHD"}],"id":"mlIxcwBGrb-kc_"}],"id":"JbSSMLiROVBigK"},{"tagName":"mj-section","attributes":{"background-color":"#ffffff","text-align":"center","padding-bottom":"0px","vertical-align":"top","padding":"20px 0","passport":{"name":"head","id":"sfnVk4THm"},"padding-top":"0px","background-repeat":"repeat"},"children":[{"tagName":"mj-column","attributes":{"passport":{"id":"j0lmah361j"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 21px; text-align: left; margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">Please make sure that someone can sign for your parcel at&nbsp;the&nbsp;above shipping address when it’s delivered.</span></span></p><p style=\"line-height: 21px; text-align: left; margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:14px\">If you have any questions you can email&nbsp;us at {{var:store_email}} or give us a call at {{var:store_phone}}.</span></span></p><p style=\"line-height: 21px; text-align: left; margin: 10px 0;\">&nbsp;</p><p style=\"line-height: 21px; text-align: left; margin: 10px 0;\"><span style=\"font-size:14px\">Best regards,</span></p><p style=\"line-height: 21px; text-align: left; margin: 10px 0;\"><span style=\"font-size:14px\">{{var:store_name}} staff</span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"40px","padding":"10px 25px","passport":{"id":"kbOjkLqu2H"},"padding-top":"0px","font-size":"13px"},"id":"3EI8nvhqXcc-tk"}],"id":"JBs9UEq3P6gb24"}],"id":"BEIpI8j2jJAyWW"},{"tagName":"mj-section","attributes":{"background-color":"transparent","text-align":"center","padding-bottom":"0px","vertical-align":"top","padding":"20px 0","passport":{"name":"head","version":"4.3.0","id":"kLi3ArLf1","savedSectionID":697324},"padding-top":"0px","background-repeat":"repeat"},"children":[{"tagName":"mj-column","attributes":{"passport":{"id":"zOTbPfe9aN"}},"children":[{"tagName":"mj-text","content":"<p style=\"line-height: 25px; text-align: center; margin: 10px 0;\"><span style=\"color:#555555\"><span style=\"font-size:12px\">This email was sent to you by {{var:store_name}} -&nbsp;{{var:store_address}}</span></span></p>","attributes":{"line-height":"22px","font-family":"Arial, sans-serif","color":"#4e4e4e","align":"left","padding-bottom":"10px","container-background-color":"transparent","padding":"10px 25px","passport":{"id":"3hgTtcb10Q"},"padding-top":"10px","font-size":"13px"},"id":"CNcsyfjIk4_pqK"}],"id":"-C24Q-L8YCAmyD"}],"id":"tuR62JHSyx-l5b"}],"id":"mk0_fPclh_2pd"}],"attributes":{"version":"4.3.0","owa":"desktop"},"id":"FqoZrqUmA2v8W"}';
        $templateDetail['MJMLContent'] = json_decode($json);
        $templateDetail['Html-part'] = file_get_contents(MAILJET_ADMIN_TAMPLATE_DIR . '/IntegrationAutomationTemplates/WooCommerceOrderConfirmation.html');
        $templateDetail['Headers']= [
            'Subject' => 'We just received your order from {{var:store_name}} - {{var:order_number}}',
            'SenderName' => '{{var:store_name}}',
            'From' => '{{var:store_name:""}} <{{var:store_email:""}}>'
        ];

        return $templateDetail;
    }

    private function edataSync($order)
    {

    }
}
