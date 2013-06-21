<?php
/**
 * @package     Joomla.Installation
 * @subpackage  Application
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Joomla! Installation Application class
 *
 * @package     Joomla.Installation
 * @subpackage  Application
 * @since       3.1
 */
final class InstallationApplicationWeb extends JApplicationWeb
{
	/**
	 * The application message queue.
	 *
	 * @var    array
	 * @since  3.1
	 */
	protected $_messageQueue = array();

	/**
	 * Class constructor.
	 *
	 * @since   3.1
	 */
	public function __construct()
	{
		// Run the parent constructor
		parent::__construct();

		// Load and set the dispatcher
		$this->loadDispatcher();

		// Enable sessions by default.
		if (is_null($this->config->get('session')))
		{
			$this->config->set('session', true);
		}

		// Set the session default name.
		if (is_null($this->config->get('session_name')))
		{
			$this->config->set('session_name', 'installation');
		}

		// Create the session if a session name is passed.
		if ($this->config->get('session') !== false)
		{
			$this->loadSession();

			// Register the session with JFactory
			JFactory::$session = $this->getSession();
		}

		// Store the debug value to config based on the JDEBUG flag
		$this->config->set('debug', JDEBUG);

		// Register the config to JFactory
		JFactory::$config = $this->config;

		// Register the application to JFactory
		JFactory::$application = $this;

		// Set the root in the URI based on the application name
		JUri::root(null, str_ireplace('/installation', '', JUri::base(true)));
	}

	/**
	 * Method to display errors in language parsing
	 *
	 * @return  string  Language debug output
	 *
	 * @since   3.1
	 */
	public function debugLanguage()
	{
		$lang   = JFactory::getLanguage();
		$output = '<h4>' . JText::_('JDEBUG_LANGUAGE_FILES_IN_ERROR') . '</h4>';

		$errorfiles = $lang->getErrorFiles();

		if (count($errorfiles))
		{
			$output .= '<ul>';

			foreach ($errorfiles as $error)
			{
				$output .= "<li>$error</li>";
			}

			$output .= '</ul>';
		}
		else
		{
			$output .= '<pre>' . JText::_('JNONE') . '</pre>';
		}

		$output .= '<h4>' . JText::_('JDEBUG_LANGUAGE_UNTRANSLATED_STRING') . '</h4>';
		$output .= '<pre>';
		$orphans = $lang->getOrphans();

		if (count($orphans))
		{
			ksort($orphans, SORT_STRING);

			$guesses = array();
			foreach ($orphans as $key => $occurance)
			{
				$guess = str_replace('_', ' ', $key);

				$parts = explode(' ', $guess);

				if (count($parts) > 1)
				{
					array_shift($parts);
					$guess = implode(' ', $parts);
				}

				$guess = trim($guess);

				$key = trim(strtoupper($key));
				$key = preg_replace('#\s+#', '_', $key);
				$key = preg_replace('#\W#', '', $key);

				// Prepare the text
				$guesses[] = $key . '="' . $guess . '"';
			}

			$output .= implode("\n", $guesses);
		}
		else
		{
			$output .= '<pre>' . JText::_('JNONE') . '</pre>';
		}

		$output .= '</pre>';

		return $output;
	}

	/**
	 * Dispatch the application
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function dispatch()
	{
		try
		{
			// Load the document to the API
			$this->loadDocument();

			// Set up the params
			$document = $this->getDocument();

			// Register the document object with JFactory
			JFactory::$document = $document;

			if ($document->getType() == 'html')
			{
				// Set metadata
				$document->setTitle(JText::_('INSTL_PAGE_TITLE'));
			}

			// Define component path
			define('JPATH_COMPONENT', JPATH_BASE);
			define('JPATH_COMPONENT_SITE', JPATH_SITE);
			define('JPATH_COMPONENT_ADMINISTRATOR', JPATH_ADMINISTRATOR);

			// Execute the task.
			try
			{
				$controller = $this->fetchController($this->input->getCmd('task'));
				$contents   = $controller->execute();
			}
			catch (RuntimeException $e)
			{
				echo $e->getMessage();
				$this->close($e->getCode());
			}

			// If debug language is set, append its output to the contents
			if ($this->config->get('debug_lang'))
			{
				$contents .= $this->debugLanguage();
			}

			$document->setBuffer($contents, 'component');
			$document->setTitle(JText::_('INSTL_PAGE_TITLE'));
		}

		// Mop up any uncaught exceptions.
		catch (Exception $e)
		{
			echo $e->getMessage();
			$this->close($e->getCode());
		}
	}

	/**
	 * Method to run the Web application routines.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function doExecute()
	{
		// Initialise the application
		$this->initialiseApp();

		// Dispatch the application
		$this->dispatch();
	}

	/**
	 * Enqueue a system message.
	 *
	 * @param   string  $msg   The message to enqueue.
	 * @param   string  $type  The message type. Default is message.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function enqueueMessage($msg, $type = 'message')
	{
		// For empty queue, if messages exists in the session, enqueue them first.
		if (!count($this->_messageQueue))
		{
			$session = JFactory::getSession();
			$sessionQueue = $session->get('application.queue');

			if (count($sessionQueue))
			{
				$this->_messageQueue = $sessionQueue;
				$session->set('application.queue', null);
			}
		}

		// Enqueue the message.
		$this->_messageQueue[] = array('message' => $msg, 'type' => strtolower($type));
	}

	/**
	 * Method to get a controller object.
	 *
	 * @param   string  $task  The task being executed
	 *
	 * @return  JController
	 *
	 * @since   3.1
	 * @throws  RuntimeException
	 */
	protected function fetchController($task)
	{
		if (is_null($task))
		{
			$task = 'default';
		}

		// Set the controller class name based on the task
		$class = 'InstallationController' . ucfirst($task);

		// If the requested controller exists let's use it.
		if (class_exists($class))
		{
			return new $class;
		}

		// Nothing found. Panic.
		throw new RuntimeException('Class ' . $class . ' not found');
	}

	/**
	 * Gets the client id of the current running application.
	 *
	 * @return  integer  A client identifier.
	 *
	 * @since   3.1
	 */
	public function getClientId()
	{
		return 2;
	}

	/**
	 * Returns the language code and help url set in the localise.xml file.
	 * Used for forcing a particular language in localised releases.
	 *
	 * @return  mixed  False on failure, array on success.
	 *
	 * @since   3.1
	 */
	public function getLocalise()
	{
		$xml = simplexml_load_file(JPATH_INSTALLATION . '/localise.xml');

		if (!$xml)
		{
			return false;
		}

		// Check that it's a localise file
		if ($xml->getName() != 'localise')
		{
			return false;
		}

		$ret = array();

		$ret['language'] = (string) $xml->forceLang;
		$ret['helpurl'] = (string) $xml->helpurl;
		$ret['debug'] = (string) $xml->debug;
		$ret['sampledata'] = (string) $xml->sampledata;

		return $ret;
	}

	/**
	 * Returns the installed language files in the administrative and
	 * front-end area.
	 *
	 * @param   mixed  $db  JDatabaseDriver instance
	 *
	 * @return  array  Array with installed language packs in admin and site area
	 *
	 * @since   3.1
	 */
	public function getLocaliseAdmin($db = false)
	{
		jimport('joomla.filesystem.folder');

		// Read the files in the admin area
		$path = JLanguage::getLanguagePath(JPATH_ADMINISTRATOR);
		$langfiles['admin'] = JFolder::folders($path);

		// Read the files in the site area
		$path = JLanguage::getLanguagePath(JPATH_SITE);
		$langfiles['site'] = JFolder::folders($path);

		if ($db)
		{
			$langfiles_disk = $langfiles;
			$langfiles = array();
			$langfiles['admin'] = array();
			$langfiles['site'] = array();
			$query = $db->getQuery(true)
				->select($db->quoteName(array('element','client_id')))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('type') . ' = ' . $db->quote('language'));
			$db->setQuery($query);
			$langs = $db->loadObjectList();
			foreach ($langs as $lang)
			{
				switch ($lang->client_id)
				{
					// Site
					case 0:
						if (in_array($lang->element, $langfiles_disk['site']))
						{
							$langfiles['site'][] = $lang->element;
						}
						break;

					// Administrator
					case 1:
						if (in_array($lang->element, $langfiles_disk['admin']))
						{
							$langfiles['admin'][] = $lang->element;
						}
						break;
				}
			}
		}

		return $langfiles;
	}

	/**
	 * Get the system message queue.
	 *
	 * @return  array  The system message queue.
	 *
	 * @since   3.1
	 */
	public function getMessageQueue()
	{
		// For empty queue, if messages exists in the session, enqueue them.
		if (!count($this->_messageQueue))
		{
			$session = JFactory::getSession();
			$sessionQueue = $session->get('application.queue');

			if (count($sessionQueue))
			{
				$this->_messageQueue = $sessionQueue;
				$session->set('application.queue', null);
			}
		}

		return $this->_messageQueue;
	}

	/**
	 * Gets the name of the current template.
	 *
	 * @param   boolean  $params  True to return the template parameters
	 *
	 * @return  string  The name of the template.
	 *
	 * @since   3.1
	 */
	public function getTemplate($params = false)
	{
		if ($params)
		{
			$template = new stdClass;
			$template->template = 'template';
			$template->params = new JRegistry;
			return $template;
		}

		return 'template';
	}

	/**
	 * Initialise the application.
	 *
	 * @param   array  $options  An optional associative array of configuration settings.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function initialiseApp($options = array())
	{
		// Get the localisation information provided in the localise.xml file.
		$forced = $this->getLocalise();

		// Check the request data for the language.
		if (empty($options['language']))
		{
			$requestLang = $this->input->getCmd('lang', null);
			if (!is_null($requestLang))
			{
				$options['language'] = $requestLang;
			}
		}

		// Check the session for the language.
		if (empty($options['language']))
		{
			$sessionOptions = $this->getSession()->get('setup.options');

			if (isset($sessionOptions['language']))
			{
				$options['language'] = $sessionOptions['language'];
			}
		}

		// This could be a first-time visit - try to determine what the client accepts.
		if (empty($options['language']))
		{
			if (!empty($forced['language']))
			{
				$options['language'] = $forced['language'];
			}
			else
			{
				$options['language'] = JLanguageHelper::detectLanguage();
				if (empty($options['language']))
				{
					$options['language'] = 'en-GB';
				}
			}
		}

		// Give the user English
		if (empty($options['language']))
		{
			$options['language'] = 'en-GB';
		}

		// Set the language in the class
		$this->config->set('language', $options['language']);
		$this->config->set('debug_lang', $forced['debug']);
		$this->config->set('sampledata', $forced['sampledata']);
	}

	/**
	 * Allows the application to load a custom or default session.
	 *
	 * The logic and options for creating this object are adequately generic for default cases
	 * but for many applications it will make sense to override this method and create a session,
	 * if required, based on more specific needs.
	 *
	 * @param   JSession  $session  An optional session object. If omitted, the session is created.
	 *
	 * @return  JApplicationWeb This method is chainable.
	 *
	 * @since   3.1
	 */
	public function loadSession(JSession $session = null)
	{
		jimport('legacy.application.application');

		$options = array();
		$options['name'] = JApplication::getHash($this->config->get('session_name'));

		$session = JFactory::getSession($options);
		$session->initialise($this->input);
		$session->start();
		if (!$session->get('registry') instanceof JRegistry)
		{
			// Registry has been corrupted somehow
			$session->set('registry', new JRegistry('session'));
		}

		// Set the session object.
		$this->session = $session;

		return $this;
	}

	/**
	 * Rendering is the process of pushing the document buffers into the template
	 * placeholders, retrieving data from the document and pushing it into
	 * the application response buffer.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function render()
	{
		$file = $this->input->getCmd('tmpl', 'index');

		$options = array(
			'template' => 'template',
			'file' => $file . '.php',
			'directory' => JPATH_THEMES,
			'params' => '{}'
		);

		// Parse the document.
		$this->document->parse($options);

		// Render the document.
		$data = $this->document->render($this->get('cache_enabled'), $options);

		// Set the application output data.
		$this->setBody($data);
	}

	/**
	 * Method to send a JSON response. The data parameter
	 * can be a Exception object for when an error has occurred or
	 * a stdClass for a good response.
	 *
	 * @param   mixed  $response  stdClass on success, Exception on failure.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function sendJsonResponse($response)
	{
		// Check if we need to send an error code.
		if ($response instanceof Exception)
		{
			// Send the appropriate error code response.
			$this->setHeader('status', $response->getCode());
			$this->setHeader('Content-Type', 'application/json; charset=utf-8');
			$this->sendHeaders();
		}

		// Send the JSON response.
		echo json_encode(new InstallationResponseJson($response));

		// Close the application.
		$this->close();
	}

	/**
	 * Set configuration values
	 *
	 * @param   array   $vars       Array of configuration values
	 * @param   string  $namespace  The namespace
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function setCfg(array $vars = array(), $namespace = 'config')
	{
		$this->config->loadArray($vars, $namespace);
	}
}
