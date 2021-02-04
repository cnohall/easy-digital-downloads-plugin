<?php

/**
 * This class is responsible for communicating with the Blockonomics API
 */
class BlockonomicsAPI
{
    const BASE_URL = 'https://www.blockonomics.co';
    const NEW_ADDRESS_URL = 'https://www.blockonomics.co/api/new_address';
    const PRICE_URL = 'https://www.blockonomics.co/api/price';
    const ADDRESS_URL = 'https://www.blockonomics.co/api/address?only_xpub=true&get_callback=true';
    const SET_CALLBACK_URL = 'https://www.blockonomics.co/api/update_callback';
    const GET_CALLBACKS_URL = 'https://www.blockonomics.co/api/address?&no_balance=true&only_xpub=true&get_callback=true';

    const BCH_BASE_URL = 'https://bch.blockonomics.co';
    const BCH_NEW_ADDRESS_URL = 'https://bch.blockonomics.co/api/new_address';
    const BCH_PRICE_URL = 'https://bch.blockonomics.co/api/price';
    const BCH_SET_CALLBACK_URL = 'https://bch.blockonomics.co/api/update_callback';
    const BCH_GET_CALLBACKS_URL = 'https://bch.blockonomics.co/api/address?&no_balance=true&only_xpub=true&get_callback=true';


    public function __construct()
    {    
    }

    public function get_crypto() 
    {
        $bch_enabled  = edd_get_option('edd_blockonomics_bch_enabled');
        if ($bch_enabled  == '1'){
            return 'bch';
        }else{
            return 'btc';
        }
    }

    public function test_setup()
    {
        // Fetch the crypto to test based on the plugin settings
        $crypto = $this->get_crypto();
        $error_str = $this->check_callback_urls_or_set_one($crypto);
        if (!$error_str)
        {
            //Everything OK ! Test address generation
            $error_str = $this->test_new_address_gen($crypto);
        }
        if($error_str) {
            // Append troubleshooting article to all errors
            $error_str = $error_str . '<p>' . __('For more information, please consult <a href="http://help.blockonomics.co/support/solutions/articles/33000215104-unable-to-generate-new-address" target="_blank">this troubleshooting article</a>', 'edd-blockonomics'). '</p>';
            return $error_str;
        }
        // No errors
        return false;
    }

    public function test_new_address_gen($crypto)
    {
        $error_str = '';
        $callback_secret = edd_get_option('edd_blockonomics_callback_secret', '');
        $error_str = $this->new_address($callback_secret, $crypto, true);
        if ($response->response_code!=200){	
             $error_str = $response->response_message;
        }
        return $error_str;
    }

    public function check_callback_urls_or_set_one($crypto) 
    {
        $api_key = edd_get_option('edd_blockonomics_api_key');
        //If BCH enabled and API Key is not set: give error
        if (!$api_key && $crypto === 'bch'){
            $error_str = __('Set the API Key or disable BCH', 'edd-blockonomics');
            return $error_str;
        }
        $response = $this->get_callbacks($crypto, $api_key);
        //chek the current callback and detect any potential errors
        $error_str = $this->check_get_callbacks_response_code($response, $crypto);
        if(!$error_str){
            //if needed, set the callback.
            $error_str = $this->check_get_callbacks_response_body($response, $crypto);
        }
        return $error_str;
    }

    public function check_get_callbacks_response_code($response, $crypto){
        $error_str = '';
        $error_crypto = strtoupper($crypto).' error: ';
        //TODO: Check This: WE should actually check code for timeout
        if (!wp_remote_retrieve_response_code($response)) {
            $error_str = __($error_crypto.'Your server is blocking outgoing HTTPS calls', 'edd-blockonomics');
        }
        elseif (wp_remote_retrieve_response_code($response)==401)
            $error_str = __($error_crypto.'API Key is incorrect', 'edd-blockonomics');
        elseif (wp_remote_retrieve_response_code($response)!=200)
            $error_str = $error_crypto.$response->data;
        return $error_str;
    }

    public function check_get_callbacks_response_body ($response, $crypto){
        $error_str = '';
        $error_crypto = strtoupper($crypto).' error: ';
        $response_body = json_decode(wp_remote_retrieve_body($response));

        $callback_secret = edd_get_option('edd_blockonomics_callback_secret', '');
        $api_url = add_query_arg('edd-listener', 'blockonomics', home_url() );
        $callback_url = add_query_arg('secret', $callback_secret, $api_url);
        $callback_url_without_schema = preg_replace('/https?:\/\//', '', $callback_url);

        if (!isset($response_body) || count($response_body) == 0)
        {
            $error_str = __($error_crypto.'You have not entered an xPub', 'edd-blockonomics');
        }
        elseif (count($response_body) == 1)
        {
            $response_callback = '';
            $response_address = '';
            if(isset($response_body[0])){
                $response_callback = isset($response_body[0]->callback) ? $response_body[0]->callback : '';
                $response_address = isset($response_body[0]->address) ? $response_body[0]->address : '';
            }
            $response_callback_without_schema = preg_replace('/https?:\/\//', '', $response_callback);

            if(!$response_callback || $response_callback == null)
            {
                //No callback URL set, set one 
                $this->update_callback($callback_url, $crypto, $response_address);
            }
            elseif($response_callback_without_schema != $callback_url_without_schema)
            {
                $base_url = get_bloginfo('wpurl');
                $base_url = preg_replace('/https?:\/\//', '', $base_url);
                // Check if only secret differs
                if(strpos($response_callback, $base_url) !== false)
                {
                    //Looks like the user regenrated callback by mistake
                    //Just force Update_callback on server
                    $this->update_callback($callback_url, $crypto, $response_address);
                }
                else
                {
                    $error_str = __($error_crypto."You have an existing callback URL. Refer instructions on integrating multiple websites", 'edd-blockonomics');
                }
                
            }
        }
        else 
        {
            $error_str = __("You have an existing callback URL. Refer instructions on integrating multiple websites", 'edd-blockonomics');
            // Check if callback url is set
            foreach ($response_body as $res_obj)
             if(preg_replace('/https?:\/\//', '', $res_obj->callback) == $callback_url_without_schema)
                $error_str = "";
        }  
        return $error_str;
    }


    public function new_address($secret, $crypto, $reset=false)
    {
        if($reset)
        {
            $get_params = "?match_callback=$secret&reset=1";
        } 
        else
        {
            $get_params = "?match_callback=$secret";
        }
        if($this->crypto == 'btc'){
            $url = BlockonomicsAPI::NEW_ADDRESS_URL.$get_params;
        }else{
            $url = BlockonomicsAPI::BCH_NEW_ADDRESS_URL.$get_params;            
        }
        $api_key = edd_get_option('edd_blockonomics_api_key');
        $response = $this->post($url, $api_key);
        if (!isset($responseObj)) $responseObj = new stdClass();
        $responseObj->{'response_code'} = wp_remote_retrieve_response_code($response);
        if (wp_remote_retrieve_body($response))
        {
          $body = json_decode(wp_remote_retrieve_body($response));
          $responseObj->{'response_message'} = isset($body->message) ? $body->message : '';
          $responseObj->{'address'} = isset($body->address) ? $body->address : '';
        }
        return $responseObj;
    }

    public function get_price($currency)
    {
        if($this->crypto == 'btc'){
            $url = BlockonomicsAPI::PRICE_URL. "?currency=$currency";
        }else{
            $url = BlockonomicsAPI::BCH_PRICE_URL. "?currency=$currency";
        }
        $response = $this->get($url);
        return json_decode(wp_remote_retrieve_body($response))->price;
    }

    //This function is not being used?
    public function get_xpubs($api_key)
    {
    	$url = BlockonomicsAPI::ADDRESS_URL;
        $response = $this->get($url, $api_key);
        return json_decode(wp_remote_retrieve_body($response));
    }

    public function update_callback($callback_url, $crypto, $xpub)
    {
        if ($crypto == 'btc'){
            $url = BlockonomicsAPI::SET_CALLBACK_URL;
        }else{
            $url = BlockonomicsAPI::BCH_SET_CALLBACK_URL;
        }
        $api_key = edd_get_option('edd_blockonomics_api_key');
    	$body = json_encode(array('callback' => $callback_url, 'xpub' => $xpub));
    	$response = $this->post($url, $api_key, $body);
        return json_decode(wp_remote_retrieve_body($response));
    }

    public function get_callbacks($crypto, $api_key)
    {
        if ($crypto == 'btc'){
            $url = BlockonomicsAPI::GET_CALLBACKS_URL;
        }else{
            $url = BlockonomicsAPI::BCH_GET_CALLBACKS_URL;
        }
    	$response = $this->get($url, $api_key);
        return $response;
    }

    private function get($url, $api_key = '')
    {
    	$headers = $this->set_headers($api_key);

        $response = wp_remote_get( $url, array(
            'method' => 'GET',
            'headers' => $headers
            )
        );

        if(is_wp_error( $response )){
           $error_message = $response->get_error_message();
           echo "Something went wrong: $error_message";
        }else{
            return $response;
        }
    }

    private function post($url, $api_key = '', $body = '', $type = '')
    {
    	$headers = $this->set_headers($api_key);

        $response = wp_remote_post( $url, array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => $body
            )
        );
        if(is_wp_error( $response )){
           $error_message = $response->get_error_message();
           echo "Something went wrong: $error_message";
        }else{
            return $response;
        }
    }

    private function set_headers($api_key)
    {
    	if($api_key){
    		return 'Authorization: Bearer ' . $api_key;
    	}else{
    		return '';
    	}
    }
}
