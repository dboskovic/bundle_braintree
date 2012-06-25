<?php

namespace Bundles\Braintree;
use Exception;
use e;


/**
 * Braintree Bundle
 * @author David D. Boskovic
 */
class Bundle {

	/**
	 * Initialize Braintree and load resources.
	 **/	
	public function __initBundle() {

		# include the resources
		include_once('source/Braintree.php');

		$event = e::$events->first->braintreeAPIKeys();

		/**
		 * If nothing is providing us with the API keys. Let's pull them from system.vars and assume this is not a multiple environment app.
		 */
		if(!$event)
			$this->_configure(
				e::$environment->requireVar('braintree.environment', 'development|sandbox|production|qa'),
				e::$environment->requireVar('braintree.merchant_id'),
				e::$environment->requireVar('braintree.public_key'),
				e::$environment->requireVar('braintree.private_key')
			);

		/**
		 * Otherwise use the vars provided by whatever bundle is responding with the keys (likely webapp or something similar)
		 */
		else
			$this->_configure($event['environment'], $event['merchant_id'], $event['public_key'], $event['private_key']);
	}

	public function __routeBundle($path) {
		switch($path[0]) {
			case 'response':
				$pd = e::$session->data->_braintree_presave;
				$result = \Braintree_TransparentRedirect::confirm($_SERVER['QUERY_STRING']);
			break;
			default:
				throw new Exception("Cannot access /@braintree directly.");
			break;
		}

	}

	/**
	 * get the transparent redirect URL
	 **/
	public function transparentRedirectURL() {
		return \Braintree_TransparentRedirect::url();
	}

	public function confirmResponse() {
		return \Braintree_TransparentRedirect::confirm($_SERVER['QUERY_STRING']);
	}

	/**
	 * Get the data for the Transparent Redirect
	 **/
	public function transparentRedirectData($type = 'customer', $data = false) {
		switch($type) {
			case 'customer':
				$a['redirectUrl'] = e::$url->link(isset($data['link']) ? $data['link'] :'/@braintree/response');
				if(isset($data['id'])) {
					// check to see if we've already created a vault entry for this member

					$a['customerId'] = $data['id'];
					return \Braintree_TransparentRedirect::updateCustomerData($a);
				}
				return \Braintree_TransparentRedirect::createCustomerData($a);
			break;
			case  'transaction':
			break;
		}
	}

	/**
	 * Respond to invoice charge event.
	 * @return array
	 **/
	public function _on_invoiceCharge($array) {

		// 
		return array('type' => 'braintree', 'token' => $result->id, 'charged' => $result->paid);
	}


	public function _on_invoiceInfo($chargeToken) {
		return $this->charge('retrieve', $chargeToken);
	}
	
	public function _on_invoiceRefund($chargeToken) {
		$ch = $this->charge('retrieve', $chargeToken);
		return $ch->refund();
	}

	/**
	 * Configure the Braintree Bundle.
	 * @todo add exceptions and verification here.
	 */
	private function _configure($environment, $merchant_id, $public_key, $private_key) {		
		\Braintree_Configuration::environment($environment);
		\Braintree_Configuration::merchantId($merchant_id);
		\Braintree_Configuration::publicKey($public_key);
		\Braintree_Configuration::privateKey($private_key);
	}
}