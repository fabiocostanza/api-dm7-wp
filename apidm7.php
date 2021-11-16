<?php
/*
Plugin Name: Hubitat API DM7
Plugin URI: https://www.wearehubitat.com/hubitat-api-dm7/
Description: Hubitat API DM7
Author: hubitat
Author URI: https://www.wearehubitat.com/
Version: 1.2
*/


//add_action('woocommerce_payment_complete', 'login_dm7', 10, 1);




//hook funzionante 
add_action('woocommerce_before_thankyou', 'login_dm7');

function login_dm7($order_id) {
	//@file_put_contents(ABSPATH . 'debug.log', $order_id . "\n", FILE_APPEND);
		
    $order = new WC_Order($order_id);
	
	$url_api = get_option( 'apidm7' );	
	
	$items = $order->get_items(); 
	
	$prodotti_dm7 = explode(",",$url_api['api_select_products']);
	
	foreach ( $items as $item_id => $item ) {
	   $product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
	   if (in_array($product_id, $prodotti_dm7)) {
		   // se il prodotto fa parte di attivazione prodotto DM7, chiamo API.
		   $order->update_status('completed');
    
			

			$response = wp_remote_post($url_api['api_login'], array(
				'method'      => 'POST', // Use 'GET' for GET request
				'timeout'     => 45,
				'headers'     => array(),
				'sslverify'   => false,
				'body'        => array(
					'username' => 'wearehubitat_admin',
					'password' => 'Hubitat1234'
					),
				)
			);

			//Get the response
			if ( is_wp_error( $response ) ) {
				$error_message = $response->get_error_message();
			   // echo "Errore: $error_message";		

				$upload_dir = plugin_dir_path( __DIR__ ).'/hubitat-api-dm7/debug.txt';
				file_put_contents($upload_dir, $error_message. "\n", FILE_APPEND);

			} else {		
				$json = json_decode($response['body'], true);		
				$dm7_token = $json['data']['access_token'];	

				$to = $url_api['api_notification_email'];

				$environment = dm7_add_environment($dm7_token, $url_api['api_env_setting_id'], $order->get_billing_email(), $url_api['api_add_doc_environment']);

				$company = ($environment['msg'] == "Ambiente documentale gia' presente nel sistema ") ? api_report_mail_dm7($to, $url_api['api_add_company'], $order) : dm7_add_company($dm7_token, $environment['data']['id'], $url_api['api_add_company'], $order);


				if( isset( $company['data'] ) ){
					$user = dm7_add_user($dm7_token, $environment['data']['id'], $company['data']['id'], $url_api['api_add_user'], $order);

					($user['msg'] == "Creazione utente   NON RIUSCITO : Il nome utente scelto esiste gia'. Riprovare con un altro!") ? api_report_mail_dm7($to, $url_api['api_add_user'], $order) : '';

				}		
			}		   
	    }
	}	 
}  


function dm7_add_environment($token, $env_setting_id, $email, $url) {
    
    $args = array(
            'headers' => array(
                'dm7auth' => $token,
            ),
            'body' => array(
                'name'=> $email,
                'env_setting_id' => $env_setting_id,
                'send_mail'=> true
            ),
            'sslverify' => false,
            'timeout' => '40'
        );

        $response = wp_remote_post($url, $args);

        if ($response instanceof WP_Error) {
            throw new Exception('Si è verificato un errore durante il tentativo di accesso ' . $url);
        }
    return json_decode($response['body'], TRUE);
}

function dm7_add_company($token, $env_id, $url, $order) {
    $args = array(
            'headers' => array(
                'dm7auth' => $token,
            ),
            'body' => array(
                'customer_code'=> 'WC-' . $order->get_order_number(),
                'company_type' => 'AZ',
                'company_name' => $order->get_billing_company() ? $order->get_billing_company() : $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'address' => $order->get_billing_address_1(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_shipping_country(),
                'province' => $order->get_billing_state(),
                'zipcode' => $order->get_billing_postcode(),
                'taxcode' => 'xxxxxxxxxxx',
                'vatcode' => '',
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'website' => '',
                'environment_id' => $env_id,
                'contact_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'contact_email' => ($a = get_userdata($order->get_user_id() )) ? $a->user_email : '',
                'contact_phone' => $order->get_billing_phone()
            ),
            'sslverify' => false,
            'timeout' => '40'
        );
            
        $response = wp_remote_post($url, $args);

        if ($response instanceof WP_Error) {
            throw new Exception('Si è verificato un errore durante il tentativo di accesso ' . $url);
        }

    return json_decode($response['body'], TRUE);
}

function dm7_add_user($token, $env_id, $company_id, $url, $order) {
    $args = array(
            'headers' => array(
                'dm7auth' => $token,
            ),
            'body' => array(					
                'username'=> $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'company_id' => $company_id,
                'environment_id' => $env_id,
                'email' => $order->get_billing_email(),
                "enabled" => true,
                "roles"  => array("usr")					
            ),
            'sslverify' => false,
            'timeout' => '40'
        );
    

        $response = wp_remote_post($url, $args);

        if ($response instanceof WP_Error) {
            throw new Exception('Si è verificato un errore durante il tentativo di accesso ' . $url);
        }
    return json_decode($response['body'], TRUE);
}



function api_report_mail_dm7($to, $endpoint, $request = null) {
    $subject = sprintf(__('DM7 Error report: %s'), $endpoint);
    $message = '';
    // $message .= sprintf(__('The %s/%s request to: %s with request body:'), $method, $service, $endpoint);
    $message .= 'Lista piano acquistato: ';
    if (!empty($request)) {
        
        $json_req = json_decode($request, true);
        $order_id = $json_req['id'];
        
        
        if (!empty($order_id)) {
            $order = new WC_Order($order_id);
            $product_details = array();
            $products = $order->get_items(); 	
            foreach ( $products as $prod ) {					
                $product_details[] = $prod->get_name()." x ".$prod->get_quantity();
            }
            $product_list = implode( ',', $product_details );
        }
        
        $message .= "\n\n";
        $message .= $product_list;
        $message .= "\n\n";
                
        $message .= 'Dettagli request: ';
        $message .= "\n\n";		
        $message .= json_encode($json_req, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $message .= "\n\n";

    return wp_mail($to, $subject, $message);
}

class HubitatSettingsPage
{
    private $options;
    private $option_name;
    private $page;

    /**
     * Start up
     */
    public function __construct()
    {
        $this->option_name = 'apidm7' ;        
        $this->page = 'apidm7-setting-admin' ; 
        $this->group = 'apidm7-option-group' ;
        add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
        add_action( 'admin_init', array( $this, 'page_init' ) );
    }

    /**
     * Add options page
     */
    public function add_plugin_page()
    {
        // This page will be under "Settings"
        add_options_page(
			'Impostazioni API DM7', // page_title
			'API DM7', // menu_title
			'manage_options', // capability
			$this->page, // menu_slug
			array( $this, 'create_admin_page' ) // function
		);
    }

    /**
     * Options page callback
     */
    public function create_admin_page()
    {
        // Set class property
        $this->options = get_option( $this->option_name );  
        ?>
        <div class="wrap">
            <?php screen_icon(); ?>
            <h2>Impostazioni DM7</h2>    
            <p>Parametri di configurazione per la connessione al gestionale DM7</p>
            <?php settings_errors(); ?>
       
            <form method="post" action="options.php">
            <?php                
                settings_fields(  $this->group );   
                do_settings_sections( $this->page );
                submit_button(); 
            ?>
            </form>
        </div>
        <?php
    }

    /**
     * Register and add settings
     */
    public function page_init()
    {        
        register_setting(
            $this->group, 
            $this->option_name, 
            array( $this, 'sanitize' ) 
        );

        add_settings_section(
            'setting_section_id', 
            'Impostazioni connessione API DM7',
            array( $this, 'print_section_info' ), 
            $this->page // Page
        );  

        add_settings_field(
			'api_login', // id
			'API LOGIN', // title
			array( $this, 'api_login_callback' ), // callback
			$this->page, // page
			'setting_section_id' // section
		);

		add_settings_field(
			'api_add_doc_environment', // id
			'API ADD_DOC_ENVIRONMENT', // title
			array( $this, 'api_add_doc_environment_callback' ), // callback
			$this->page, // page
			'setting_section_id' // section
		);

		add_settings_field(
			'api_add_company', // id
			'API ADD_COMPANY', // title
			array( $this, 'api_add_company_callback' ), // callback
			$this->page, // page
			'setting_section_id' // section
		);

		add_settings_field(
			'api_add_user', // id
			'API ADD_USER', // title
			array( $this, 'api_add_user_callback' ), // callback
			$this->page, // page
			'setting_section_id' // section
		);

		add_settings_field(
			'api_logout', // id
			'API LOGOUT', // title
			array( $this, 'api_logout_callback' ), // callback
			$this->page, // page
			'setting_section_id' // section
		);
		
		add_settings_field(
			'api_env_setting_id', // id
			'API ENV SETTING ID', // title
			array( $this, 'api_env_setting_id_callback' ), // callback
			$this->page, // page
			'setting_section_id' // section
		);

        add_settings_field(
			'api_notification_email', // id
			'API EMAIL NOTIFICA', // title
			array( $this, 'api_notification_email_callback' ), // callback
			$this->page, // page
			'setting_section_id' // section
		);

        add_settings_field(
			'api_select_products', // id
			'API SELEZIONA PRODOTTI', // title
			array( $this, 'api_select_products_callback' ), // callback
			$this->page, // page
			'setting_section_id' // section
		);

    }

    /**
     * Sanitize each setting field as needed
     *
     * @param array $input Contains all settings fields as array keys
     */
    public function sanitize( $input )
    {
        $new_input = array();
        if( isset( $input['api_login'] ) )
            $new_input['api_login'] = sanitize_text_field( $input['api_login'] );

        if( isset( $input['api_add_doc_environment'] ) )
            $new_input['api_add_doc_environment'] = sanitize_text_field( $input['api_add_doc_environment'] );            
            
        if( isset( $input['api_add_company'] ) )
            $new_input['api_add_company'] = sanitize_text_field( $input['api_add_company'] );
        
        if( isset( $input['api_add_user'] ) )
            $new_input['api_add_user'] = sanitize_text_field( $input['api_add_user'] );

        if( isset( $input['api_logout'] ) )
            $new_input['api_logout'] = sanitize_text_field( $input['api_logout'] );
		
		if( isset( $input['api_env_setting_id'] ) )
            $new_input['api_env_setting_id'] = sanitize_text_field( $input['api_env_setting_id'] );
        
        if( isset( $input['api_notification_email'] ) )
            $new_input['api_notification_email'] = sanitize_text_field( $input['api_notification_email'] );
        
        if( isset( $input['api_select_products'] ) )
            $new_input['api_select_products'] = sanitize_text_field( $input['api_select_products'] );

        return $new_input;
    }

    /** 
     * Print the Section text
     */
    public function print_section_info() {

    }

    /** 
     * Get the settings option array and print one of its values
     */
    public function api_login_callback() {
        $k = 'api_login' ;
        printf(
			'<input class="regular-text" type="text" name='.$this->option_name."[".$k."]".' id="'.$k.'" value="%s">',
			isset( $this->options[$k] ) ? esc_attr( $this->options[$k]) : ''
		);
    }
   
    public function api_add_doc_environment_callback()
    {
        $k = 'api_add_doc_environment' ;
        printf(
			'<input class="regular-text" type="text" name='.$this->option_name."[".$k."]".' id="'.$k.'" value="%s">',
			isset( $this->options[$k] ) ? esc_attr( $this->options[$k]) : ''
		);
    }

    public function api_add_company_callback()
    {
        $k = 'api_add_company' ;
        printf(
			'<input class="regular-text" type="text" name='.$this->option_name."[".$k."]".' id="'.$k.'" value="%s">',
			isset( $this->options[$k] ) ? esc_attr( $this->options[$k]) : ''
		);
    }

    public function api_add_user_callback()
    {
        $k = 'api_add_user' ;
        printf(
			'<input class="regular-text" type="text" name='.$this->option_name."[".$k."]".' id="'.$k.'" value="%s">',
			isset( $this->options[$k] ) ? esc_attr( $this->options[$k]) : ''
		);
    }

    public function api_logout_callback()
    {
        $k = 'api_logout' ;
        printf(
			'<input class="regular-text" type="text" name='.$this->option_name."[".$k."]".' id="'.$k.'" value="%s">',
			isset( $this->options[$k] ) ? esc_attr( $this->options[$k]) : ''
		);
    }
	
	public function api_env_setting_id_callback()
    {
        $k = 'api_env_setting_id' ;
        printf(
			'<input class="regular-text" type="text" name='.$this->option_name."[".$k."]".' id="'.$k.'" value="%s">',
			isset( $this->options[$k] ) ? esc_attr( $this->options[$k]) : ''
		);
    }

    public function api_notification_email_callback()
    {
        $k = 'api_notification_email' ;
        printf(
			'<input class="regular-text" type="text" name='.$this->option_name."[".$k."]".' id="'.$k.'" value="%s">',
			isset( $this->options[$k] ) ? esc_attr( $this->options[$k]) : ''
		);
    }

    public function api_select_products_callback()
    {
        $k = 'api_select_products' ;
        printf(
			'<input class="regular-text" type="text" name='.$this->option_name."[".$k."]".' id="'.$k.'" value="%s">',
			isset( $this->options[$k] ) ? esc_attr( $this->options[$k]) : ''
		);
		echo '<br><small>inserire ID prodotto (Woocommerce), se multiplo utilizzare come separatore la virgola senza spazi.</small>';
    }

}

if( is_admin() )
    $hubitat_settings_page = new HubitatSettingsPage();
