<?php
/**
 * @package     Joomla.Libraries
 * @subpackage  Installer
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

jimport('joomla.filesystem.folder');

/**
 * Plugin installation adapter
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 */
class JInstallerAdapterPlugin extends JInstallerAdapter
{
	/**
	 * The list of current files that are installed and is read
	 * from the manifest on disk in the update area to handle doing a diff
	 * and deleting files that are in the old files list and not in the new
	 * files list.
	 *
	 * @var    array
	 * @since  3.1
	 */
	protected $oldFiles = null;

	public $group = null;

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

		$this->group = (string) $this->manifest->attributes()->group;
	}

	/**
	 * Get the filtered extension element from the manifest
	 *
	 * @return  string  The filtered element
	 *
	 * @since   3.1
	 */
	public function getElement($element = null)
	{
		if (!$element)
		{
			// Backward Compatibility
			// @todo Deprecate in future version

			if (count($this->manifest->files->children()))
			{
				$type = (string) $this->manifest->attributes()->type;
				foreach ($this->manifest->files->children() as $file)
				{
					if ((string) $file->attributes()->$type)
					{
						$element = (string) $file->attributes()->$type;
						break;
					}
				}
			}
		}

		return $element;
	}

	/**
	 * Load language from a path
	 *
	 * @param   string  $path  The path of the language.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function loadLanguage($path = null)
	{
		$source = $this->parent->getPath('source');

		if (!$source)
		{
			$this->parent->setPath('source', JPATH_PLUGINS . '/' . $this->parent->extension->folder . '/' . $this->parent->extension->element);
		}
		$element = $this->manifest->files;

		if ($element)
		{
			$group = strtolower((string) $this->manifest->attributes()->group);
			$name = '';

			if (count($element->children()))
			{
				foreach ($element->children() as $file)
				{
					if ((string) $file->attributes()->plugin)
					{
						$name = strtolower((string) $file->attributes()->plugin);
						break;
					}
				}
			}
			if ($name)
			{
				$extension = "plg_${group}_${name}";
				$source = $path ? $path : JPATH_PLUGINS . "/$group/$name";
				$folder = (string) $element->attributes()->folder;

				if ($folder && file_exists("$path/$folder"))
				{
					$source = "$path/$folder";
				}

				$this->doLoadLanguage($extension, $source, JPATH_ADMINISTRATOR);
			}
		}
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
		return 'plg' . str_replace('-', '', $this->group) . $this->element . 'InstallerScript';
	}

	/**
	 * Custom install method
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	public function install()
	{
		parent::install();

		// Get a database connector object
		$db = $this->parent->getDbo();

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Manifest Document Setup Section
		 * ---------------------------------------------------------------------------------------------
		 */

		if (!empty($this->element) && !empty($this->group))
		{
			$this->parent->setPath('extension_root', JPATH_PLUGINS . '/' . $this->group . '/' . $this->element);
		}
		else
		{
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_PLG_INSTALL_NO_FILE', JText::_('JLIB_INSTALLER_' . $this->route)));
		}

		// Check if we should enable overwrite settings

		// Check to see if a plugin by the same name is already installed.
		try
		{
			$id = $this->extension->find(array('type' => 'plugin', 'element' => $this->element, 'folder' => $this->group));
		}
		catch (RuntimeException $e)
		{
			// Install failed, roll back changes
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_PLG_INSTALL_ROLLBACK', JText::_('JLIB_INSTALLER_' . $this->route), $db->stderr(true)));
		}

		// If it's on the fs...
		if (file_exists($this->parent->getPath('extension_root')) && (!$this->parent->isOverwrite() || $this->parent->isUpgrade()))
		{
			$updateElement = $this->manifest->update;

			// Upgrade manually set or update function available or update tag detected
			if ($this->parent->isUpgrade() || ($this->parent->manifestClass && method_exists($this->parent->manifestClass, 'update'))
				|| $updateElement)
			{
				// Force this one
				$this->parent->setOverwrite(true);
				$this->parent->setUpgrade(true);

				if ($id)
				{
					// If there is a matching extension mark this as an update; semantics really
					$this->setRoute('update');
				}
			}
			elseif (!$this->parent->isOverwrite())
			{
				// Overwrite is set
				// We didn't have overwrite set, find an update function or find an update tag so lets call it safe
				throw new RuntimeException(
					JText::sprintf(
						'JLIB_INSTALLER_ABORT_PLG_INSTALL_DIRECTORY', JText::_('JLIB_INSTALLER_' . $this->route),
						$this->parent->getPath('extension_root')
					)
				);
			}
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Installer Trigger Loading
		 * ---------------------------------------------------------------------------------------------
		 */

		$this->setupScriptfile();
		$this->triggerManifestScript('preflight');

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Filesystem Processing Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// If the plugin directory does not exist, lets create it
		$created = false;

		if (!file_exists($this->parent->getPath('extension_root')))
		{
			if (!$created = JFolder::create($this->parent->getPath('extension_root')))
			{
				throw new RuntimeException(
					JText::sprintf(
						'JLIB_INSTALLER_ABORT_PLG_INSTALL_CREATE_DIRECTORY', JText::_('JLIB_INSTALLER_' . $this->route),
						$this->parent->getPath('extension_root')
					)
				);
			}
		}

		// If we're updating at this point when there is always going to be an extension_root find the old XML files
		if ($this->route == 'update')
		{
			// Create a new installer because findManifest sets stuff; side effects!
			$tmpInstaller = new JInstaller;

			// Look in the extension root
			$tmpInstaller->setPath('source', $this->parent->getPath('extension_root'));

			if ($tmpInstaller->findManifest())
			{
				$old_manifest = $tmpInstaller->getManifest();
				$this->oldFiles = $old_manifest->files;
			}
		}

		/*
		 * If we created the plugin directory and will want to remove it if we
		 * have to roll back the installation, let's add it to the installation
		 * step stack
		 */

		if ($created)
		{
			$this->parent->pushStep(array('type' => 'folder', 'path' => $this->parent->getPath('extension_root')));
		}

		// Copy all necessary files
		if ($this->parent->parseFiles($this->manifest->files, -1, $this->oldFiles) === false)
		{
			// TODO: throw exception
			return false;
		}

		// Parse optional tags -- media and language files for plugins go in admin app
		$this->parent->parseMedia($this->manifest->media, 1);
		$this->parent->parseLanguages($this->manifest->languages, 1);

		// If there is a manifest script, lets copy it.
		if ($this->manifest_script)
		{
			$path['src'] = $this->parent->getPath('source') . '/' . $this->manifest_script;
			$path['dest'] = $this->parent->getPath('extension_root') . '/' . $this->manifest_script;

			if (!file_exists($path['dest']))
			{
				if (!$this->parent->copyFiles(array($path)))
				{
					// Install failed, rollback changes
					throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_PLG_INSTALL_MANIFEST', JText::_('JLIB_INSTALLER_' . $this->route)));
				}
			}
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Database Processing Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// Was there a plugin with the same name already installed?
		if ($id)
		{
			if (!$this->parent->isOverwrite())
			{
				// Install failed, roll back changes
				throw new RuntimeException(
					JText::sprintf(
						'JLIB_INSTALLER_ABORT_PLG_INSTALL_ALLREADY_EXISTS', JText::_('JLIB_INSTALLER_' . $this->route),
						$this->name
					)
				);
			}
			$this->extension->load($id);
			$this->extension->name = $this->name;
			$this->extension->manifest_cache = $this->parent->generateManifestCache();

			// Update the manifest cache and name
			$this->extension->store();
		}
		else
		{
			// Store in the extensions table (1.6)
			$this->extension->name = $this->name;
			$this->extension->type = 'plugin';
			$this->extension->ordering = 0;
			$this->extension->element = $this->element;
			$this->extension->folder = $this->group;
			$this->extension->enabled = 0;
			$this->extension->protected = 0;
			$this->extension->access = 1;
			$this->extension->client_id = 0;
			$this->extension->params = $this->parent->getParams();

			// Custom data
			$this->extension->custom_data = '';

			// System data
			$this->extension->system_data = '';
			$this->extension->manifest_cache = $this->parent->generateManifestCache();

			// Editor plugins are published by default
			if ($this->group == 'editors')
			{
				$this->extension->enabled = 1;
			}

			if (!$this->extension->store())
			{
				// Install failed, roll back changes
				throw new RuntimeException(
					JText::sprintf('JLIB_INSTALLER_ABORT_PLG_INSTALL_ROLLBACK', JText::_('JLIB_INSTALLER_' . $this->route), $db->stderr(true))
				);
			}

			// Since we have created a plugin item, we add it to the installation step stack
			// so that if we have to rollback the changes we can undo it.
			$this->parent->pushStep(array('type' => 'extension', 'id' => $this->extension->extension_id));
			$id = $this->extension->extension_id;
		}

		// Let's run the queries for the plugin
		if ($this->route == 'install')
		{
			if (!$this->doDatabaseTransactions('install'))
			{
				return false;
			}

			// Set the schema version to be the latest update version
			if ($this->manifest->update)
			{
				$this->parent->setSchemaVersion($this->manifest->update->schemas, $this->extension->extension_id);
			}
		}
		elseif ($this->route == 'update')
		{
			if ($this->manifest->update)
			{
				$result = $this->parent->parseSchemaUpdates($this->manifest->update->schemas, $this->extension->extension_id);

				if ($result === false)
				{
					// Install failed, rollback changes
					throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_PLG_UPDATE_SQL_ERROR', $db->stderr(true)));
				}
			}
		}

		// Run the custom method based on the route
		$this->triggerManifestScript($this->route);

		/**
		 * ---------------------------------------------------------------------------------------------
		 * Finalization and Cleanup Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// Lastly, we will copy the manifest file to its appropriate place.
		if (!$this->parent->copyManifest(-1))
		{
			// Install failed, rollback changes
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_PLG_INSTALL_COPY_SETUP', JText::_('JLIB_INSTALLER_' . $this->route)));
		}

		// And now we run the postflight
		$this->triggerManifestScript('postflight');

		return $id;
	}

	/**
	 * Method to prepare the uninstall script
	 *
	 * This method populates the $this->extension object, checks whether the extension is protected,
	 * and sets the extension paths
	 *
	 * @param   integer  $id  The extension ID to load
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	protected function setupUninstall($id)
	{
		// Run the common parent methods
		if (parent::setupUninstall($id))
		{
			// Get the plugin folder so we can properly build the plugin path
			if (trim($this->extension->folder) == '')
			{
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_PLG_UNINSTALL_FOLDER_FIELD_EMPTY'), JLog::WARNING, 'jerror');

				return false;
			}

			$this->group = $this->extension->folder;

			// Set the plugin root path
			$this->parent->setPath('extension_root', JPATH_PLUGINS . '/' . $this->extension->folder . '/' . $this->extension->element);

			$this->parent->setPath('source', $this->parent->getPath('extension_root'));
		}

		return true;
	}

	/**
	 * Custom uninstall method
	 *
	 * @param   integer  $id  The id of the plugin to uninstall
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	public function uninstall($id)
	{
		$db = $this->parent->getDbo();

		// Prepare the uninstaller for action
		$this->setupUninstall((int) $id);

		$this->parent->findManifest();
		$this->manifest = $this->parent->getManifest();

		// Attempt to load the language file; might have uninstall strings
		$this->loadLanguage($this->parent->getPath('extension_root'));

		/**
		 * ---------------------------------------------------------------------------------------------
		 * Installer Trigger Loading
		 * ---------------------------------------------------------------------------------------------
		 */

		$this->setupScriptfile();

		// TODO: shouldn't this be removed?!?
		$this->triggerManifestScript('preflight');

		// Let's run the queries for the plugin
		$result = $this->doDatabaseTransactions('uninstall');

		if ($result === false)
		{
			// Install failed, rollback changes
			$this->parent->abort(JText::sprintf('JLIB_INSTALLER_ABORT_PLG_UNINSTALL_SQL_ERROR', $db->stderr(true)));

			return false;
		}

		// Run the custom uninstall method if possible
		$this->triggerManifestScript('uninstall');

		// Remove the plugin files
		$this->parent->removeFiles($this->manifest->files, -1);

		// Remove all media and languages as well
		$this->parent->removeFiles($this->manifest->media);
		$this->parent->removeFiles($this->manifest->languages, 1);

		// Remove the schema version
		$query = $db->getQuery(true);
		$query->delete()->from('#__schemas')->where('extension_id = ' . $this->extension->extension_id);
		$db->setQuery($query);
		$db->execute();

		// Now we will no longer need the plugin object, so let's delete it
		$this->extension->delete($this->extension->extension_id);
		unset($this->extension);

		// Remove the plugin's folder
		JFolder::delete($this->parent->getPath('extension_root'));

		return true;
	}

	/**
	 * Custom discover method
	 *
	 * @return  array  JExtension list of extensions available
	 *
	 * @since   3.1
	 */
	public function discover()
	{
		$results = array();
		$folder_list = JFolder::folders(JPATH_SITE . '/plugins');

		foreach ($folder_list as $folder)
		{
			$file_list = JFolder::files(JPATH_SITE . '/plugins/' . $folder, '\.xml$');

			foreach ($file_list as $file)
			{
				$manifest_details = JInstaller::parseXMLInstallFile(JPATH_SITE . '/plugins/' . $folder . '/' . $file);
				$file = JFile::stripExt($file);

				$extension = JTable::getInstance('extension');
				$extension->type = 'plugin';
				$extension->client_id = 0;
				$extension->element = $file;
				$extension->folder = $folder;
				$extension->name = $file;
				$extension->state = -1;
				$extension->manifest_cache = json_encode($manifest_details);
				$results[] = $extension;
			}

			$folder_list = JFolder::folders(JPATH_SITE . '/plugins/' . $folder);

			foreach ($folder_list as $plugin_folder)
			{
				$file_list = JFolder::files(JPATH_SITE . '/plugins/' . $folder . '/' . $plugin_folder, '\.xml$');

				foreach ($file_list as $file)
				{
					$manifest_details = JInstaller::parseXMLInstallFile(
						JPATH_SITE . '/plugins/' . $folder . '/' . $plugin_folder . '/' . $file
					);
					$file = JFile::stripExt($file);

					$extension = JTable::getInstance('extension');
					$extension->type = 'plugin';
					$extension->client_id = 0;
					$extension->element = $file;
					$extension->folder = $folder;
					$extension->name = $file;
					$extension->state = -1;
					$extension->manifest_cache = json_encode($manifest_details);
					$results[] = $extension;
				}
			}
		}

		return $results;
	}

	/**
	 * Custom discover_install method.
	 *
	 * @return  mixed
	 *
	 * @since   3.1
	 */
	public function discover_install()
	{
		$this->element = $this->parent->extension->element;
		$this->group = $this->parent->extension->folder;

		/*
		 * Plugins use the extensions table as their primary store
		 * Similar to modules and templates, rather easy
		 * If it's not in the extensions table we just add it
		 */
		// @deprecated  4.0  This conditional handles 1.5 style plugin installs, all other support was dropped for 3.0
		if (is_dir(JPATH_SITE . '/plugins/' . $this->group . '/' . $this->element))
		{
			$manifestPath = JPATH_SITE . '/plugins/' . $this->group . '/' . $this->element . '/' . $this->element . '.xml';
		}
		else
		{
			$manifestPath = JPATH_SITE . '/plugins/' . $this->group . '/' . $this->element . '.xml';
		}
		$this->parent->manifest = $this->parent->isManifest($manifestPath);
		$description = (string) $this->parent->manifest->description;

		if ($description)
		{
			$this->parent->message = JText::_($description);
		}
		else
		{
			$this->parent->message = '';
		}
		$this->parent->setPath('manifest', $manifestPath);
		$manifest_details = JInstaller::parseXMLInstallFile($manifestPath);
		$this->parent->extension->manifest_cache = json_encode($manifest_details);
		$this->parent->extension->state = 0;
		$this->parent->extension->name = $manifest_details['name'];
		$this->parent->extension->enabled = ('editors' == $this->group) ? 1 : 0;
		$this->parent->extension->params = $this->parent->getParams();

		if ($this->parent->extension->store())
		{
			return $this->parent->extension->extension_id;
		}
		else
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_PLG_DISCOVER_STORE_DETAILS'), JLog::WARNING, 'jerror');

			return false;
		}
	}

	/**
	 * Refreshes the extension table cache.
	 *
	 * @return  boolean  Result of operation, true if updated, false on failure.
	 *
	 * @since   3.1
	 */
	public function refreshManifestCache()
	{
		/*
		 * Plugins use the extensions table as their primary store
		 * Similar to modules and templates, rather easy
		 * If it's not in the extensions table we just add it
		 */
		$manifestPath = JPATH_SITE . '/plugins/' . $this->parent->extension->folder . '/' . $this->parent->extension->element . '/'
			. $this->parent->extension->element . '.xml';

		return $this->doRefreshManifestCache($manifestPath);
	}
}

/**
 * Deprecated class placeholder. You should use JInstallerAdapterPlugin instead.
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 * @deprecated  4.0
 * @codeCoverageIgnore
 */
class JInstallerPlugin extends JInstallerAdapterPlugin
{
}
