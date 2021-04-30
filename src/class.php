<?php

use MDDD\HTTP;
use MDDD\UI;

// include helper functions
require_once(dirname(__FILE__) . '/utils/HTTP.php');
require_once(dirname(__FILE__) . '/utils/UI.php');

function wooce_payment_gateway_init() {
  class WC_Gateway_CG extends WC_Payment_Gateway {
    // Setup basics
    public function __construct() {
      $this->id = "cg";
      $this->icon = $this->getIcon();
      $this->has_fields = false;
      $this->method_title = "Circulair Geld";
      $this->method_description = "Accepteer betalingen met Circulair Geld";

      $this->supports = array('products');
      $this->init_form_fields();
      $this->init_settings();

      // settings
      $this->title = $this->get_option( 'title' );
      $this->description = $this->get_option( 'description' );
      $this->enabled = $this->get_option( 'enabled' );
      $this->testmode = 'yes' === $this->get_option( 'testmode' );
      $this->use_accessclient = 'yes' === $this->get_option( 'use_accessclient' );

      $this->cg_url = 'https://mijn.circuitnederland.nl';
      $this->root_url = $this->testmode ? 'https://demo.cyclos.org' : $this->cg_url;
      $this->api_endpoint = $this->root_url . '/api';
      $this->username = $this->testmode ? "ticket" : $this->get_option( 'username' );
      $this->password = $this->testmode ? "1234" : $this->get_option( 'password' );
      $this->accessclient = $this->use_accessclient ? $this->get_option( 'accessclient' ) : NULL;

      // test user credentials if button is clicked
      if(array_key_exists('generateAccesclientButton',$_POST)) {
        if( !empty($_POST['accessClientCode'])) {
          $accesscode = $_POST['accessClientCode'];
          $token = HTTP::generate_accessclient_token($this->cg_url.'/api', $accesscode, $this->username, $this->password);
          $this->update_option('accessclient', $token);
          $this->update_option('use_accessclient', 'yes');
        }
      }

      if(array_key_exists('testUserCredentialsButton', $_POST)) {
        HTTP::test_user_credentials($this->api_endpoint, $this->username, $this->password);
      }
      
      // This action hook saves the settings
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

      //Webhook for when payment is complete.
      add_action('woocommerce_api_cg_payment_completed', array($this, 'webhook'));
    }

    public function admin_options() {
      ?>
        <h2>Circulair Geld</h2>
        <table class="form-table">
          <?php $this->generate_settings_html(); ?>
        </table>
      <?php
    }

    // Generate appropriate headers to make requests
    private function headers() {
      if ($this->use_accessclient) {
        return array(
          'Content-Transfer-Encoding' 	=> 'application/json',
          'Content-type' 								=> 'application/json;charset=utf-8',
          'Access-Client-Token' 				=> $this->accessclient
        ); 
      } else {
        return array(
          'Content-Transfer-Encoding' 	=> 'application/json',
          'Content-type'								=> 'application/json;charset=utf-8',
          'Authorization' 							=> 'Basic '. base64_encode($this->username . ':' . $this->password)
        );
      }
    }

    private function getIcon() {
      return plugins_url('assets/logo.png', dirname(__FILE__));
    }

    public function generate_screen_button_html( $key, $data ) {
      return UI::screen_button($key, $data, $this->plugin_id . $this->id . '_' . $key, $this);
    }

    public function generate_test_credentials_button_html( $key, $data ) {
      return UI::test_credentials_button($key, $data, $this->plugin_id . $this->id . '_' . $key, $this);
    }
          
    public function generate_donate_img_html( $key, $data ) {
      return UI::donate_img($key, $data, $this->plugin_id . $this->id . '_' . $key);
    }
            
    public function generate_logo_dev_html( $key, $data ) {
      return UI::logo_dev($key, $data, $this->plugin_id . $this->id . '_' . $key);
    }

    // Plugin options
    public function init_form_fields() {
      $this->form_fields = UI::form_fields();
    }
          
    //Back-end options validation and processing.	
    public function process_admin_options(){
      parent::process_admin_options();
    }

    // We're processing the payments here
    public function process_payment( $order_id ) {
      global $woocommerce;

      $order = wc_get_order( $order_id );
      $order_key = $order->get_order_key();
      $amount = $order->get_total();
      $shop_title = get_bloginfo('name');
      $description = "Betaling van $amount aan $shop_title";

      //urls
      $url_data = "/wc-api/ce_payment_completed?order_id=$order_id&key=$order_key";
      $successUrl = get_home_url(NULL, $url_data);
      $successWebhookUrl = get_home_url(NULL, $url_data);
      $cancelUrl = $order->get_cancel_order_url();

      // allow easy customization of urls
      $successUrl = apply_filters('wccg_succes_url', $successUrl, $order_id, $order_key, $url_data);
      $cancelUrl = apply_filters('wccg_cancel_url', $cancelUrl);
      $successWebhookUrl = apply_filters('wccg_webhook_url', $successWebhookUrl, $order_id, $order_key, $url_data);

      //create request body
      $body = array(
        'amount' => $amount,
        'description' => $description,
        'payer' => null,
        'successUrl' => $successUrl,
        'successWebhook' => $successWebhookUrl,
        'cancelUrl' => $cancelUrl,
        'orderId' => $order_id,
        'expiresAfter' => array(
          'amount' => 1,
          'field' => 'hours'
        )
      );
      
      if ($this->testmode !== true) {
        $body['type'] = "handelsrekening.handels_transactie";
      }

      $ticketNumber = HTTP::generate_ticket_number($this->api_endpoint, $this->headers(), $body);;

      if (strpos($ticketNumber, 'Error') !== false) {
        wc_add_notice($ticketNumber);
        return false;
      } else {
        return array(
          'result' => 'success',
          'redirect' => "{$this->root_url}/pay/{$ticketNumber}"
        );
      }
    }

    // webhook to let WP know to finalize payment
    public function webhook() { 
      $order_id = $_GET['orderId'];
      $order = wc_get_order( $order_id );
      $ticketNumber = $_GET['ticketNumber'];
      
      try {
        $transactionNumber = HTTP::process_ticket($this->api_endpoint, $this->headers(), $ticketNumber, $order_id);

        if (!empty($transactionNumber)) {
          $order->payment_complete($transactionNumber);
          $order->reduce_order_stock();
          $note = "Bestelling compleet met transactie-ID: $transactionNumber";
          $order->add_order_note($note);
        }
      } catch (Exception $e) {
        $order->update_status('Mislukt', sprintf(__('Foutmelding: %1$s'), $e));
        $note = sprintf(__('Foutmelding: %1$s'), $e);
        $order->add_order_note($note);
      }

      http_response_code(200);

      update_option('webhook_debug', $_GET);
      die();
    } 
  }
}