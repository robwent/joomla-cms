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
 * Module installation adapter
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 */
class JInstallerAdapterModule extends JInstallerAdapter
{
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
			if (count($this->manifest->files->children()))
			{
				foreach ($this->manifest->files->children() as $file)
				{
					if ((string) $file->attributes()->module)
					{
						$element = (string) $file->attributes()->module;

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
			$this->parent
				->setPath(
				'source',
				($this->parent->extension->client_id ? JPATH_ADMINISTRATOR : JPATH_SITE) . '/modules/' . $this->parent->extension->element
			);
		}

		if ($this->manifest->files)
		{
			$element = $this->manifest->files;
			$extension = '';

			if (count($element->children()))
			{
				foreach ($element->children() as $file)
				{
					if ((string) $file->attributes()->module)
					{
						$extension = strtolower((string) $file->attributes()->module);
						break;
					}
				}
			}

			if ($extension)
			{
				$source = $path ? $path : ($this->parent->extension->client_id ? JPATH_ADMINISTRATOR : JPATH_SITE) . '/modules/' . $extension;
				$folder = (string) $element->attributes()->folder;

				if ($folder && file_exists($path . '/' . $folder))
				{
					$source = $path . '/' . $folder;
				}

				$client = (string) $this->manifest->attributes()->client;

				$this->doLoadLanguage($extension, $source, constant('JPATH_' . strtoupper($client)));
			}
		}
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
		 * Target Application Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// Get the target application
		$cname = (string) $this->manifest->attributes()->client;

		if ($cname)
		{
			// Attempt to map the client to a base path
			$client = JApplicationHelper::getClientInfo($cname, true);

			if ($client === false)
			{
				throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_MOD_UNKNOWN_CLIENT', JText::_('JLIB_INSTALLER_' . $this->route), $client->name));
			}

			$basePath = $client->path;
			$clientId = $client->id;
		}
		else
		{
			// No client attribute was found so we assume the site as the client
			$basePath = JPATH_SITE;
			$clientId = 0;
		}

		// Set the installation path
		if (!empty($this->element))
		{
			$this->parent->setPath('extension_root', $basePath . '/modules/' . $this->element);
		}
		else
		{
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_MOD_INSTALL_NOFILE', JText::_('JLIB_INSTALLER_' . $this->route)));
		}

		/*
		 * Check to see if a module by the same name is already installed
		 * If it is, then update the table because if the files aren't there
		 * we can assume that it was (badly) uninstalled
		 * If it isn't, add an entry to extensions
		 */
		try
		{
			$id = JTable::getInstance('extension')->find(array('element' => $this->element, 'type' => 'module', 'client_id'=>$clientId));
		}
		catch (RuntimeException $e)
		{
			// Install failed, roll back changes
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_MOD_ROLLBACK', JText::_('JLIB_INSTALLER_' . $this->route), $e->getMessage()));
		}

		/*
		 * If the module directory already exists, then we will assume that the
		 * module is already installed or another module is using that
		 * directory.
		 * Check that this is either an issue where its not overwriting or it is
		 * set to upgrade anyway
		 */
		if (file_exists($this->parent->getPath('extension_root')) && (!$this->parent->isOverwrite() || $this->parent->isUpgrade()))
		{
			// Look for an update function or update tag
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
						'JLIB_INSTALLER_ABORT_MOD_INSTALL_DIRECTORY',
						JText::_('JLIB_INSTALLER_' . $this->route),
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

		// If the module directory does not exist, lets create it
		$created = false;

		if (!file_exists($this->parent->getPath('extension_root')))
		{
			if (!$created = JFolder::create($this->parent->getPath('extension_root')))
			{
				throw new RuntimeException(
					JText::sprintf(
						'JLIB_INSTALLER_ABORT_MOD_INSTALL_CREATE_DIRECTORY', JText::_('JLIB_INSTALLER_' . $this->route),
						$this->parent->getPath('extension_root')
					)
				);
			}
		}

		/*
		 * Since we created the module directory and will want to remove it if
		 * we have to roll back the installation, let's add it to the
		 * installation step stack
		 */

		if ($created)
		{
			$this->parent->pushStep(array('type' => 'folder', 'path' => $this->parent->getPath('extension_root')));
		}

		// Copy all necessary files
		if ($this->parent->parseFiles($this->manifest->files, -1) === false)
		{
			// TODO: throw exception
			return false;
		}

		// If there is a manifest script, let's copy it.
		if ($this->manifest_script)
		{
			$path['src'] = $this->parent->getPath('source') . '/' . $this->manifest_script;
			$path['dest'] = $this->parent->getPath('extension_root') . '/' . $this->manifest_script;

			if (!file_exists($path['dest']) || $this->parent->isOverwrite())
			{
				if (!$this->parent->copyFiles(array($path)))
				{
					// Install failed, rollback changes
					throw new RuntimeException(JText::_('JLIB_INSTALLER_ABORT_MOD_INSTALL_MANIFEST'));
				}
			}
		}

		// Parse optional tags
		$this->parent->parseMedia($this->manifest->media, $clientId);
		$this->parent->parseLanguages($this->manifest->languages, $clientId);

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Database Processing Section
		 * ---------------------------------------------------------------------------------------------
		 */

		$row = JTable::getInstance('extension');

		// Was there a module already installed with the same name?
		if ($id)
		{
			// Load the entry and update the manifest_cache
			$row->load($id);

			// Update name
			$row->name = $this->name;

			// Update manifest
			$row->manifest_cache = $this->parent->generateManifestCache();

			if (!$row->store())
			{
				// Install failed, roll back changes
				throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_MOD_ROLLBACK', JText::_('JLIB_INSTALLER_' . $this->route), $db->stderr(true)));
			}
		}
		else
		{
			$row->name = $this->name;
			$row->type = 'module';
			$row->element = $this->element;

			// There is no folder for modules
			$row->folder = '';
			$row->enabled = 1;
			$row->protected = 0;
			$row->access = $clientId == 1 ? 2 : 0;
			$row->client_id = $clientId;
			$row->params = $this->parent->getParams();

			// Custom data
			$row->custom_data = '';
			$row->manifest_cache = $this->parent->generateManifestCache();

			if (!$row->store())
			{
				// Install failed, roll back changes
				throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_MOD_ROLLBACK', JText::_('JLIB_INSTALLER_' . $this->route), $db->stderr(true)));
			}

			// Since we have created a module item, we add it to the installation step stack
			// so that if we have to rollback the changes we can undo it.
			$this->parent->pushStep(array('type' => 'extension', 'extension_id' => $row->extension_id));

			// Create unpublished module in jos_modules
			$name = preg_replace('#[\*?]#', '', JText::_($this->name));
			$module = JTable::getInstance('module');

			$module->title = $name;
			$module->content = '';
			$module->module = $this->element;
			$module->access = '1';
			$module->showtitle = '1';
			$module->params = '';
			$module->client_id = $clientId;
			$module->language = '*';

			$module->store();
		}

		// Let's run the queries for the module
		if ($this->route == 'install')
		{
			if (!$this->doDatabaseTransactions('install'))
			{
				// TODO: throw exception
				return false;
			}

			// Set the schema version to be the latest update version
			if ($this->manifest->update)
			{
				$this->parent->setSchemaVersion($this->manifest->update->schemas, $row->extension_id);
			}
		}
		elseif ($this->route == 'update')
		{
			if ($this->manifest->update)
			{
				$result = $this->parent->parseSchemaUpdates($this->manifest->update->schemas, $row->extension_id);

				if ($result === false)
				{
					// Install failed, rollback changes
					throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_MOD_UPDATE_SQL_ERROR', $db->stderr(true)));
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
			throw new RuntimeException(JText::_('JLIB_INSTALLER_ABORT_MOD_INSTALL_COPY_SETUP'));
		}

		// And now we run the postflight
		$this->triggerManifestScript('postflight');

		return $row->extension_id;
	}

	/**
	 * Custom discover method
	 *
	 * @return  array  Array of JTableExtension instances of extensions available to install
	 *
	 * @since   3.1
	 */
	public function discover()
	{
		$results = array();
		$site_list = JFolder::folders(JPATH_SITE . '/modules');
		$admin_list = JFolder::folders(JPATH_ADMINISTRATOR . '/modules');
		$site_info = JApplicationHelper::getClientInfo('site', true);
		$admin_info = JApplicationHelper::getClientInfo('administrator', true);

		foreach ($site_list as $module)
		{
			$manifest_details = JInstaller::parseXMLInstallFile(JPATH_SITE . "/modules/$module/$module.xml");
			$extension = JTable::getInstance('extension');
			$extension->type = 'module';
			$extension->client_id = $site_info->id;
			$extension->element = $module;
			$extension->name = $module;
			$extension->state = -1;
			$extension->manifest_cache = json_encode($manifest_details);
			$results[] = clone $extension;
		}

		foreach ($admin_list as $module)
		{
			$manifest_details = JInstaller::parseXMLInstallFile(JPATH_ADMINISTRATOR . "/modules/$module/$module.xml");
			$extension = JTable::getInstance('extension');
			$extension->type = 'module';
			$extension->client_id = $admin_info->id;
			$extension->element = $module;
			$extension->name = $module;
			$extension->state = -1;
			$extension->manifest_cache = json_encode($manifest_details);
			$results[] = clone $extension;
		}

		return $results;
	}

	/**
	 * Custom discover_install method
	 *
	 * @return  mixed  Extension ID on success, boolean false on failure
	 *
	 * @since   3.1
	 */
	public function discover_install()
	{
		$this->element = $this->parent->extension->element;

		// Modules are like templates, and are one of the easiest
		// If its not in the extensions table we just add it
		$client = JApplicationHelper::getClientInfo($this->parent->extension->client_id);
		$manifestPath = $client->path . '/modules/' . $this->element . '/' . $this->element . '.xml';
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
		$manifest_details = JInstaller::parseXMLInstallFile($this->parent->getPath('manifest'));

		// TODO: Re-evaluate this; should we run installation triggers? postflight perhaps?
		$this->parent->extension->manifest_cache = json_encode($manifest_details);
		$this->parent->extension->state = 0;
		$this->parent->extension->name = $manifest_details['name'];
		$this->parent->extension->enabled = 1;
		$this->parent->extension->params = $this->parent->getParams();

		if ($this->parent->extension->store())
		{
			return $this->parent->extension->extension_id;
		}
		else
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_MOD_DISCOVER_STORE_DETAILS'), JLog::WARNING, 'jerror');

			return false;
		}
	}

	/**
	 * Refreshes the extension table cache
	 *
	 * @return  boolean  Result of operation, true if updated, false on failure.
	 *
	 * @since   3.1
	 */
	public function refreshManifestCache()
	{
		$client = JApplicationHelper::getClientInfo($this->parent->extension->client_id);
		$manifestPath = $client->path . '/modules/' . $this->parent->extension->element . '/' . $this->parent->extension->element . '.xml';

		return $this->doRefreshManifestCache($manifestPath);
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
			$client = JApplicationHelper::getClientInfo($this->extension->client_id);

			if ($client === false)
			{
				$this->parent->abort(JText::sprintf('JLIB_INSTALLER_ERROR_MOD_UNINSTALL_UNKNOWN_CLIENT', $this->extension->client_id));

				return false;
			}

			$this->parent->setPath('extension_root', $client->path . '/modules/' . $this->element);

			$this->parent->setPath('source', $this->parent->getPath('extension_root'));
		}

		return true;
	}

	/**
	 * Custom uninstall method
	 *
	 * @param   integer  $id  The id of the module to uninstall
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	public function uninstall($id)
	{
		$retval = true;
		$db = $this->parent->getDbo();

		// Prepare the uninstaller for action
		$this->setupUninstall((int) $id);

		// Get the module's manifest objecct
		// We do findManifest to avoid problem when uninstalling a list of extensions: getManifest cache its manifest file.
		$this->parent->findManifest();
		$this->manifest = $this->parent->getManifest();

		// Attempt to load the language file; might have uninstall strings
		$this->loadLanguage(($this->extension->client_id ? JPATH_ADMINISTRATOR : JPATH_SITE) . '/modules/' . $this->element);

		$this->setupScriptfile();
		$this->triggerManifestScript('uninstall');

		if (!($this->manifest instanceof SimpleXMLElement))
		{
			// Make sure we delete the folders
			JFolder::delete($this->parent->getPath('extension_root'));
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_MOD_UNINSTALL_INVALID_NOTFOUND_MANIFEST'), JLog::WARNING, 'jerror');

			return false;
		}

		// Let's run the uninstall queries for the module
		$result = $this->doDatabaseTransactions('uninstall');

		if ($result === false)
		{
			// Install failed, rollback changes
			JLog::add(JText::sprintf('JLIB_INSTALLER_ERROR_MOD_UNINSTALL_SQL_ERROR', $db->stderr(true)), JLog::WARNING, 'jerror');
			$retval = false;
		}

		// Remove the schema version
		$query = $db->getQuery(true);
		$query->delete('#__schemas')->where('extension_id = ' . $this->extension->extension_id);
		$db->setQuery($query);
		$db->execute();

		// Remove other files
		$this->parent->removeFiles($this->manifest->media);
		$this->parent->removeFiles($this->manifest->languages, $this->extension->client_id);

		// Let's delete all the module copies for the type we are uninstalling
		$query = $db->getQuery(true);
		$query->select($query->qn('id'))->from($query->qn('#__modules'));
		$query->where($query->qn('module') . ' = ' . $query->q($this->extension->element));
		$query->where($query->qn('client_id') . ' = ' . (int) $this->extension->client_id);
		$db->setQuery($query);

		try
		{
			$modules = $db->loadColumn();
		}
		catch (RuntimeException $e)
		{
			$modules = array();
		}

		// Do we have any module copies?
		if (count($modules))
		{
			// Ensure the list is sane
			JArrayHelper::toInteger($modules);
			$modID = implode(',', $modules);

			// Wipe out any items assigned to menus
			$query = $db->getQuery(true);
			$query->delete($db->quoteName('#__modules_menu'));
			$query->where($db->quoteName('moduleid') . ' IN (' . $modID . ')');
			$db->setQuery($query);

			try
			{
				$db->execute();
			}
			catch (RuntimeException $e)
			{
				JLog::add(JText::sprintf('JLIB_INSTALLER_ERROR_MOD_UNINSTALL_EXCEPTION', $db->stderr(true)), JLog::WARNING, 'jerror');
				$retval = false;
			}

			// Wipe out any instances in the modules table
			$query = $db->getQuery(true);
			$query->delete($db->quoteName('#__modules'));
			$query->where($db->quoteName('id') . ' IN (' . $modID . ')');
			$db->setQuery($query);

			try
			{
				$db->execute();
			}
			catch (RuntimeException $e)
			{
				JLog::add(JText::sprintf('JLIB_INSTALLER_ERROR_MOD_UNINSTALL_EXCEPTION', $db->stderr(true)), JLog::WARNING, 'jerror');
				$retval = false;
			}
		}

		// Now we will no longer need the module object, so let's delete it and free up memory
		$this->extension->delete($this->extension->extension_id);
		$query = $db->getQuery(true);
		$query->delete($db->quoteName('#__modules'));
		$query->where($db->quoteName('module') . ' = ' . $db->quote($this->extension->element));
		$query->where($db->quoteName('client_id') . ' = ' . $this->extension->client_id);
		$db->setQuery($query);

		try
		{
			// Clean up any other ones that might exist as well
			$db->execute();
		}
		catch (RuntimeException $e)
		{
			// Ignore the error...
		}

		unset($this->extension);

		// Remove the installation folder
		if (!JFolder::delete($this->parent->getPath('extension_root')))
		{
			// JFolder should raise an error
			$retval = false;
		}

		return $retval;
	}

	/**
	 * Custom rollback method
	 * - Roll back the menu item
	 *
	 * @param   array  $arg  Installation step to rollback
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	protected function _rollback_menu($arg)
	{
		// Get database connector object
		$db = $this->parent->getDbo();

		// Remove the entry from the #__modules_menu table
		$query = $db->getQuery(true);
		$query->delete($db->quoteName('#__modules_menu'));
		$query->where($db->quoteName('moduleid') . ' = ' . (int) $arg['id']);
		$db->setQuery($query);

		try
		{
			return $db->execute();
		}
		catch (RuntimeException $e)
		{
			return false;
		}
	}

	/**
	 * Custom rollback method
	 * - Roll back the module item
	 *
	 * @param   array  $arg  Installation step to rollback
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	protected function _rollback_module($arg)
	{
		// Get database connector object
		$db = $this->parent->getDbo();

		// Remove the entry from the #__modules table
		$query = $db->getQuery(true);
		$query->delete($db->quoteName('#__modules'));
		$query->where($db->quoteName('id') . ' = ' . (int) $arg['id']);
		$db->setQuery($query);

		try
		{
			return $db->execute();
		}
		catch (RuntimeException $e)
		{
			return false;
		}
	}
}

/**
 * Deprecated class placeholder. You should use JInstallerAdapterModule instead.
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 * @deprecated  4.0
 * @codeCoverageIgnore
 */
class JInstallerModule extends JInstallerAdapterModule
{
}
