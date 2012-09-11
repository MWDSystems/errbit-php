<?php

/**
 * Errbit PHP Notifier.
 *
 * Copyright © Flippa.com Pty. Ltd.
 * See the LICENSE file for details.
 */

/**
 * Builds a complete payload for the notice sent to Errbit.
 */
class Errbit_Notice {
	private $_exception;
	private $_options;

	/**
	 * Create a new notice for the given Exception with the given $options.
	 *
	 * @param [Exception] $exception
	 *   the exception that occurred
	 *
	 * @param [Array] $options
	 *   full configuration + options
	 */
	public function __construct($exception, $options = array()) {
		$this->_exception = $exception;
		$this->_options   = array_merge(
			array(
				'url'        => !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : null,
				'parameters' => !empty($_REQUEST) ? $_REQUEST : array(),
				'session'    => !empty($_SESSION) ? $_SESSION : array(),
				'cgi_data'   => !empty($_SERVER)  ? $_SERVER  : array()
			),
			$options
		);

		$this->_filterData();
	}

	/**
	 * Convenience method to instantiate a new notice.
	 */
	public static function forException($exception, $options = array()) {
		return new self($exception, $options);
	}

	/**
	 * Extract a human-readable method/function name from the given stack frame.
	 *
	 * @param [Array] $frame
	 *   a single entry for the backtrace
	 *
	 * @return [String]
	 *   the name of the method/function
	 */
	public static function formatMethod($frame) {
		if (!empty($frame['class']) && !empty($frame['type']) && !empty($frame['function'])) {
			return sprintf('%s%s%s()', $frame['class'], $frame['type'], $frame['function']);
		} else {
			return sprintf('%s()', !empty($frame['function']) ? $frame['function'] : '<unknown>');
		}
	}

	/**
	 * Recursively build an list of the all the vars in the given array.
	 *
	 * @param [XmlBuilder] $builder
	 *   the builder instance to set the data into
	 *
	 * @param [Array] $array
	 *   the stack frame entry
	 */
	public static function xmlVarsFor($builder, $array) {
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				$builder->tag('var', array('key' => $key), function($var) use ($value) {
					Errbit_Notice::xmlVarsFor($var, $value);
				});
			} else {
				$builder->tag('var', $value, array('key' => $key));
			}
		}
	}

	/**
	 * Perform search/replace filters on a backtrace entry.
	 *
	 * @param [String] $str
	 *   the entry from the backtrace
	 *
	 * @return [String]
	 *   the filtered entry
	 */
	public function filterTrace($str) {
		if (empty($this->_options['backtrace_filters'])) {
			return $str;
		}

		foreach ($this->_options['backtrace_filters'] as $pattern => $replacement) {
			$str = preg_replace($pattern, $replacement, $str);
		}

		return $str;
	}

	/**
	 * Build the full XML document for the notice.
	 *
	 * @return [String]
	 *   the XML
	 */
	public function asXml() {
		$exception = $this->_exception;
		$options   = $this->_options;
		$builder   = new Errbit_XmlBuilder();
		$self      = $this;

		return $builder->tag(
			'notice',
			array('version' => Errbit::API_VERSION),
			function($notice) use ($exception, $options, $self) {
				$notice->tag('api-key',  $options['api_key']);
				$notice->tag('notifier', function($notifier) {
					$notifier->tag('name',    Errbit::PROJECT_NAME);
					$notifier->tag('version', Errbit::VERSION);
					$notifier->tag('url',     Errbit::PROJECT_URL);
				});

				$notice->tag('error', function($error) use ($exception, $self) {
					$error->tag('class',     $self->filterTrace(get_class($exception)));
					$error->tag('message',   $self->filterTrace($exception->getMessage()));
					$error->tag('backtrace', function($backtrace) use ($exception, $self) {
						foreach ($exception->getTrace() as $frame) {
							$backtrace->tag(
								'line',
								array(
									'number' => isset($frame['line']) ? $frame['line'] : 0,
									'file'   => isset($frame['file']) ? $self->filterTrace($frame['file']) : '<unknown>',
									'method' => $self->filterTrace(Errbit_Notice::formatMethod($frame))
								)
							);
						}
					});
				});

				if (!empty($options['url'])
					|| !empty($options['controller'])
					|| !empty($options['action'])
					|| !empty($options['parameters'])
					|| !empty($options['session_data'])
					|| !empty($options['cgi_data'])) {
					$notice->tag('request', function($request) use ($options) {
						$request->tag('url',       !empty($options['url']) ? $options['url'] : '');
						$request->tag('component', !empty($options['controller']) ? $options['controller'] : '');
						$request->tag('action',    !empty($options['action']) ? $options['action'] : '');
						if (!empty($options['parameters'])) {
							$request->tag('params', function($params) use ($options) {
								Errbit_Notice::xmlVarsFor($params, $options['parameters']);
							});
						}

						if (!empty($options['session_data'])) {
							$request->tag('session', function($session) use ($options) {
								Errbit_Notice::xmlVarsFor($session, $options['session_data']);
							});
						}

						if (!empty($options['cgi_data'])) {
							$request->tag('cgi-data', function($cgiData) use ($options) {
								Errbit_Notice::xmlVarsFor($cgiData, $options['cgi_data']);
							});
						}
					});
				}

				$notice->tag('server-environment', function($env) use ($options) {
					$env->tag('project-root',     $options['project_root']);
					$env->tag('environment-name', $options['environment_name']);
					$env->tag('hostname',         $options['hostname']);
				});
			}
		)->asXml();
	}

	// -- Private Methods

	private function _filterData() {
		if (empty($this->_options['params_filters'])) {
			return;
		}

		foreach (array('parameters', 'session_data', 'cgi_data') as $name) {
			$this->_filterParams($name);
		}
	}

	private function _filterParams($name) {
		if (empty($this->_options[$name])) {
			return;
		}

		foreach ($this->_options['params_filters'] as $pattern) {
			foreach ($this->_options[$name] as $key => $value) {
				if (preg_match($pattern, $key)) {
					$this->_options[$name][$key] = '[FILTERED]';
				}
			}
		}
	}
}