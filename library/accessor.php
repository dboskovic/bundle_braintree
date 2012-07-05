<?php

namespace Bundles\Braintree;
use Exception;
use e;

/**
 * Braintree Accessor Mask
 *
 * @author    David Boskovic
 * @since     06/24/2012
 * @package   braintree
 * @copyright Apache 2.0 Open Source
 */
class Accessor {



	/**
	 * This is the class name to use when calling the submethod.
	 * @var string
	 */
	private $class;



	/**
	 * Initialize the accessor object with the class.
	 *
	 * @author  David Boskovic
	 * @since   06/24/2012
	 */
	public function __construct($class) {

		/**
		 * Prepend the Braintree faux namespace to the class being called.
		 * @todo add regex checking to make sure that $class is a valid classname string.
		 */
		$class = '\\Braintree_'.$class;


		/**
		 * Validate that this is a  Braintree class.
		 */
		if(!class_exists($class))
			throw new Exception('You are trying to access an invalid Braintree object `'.$class.'`.');

		/**
		 * Save this classname for the __call accessor.
		 */
		$this->class = $class;
	}



	/**
	 * Call a static method on this object.
	 *
	 * @author  David Boskovic
	 * @since   06/24/2012
	 */
	public function __call($method, $args) {

		/**
		 * Verify that the method exists.
		 */
		if(!method_exists($this->class, $method))
			throw new Exception("Trying to access an invalid method `$method` on the Braintree object `$this->class`.");

		/**
		 * Return the results of this method.
		 */
		return call_user_func_array($this->class.'::'.$method, $args);
	}

}