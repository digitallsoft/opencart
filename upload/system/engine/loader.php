<?php
/**
 * @package        OpenCart
 * @author        Daniel Kerr
 * @copyright    Copyright (c) 2005 - 2017, OpenCart, Ltd. (https://www.opencart.com/)
 * @license        https://opensource.org/licenses/GPL-3.0
 * @link        https://www.opencart.com
 */

/**
 * Loader class
 */
namespace System\Engine;
final class Loader {
	protected $registry;

	/**
	 * Constructor
	 *
	 * @param    object $registry
	 */
	public function __construct($registry) {
		$this->registry = $registry;
	}

	/**
	 * Controller
	 *
	 * https://wiki.php.net/rfc/variadics
	 *
	 * @param    string $route
	 * @param    array $data
	 *
	 * @return    mixed
	 */
	public function controller($route, ...$args) {
		// Sanitize the call
		$route = preg_replace('/[^a-zA-Z0-9_\/]/', '', (string)$route);

		// Keep the original trigger
		$trigger = $route;

		// Trigger the pre events
		$result = $this->registry->get('event')->trigger('controller/' . $trigger . '/before', array(&$route, &$args));

		// Make sure its only the last event that returns an output if required.
		if ($result) {
			$output = $result;
		} else {
			$action = new \System\Engine\Action($route);
			$output = $action->execute($this->registry, $args);
		}

		// Trigger the post events
		$result = $this->registry->get('event')->trigger('controller/' . $trigger . '/after', array(&$route, &$args, &$output));

		if ($result) {
			$output = $result;
		}

		if (!$output instanceof Exception) {
			return $output;
		}
	}

	/**
	 * Model
	 *
	 * @param    string $route
	 */
	public function model($route) {
		// Sanitize the call
		$route = preg_replace('/[^a-zA-Z0-9_\/]/', '', (string)$route);

		// Check if the requested model is already stored in the registry.
		if (!$this->registry->has('model_' . str_replace('/', '_', $route))) {
			// Converting a route path to a class name
			$class = '\Application\Model\\' . str_replace(array('_', '/'), array('', '\\'), ucwords($route, '_/'));


			//echo 'model: ' . $class . "\n";

			if (class_exists($class)) {
				$proxy = new Proxy();

				// Overriding models is a little harder so we have to use PHP's magic methods.
				foreach (get_class_methods($class) as $method) {
					if (substr($method, 0, 2) != '__') {
						$proxy->{$method} = $this->callback($route . '/' . $method);
					}
				}

				$this->registry->set('model_' . str_replace('/', '_', (string)$route), $proxy);
			} else {
				throw new \Exception('Error: Could not load model ' . $route . '!');
			}
		}
	}

	/**
	 * View
	 *
	 *
	 *
	 * @param    string $route
	 * @param    array $data
	 *
	 * @return   string
	 */
	public function view($route, $data = array()) {
		// Sanitize the call
		$route = preg_replace('/[^a-zA-Z0-9_\/]/', '', (string)$route);

		// Keep the original trigger
		$trigger = $route;

		// Trigger the pre events
		$this->registry->get('event')->trigger('view/' . $trigger . '/before', array(&$route, &$data, &$code));

		// Make sure its only the last event that returns an output if required.
		$template = new \System\Library\Template($this->registry->get('config')->get('template_engine'));

		foreach ($data as $key => $value) {
			$template->set($key, $value);
		}

		$output = $template->render($this->registry->get('config')->get('template_directory') . $route, $code);

		// Trigger the post events
		$this->registry->get('event')->trigger('view/' . $trigger . '/after', array(&$route, &$data, &$output));

		return $output;
	}

	/**
	 * Library
	 *
	 * This method is used for loading library classes
	 *
	 * @param    string $route	The path to the library file.
	 * @param    string $args	A list of arguments to pass into the library object being created.
	 */
	public function library($route, &...$args) {
		// Sanitize the call
		$route = preg_replace('/[^a-zA-Z0-9_\/]/', '', (string)$route);

		// Keep the original trigger
		$trigger = $route;

		$this->registry->get('event')->trigger('library/' . $trigger . '/before', array(&$route, &$args));

		$class = 'System\Library\\' . str_replace('/', '\\', $route);

		if (class_exists($class)) {
			$reflection = new ReflectionClass($class);

			$object = $class->newInstanceArgs($args);
		} else {
			throw new \Exception('Error: Could not load library ' . $route . '!');
		}

		$this->registry->get('event')->trigger('library/' . $trigger . '/after', array(&$route, &$args, &$object));

		$this->registry->set($route, $object);

		return $object;
	}

	/**
	 * Helper
	 *
	 * @param    string $route
	 */
	public function helper($route) {
		$file = DIR_SYSTEM . 'helper/' . preg_replace('/[^a-zA-Z0-9_\/]/', '', (string)$route) . '.php';

		if (is_file($file)) {
			include_once($file);
		} else {
			throw new \Exception('Error: Could not load helper ' . $route . '!');
		}
	}

	/**
	 * Config
	 *
	 * @param    string $route
	 */
	public function config($route) {
		// Sanitize the call
		$route = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', (string)$route);

		// Keep the original trigger
		$trigger = $route;

		$this->registry->get('event')->trigger('config/' . $trigger . '/before', array(&$route));

		$this->registry->get('config')->load($route);

		$this->registry->get('event')->trigger('config/' . $trigger . '/after', array(&$route));
	}

	/**
	 * Language
	 *
	 * @param    string $route
	 * @param    string $key
	 *
	 * @return    array
	 */
	public function language($route, $prefix = '') {
		// Sanitize the call
		$route = preg_replace('/[^a-zA-Z0-9_\-\/]/', '', (string)$route);

		// Keep the original trigger
		$trigger = $route;

		$this->registry->get('event')->trigger('language/' . $trigger . '/before', array(&$route, &$prefix));

		$data = $this->registry->get('language')->load($route, $prefix);

		$this->registry->get('event')->trigger('language/' . $trigger . '/after', array(&$route, &$prefix, &$data));

		return $data;
	}

	/**
	 * Callback
	 *
	 * https://www.php.net/manual/en/class.closure.php
	 *
	 * @param	string $route
	 *
	 * @return	closure
	 */
	protected function callback($route) {
		return function (&...$args) use ($route) {
			// Grab args using function because we don't know the number of args being passed.
			// https://www.php.net/manual/en/functions.arguments.php#functions.variable-arg-list
			// https://wiki.php.net/rfc/variadics
			$route = preg_replace('/[^a-zA-Z0-9_\/]/', '', (string)$route);

			// Keep the original trigger
			$trigger = $route;

			// Trigger the pre events
			$result = $this->registry->get('event')->trigger('model/' . $trigger . '/before', array(&$route, &$args));

			if ($result) {
				$output = $result;
			} else {
				// Create a key to store the model object
				$key = substr($route, 0, strrpos($route, '/'));

				// Create the class name from the key
				$class = '\Application\Model\\' . str_replace(array('_', '/'), array('', '\\'), ucwords($key, '_/'));

				// Create the method to be used
				$method = substr($route, strrpos($route, '/') + 1);

				// Check if the model has already been initialised or not
				if (!$this->registry->has($key)) {
					$object = new $class($this->registry);

					$this->registry->set($key, $object);
				} else {
					$object = $this->registry->get($key);
				}

				$callable = array($object, $method);

				if (is_callable($callable)) {
					$output = call_user_func_array($callable, $args);
				} else {
					throw new \Exception('Error: Could not call model/' . $route . '!');
				}
			}

			// Trigger the post events
			$result = $this->registry->get('event')->trigger('model/' . $trigger . '/after', array(&$route, &$args, &$output));

			if ($result) {
				$output = $result;
			}

			return $output;
		};
	}
}