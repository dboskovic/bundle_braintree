<?php

namespace Bundles\Braintree;
use Bundles\SQL\callException;
use Bundles\SQL\SQLBundle;
use Exception;
use e;

/**
 * Braintree EvolutionSDK Bundle
 *
 * @author    David Boskovic
 * @since     06/24/2012
 * @package   braintree
 * @copyright Apache 2.0 Open Source
 *
 * @todo add support for a special secure subdomain to be configured for https links
 * @todo clean up and document better
 */
class Bundle extends SQLBundle  {


	/**
	 * Initialize Braintree and load resources.
	 *	 
	 * @author  David Boskovic
	 * @since   06/24/2012
	 *
	 * @todo Clean this up. I don't like the way it's formatted.
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

	
	/**
	 * Sync up the braintree customer to our customer.
	 *	 
	 * @author  David Boskovic
	 * @since   06/24/2012
	 *
	 * @example Braintree_TransparentRedirect::url(); => e::$braintree->transparentRedirect->url();
	 * @return  object
	 **/	
	public function syncMemberRecords($customer, $member) {

		$match = $this->getCustomers()->condition('braintree_id', $customer->id)->first();
		if(!$match) {
			$cc = $this->newCustomer();
			$cc->braintree_id = $customer->id;

			foreach($customer->creditCards as $card) {
				if($card->isDefault())
					break;
			}

			$cc->default_card_token = $card->token;
			$cc->save();
			$cc->linkMembersMember($member->id);
		} else {
			
		}

	}


	
	/**
	 * Access all the braintree functionality through a nice little EvolutionSDK Mask.
	 *	 
	 * @author  David Boskovic
	 * @since   06/24/2012
	 *
	 * @example Braintree_TransparentRedirect::url(); => e::$braintree->transparentRedirect->url();
	 * @return  object
	 **/	
	public function __get($class) {

		/**
		 * Ignore non-braintree var requests.
		 */
		if(!isset($this->$class))
			return null;

		return new Accessor($class);
	}

	
	/**
	 * Check to see if a Braintree object exists (this is super important for LHTML)
	 *	 
	 * @author  David Boskovic
	 * @since   06/24/2012
	 **/	
	public function __isset($class) {
		if(!class_exists('\\Braintree_'.$class)) return false;
		return true;
	}



	/**
	 * Get the data for the Transparent Redirect
	 * @todo clean this up muchly
	 **/
	public function transparentRedirectData($type = 'customer', $data = false) {
		if($data && is_string($data))
			$data = array('link' => $data);
		switch($type) {
			case 'customer':
				$a['redirectUrl'] = e::$url->link(isset($data['link']) ? $data['link'] :'/@braintree/response');
				if(isset($data['id'])) {
					// check to see if we've already created a vault entry for this member
					$member = e::$members->getMember($data['id']);
					$customer = $member->getBraintreeCustomer();

					if($customer && $customer->braintree_id)
						$a['customerId'] = $data['id'];
					else
						$a['customer']['id'] = $data['id'];
				}
				if($customer && $customer->braintree_id) return $this->transparentRedirect->updateCustomerData($a);
				return $this->transparentRedirect->createCustomerData($a);
			break;
			case  'transaction':
			break;
		}
	}

	/**
	 * Respond to invoice charge event.
	 * @return array
	 * @todo make this actually charge the member account
	 **/
	public function _on_invoiceCharge($data, $invoice) {

		/**
		 * Skip if the gateway is not braintree.
		 */
		if($data['gateway'] != 'braintree')
			throw new Exception("Skip this event response.",401);

		/**
		 * If this is a new transaction, just use Authorize with the settlement parameter.
		 */
		if(!$data['token'])
			return $this->_on_invoiceAuthorize($data, $invoice, true);
		else {
			$result = e::$braintree->transaction->submitForSettlement($data['token'], $data['amount']/100);

			if($result->success == false) {
				throw new Exception($result->message);
			}
			else {
				$transaction = $result->transaction;
				return array('charged' => true, 'status' => $transaction->status);
			}
		}
	}


	/**
	 * Respond to invoice charge event.
	 * @return array
	 * @todo make this actually charge the member account
	 **/
	public function _on_invoiceAuthorize($data, $invoice, $settle = false) {

		/**
		 * Skip if the gateway is not braintree.
		 */
		if($data['gateway'] != 'braintree')
			throw new Exception("Skip this event response.",401);

		/**
		 * Prepare Braintree Data
		 */
		$submit_data = array(
			'amount' => $data['amount'] / 100, // convert back to dollars
			'orderId' => $invoice->id,
			'options' => array(
				'submitForSettlement' => (bool) $settle
			)
		);

		/**
		 * This makes sure that the actual merchant account ID is honored.
		 */
		if($merchant_account_id = $this->getWebAppSetting('braintree-merchant-account-id'))
			$submit_data['merchantAccountId'] = $merchant_account_id;
		else
			throw Exception("No Merchant account ID configured. Add through `braintree-merchant-account-id`");


		// load the member from the invoice
		$member = $invoice->getMembersMember();

		// get the braintree customer from the member
		$customer = $member->getBraintreeCustomer();

		/**
		 * Charge the Customer
		 */
		$result = e::$braintree->customer->sale($customer->braintree_id, $submit_data);
		
		if($result->success == false) {
			throw new Exception($result->message);
		}
		else {
			$transaction = $result->transaction;
			return array(
				'type' => 'braintree', 
				'token' => $transaction->id, 
				'currency' => $transaction->currencyIsoCode, 
				'last4' => $transaction->creditCardDetails->last4, 
				'card_type' => $transaction->creditCardDetails->cardType, 
				'charged' => $settle, 
				'status' => $transaction->status
			);
		}
	}

	public function _on_invoiceSynchronizeStatus($data) {

		if($data['gateway'] != 'braintree')
			throw new Exception("Skip this event response.",401);

		$transaction = e::$braintree->transaction->find($data['token']);

		return array('status' => $transaction->status);
	}
	
	public function _on_invoiceRefund($data) {

		if($data['gateway'] != 'braintree')
			throw new Exception("Skip this event response.",401);
		
		$result = $data['amount'] ? $this->transaction->refund($data['token'], $data['amount'] / 100) : $this->transaction->refund($data['token']) ;

		if($result->success == false) {
			throw new Exception($result->message);
		}
		else {
			$transaction = $result->transaction;
			return array('type' => 'braintree', 'token' => $transaction->id, 'currency' => $transaction->currencyIsoCode, 'status' => $transaction->status);
		}
	}
	
	public function _on_invoiceCredit($token) {

		if($array['gateway'] != 'braintree')
			throw new Exception("Skip this event response.",401);
	}

	public function _on_invoiceVoid($data) {

		if($data['gateway'] != 'braintree')
			throw new Exception("Skip this event response.",401);
		
		$result = $this->transaction->void($data['token']);

		if($result->success == false) {
			throw new Exception($result->message);
		}
		else {
			$transaction = $result->transaction;
			return array('status' => $transaction->status);
		}
	}

	private $settings;
	private function getWebAppSetting($field) {
		if(!$this->settings)
			$this->settings = e::$webapp->subdomainAccount()->_->information;
		return $this->settings->$field;
	}

	/**
	 * Configure the Braintree Bundle.
	 * @todo add exceptions and verification here.
	 */
	private function _configure($environment, $merchant_id, $public_key, $private_key) {		
		$this->configuration->environment($environment);
		$this->configuration->merchantId($merchant_id);
		$this->configuration->publicKey($public_key);
		$this->configuration->privateKey($private_key);
	}
}