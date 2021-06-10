<?php

class BlockonomicsAPI
{

    public function __construct()
    {
    }


    public function new_address($api_key, $secret, $reset=false)
    {
        if($reset)
        {
            $get_params = "?match_callback=$secret&reset=1";
        } 
        else
        {
            $get_params = "?match_callback=$secret";
        }
        $url = BlockonomicsAPI::NEW_ADDRESS_URL.$get_params;
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
    	$url = BlockonomicsAPI::PRICE_URL. "?currency=$currency";
        $response = $this->get($url);
        return json_decode(wp_remote_retrieve_body($response))->price;
    }

    public function update_callback($api_key, $callback_url, $xpub)
    {
    	$url = BlockonomicsAPI::SET_CALLBACK_URL;
    	$body = json_encode(array('callback' => $callback_url, 'xpub' => $xpub));
    	$response = $this->post($url, $api_key, $body);
        return json_decode(wp_remote_retrieve_body($response));
    }

    public function get_callbacks($api_key)
    {
    	$url = BlockonomicsAPI::GET_CALLBACKS_URL;
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
