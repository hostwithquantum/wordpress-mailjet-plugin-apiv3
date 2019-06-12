<?php

namespace MailjetPlugin\Includes\SettingsPages;

use MailjetPlugin\Admin\Partials\MailjetAdminDisplay;
use MailjetPlugin\Includes\MailjetApi;
use MailjetPlugin\Includes\Mailjeti18n;
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
class SubscriptionOptionsSettings
{

    const WIDGET_HASH = '[\^=34|>5i!? {xIas';

    private $subscrFieldset = '/settingTemplates/SubscriptionSettingsPartials/subscrFieldset.php';
    private $profileFields = '/settingTemplates/SubscriptionSettingsPartials/profileFields.php';

    public function __construct()
    {
	    add_action( 'admin_enqueue_scripts', [$this, 'enqueueScripts' ]);
	    add_action( 'wp_ajax_resync_mailjet', [$this, 'ajaxResync']);
	    add_action( 'wp_ajax_get_contact_lists', [$this, 'getContactListsMenu']);
    }

	public function mailjet_section_subscription_options_cb($args)
    {
        ?>
<!--        <p id="--><?php //echo esc_attr( $args['id'] ); ?><!--">-->
<!--            --><?php //esc_html_e( 'Automatically add Wordpress users to a Mailjet list. Each user’s email address and role (subscriber, administrator, author, …) is synchronized to the list and available for use inside Mailjet.', 'mailjet-for-wordpress' ); ?>
<!--        </p>-->
        <?php
    }


    public function mailjet_subscription_options_cb($args)
    {
        // get the value of the setting we've registered with register_setting()


        $mailjetContactLists = MailjetApi::getContactListByID(get_option('mailjet_sync_list'));
        $mailjetContactLists = !empty($mailjetContactLists) ? $mailjetContactLists : array();
        $mailjetSyncActivated = get_option('activate_mailjet_sync');
        $mailjetInitialSyncActivated = get_option('activate_mailjet_initial_sync');
        $mailjetCommentAuthorsList = get_option('mailjet_comment_authors_list');
        $mailjetCommentAuthorsSyncActivated = get_option('activate_mailjet_comment_authors_sync');

	    $mailjetContactLists = !empty($mailjetContactLists) ? $mailjetContactLists[0]['Name'] . ' ('.$mailjetContactLists[0]['SubscriberCount'].')' : 'No list selected';

	    set_query_var('mailjetContactLists', $mailjetContactLists);
	    set_query_var('mailjetSyncActivated', $mailjetSyncActivated);
	    set_query_var('mailjetCommentAuthorsList', $mailjetCommentAuthorsList);
	    set_query_var('mailjetInitialSyncActivated', $mailjetInitialSyncActivated);
	    set_query_var('mailjetCommentAuthorsSyncActivated', $mailjetCommentAuthorsSyncActivated);

	    load_template(MAILJET_ADMIN_TAMPLATE_DIR . $this->subscrFieldset);

    }


    /**
     * top level menu:
     * callback functions
     */
    public function mailjet_subscription_options_page_html()
    {
        // check user capabilities
        if (!current_user_can('read')) {
            MailjetLogger::error('[ Mailjet ] [ ' . __METHOD__ . ' ] [ Line #' . __LINE__ . ' ] [ Current user don\'t have \`manage_options\` permission ]');
            return;
        }


	    // register a new section in the "mailjet" page
        add_settings_section(
            'mailjet_subscription_options_settings',
            null,
            array($this, 'mailjet_section_subscription_options_cb'),
            'mailjet_subscription_options_page'
        );

        // register a new field in the "mailjet_section_developers" section, inside the "mailjet" page
        add_settings_field(
            'mailjet_subscription_options', // as of WP 4.6 this value is used only internally
            // use $args' label_for to populate the id inside the callback
            __( 'Subscription Options', 'mailjet' ),
            array($this, 'mailjet_subscription_options_cb'),
            'mailjet_subscription_options_page',
            'mailjet_subscription_options_settings',
            [
                'label_for' => 'mailjet_subscription_options',
                'class' => 'mailjet_row',
                'mailjet_custom_data' => 'custom',
            ]
        );


        // add error/update messages

        // check if the user have submitted the settings
        // wordpress will add the "settings-updated" $_GET parameter to the url
        if (isset($_GET['settings-updated'])) {
            $executionError = false;
            // Initial sync WP users to Mailjet
            $activate_mailjet_initial_sync = get_option('activate_mailjet_initial_sync');
            $mailjet_sync_list = get_option('mailjet_sync_list');
            if (!empty($activate_mailjet_initial_sync) && intval($mailjet_sync_list) > 0) {
                $syncResponse = self::syncAllWpUsers();
                if (false === $syncResponse) {
                    $executionError = true;
                    add_settings_error('mailjet_messages', 'mailjet_message', __('The settings could not be saved. Please try again or in case the problem persists contact Mailjet support.', 'mailjet-for-wordpress'), 'error');
                }
            }
            if (false === $executionError) {
                // add settings saved message with the class of "updated"
                add_settings_error('mailjet_messages', 'mailjet_message', __('Settings Saved', 'mailjet-for-wordpress'), 'updated');
            }
        }

        // show error/update messages
        settings_errors('mailjet_messages');
        load_template(MAILJET_ADMIN_TAMPLATE_DIR . '/settingTemplates/mainSettingsTemplate.php');
    }


    public static function syncAllWpUsers()
    {
        $mailjet_sync_list = get_option('mailjet_sync_list');
        if (empty($mailjet_sync_list)) {
            add_settings_error('mailjet_messages', 'mailjet_message', __('Please select a contact list.', 'mailjet-for-wordpress'), 'error');
            return false;
        }
        $contactListId = get_option('mailjet_sync_list');

        $users = get_users(array('fields' => array('ID', 'user_email')));
        if (!(count($users) > 0)) {
            add_settings_error('mailjet_messages', 'mailjet_message', __('No Wordpress users to add to Mailjet contact list', 'mailjet-for-wordpress'), 'error');
            return false;
        }

        if (false === self::syncContactsToMailjetList($contactListId, $users, 'addforce')) {
            add_settings_error('mailjet_messages', 'mailjet_message', __('Something went wrong with adding existing Wordpress users to your Mailjet contact list', 'mailjet-for-wordpress'), 'error');
            return false;
        } else {
            add_settings_error('mailjet_messages', 'mailjet_message', __('All Wordpress users were successfully added to your Mailjet contact list', 'mailjet-for-wordpress'), 'updated');
        }
        return true;
    }


    /**
     * Add or Remove a contact to Mailjet contact list
     *
     * @param $contactListId
     * @param $users - can be array of users or a single user
     * @param $action - addnoforce, addforce, remove
     * @return array|bool|int
     */
    public static function syncContactsToMailjetList($contactListId, $users, $action)
    {
        $contacts = array();

        if (!is_array($users)) {
            $users = array($users);
        }

        foreach ($users as $user) {
            $userInfo = get_userdata($user->ID);
            $userRoles = $userInfo->roles;
            $userMetadata = get_user_meta($user->ID);
            $userNames = '';

            $contactProperties = array();
            if (!empty($userMetadata['first_name'][0])) {
                $contactProperties['firstname'] = $userMetadata['first_name'][0];
                $userNames = $contactProperties['firstname'];
            }
            if (!empty($userMetadata['last_name'][0])) {
                $contactProperties['lastname'] = $userMetadata['last_name'][0];
                $userNames.= ' ' . $contactProperties['lastname'];
            }
            if (!empty($userRoles[0])) {
                $contactProperties['wp_user_role'] = $userRoles[0];
            }

            $contacts[] = array(
                'Email' => $user->user_email,
                'Name' => $userNames,
                'Properties' => $contactProperties
            );
        }

        return MailjetApi::syncMailjetContacts($contactListId, $contacts, $action);
    }


    public static function syncSingleContactEmailToMailjetList($contactListId, $email, $action, $contactProperties = [])
    {
        if (empty($email)) {
            return false;
        }

        $contact = [];
        $contact['Email'] = $email;
        if (!empty($contactProperties)) {
            $contact['Properties'] = $contactProperties;
        }

        return MailjetApi::syncMailjetContact($contactListId, $contact, $action);
    }

    /**
     *  Adding checkboxes and extra fields for subscribing user and comment authors
     */
    public function mailjet_show_extra_profile_fields($user)
    {
        // If contact list is not selected, then do not show the extra fields
        $activate_mailjet_sync = get_option('activate_mailjet_sync');
        $mailjet_sync_list = get_option('mailjet_sync_list');
        if (!empty($activate_mailjet_sync) && !empty($mailjet_sync_list)) {
            // Update the extra fields
            if (is_object($user) && intval($user->ID) > 0) {
                $this->mailjet_subscribe_unsub_user_to_list(esc_attr(get_the_author_meta('mailjet_subscribe_ok', $user->ID)), $user->ID);
            }
            $checked = (is_object($user) && intval($user->ID) > 0 && esc_attr(get_the_author_meta('mailjet_subscribe_ok', $user->ID))) ? 'checked="checked" ' : '';
            set_query_var('checked', $checked);
            load_template(MAILJET_ADMIN_TAMPLATE_DIR . $this->profileFields);
        }
    }


    /**
     *  Update extra profile fields when the profile is saved
     */
    public function mailjet_save_extra_profile_fields($user_id)
    {
        $subscribe = filter_var($_POST ['mailjet_subscribe_ok'], FILTER_SANITIZE_NUMBER_INT);
        update_user_meta($user_id, 'mailjet_subscribe_ok', $subscribe);
        $this->mailjet_subscribe_unsub_user_to_list($subscribe, $user_id);
    }


    /**
     *  Subscribe or unsubscribe a wordpress user (admin, editor, etc.) in/from a Mailjet's contact list when the profile is saved
     */
    public function mailjet_subscribe_unsub_user_to_list($subscribe, $user_id)
    {
        $mailjet_sync_list = get_option('mailjet_sync_list');
        if (!empty($mailjet_sync_list)) {
            $user = get_userdata($user_id);
            $action = intval($subscribe) === 1 ? 'addforce' : 'remove';
            // Add the user to a contact list
            if (false == SubscriptionOptionsSettings::syncContactsToMailjetList(get_option('mailjet_sync_list'), $user, $action)) {
                return false;
            } else {
                return true;
            }
        }
    }

    public function mailjet_subscribe_confirmation_from_widget($subscription_email, $instance, $subscription_locale, $widgetId = false)
    {
        $homeUrl = get_home_url();
        $language = Mailjeti18n::getCurrentUserLanguage();
        $thankYouPageId = !empty($instance[$language]['thank_you']) ? $instance[$language]['thank_you'] : false;
        $thankYouURI = $homeUrl;
        if ($thankYouPageId) {
            $thankYouURI = get_page_link($thankYouPageId);
        }
        $locale = Mailjeti18n::getLocale();

        $email_subject = !empty($instance[$locale]['email_subject']) ? apply_filters('widget_email_subject', $instance[$locale]['email_subject']) : Mailjeti18n::getTranslationsFromFile($locale, 'Subscription Confirmation');
        $email_title = !empty($instance[$locale]['email_content_title']) ? apply_filters('widget_email_content_title', $instance[$locale]['email_content_title']) : Mailjeti18n::getTranslationsFromFile($locale, 'Please confirm your subscription');
        $email_button_value = !empty($instance[$locale]['email_content_confirm_button']) ? apply_filters('widget_email_content_confirm_button', $instance[$locale]['email_content_confirm_button']) : Mailjeti18n::getTranslationsFromFile($locale, 'Yes, subscribe me to this list');
        $wpUrl = sprintf('<a href="%s" target="_blank">%s</a>', $homeUrl, $homeUrl);
        $test = sprintf(Mailjeti18n::getTranslationsFromFile($locale, 'To receive newsletters from %s please confirm your subscription by clicking the following button:'), $wpUrl);
//        $test = sprintf(__('To receive newsletters from %s please confirm your subscription by clicking the following button:', 'mailjet-for-wordpress'), $wpUrl);
        $email_main_text = !empty($instance[$locale]['email_content_main_text']) ? apply_filters('widget_email_content_main_text', sprintf($instance[$locale]['email_content_main_text'], get_option('blogname'))) : $test;
        $email_content_after_button = !empty($instance[$locale]['email_content_after_button']) ? $instance[$locale]['email_content_after_button'] : Mailjeti18n::getTranslationsFromFile($locale, 'If you received this email by mistake or don\'t wish to subscribe anymore, simply ignore this message.');
        $properties = isset($_POST['properties']) ? $_POST['properties'] : array();
        $params = array(
            'subscription_email' => $subscription_email,
            'subscription_locale' => $subscription_locale,
            'list_id' => isset($instance[$subscription_locale]['list']) ? $instance[$subscription_locale]['list'] : '',
            'properties' => $properties,
//            'thank_id' => $thankYouURI
        );

        if ($widgetId){
            $params['widget_id'] = $widgetId;
        }
        $params = http_build_query($params);
        $subscriptionTemplate = apply_filters('mailjet_confirmation_email_filename', dirname(dirname(dirname(__FILE__))) . '/templates/confirm-subscription-email.php');
        $message = file_get_contents($subscriptionTemplate);

        $permalinkStructure = get_option('permalink_structure');
        if (!$thankYouPageId) {
            $qm = '?';
        } else {
            $qm = ("" === $permalinkStructure) ? '&' : '?';
        }

        $emailData = array(
            '__EMAIL_TITLE__' => $email_title,
            '__EMAIL_HEADER__' => $email_main_text,
            '__WP_URL__' => $homeUrl,
            '__CONFIRM_URL__' => $thankYouURI . $qm . $params . '&mj_sub_token=' . sha1($params . self::WIDGET_HASH),
            '__CLICK_HERE__' => $email_button_value,
            '__FROM_NAME__' => $homeUrl, //get_option('blogname'),
            '__IGNORE__' => $email_content_after_button,
        );
        $emailParams = apply_filters('mailjet_subscription_widget_email_params', $emailData);
        foreach ($emailParams as $key => $value) {
            $message = str_replace($key, $value, $message);
        }
        add_filter('wp_mail_content_type', array($this, 'set_html_content_type'));
        return wp_mail($subscription_email, $email_subject, $message, array('From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'));
//        echo '<p class="success">' . __('Subscription confirmation email sent. Please check your inbox and confirm the subscription.', 'mailjet-for-wordpress') . '</p>';
//        die;
    }

    public function getContactListsMenu()
    {
	    $allWpUsers = get_users(array('fields' => array('ID', 'user_email')));
	    $wpUsersCount = count($allWpUsers);
	    $mailjetSyncList = (int) get_option('mailjet_sync_list');
	    $mailjetContactLists = MailjetApi::getMailjetContactLists();
	    $mailjetContactLists = !empty($mailjetContactLists) ? $mailjetContactLists : array();
	    $mailjetSyncActivated = get_option('activate_mailjet_sync');

	    wp_send_json_success([
	            'wpUsersCount' => $wpUsersCount,
                'mailjetContactLists' => $mailjetContactLists,
                'mailjetSyncActivated' => $mailjetSyncActivated,
                'mailjetSyncList' => $mailjetSyncList
        ]);
    }

    public function set_html_content_type()
    {
        return 'text/html';
    }

    public function ajaxResync()
    {
         if ($this->syncAllWpUsers()) {
            $response = [
                    'message' => 'Contact list resync has started. You can check the progress inside',
                    'ID' => 1
            ];
	        wp_send_json_success( $response );
         }else{
	        wp_send_json_error();
         }
    }

    public function enqueueScripts()
    {
        $path = plugins_url('/src/admin/js/mailjet-ajax.js', MAILJET_PLUGIN_DIR . 'src');
	    wp_register_script('ajaxHandle',  $path,  array('jquery'), false,true);
	    wp_enqueue_script( 'ajaxHandle' );
    }
}
