<?php
/**
 * @package     Joomla.Libraries
 * @subpackage  Installer
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

/**
 * Package installation adapter
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 */
class JInstallerAdapterPackage extends JInstallerAdapter
{
	/**
	 * The results of each installed extensions
	 *
	 * @var    array
	 * @since  3.1
	 */
	protected $results = array();

	/**
	 * Load language from a path
	 *
	 * @param   string  $path  The path of the language.
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	public function loadLanguage($path)
	{
		$this->manifest = $this->parent->getManifest();
		$extension = 'pkg_' . strtolower(JFilterInput::getInstance()->clean((string) $this->manifest->packagename, 'cmd'));

		$this->doLoadLanguage($extension, $path);
	}

	/**
	 * Custom install method
	 *
	 * @return  int  The extension id
	 *
	 * @since   3.1
	 */
	public function install()
	{
		parent::install();

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Manifest Document Setup Section
		 * ---------------------------------------------------------------------------------------------
		 */

		$filter = JFilterInput::getInstance();
		$element = 'pkg_' . $filter->clean($this->manifest->packagename, 'cmd');
		$this->element = $element;

		// Set the installation path
		$files = $this->manifest->files;
		$group = (string) $this->manifest->packagename;

		if (!empty($group))
		{
			$this->parent->setPath('extension_root', JPATH_MANIFESTS . '/packages/' . implode(DIRECTORY_SEPARATOR, explode('/', $group)));
		}
		else
		{
			$this->parent->abort(JText::sprintf('JLIB_INSTALLER_ABORT_PACK_INSTALL_NO_PACK', JText::_('JLIB_INSTALLER_' . strtoupper($this->route))));

			return false;
		}

		// If the package manifest already exists, then we will assume that the package is already installed.
		if (file_exists(JPATH_MANIFESTS . '/packages/' . basename($this->parent->getPath('manifest'))))
		{
			// Look for an update function or update tag
			$updateElement = $this->manifest->update;

			// If $this->upgrade has already been set, or an update property exists in the manifest, update the extensions
			if ($this->parent->isUpgrade() || $updateElement)
			{
				// Use the update route for all packaged extensions
				$this->route = 'update';
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

		if ($folder = $files->attributes()->folder)
		{
			$source = $this->parent->getPath('source') . '/' . $folder;
		}
		else
		{
			$source = $this->parent->getPath('source');
		}

		// Install all necessary files
		if (count($this->manifest->files->children()))
		{
			$i = 0;

			foreach ($this->manifest->files->children() as $child)
			{
				$file = $source . '/' . $child;

				if (is_dir($file))
				{
					// If it's actually a directory then fill it up
					$package = array();
					$package['dir'] = $file;
					$package['type'] = JInstallerHelper::detectType($file);
				}
				else
				{
					// If it's an archive
					$package = JInstallerHelper::unpack($file);
				}
				$tmpInstaller = new JInstaller;
				$installResult = $tmpInstaller->{$this->route}($package['dir']);

				if (!$installResult)
				{
					$this->parent->abort(
						JText::sprintf(
							'JLIB_INSTALLER_ABORT_PACK_INSTALL_ERROR_EXTENSION', JText::_('JLIB_INSTALLER_' . strtoupper($this->route)),
							basename($file)
						)
					);

					return false;
				}
				else
				{
					$this->results[$i] = array(
						'name' => $tmpInstaller->manifest->name,
						'result' => $installResult
					);
				}
				$i++;
			}
		}
		else
		{
			$this->parent->abort(JText::sprintf('JLIB_INSTALLER_ABORT_PACK_INSTALL_NO_FILES', JText::_('JLIB_INSTALLER_' . strtoupper($this->route))));

			return false;
		}

		// Parse optional tags
		$this->parent->parseLanguages($this->manifest->languages);

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Extension Registration
		 * ---------------------------------------------------------------------------------------------
		 */

		$row = JTable::getInstance('extension');
		$eid = $row->find(array('element' => strtolower($this->element), 'type' => 'package'));

		if ($eid)
		{
			$row->load($eid);
		}
		else
		{
			$row->name = $this->name;
			$row->type = 'package';
			$row->element = $this->element;

			// There is no folder for modules
			$row->folder = '';
			$row->enabled = 1;
			$row->protected = 0;
			$row->access = 1;
			$row->client_id = 0;

			// Custom data
			$row->custom_data = '';
			$row->params = $this->parent->getParams();
		}
		// Update the manifest cache for the entry
		$row->manifest_cache = $this->parent->generateManifestCache();

		if (!$row->store())
		{
			// Install failed, roll back changes
			$this->parent->abort(JText::sprintf('JLIB_INSTALLER_ABORT_PACK_INSTALL_ROLLBACK', $row->getError()));

			return false;
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Finalization and Cleanup Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// Run the custom method based on the route
		$this->triggerManifestScript($this->route);

		// Lastly, we will copy the manifest file to its appropriate place.
		$manifest = array();
		$manifest['src'] = $this->parent->getPath('manifest');
		$manifest['dest'] = JPATH_MANIFESTS . '/packages/' . basename($this->parent->getPath('manifest'));

		if (!$this->parent->copyFiles(array($manifest), true))
		{
			// Install failed, rollback changes
			$this->parent->abort(
				JText::sprintf('JLIB_INSTALLER_ABORT_PACK_INSTALL_COPY_SETUP', JText::_('JLIB_INSTALLER_ABORT_PACK_INSTALL_NO_FILES'))
			);

			return false;
		}

		// If there is a manifest script, let's copy it.
		if ($this->manifest_script)
		{
			// First, we have to create a folder for the script if one isn't present
			if (!file_exists($this->parent->getPath('extension_root')))
			{
				JFolder::create($this->parent->getPath('extension_root'));
			}

			$path['src'] = $this->parent->getPath('source') . '/' . $this->manifest_script;
			$path['dest'] = $this->parent->getPath('extension_root') . '/' . $this->manifest_script;

			if (!file_exists($path['dest']) || $this->parent->isOverwrite())
			{
				if (!$this->parent->copyFiles(array($path)))
				{
					// Install failed, rollback changes
					$this->parent->abort(JText::_('JLIB_INSTALLER_ABORT_PACKAGE_INSTALL_MANIFEST'));

					return false;
				}
			}
		}

		// And now we run the postflight
		$this->triggerManifestScript('postflight', $results);

		return $row->extension_id;
	}

	/**
	 * Custom uninstall method
	 *
	 * @param   integer  $id  The id of the package to uninstall.
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	public function uninstall($id)
	{
		$row = null;
		$retval = true;

		$row = JTable::getInstance('extension');
		$row->load($id);

		if ($row->protected)
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_PACK_UNINSTALL_WARNCOREPACK'), JLog::WARNING, 'jerror');

			return false;
		}

		$manifestFile = JPATH_MANIFESTS . '/packages/' . $row->element . '.xml';
		$manifest = new JInstallerManifestPackage($manifestFile);

		// Set the package root path
		$this->parent->setPath('extension_root', JPATH_MANIFESTS . '/packages/' . $manifest->packagename);

		// Because packages may not have their own folders we cannot use the standard method of finding an installation manifest
		if (!file_exists($manifestFile))
		{
			// TODO: Fail?
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_PACK_UNINSTALL_MISSINGMANIFEST'), JLog::WARNING, 'jerror');

			return false;

		}

		$xml = simplexml_load_file($manifestFile);

		// If we cannot load the XML file return false
		if (!$xml)
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_PACK_UNINSTALL_LOAD_MANIFEST'), JLog::WARNING, 'jerror');

			return false;
		}

		// Check for a valid XML root tag.
		if ($xml->getName() != 'extension')
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_PACK_UNINSTALL_INVALID_MANIFEST'), JLog::WARNING, 'jerror');

			return false;
		}

		$this->setupScriptfile();
		$this->triggerManifestScript('uninstall');

		$error = false;

		foreach ($manifest->filelist as $extension)
		{
			$tmpInstaller = new JInstaller;
			$id = $this->_getExtensionID($extension->type, $extension->id, $extension->client, $extension->group);
			$client = JApplicationHelper::getClientInfo($extension->client, true);

			if ($id)
			{
				if (!$tmpInstaller->uninstall($extension->type, $id, $client->id))
				{
					$error = true;
					JLog::add(JText::sprintf('JLIB_INSTALLER_ERROR_PACK_UNINSTALL_NOT_PROPER', basename($extension->filename)), JLog::WARNING, 'jerror');
				}
			}
			else
			{
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_PACK_UNINSTALL_UNKNOWN_EXTENSION'), JLog::WARNING, 'jerror');
			}
		}

		// Remove any language files
		$this->parent->removeFiles($xml->languages);

		// Clean up manifest file after we're done if there were no errors
		if (!$error)
		{
			JFile::delete($manifestFile);
			$folder = $this->parent->getPath('extension_root');

			if (JFolder::exists($folder))
			{
				JFolder::delete($folder);
			}
			$row->delete();
		}
		else
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_PACK_UNINSTALL_MANIFEST_NOT_REMOVED'), JLog::WARNING, 'jerror');
		}

		// Return the result up the line
		return $retval;
	}

	/**
	 * Gets the extension id.
	 *
	 * @param   string   $type    The extension type.
	 * @param   string   $id      The name of the extension (the element field).
	 * @param   integer  $client  The application id (0: Joomla CMS site; 1: Joomla CMS administrator).
	 * @param   string   $group   The extension group (mainly for plugins).
	 *
	 * @return  integer
	 *
	 * @since   3.1
	 */
	protected function _getExtensionID($type, $id, $client, $group)
	{
		$db = $this->parent->getDbo();

		$query = $db->getQuery(true);
		$query->select('extension_id');
		$query->from('#__extensions');
		$query->where('type = ' . $db->Quote($type));
		$query->where('element = ' . $db->Quote($id));

		switch ($type)
		{
			case 'plugin':
				// Plugins have a folder but not a client
				$query->where('folder = ' . $db->Quote($group));
				break;

			case 'library':
			case 'package':
			case 'component':
				// Components, packages and libraries don't have a folder or client.
				// Included for completeness.
				break;

			case 'language':
			case 'module':
			case 'template':
				// Languages, modules and templates have a client but not a folder
				$client = JApplicationHelper::getClientInfo($client, true);
				$query->where('client_id = ' . (int) $client->id);
				break;
		}

		$db->setQuery($query);
		$result = $db->loadResult();

		// Note: For templates, libraries and packages their unique name is their key.
		// This means they come out the same way they came in.
		return $result;
	}

	/**
	 * Refreshes the extension table cache
	 *
	 * @return  boolean  Result of operation, true if updated, false on failure
	 *
	 * @since   3.1
	 */
	public function refreshManifestCache()
	{
		// Need to find to find where the XML file is since we don't store this normally
		$manifestPath = JPATH_MANIFESTS . '/packages/' . $this->parent->extension->element . '.xml';

		return $this->doRefreshManifestCache($manifestPath);
	}

	/**
	 * Executes the custom preflight method for an install script
	 *
	 * @param   string  $method  The install method to execute
	 *
	 * @return  mixed  Boolean false if there's a failure, void otherwise
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
				// The preflight method takes the route as a param
				case 'preflight':
					if ($this->parent->manifestClass->$method($this->route, $this) === false)
					{
						// The script failed, rollback changes
						$this->parent->abort(JText::_('JLIB_INSTALLER_ABORT_INSTALL_CUSTOM_INSTALL_FAILURE'));
						return false;
					}
					break;

				// The postflight method takes the route and a results array as params
				case 'postflight':
					if ($this->parent->manifestClass->$method($this->route, $this, $this->results) === false)
					{
						// The script failed, rollback changes
						$this->parent->abort(JText::_('JLIB_INSTALLER_ABORT_INSTALL_CUSTOM_INSTALL_FAILURE'));
						return false;
					}
					break;

				// The install, uninstall, and update methods only pass this object as a param
				case 'install':
				case 'uninstall':
				case 'update':
					if ($this->parent->manifestClass->$method($this) === false)
					{
						// The script failed, rollback changes
						$this->parent->abort(JText::_('JLIB_INSTALLER_ABORT_INSTALL_CUSTOM_INSTALL_FAILURE'));
						return false;
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
}

/**
 * Deprecated class placeholder. You should use JInstallerAdapterPackage instead.
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 * @deprecated  4.0
 * @codeCoverageIgnore
 */
class JInstallerPackage extends JInstallerAdapterPackage
{
}
