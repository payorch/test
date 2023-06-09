<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require ABSPATH . 'wp-includes/version.php';

/**
 * Geidea Payment Gateway class
 *
 * Extended by individual payment gateways to handle payments.
 *
 * @class       WC_Geidea
 * @extends     WC_Payment_Gateway
 * @version     1.3.2
 * @author      Geidea
 */

add_action('wp_ajax_ajax_order', array('WC_Gateway_Geidea', 'init_payment'));
add_action('wp_ajax_nopriv_ajax_order', array('WC_Gateway_Geidea', 'init_payment'));

class WC_Gateway_Geidea extends WC_Payment_Gateway
{
    public static $instance = null;

    public function __construct()
    {
        $this->id = 'geidea';

        $lang = get_bloginfo('language');

        if ($lang == "ar") {
            include_once 'lang/settings.ar.php';
        } else {
            include_once 'lang/settings.en.php';
        }

        require_once 'includes/GIFunctions.php';
        require_once 'includes/GITable.php';

        $this->config = require 'config.php';

        $this->functions = new \Geidea\Includes\GIFunctions();

        $this->method_title = geideaTitle;
        $this->has_fields = true;

        $this->errors = [];

        //Initialization the form fields
        $this->init_form_fields();

        //Initialization the settings
        $this->init_settings();

        $this->supports = array(
            'products',
            'tokenization',
            'refunds',
        );

        $this->title = $this->get_option('title');
        $this->enabled = $this->get_option('enabled');
        $this->description = $this->get_option('description');

        $this->logo = $this->get_option('checkout_icon');
        $this->icon = apply_filters('woocommerce_' . $this->id . '_icon', (!empty($this->logo)
            ? $this->logo
            : plugins_url('assets/imgs/geidea-logo.svg', __FILE__)));

        $this->tokenise_param = "wc-{$this->id}-new-payment-method";
        $this->token_id_param = "wc-{$this->id}-payment-token";

        if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
        } else {
            add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
        }
        add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page'));
        add_action('woocommerce_api_wc_' . $this->id, array($this, 'return_handler'));
        add_action('wp_footer', array($this, 'checkout_js_order_handler'));

        add_action('wp_enqueue_scripts', array($this, 'add_scroll_script'));
 
        if (!empty($_GET['wc-api']) && $_GET['wc-api'] == 'geidea') {
            do_action('woocommerce_api_wc_' . $this->id);
        }
    }

    public function add_scroll_script() {
        if (is_checkout() && !is_wc_endpoint_url()) {  
            wp_register_style('geidea', plugins_url('assets/css/gi-styles.css', __FILE__));
            wp_enqueue_style('geidea');
      
            wp_register_script('geidea', plugins_url('assets/js/script.js', __FILE__));
            wp_enqueue_script('geidea');

            wp_register_script('geidea_sdk', $this->config['jsSdkUrl']);
            wp_enqueue_script('geidea_sdk');
        }
    }

    public static function getInstance()
    {
        null === self::$instance and self::$instance = new self;
        return self::$instance;
    }

    public function checkout_js_order_handler()
    {
        $is_checkout_page = is_checkout() && !is_wc_endpoint_url();
        $is_order_pay_page = is_checkout() && is_wc_endpoint_url('order-pay');

        $form_selector = $is_checkout_page ? 'form.checkout' : 'form#order_review';
        if ($is_order_pay_page) {
            wp_register_script('geidea_sdk', $this->config['jsSdkUrl']);
            wp_enqueue_script('geidea_sdk');

            $order_id = absint( get_query_var( 'order-pay' ) );
        ?>
            <script type="text/javascript">
            jQuery(function($){
                if (typeof wc_checkout_params === 'undefined')
                    return false;

                $(document.body).on("click", "#place_order", function(evt) {
                    var choosenPaymentMethod = $('input[name^="payment_method"]:checked').val();
                    var choosenToken = $('input[name^="wc-geidea-payment-token"]:checked').val();

                    var newCardPayment = false;
                    if (typeof choosenToken === 'undefined') {
                        newCardPayment = true;
                    } else if (choosenToken == 'new') {
                        newCardPayment = true;
                    }
                    if( choosenPaymentMethod == 'geidea' && newCardPayment ) {
                        $('#place_order').attr('disabled', 'disabled');
                        evt.preventDefault();
                        $.ajax({
                            type: 'POST',
                            url: wc_checkout_params.ajax_url,
                            contentType: "application/x-www-form-urlencoded; charset=UTF-8",
                            enctype: 'multipart/form-data',
                            data: {
                                'action': 'ajax_order',
                                'fields': $('<?php echo $form_selector; ?>').serializeArray(),
                                'user_id': <?php echo get_current_user_id(); ?>,
                                'order_id': <?php echo $order_id; ?>,
                            },
                            dataType: 'json',
                            success: function (result) {
                                if (result.result == 'failure') {
                                    alert(result.message);
                                    $('#place_order').removeAttr('disabled');
                                } else {
                                    initGIPaymentOnOrderPayPage(result);
                                }
                            },
                            error: function(error) {
                                $('#place_order').removeAttr('disabled');
                                console.log(error);
                            }
                        });
                    }
                });
            });

            function initGIPaymentOnOrderPayPage(data) {
                try {
                    var onSuccess = function(_message, _statusCode) {
                        setTimeout(document.location.href = data.successUrl, 1000);
                    }

                    var onError = function(error) {
                        jQuery('#place_order').removeAttr('disabled');
                        alert(<?php echo geideaPaymentGatewayError; ?> + error.responseMessage);
                    }

                    var onCancel = function() {
                        jQuery('#place_order').removeAttr('disabled');
                    }

                    var api = new GeideaApi(data.merchantGatewayKey, onSuccess, onError, onCancel);

                    api.configurePayment({
                        callbackUrl: data.callbackUrl,
                        amount: parseFloat(data.amount),
                        currency: data.currencyId,
                        merchantReferenceId: data.orderId,
                        cardOnFile: Boolean(data.cardOnFile),
                        initiatedBy: "Internet",
                        customerEmail: data.customerEmail,
                        address: {
                            showAddress: false,
                            billing: data.billingAddress,
                            shipping: data.shippingAddress
                        },
                        merchantLogoUrl: data.merchantLogoUrl,
                        language: data.language,
                        styles: { "headerColor": data.headerColor },
                        integrationType: data.integrationType,
                        name: data.name,
                        version: data.version,
                        pluginVersion: data.pluginVersion,
                        partnerId: data.partnerId,
                        isTransactionReceiptEnabled: data.receiptEnabled === 'yes'
                    });

                    api.startPayment();
                } catch (err) {
                    alert(err);
                }

            }
            </script>
        <?php
        }
    }

    public function init_payment()
    {
        if ( (isset($_POST['fields']) && !empty($_POST['fields'])) ) {
            $payment_obj = WC_Gateway_Geidea::getInstance();
            $order = null;

            $data = [];
            foreach ($_POST['fields'] as $values) {
                $data[$values['name']] = $values['value'];
            }

            $order_id = $_POST['order_id'];

            if ($order_id != 0) {
                // Get an existing order
                $order = wc_get_order($order_id);
            }

            if ($order) {
                $order_currency = $order->currency;
                $available_currencies = $payment_obj->config['availableCurrencies'];

                $result_currency = in_array($order_currency, $available_currencies) ? $order_currency : $payment_obj->get_option('currency_id');

                $save_card = $data["wc-geidea-new-payment-method"] == true ? true : false;

                global $wp_version;
                $lang = get_bloginfo('language');

                $payment_data = [];
                $payment_data['orderId'] = (string) $order->id;
                $payment_data['amount'] = number_format($order->order_total, 2, '.', '');
                $payment_data['merchantGatewayKey'] = $payment_obj->get_option('merchant_gateway_key');
                $payment_data['currencyId'] = $result_currency;
                $payment_data['successUrl'] = $payment_obj->get_return_url($order);
                $payment_data['failUrl'] = $payment_obj->get_return_url($order);
                $payment_data['headerColor'] = $payment_obj->get_option('header_color');
                $payment_data['cardOnFile'] = $save_card;
                $payment_data['customerEmail'] = sanitize_text_field($order->get_billing_email());
                $payment_data['billingAddress'] = json_encode($payment_obj->get_formatted_billing_address($order));
                $payment_data['shippingAddress'] = json_encode($payment_obj->get_formatted_shipping_address($order));

                $callbackUrl = get_site_url() . '/?wc-api=geidea';
                // Force https for Geidea Gateway
                $payment_data['callbackUrl'] = str_replace('http://', 'https://', $callbackUrl);

                $logoUrl = $payment_obj->get_option('logo');
                // Force https for Geidea Gateway
                $payment_data['merchantLogoUrl'] = str_replace('http://', 'https://', $logoUrl);

                if ($lang == "ar") {
                    $payment_data['language'] = $lang;
                }

                $payment_data['integrationType'] = 'plugin';
                $payment_data['name'] = 'Wordpress';
                $payment_data['version'] = $wp_version;
                $payment_data['pluginVersion'] = GEIDEA_ONLINE_PAYMENTS_CURRENT_VERSION;
                $payment_data['partnerId'] = $payment_obj->get_option('partner_id');

                $text = sprintf(geideaOrderResultCreated, $order->id, $payment_obj->get_option('order_status_waiting'));
                $order->add_order_note($text);
                $status = str_replace('wc-', '', $payment_obj->get_option('order_status_waiting'));

                $order->update_status($status, $text);

                echo json_encode($payment_data);
            } else {
                $response = [
                    'result' => 'failure',
                    'messages' => geideaOrderNotFound,
                    "refresh" => false,
                    "reload" => false
                ];
                echo json_encode($response);
            }

            die();
        } else {
            $response = [
                'result' => 'failure',
                'messages' => geideaEmptyRequest,
                "refresh" => false,
                "reload" => false
            ];
            echo json_encode($response);
        }

        die();
    }

    public static function add_card_tokens_menu()
    {
        require_once 'includes/GITable.php';

        $lang = get_bloginfo('language');

        if ($lang == "ar") {
            include_once 'lang/settings.ar.php';
        } else {
            include_once 'lang/settings.en.php';
        }

        add_submenu_page(
            'woocommerce',
            geideaTokensTitle,
            geideaTokensTitle,
            'manage_woocommerce',
            'card_tokens',
            array('WC_Gateway_Geidea', 'tokens_table'),
            3
        );
    }

    /**
     * Return errors if occured
     * 
     * @return array
     */
    function upload_logo($logo, $field) {
        $errors = [];

        $arr_file_type = wp_check_filetype(basename($logo['name']));
        $uploaded_file_type = $arr_file_type['type'];

        $allowed_file_types = array('image/jpg', 'image/jpeg', 'image/png', 'image/svg+xml');

        if (in_array($uploaded_file_type, $allowed_file_types)) {

            if (!function_exists('wp_handle_upload')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $uploaded_file = wp_handle_upload($logo, array('test_form' => false, 'unique_filename_callback' => 'generate_logo_filename'));

            if (isset($uploaded_file['file'])) {
                $this->settings[$field] = $uploaded_file['url'];
            } else {
                $errors[] = geideaFileUploadingError;
            }

        } else {
            $errors[] = geideaWrongFileType;
        }

        return $errors;
    }

    public function process_admin_options()
    {
        function generate_logo_filename($dir, $name, $ext)
        {
            return "logo_" . bin2hex(random_bytes(16)) . $ext;
        }

        $this->init_settings();

        $post_data = $this->get_post_data();

        foreach ($this->get_form_fields() as $key => $field) {
            if ('title' !== $this->get_field_type($field) && ($key !== 'logo' && $key !== 'checkout_icon')) {
                try {
                    $this->settings[$key] = $this->get_field_value($key, $field, $post_data);
                } catch (Exception $e) {
                    $this->add_error($e->getMessage());
                }
            }
        }

        if (isset($_FILES['woocommerce_geidea_logo']) && ($_FILES['woocommerce_geidea_logo']['size'] > 0)) {
            $field = 'logo';
            $upload_errors = $this->upload_logo($_FILES['woocommerce_geidea_logo'], $field);
            $this->errors = array_merge($this->errors, $upload_errors);
        }

        if (isset($_FILES['woocommerce_geidea_checkout_icon']) && ($_FILES['woocommerce_geidea_checkout_icon']['size'] > 0)) {
            $field = 'checkout_icon';
            $upload_errors = $this->upload_logo($_FILES['woocommerce_geidea_checkout_icon'], $field);
            $this->errors = array_merge($this->errors, $upload_errors);
        }

        if (!empty($this->errors)) {
            $this->enabled = false;
            $this->settings['enabled'] = 'no';

            foreach ($this->errors as $v) {
                WC_Admin_Settings::add_error($v);
            }
        }
        $this->settings['return_url'] = 'yes';

        $are_valid_credentials = false;
        $merchant_config = [];
        if (!empty($this->settings['merchant_gateway_key'])) {
            $result = $this->get_merchant_config($this->settings['merchant_gateway_key'], $this->settings['merchant_password']);

            if ($result['errors']) {
                $are_valid_credentials = false;
            } else {
                $are_valid_credentials = true;
                $merchant_config = $result['config'];

                $this->settings['available_currencies'] = implode(',', $merchant_config['currencies']);

                $availablePaymentMethods = [];
                foreach ($merchant_config['paymentMethods'] as $paymentMethod) {
                    $availablePaymentMethods[] = $paymentMethod;
                }
    
                if ($merchant_config['applePay']['isApplePayWebEnabled'] == true) {
                    $availablePaymentMethods[] = 'applepay';
                }

                $this->settings['avaliable_payment_methods'] = implode(',', $availablePaymentMethods);
            }
        }
        
        if (!$are_valid_credentials) {
            $this->settings['valid_creds'] = false;
            if (!empty($this->settings['merchant_gateway_key'])) {
                WC_Admin_Settings::add_error(geideaInvalidCredentials);
            }
        } else {
            $this->settings['valid_creds'] = true;
        }

        if ($this->settings['needs_setup'] == 'true') {
            $this->settings['needs_setup'] = 'false';
        }

        return update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings), 'yes');
    }

    public function process_payment( $order_id ) {
        $order = new WC_Order($order_id);

        $user_id = get_current_user_id();

        $save_card = false;

        if ($user_id != 0) {
            $token = $this->get_token();
            if ($token) {
                $success = $this->tokenise_payment($order, $token);

                if ($success) {
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order),
                    );
                } else {
                    return array('result' => 'fail');
                }
            }

            $save_card = $this->need_to_save_new_card($user_id);
        }

        //get information of order
        $order = wc_get_order($order_id);

        $order_currency = $order->currency;
        $available_currencies = $this->config['availableCurrencies'];

        $result_currency = in_array($order_currency, $available_currencies) ? $order_currency : $this->get_option('currency_id');

        global $wp_version;
        $lang = get_bloginfo('language');

        $result_fields = [];
        $result_fields['orderId'] = $order->id;
        $result_fields['amount'] = number_format($order->order_total, 2, '.', '');
        $result_fields['merchantGatewayKey'] = $this->get_option('merchant_gateway_key');
        $result_fields['currencyId'] = $result_currency;
        $result_fields['successUrl'] = $this->get_return_url($order);
        $result_fields['failUrl'] = $this->get_return_url($order);
        $result_fields['headerColor'] = $this->get_option('header_color');
        $result_fields['cardOnFile'] = $save_card;
        $result_fields['customerEmail'] = sanitize_text_field($order->get_billing_email());

        $result_fields['customerPhoneNumber'] = $order->get_billing_phone();
        if ($result_fields['customerPhoneNumber'][0] != '+') {
            $result_fields['customerPhoneNumber'] = '+'. $result_fields['customerPhoneNumber'];
        }

        $result_fields['billingAddress'] = json_encode($this->get_formatted_billing_address($order));
        $result_fields['shippingAddress'] = json_encode($this->get_formatted_shipping_address($order));

        $callbackUrl = get_site_url() . '/?wc-api=geidea';
        // Force https for Geidea Gateway
        $result_fields['callbackUrl'] = str_replace('http://', 'https://', $callbackUrl);

        $logoUrl = $this->get_option('logo');
        // Force https for Geidea Gateway
        $result_fields['merchantLogoUrl'] = str_replace('http://', 'https://', $logoUrl);

        if ($lang == "ar") {
            $result_fields['language'] = $lang;
        }

        $result_fields['integrationType'] = 'plugin';
        $result_fields['name'] = 'Wordpress';
        $result_fields['version'] = $wp_version;
        $result_fields['pluginVersion'] = GEIDEA_ONLINE_PAYMENTS_CURRENT_VERSION;
        $result_fields['partnerId'] = $this->get_option('partner_id');

        $encode_params = json_encode($result_fields);

        $script = '
            <script>
                initGIPaymentOnCheckoutPage('.$encode_params.');
            </script>
        ';

        return array(
            'result' => 'success',
            'messages' => $script,
            'refresh' => true,
            'reload' => false,
        );
    }

    /**
     * Output form of setting payment system.
     */
    public function init_form_fields()
    {
        wp_register_style('geidea', plugins_url('assets/css/gi-styles.css', __FILE__));
        wp_enqueue_style('geidea');

        $statuses = wc_get_order_statuses();

        $options = get_option('woocommerce_' . $this->id . '_settings');

        $logo = $options['logo'];
        $merchantLogo = sanitize_text_field($logo);
        $merchantLogoDescr = geideaMerchantLogoDescription;
        if (!empty($merchantLogo)) {
            $merchantLogoDescr .= '</br><img src="' . esc_html($merchantLogo) . '" width="70"></br>';
        }

        $checkoutIcon = $options['checkout_icon'];
        $checkoutIcon = sanitize_text_field($checkoutIcon);
        $checkoutIconDescr = geideaCheckoutIconDescription;
        if (empty($checkoutIcon)) {
            $checkoutIcon = plugins_url('assets/imgs/geidea-logo.svg', __FILE__);

        }
        $checkoutIconDescr .= '</br><img src="' . esc_html($checkoutIcon) . '" width="70"></br>'; 

        $available_currencies = explode(",", $options['available_currencies']);
        $currency_options = ['' => ''];
        foreach ($available_currencies as $currency) {
            $currency_options[$currency] = $this->config['currenciesMapping'][$currency];
        }

        $availablePaymentMethods = [];
        foreach (explode(",", $options['avaliable_payment_methods']) as $paymentMethod) {
            $availablePaymentMethods[] = $this->config['paymentMethodsMapping'][$paymentMethod];
        }

        $disable_extra_fields = !$options['valid_creds'];

        if ($availablePaymentMethods) {
            $default_title = implode(", ", $availablePaymentMethods);
        } else {
            $default_title = geideaAvailablePaymentMethodsByDefault;
        }

        $this->form_fields = array(
            'enabled' => array(
                'title' => geideaSettingsActive,
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
            ),
            'merchant_gateway_key' => array(
                'title' => geideaSettingsMerchant . ' *',
                'type' => 'text',
                'description' => '<p class="geidea-error-message">'.geideaInvalidCredentials.'</p>',
                'default' => '',
                'class' => 'geidea-merchant-gateway-key'
            ),
            'merchant_password' => array(
                'title' => geideaSettingsPassword . ' *',
                'type' => 'text',
                'description' => '',
                'default' => '',
                'class' => 'geidea-merchant-password'
            ),
            'title' => array(
                'title' => geideaSettingsName,
                'type' => 'text',
                'default' => $default_title, 
                'class' => 'geidea-extra-field',
                'description' => sprintf(geideaForExample, $default_title),
                'disabled' => $disable_extra_fields
            ),
            'description' => array(
                'title' => geideaSettingsDesc,
                'type' => 'textarea',
                'description' => geideaDescriptionHint,
                'default' => geideaTitleDesc,
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields
            ),
            'currency_id' => array(
                'title' => geideaSettingsCurrency . ' *',
                'type' => 'select',
                'options' => $currency_options,
                'default' => '',
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields			   
            ),
            'checkout_icon' => array(
                'title' => geideaCheckoutIcon,
                'type' => 'file',
                'description' => $checkoutIconDescr,
                'default' => '',
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields
            ),
            'logo' => array(
                'title' => geideaMerchantLogo,
                'type' => 'file',
                'description' => $merchantLogoDescr,
                'default' => '',
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields
            ),
            'header_color' => array(
                'title' => geideaSettingsHeaderColor,
                'type' => 'text',
                'description' => geideaSettingsHeaderColorDesc,
                'default' => '',
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields
            ),
            'partner_id' => array(
                'title' => geideaSettingsPartnerId,
                'type' => 'text',
                'description' => '',
                'default' => '',
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields
            ),
            'receipt_enabled' => array(
                'title' => geideaSettingsReceiptEnabled,
                'type' => 'checkbox',
                'label' => ' ',
                'default' => 'no',
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields
            ),
            'order_status_success' => array(
                'title' => geideaSettingsOrderStatusSuccess,
                'type' => 'select',
                'options' => $statuses,
                'default' => 'wc-processing',
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields
            ),
            'order_status_waiting' => array(
                'title' => geideaSettingsOrderStatusWaiting,
                'type' => 'select',
                'options' => $statuses,
                'default' => 'wc-pending',
                'class' => 'geidea-extra-field',
                'disabled' => $disable_extra_fields
            ),
            'needs_setup' => array(
                'type' => 'hidden',
                'default' => 'true',
                'class' => 'geidea-extra-field-hidden'
            ),
            'valid_creds' => array(
                'type' => 'hidden',
                'default' => 'false',
                'class' => 'geidea-extra-field-hidden'
            ),
            'available_currencies' => array(
                'type' => 'hidden',
                'default' => '',
                'class' => 'geidea-extra-field-hidden'
            ),
            'avaliable_payment_methods' => array(
                'type' => 'hidden',
                'default' => '',
                'class' => 'geidea-extra-field-hidden'
            ),
        );
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        $this->tokenization_script();
        $this->saved_payment_methods();
        $this->save_payment_method_checkbox();
    }

    /**
     *
     * @return array
     */
    private function get_options()
    {
        $options = get_option('woocommerce_' . $this->id . '_settings');
        $settings = [];
        if (isset($options['merchant_gateway_key'])) {
            $settings['merchantGatewayKey'] = $options['merchant_gateway_key'];
        }
        if (isset($options['currency_id'])) {
            $settings['currencyId'] = $options['currency_id'];
        }
        if (isset($options['currency_default'])) {
            $settings['currencyDefault'] = $options['currency_default'];
        }
        if (isset($options['order_status_success'])) {
            $settings['orderStatusSuccess'] = $options['order_status_success'];
        }
        if (isset($options['order_status_waiting'])) {
            $settings['orderStatusWaiting'] = $options['order_status_waiting'];
        }

        $settings['returnUrl'] = get_site_url() . '/?wc-api=geidea';

        return $settings;
    }

    private function delete_token($token_id)
    {
        $result = WC_Payment_Tokens::delete((int) $token_id);
    }

    /*
     * Returns array with merchant config and errors if they occured
     */
    private function get_merchant_config($merchant_key, $password = '')
    {
        $errors = [];
        $config = [];

        $response = $this->functions->send_gi_request(
            $this->config['merchantConfigUrl'] . '/' . $merchant_key,
            $merchant_key,
            $password,
            [],
            'GET'
        );

        if ($response instanceof WP_Error) {
            $error = $response->get_error_message();
            $errors[] = $error;
            
            return [
                'config' => $config,
                'errors' => $errors
            ];
        }

        $decoded_response = json_decode($response["body"], true);

        if (!empty($decoded_response["errors"])) {
            foreach ($decoded_response["errors"] as $k => $v) {
                foreach ($v as $error) {
                    $errors[] = $error;
                }
            }
        }

        if (isset($decoded_response["detailedResponseMessage"])) {
            if ($decoded_response["responseMessage"] != 'Success') {
                $errors[] = $decoded_response["detailedResponseMessage"];
            } else {
                $config = $decoded_response;
            }
        }

        return [
            'config' => $config,
            'errors' => $errors
        ];
    }

    /**
     * Admin Panel Options
     * The output html form - settings to the admin panel
     * */
    public function admin_options()
    {
        wp_register_script('geidea-admin-script', plugins_url('assets/js/admin-script.js', __FILE__));
        wp_enqueue_script('geidea-admin-script');

        $options = get_option('woocommerce_' . $this->id . '_settings');
        $settings = [];
        $settings['needs_setup'] = true;
        if (isset($options['needs_setup'])) {
            $settings['needs_setup'] = filter_var($options['needs_setup'], FILTER_VALIDATE_BOOLEAN);
        }

        $settings['valid_creds'] = false;
        if (isset($options['valid_creds'])) {
            $settings['valid_creds'] = filter_var($options['valid_creds'], FILTER_VALIDATE_BOOLEAN);
        }

        $are_valid_credentials = $settings['valid_creds'];

        $merchant_config = [];
        if ($settings['needs_setup'] == true) {
            if (!empty($options['merchant_gateway_key'])) {
                $result = $this->get_merchant_config(
                    $options['merchant_gateway_key'],
                    $options['merchant_password']
                );

                if ($result['errors']) {
                    $are_valid_credentials = false;
                } else {
                    $are_valid_credentials = true;
                    $merchant_config = $result['config'];
                }
            }
        }

        if (!$are_valid_credentials) {?>
            <script>
                 jQuery(function($) {
                    if ($("#woocommerce_geidea_merchant_gateway_key").val()){
                        $('.geidea-error-message').each(function(i, obj) {
                            obj.style.display = "block";
                        });

                        $("#woocommerce_geidea_merchant_gateway_key").addClass('geidea-invalid-field');
                    }
                 })
            </script>
        <?php }
        ?>
        <h1><?php echo geideaTitle ?></h1>
        <p><em><?php echo geideaEditableFieldsHint ?></em></p>
        <table class="form-table">
        <?php
        //Generate the HTML for the settings form.
        $form_fields = $this->get_form_fields();

        if ($are_valid_credentials && $settings['needs_setup']) {
            $currency_options = [];
            foreach ($merchant_config['currencies'] as $currency) {
                $currency_options[$currency] = $this->config['currenciesMapping'][$currency];
            }

            $form_fields['currency_id']['options'] = $currency_options;

            $availablePaymentMethods = [];
            foreach ($merchant_config['paymentMethods'] as $paymentMethod) {
                $availablePaymentMethods[] = $this->config['paymentMethodsMapping'][$paymentMethod];
            }

            if ($merchant_config['applePay']['isApplePayWebEnabled'] == true) {
                $availablePaymentMethods[] = $this->config['paymentMethodsMapping']['applepay'];
            }

            if ($availablePaymentMethods) {
                $default_title = implode(", ", $availablePaymentMethods);
            } else {
                $default_title = geideaAvailablePaymentMethodsByDefault;
            }
            
            $form_fields['title']['description'] = sprintf(geideaForExample, $default_title);
        }
        
        $this->generate_settings_html($form_fields, true);
        ?>
        </table>
        <?php
}

    public function tokens_table()
    {
        $second_action = (isset($_POST['action2'])) ? sanitize_key($_POST['action2']) : false;
        if ($second_action && $second_action == 'delete') {
            foreach ($_POST as $k => $param) {
                $san_param = sanitize_key($k);
                if (substr($san_param, 0, 12) == "delete_token") {
                    $token_id = str_replace("delete_token_", "", $san_param);
                    $this->delete_token($token_id);
                }
            }
        }

        if (isset($_GET['action'])) {
            $san_token = (isset($_GET['token'])) ? sanitize_key($_GET['token']) : false;
            if ($san_token && is_numeric($san_token)) {
                $token_id = (int) $san_token;
                $this->delete_token($token_id);
            }
        }

        ?>
        <div class="wrap"><h2><?php echo geideaTokensTitle ?></h2>
            <form method="post">
            <?php
render_tokens_table();
        ?>
            </form>
        </div>
        <?php
}

    public function get_formatted_billing_address($order)
    {
        $billing_street = $order->get_billing_address_1();
        $billing_street .= " " . $order->get_billing_address_2();
        $formatted_address = [
            'country' => sanitize_text_field(
                $this->functions->convert_country_code($order->get_billing_country())
            ),
            'street' => sanitize_text_field($billing_street),
            'city' => sanitize_text_field($order->get_billing_city()),
            'postcode' => sanitize_text_field($order->get_billing_postcode()),
        ];

        return $formatted_address;
    }

    public function get_formatted_shipping_address($order)
    {
        $shipping_street = $order->get_shipping_address_1();
        $shipping_street .= " " . $order->get_shipping_address_2();
        $formatted_address = [
            'country' => sanitize_text_field(
                $this->functions->convert_country_code($order->get_shipping_country())
            ),
            'street' => sanitize_text_field($shipping_street),
            'city' => sanitize_text_field($order->get_shipping_city()),
            'postcode' => sanitize_text_field($order->get_shipping_postcode()),
        ];

        return $formatted_address;
    }

    private function get_card_icon($icon_name)
    {
        $iconPath = GEIDEA_DIR . "assets/imgs/icons/{$icon_name}.svg";
        $icon = '';
        if (file_exists($iconPath)) {
            $icon = GEIDEA_ICONS_URL . "{$icon_name}.svg";
        }

        return $icon;
    }

    public function get_saved_payment_method_option_html($token) {
        $icon_url = $this->get_card_icon($token->get_data()['card_type']);

		$html = sprintf(
			'<li class="woocommerce-SavedPaymentMethods-token">
				<input id="wc-%1$s-payment-token-%2$s" type="radio" name="wc-%1$s-payment-token" value="%2$s" style="width:auto;" class="woocommerce-SavedPaymentMethods-tokenInput" %4$s />
                <img class="gi-card-icon" src="%5$s" />
				<label for="wc-%1$s-payment-token-%2$s">%3$s</label>
			</li>',
			esc_attr($this->id),
			esc_attr($token->get_id()),
			esc_html($token->get_display_name()),
			checked($token->is_default(), true, false),
            esc_attr($icon_url)
		);

        return apply_filters( 'woocommerce_payment_gateway_get_saved_payment_method_option_html', $html, $token, $this );
    } 

    private function get_token()
    {
        $token_id = sanitize_key($_POST[$this->token_id_param]);

        if (!$token_id) {
            return null;
        }

        if ($token_id === 'new') {
            return false;
        }

        $token = WC_Payment_Tokens::get($token_id);

        if ($token->get_user_id() !== get_current_user_id()) {
            return null;
        }

        return $token;
    }

    public function need_to_save_new_card($user_id)
    {
        $token_id = sanitize_key($_POST[$this->token_id_param]);
        $save_token = sanitize_key($_POST[$this->tokenise_param]);

        $all_tokens = WC_Payment_Tokens::get_customer_tokens($user_id, $this->id);

        // if token is new or there are no tokens for this customer
        if (($token_id === 'new' || !$all_tokens) && $save_token) {
            return true;
        } else {
            return false;
        }
    }

    public function tokenise_payment($order, $token)
    {
        $params = [];

        $order_currency = $order->currency;
        $available_currencies = $this->config['availableCurrencies'];

        $result_currency = in_array($order_currency, $available_currencies) ? $order_currency : $this->get_option('currency_id');
        $params["currency"] = $result_currency;

        $params["amount"] = number_format($order->order_total, 2, '.', '');

        $params["tokenId"] = $token->get_token();

        $params["initiatedBy"] = "Internet";
        $params["merchantReferenceId"] = (string) $order->id;

        $callbackUrl = get_site_url() . '/?wc-api=geidea';
        // Force https for Geidea Gateway
        $params['callbackUrl'] = str_replace('http://', 'https://', $callbackUrl);

        $merchantKey = $this->get_option('merchant_gateway_key');
        $password = $this->get_option('merchant_password');

        $result = $this->functions->send_gi_request(
            $this->config['payByTokenUrl'], 
            $merchantKey, 
            $password, 
            $params
        );

        if ($result instanceof WP_Error) {
            $error = $result->get_error_message();
            wc_add_notice($error, 'error');
            return false;
        } else {
            $decoded_result = json_decode($result["body"], true);
        }

        if (!empty($decoded_result["errors"])) {
            foreach ($decoded_result["errors"] as $k => $v) {
                foreach ($v as $error) {
                    wc_add_notice($error, 'error');
                }
            }
            return false;
        }

        if (isset($decoded_result["detailedResponseMessage"])) {
            if ($decoded_result["responseMessage"] != 'Success') {
                wc_add_notice($decoded_result["detailedResponseMessage"], 'error');
                return false;
            }
        }

        return true;
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return false;
        }

        $options = $this->get_options();
        // Order success status in settings at the time of placing order
        $successStatus = $order->get_meta('Order Success Status Setting', true);
        if ($order->post_status != $options['orderStatusSuccess']
            && $order->post_status != $successStatus) {
            throw new Exception(geideaRefundNotCompletedOrderError);
        }

        if ($order->order_total != $amount) {
            throw new Exception(geideaRefundInvalidAmountError);
        }

        $values = [];
        $values['orderId'] = $order->get_meta('Geidea Order Id', true);
        $merchantKey = $this->get_option('merchant_gateway_key');
        $password = $this->get_option('merchant_password');

        $result = $this->functions->send_gi_request(
            $this->config['refundUrl'],
            $merchantKey,
            $password,
            $values
        );

        if ($result instanceof WP_Error) {
            $error = $result->get_error_message();
            wc_add_notice($error, 'error');
            return false;
        } else {
            $decoded_result = json_decode($result["body"], true);
        }

        if (!empty($decoded_result['errors'])) {
            throw new Exception(geideaPaymentGatewayError);
        }

        if ($decoded_result['responseCode'] != '000') {
            $error_message = sprintf(geideaRefundTransactionError, $decoded_result['detailedResponseMessage']);
            throw new Exception($error_message);
        }

        if ($decoded_result['order']['detailedStatus'] != 'Refunded') {
            throw new Exception(geideaRefundIncorrectStatus);
        }

        $transactions = $decoded_result['order']['transactions'];
        $refund_transaction = null;
        foreach ($transactions as $t) {
            if ($t['type'] == 'Refund') {
                $refund_transaction = $t;
            }
        }

        if (!empty($refund_transaction)) {
            $text = sprintf(geideaOrderRefunded,
                $reason,
                $refund_transaction["transactionId"],
                $refund_transaction["amount"]);
            $order->add_order_note($text);
        }

        return true;
    }

    /**
     * Return handler for Hosted Payments
     */
    public function return_handler()
    {
        try {
            $json_body = file_get_contents("php://input");
            $result = json_decode($json_body, true);

            if ($result == null) {
                echo "Invalid request!";
                http_response_code(400);
                die();
            }

            $order = $result["order"];
            $callback_signature = $result["signature"];

            if ($order["merchantReferenceId"] == null) {
                echo "Order id is not defined!";
                http_response_code(400);
                die();
            }

            $merchant_key = $this->get_option('merchant_gateway_key');
            $currency = $order['currency'];
            $order_id = $order['orderId'];
            $order_status = $order['status'];
            $merchant_reference_id = $order['merchantReferenceId'];
            $merchant_password = $this->get_option('merchant_password');

            $amount = (string) number_format($order['amount'], 2, '.', '');

            $result_string = $merchant_key . $amount . $currency . $order_id . $order_status . $merchant_reference_id;

            $hash = hash_hmac('sha256', $result_string, $merchant_password, true);
            $result_signature = base64_encode($hash);

            if ($result_signature != $callback_signature) {
                echo "Invalid signature!";
                http_response_code(400);
                die();
            }

            try {
                $wc_order = new \WC_Order($order["merchantReferenceId"]);
            } catch (Exception $e) {
                echo esc_html("Order with id " . $order["merchantReferenceId"] . " not found!");
                http_response_code(404);
                die();
            }

            //get the order amount
            global $wpdb;
            $orders_fields = $wpdb->get_results("SELECT * FROM {$wpdb->postmeta} WHERE post_id = " . $wc_order->id . " ;");
            foreach ($orders_fields as $value) {
                if ($value->meta_key != '_order_total') {
                    continue;
                }
                $order_total = $value->meta_value;
            }

            //checking on the order amount
            if (number_format($order_total, 2, '.', '') != $order["amount"] &&
                (empty($wc_order->post_status) || $wc_order->post_status != 'wc-failed')) {
                echo "Invalid order amount!";
                http_response_code(400);
                die();
            }

            $options = $this->get_options();
            if (mb_strtolower($order["status"]) == "success" &&
                mb_strtolower($order["detailedStatus"]) == "paid") {
                //save token block
                $user_id = $wc_order->get_user_id();
                if ($order["cardOnFile"] == true && $user_id != 0) {
                    $token_id = $order["tokenId"];
                    $card_number = substr($order["paymentMethod"]["maskedCardNumber"], -4);
                    $expiry_date = $order["paymentMethod"]["expiryDate"];
                    $card_type = $order["paymentMethod"]["brand"];

                    $this->save_token($token_id, $card_number, $expiry_date, $card_type, $user_id);
                }

                $wc_order->update_meta_data('Geidea Order Id', $order_id);
                $wc_order->update_meta_data('Merchant Reference Id', $merchant_reference_id);
                $wc_order->update_meta_data('Order Success Status Setting', $options["orderStatusSuccess"]);

                $this->payment_complete($wc_order);
                // Remove cart
                WC()->cart->empty_cart();

                echo "Order is completed!";
                http_response_code(200);
                die();
            } elseif (mb_strtolower($order["status"]) == "failed" &&
                $wc_order->post_status != $options["orderStatusSuccess"]) {
                $last_transaction = end($order['transactions']);
                $codes = $last_transaction['codes'];

                $text = sprintf(
                    "%s: %s; %s: %s",
                    $codes["responseCode"],
                    $codes["responseMessage"],
                    $codes["detailedResponseCode"],
                    $codes["detailedResponseMessage"]
                );
                $wc_order->add_order_note($text);
                $wc_order->update_meta_data('Geidea Order Id', $order_id);
                $wc_order->update_meta_data('Merchant Reference Id', $merchant_reference_id);
                $wc_order->update_meta_data('Detailed payment gate response message', $text);
                $wc_order->update_meta_data('Order Success Status Setting', $options["orderStatusSuccess"]);

                $wc_order->update_status(apply_filters('woocommerce_payment_complete_order_status', 'failed', $wc_order->id));

                echo "Payment failed!";
                http_response_code(200);
                die();
            } else {
                echo "Not found!";
                http_response_code(404);
                die();
            }
        } catch (Exception $return_handler_exc) {
            echo "Internal Server Error!";
            echo esc_html($return_handler_exc);
            http_response_code(500);
            die();
        }
    }

    public function save_token($token_id, $card_number, $expiry_date, $card_type, $user_id)
    {
        $token = WC_Payment_Tokens::get($token_id);

        $token_exists = false;
        $all_tokens = WC_Payment_Tokens::get_customer_tokens($user_id, $this->id);

        foreach ($all_tokens as $t) {
            if ($t->get_token() == $token_id) {
                $token_exists = true;
            }
        }

        if (!$token_exists) {
            $new_token = new WC_Payment_Token_CC();
            $new_token->set_token($token_id); // Token comes from payment processor
            $new_token->set_gateway_id($this->id);
            $new_token->set_last4($card_number);
            $new_token->set_expiry_year("20" . $expiry_date['year']);
            $new_token->set_expiry_month((string) $expiry_date['month']);
            $new_token->set_card_type($card_type);
            $new_token->set_user_id($user_id);
            // Save the new token to the database
            $new_token->save();
        } else {
            echo "The token already exists!";
        }
    }

    public function payment_complete($order)
    {
        do_action('woocommerce_pre_payment_complete', $order->id);

        if (null !== WC()->session) {
            WC()->session->set('order_awaiting_payment', false);
        }
        if ($order->id) {
            $order_needs_processing = false;

            if (sizeof($order->get_items()) > 0) {
                foreach ($order->get_items() as $item) {
                    if ($_product = $order->get_product_from_item($item)) {
                        $virtual_downloadable_item = $_product->is_downloadable() && $_product->is_virtual();

                        if (apply_filters('woocommerce_order_item_needs_processing', !$virtual_downloadable_item, $_product, $order->id)) {
                            $order_needs_processing = true;
                            break;
                        }
                    } else {
                        $order_needs_processing = true;
                        break;
                    }
                }
            }

            $options = $this->get_options();
            $order->update_status(apply_filters('woocommerce_payment_complete_order_status', str_replace('wc-', '', $options["orderStatusSuccess"]), $order->id));

            add_post_meta($order->id, '_paid_date', current_time('mysql'), true);

            // Payment is complete so reduce stock levels
            if (apply_filters('woocommerce_payment_complete_reduce_order_stock', !get_post_meta($order->id, '_order_stock_reduced', true), $order->id)) {
                $order->reduce_order_stock();
            }
            do_action('woocommerce_payment_complete', $order->id);
        } else {
            do_action('woocommerce_payment_complete_order_status_' . $order->get_status(), $order->id);
        }
    }

}
?>