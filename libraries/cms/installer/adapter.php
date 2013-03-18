<?php
/**
 * @package     Joomla.Libraries
 * @subpackage  Installer
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

jimport('joomla.base.adapterinstance');

/**
 * Abstract adapter for the installer.
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 */
abstract class JInstallerAdapter extends JAdapterInstance
{
	/**
	 * The unique identifier for the extension (e.g. mod_login)
	 *
	 * @var    string
	 * @since  3.1
	 * */
	protected $element = null;

	/**
	 * Messages rendered by custom scripts
	 *
	 * @var    string
	 * @since  3.1
	 */
	protected $extensionMessage = '';

	/**
	 * Copy of the XML manifest file.
	 *
	 * Making this object public allows extensions to customize the manifest in custom scripts.
	 *
	 * @var    string
	 * @since  3.1
	 */
	public $manifest = null;

	/**
	 * A path to the PHP file that the scriptfile declaration in
	 * the manifest refers to.
	 *
	 * @var    string
	 * @since  3.1
	 * */
	protected $manifest_script = null;

	/**
	 * Name of the extension
	 *
	 * @var    string
	 * @since  3.1
	 */
	protected $name = null;

	/**
	 * Install function routing
	 *
	 * @var    string
	 * @since  3.1
	 */
	protected $route = 'install';

	/**
	 * @var    string
	 * @since  3.1
	 */
	protected $scriptElement = null;

	/**
	 * Constructor
	 *
	 * @param   JAdapter         $parent   Parent object
	 * @param   JDatabaseDriver  $db       Database object
	 * @param   array            $options  Configuration Options
	 *
	 * @since   11.1
	 */
	public function __construct($parent, $db, $options = array())
	{
		// Run the parent constructor
		parent::__construct($parent, $db, $options);

		// Set the manifest object
		$this->manifest = $this->parent->getManifest();

		// Ensure the name is a string
		$name = (string) $this->manifest->name;

		// Filter the name for illegal characters
		$name = JFilterInput::getInstance()->clean($name, 'cmd');

		// Set the name object
		$this->name = $name;
	}

	/**
	 * Method to handle database transactions for the installer
	 *
	 * @param   string  $route  The action being performed on the database
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	protected function doDatabaseTransactions($route)
	{
		// Get a database connector object
		$db = $this->parent->getDbo();

		// Let's run the install queries for the component
		if (isset($this->manifest->{$route}->sql))
		{
			$result = $this->parent->parseSQLFiles($this->manifest->{$route}->sql);

			if ($result === false)
			{
				// Only rollback if installing
				if ($route == 'install')
				{
					// Install failed, rollback changes
					$this->parent->abort(
						JText::sprintf('JLIB_INSTALLER_ABORT_INSTALL_SQL_ERROR', JText::_('JLIB_INSTALLER_' . $this->route), $db->stderr(true))
					);
				}

				return false;
			}
		}

		return true;
	}

	/**
	 * Load language files
	 *
	 * @param   string  $extension  The name of the extension
	 * @param   string  $source     Path to the extension
	 * @param   string  $default    The path to the default language location
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function doLoadLanguage($extension, $source, $default = JPATH_SITE)
	{
		$lang = JFactory::getLanguage();
		$lang->load($extension . '.sys', $source, null, false, false)
			|| $lang->load($extension . '.sys', $default, null, false, false)
			|| $lang->load($extension . '.sys', $source, $lang->getDefault(), false, false)
			|| $lang->load($extension . '.sys', $default, $lang->getDefault(), false, false);
	}

	/**
	 * Actually refresh the extension table cache
	 *
	 * @param   string  $manifestPath  Path to the manifest file
	 *
	 * @return  boolean  Result of operation, true if updated, false on failure
	 *
	 * @since   3.1
	 */
	protected function doRefreshManifestCache($manifestPath)
	{
		$this->parent->manifest = $this->parent->isManifest($manifestPath);
		$this->parent->setPath('manifest', $manifestPath);

		$manifest_details = JInstaller::parseXMLInstallFile($this->parent->getPath('manifest'));
		$this->parent->extension->manifest_cache = json_encode($manifest_details);
		$this->parent->extension->name = $manifest_details['name'];

		try
		{
			return $this->parent->extension->store();
		}
		catch (RuntimeException $e)
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_REFRESH_MANIFEST_CACHE'), JLog::WARNING, 'jerror');
			return false;
		}
	}

	/**
	 * Function used to check if extension is already installed
	 *
	 * @param   string  $extension  The element name of the extension to install
	 * @param   string  $type       The type of extension being installed
	 * @param   string  $clientId   The ID of the application
	 *
	 * @return  boolean  True if extension exists
	 *
	 * @since   3.1
	 */
	protected function extensionExists($extension, $type, $clientId = null)
	{
		// Get a database connector object
		$db = $this->parent->getDBO();

		$query = $db->getQuery(true);
		$query->select($query->qn('extension_id'))
			->from($query->qn('#__extensions'))
			->where($query->qn('type') . ' = ' . $query->q($type))
			->where($query->qn('element') . ' = ' . $query->q($extension));

		if (!is_null($clientId))
		{
			$query->where($query->qn('client_id') . ' = ' . (int) $clientId);
		}
		$db->setQuery($query);

		try
		{
			$db->execute();
		}
		catch (RuntimeException $e)
		{
			// Install failed, roll back changes
			$this->parent->abort(JText::sprintf('JLIB_INSTALLER_ABORT_ROLLBACK', $db->stderr(true)));
			return false;
		}
		$id = $db->loadResult();

		if (empty($id))
		{
			return false;
		}

		return true;
	}

	/**
	 * Get the filtered component name from the manifest
	 *
	 * @return  string  The filtered name
	 *
	 * @since   3.1
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Get the class name for the install adapter script.
	 *
	 * @return  string  The class name.
	 *
	 * @since   3.1
	 */
	protected function getScriptClassName()
	{
		return $this->element . 'InstallerScript';
	}

	/**
	 * Generic install method for extensions
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	public function install()
	{
		// Get the component description
		$description = (string) $this->manifest->description;
		if ($description)
		{
			$this->parent->message = JText::_($description);
		}
		else
		{
			$this->parent->message = '';
		}
	}

	/**
	 * Setup the manifest script file for those adapters that use it.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function setupScriptfile()
	{
		// If there is an manifest class file, lets load it; we'll copy it later (don't have dest yet)
		$this->scriptElement = (string) $this->manifest->scriptfile;

		if ($this->scriptElement)
		{
			$manifestScriptFile = $this->parent->getPath('source') . '/' . $this->scriptElement;

			if (is_file($manifestScriptFile))
			{
				// Load the file
				include_once $manifestScriptFile;
			}

			$classname = $this->getScriptClassName();

			if (class_exists($classname))
			{
				// Create a new instance
				$this->parent->manifestClass = new $classname($this);

				// And set this so we can copy it later
				$this->manifest_script = $this->scriptElement;
			}
		}
	}

	/**
	 * Executes a custom install script method
	 *
	 * @param   string  $method  The install method to execute
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	protected function triggerManifestScript($method)
	{
		ob_start();
		ob_implicit_flush(false);

		if ($this->parent->manifestClass && method_exists($this->parent->manifestClass, $method))
		{
			switch ($method)
			{
				// The preflight and postflight take the route as a param
				case 'preflight':
				case 'postflight':
					if ($this->parent->manifestClass->$method($this->route, $this) === false)
					{
						if ($method != 'postflight')
						{
							// The script failed, rollback changes
							$this->parent->abort(JText::_('JLIB_INSTALLER_ABORT_INSTALL_CUSTOM_INSTALL_FAILURE'));
							return false;
						}
					}
					break;

				// The install, uninstall, and update methods only pass this object as a param
				case 'install':
				case 'uninstall':
				case 'update':
					if ($this->parent->manifestClass->$method($this) === false)
					{
						if ($method != 'uninstall')
						{
							// The script failed, rollback changes
							$this->parent->abort(JText::_('JLIB_INSTALLER_ABORT_INSTALL_CUSTOM_INSTALL_FAILURE'));
							return false;
						}
					}
					break;
			}
		}

		// Append to the message object
		$this->extensionMessage .= ob_get_clean();

		// If in postflight or uninstall, set the message for display
		if (($method == 'uninstall' || $method == 'postflight') && $this->extensionMessage != '')
		{
			$this->parent->extension_message = $this->extensionMessage;
		}

		return true;
	}

	/**
	 * Custom update method
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   3.1
	 */
	public function update()
	{
		// Set the overwrite setting
		$this->parent->setOverwrite(true);
		$this->parent->setUpgrade(true);

		// Set the route for the install
		$this->route = 'update';

		// Go to install which handles updates properly
		return $this->install();
	}
}
