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
	 * Instance of JInstallerManifestPackage
	 *
	 * @var    JInstallerManifestPackage
	 * @since  3.1
	 */
	protected $manifestData;

	/**
	 * Path to the manifest file, used by the uninstall method
	 *
	 * @var    string
	 * @since  3.1
	 */
	protected $manifestFile;

	/**
	 * The results of each installed extensions
	 *
	 * @var    array
	 * @since  3.1
	 */
	protected $results = array();

	/**
	 * Method to check if the extension is present in the filesystem, flags the route as update if so
	 *
	 * @return  void
	 *
	 * @since   3.1
	 * @throws  RuntimeException
	 */
	protected function checkExtensionInFilesystem()
	{
		// If the package manifest already exists, then we will assume that the package is already installed.
		if (file_exists(JPATH_MANIFESTS . '/packages/' . basename($this->parent->getPath('manifest'))))
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

				if ($this->currentExtensionId)
				{
					// If there is a matching extension mark this as an update
					$this->setRoute('update');
				}
			}
			elseif (!$this->parent->isOverwrite())
			{
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
	}

	/**
	 * Method to copy the extension's base files from the <files> tag(s) and the manifest file
	 *
	 * @return  void
	 *
	 * @since   3.1
	 * @throws  RuntimeException
	 */
	protected function copyBaseFiles()
	{
		$folder = (string) $this->manifest->files->attributes()->folder;
		if ($folder)
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
			foreach ($this->manifest->files->children() as $child)
			{
				$file = $source . '/' . (string) $child;

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
					throw new RuntimeException(
						JText::sprintf(
							'JLIB_INSTALLER_ABORT_PACK_INSTALL_ERROR_EXTENSION', JText::_('JLIB_INSTALLER_' . strtoupper($this->route)),
							basename($file)
						)
					);
				}
				else
				{
					$this->results[] = array(
						'name' => (string) $tmpInstaller->manifest->name,
						'result' => $installResult
					);
				}
			}
		}
		else
		{
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_PACK_INSTALL_NO_FILES', JText::_('JLIB_INSTALLER_' . strtoupper($this->route))));
		}
	}

	/**
	 * Method to finalise the installation processing
	 *
	 * @return  void
	 *
	 * @since   3.1
	 * @throws  RuntimeException
	 */
	protected function finaliseInstall()
	{
		// Clobber any possible pending updates
		$update = JTable::getInstance('update');
		$uid = $update->find(
			array(
				'element' => $this->element,
				'type' => $this->type,
			)
		);

		if ($uid)
		{
			$update->delete($uid);
		}

		// Lastly, we will copy the manifest file to its appropriate place.
		$manifest = array();
		$manifest['src'] = $this->parent->getPath('manifest');
		$manifest['dest'] = JPATH_MANIFESTS . '/packages/' . basename($this->parent->getPath('manifest'));

		if (!$this->parent->copyFiles(array($manifest), true))
		{
			// Install failed, rollback changes
			throw new RuntimeException(
				JText::sprintf('JLIB_INSTALLER_ABORT_PACK_INSTALL_COPY_SETUP', JText::_('JLIB_INSTALLER_ABORT_PACK_INSTALL_NO_FILES'))
			);
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
					throw new RuntimeException(JText::_('JLIB_INSTALLER_ABORT_PACKAGE_INSTALL_MANIFEST'));
				}
			}
		}
	}

	/**
	 * Get the filtered extension element from the manifest
	 *
	 * @param   string  $element  Optional element name to be converted
	 *
	 * @return  string  The filtered element
	 *
	 * @since   3.1
	 */
	public function getElement($element = null)
	{
		if (!$element)
		{
			// Ensure the element is a string
			$element = (string) $this->manifest->packagename;

			// Filter the name for illegal characters
			$element = 'pkg_' . JFilterInput::getInstance()->clean($element, 'cmd');
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
	public function loadLanguage($path)
	{
		$this->doLoadLanguage($this->element, $path);
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
	 * @note    Due to non-standard processing, the manifest is also set in this extended method
	 * @since   3.1
	 */
	protected function setupUninstall($id)
	{
		// Run the common parent methods
		if (parent::setupUninstall($id))
		{
			$this->manifestFile = JPATH_MANIFESTS . '/packages/' . $this->extension->element . '.xml';

			// Because files may not have their own folders we cannot use the standard method of finding an installation manifest
			if (file_exists($this->manifestFile))
			{
				$this->manifestData = new JInstallerManifestPackage($this->manifestFile);

				// Set the package root path
				$this->parent->setPath('extension_root', JPATH_MANIFESTS . '/packages/' . $this->manifestData->packagename);

				$xml = simplexml_load_file($this->manifestFile);

				// If we cannot load the XML file return null
				if (!$xml)
				{
					JLog::add(JText::_('JLIB_INSTALLER_ERROR_LIB_UNINSTALL_LOAD_MANIFEST'), JLog::WARNING, 'jerror');

					return false;
				}

				// Check for a valid XML root tag.
				if ($xml->getName() != 'extension')
				{
					JLog::add(JText::_('JLIB_INSTALLER_ERROR_LIB_UNINSTALL_INVALID_MANIFEST'), JLog::WARNING, 'jerror');

					return false;
				}

				$this->manifest = $xml;
			}
			else
			{
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_PACK_UNINSTALL_MISSINGMANIFEST'), JLog::WARNING, 'jerror');

				return false;
			}
		}

		return true;
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
		$retval = true;

		// Prepare the uninstaller for action
		$this->setupUninstall((int) $id);

		$this->setupScriptfile();
		$this->triggerManifestScript('uninstall');

		$error = false;

		foreach ($this->manifestData->filelist as $extension)
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
		$this->parent->removeFiles($this->manifest->languages);

		// Clean up manifest file after we're done if there were no errors
		if (!$error)
		{
			JFile::delete($this->manifestFile);
			$folder = $this->parent->getPath('extension_root');

			if (JFolder::exists($folder))
			{
				JFolder::delete($folder);
			}
			$this->extension->delete();
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
	 * @param   string  $type       The extension type.
	 * @param   string  $element    The name of the extension (the element field).
	 * @param   string  $client_id  The application id (0: Joomla CMS site; 1: Joomla CMS administrator).
	 * @param   string  $group      The extension group (mainly for plugins).
	 *
	 * @return  integer
	 *
	 * @since   3.1
	 */
	protected function _getExtensionID($type, $element, $client_id = null, $group = null)
	{
		$client_id = JApplicationHelper::getClientInfo($client_id, true)->id;

		// Be default search by matching all the given fields.
		$search = array('type' => (string) $type, 'element' => (string) $element, 'client_id' => $client_id);
		if ((string) $group) $search['folder'] = (string) $group;

		switch ((string) $type)
		{
			case 'plugin':
				// Plugins have a folder but not a client
				unset($search['client_id']);
				break;

			case 'language':
			case 'module':
			case 'template':
				// Languages, modules and templates have a client but not a folder
				unset($search['folder']);
				break;

			case 'library':
			case 'package':
			case 'component':
				// Components, packages and libraries don't have a folder or client.
				unset($search['client_id'], $search['folder']);
				break;
		}

		$extension_id = JTable::getInstance('extension')->find($search);

		return $extension_id;
	}

	/**
	 * Method to parse optional tags in the manifest
	 *
	 * @return  void
	 *
	 * @since   3.1
	 */
	protected function parseOptionalTags()
	{
		// Parse optional tags
		$this->parent->parseLanguages($this->manifest->languages);
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
	 * Method to do any prechecks and setup the install paths for the extension
	 *
	 * @return  void
	 *
	 * @since   3.1
	 * @throws  RuntimeException
	 */
	protected function setupInstallPaths()
	{
		$packagepath = (string) $this->manifest->packagename;

		if (!empty($packagepath))
		{
			$this->parent->setPath('extension_root', JPATH_MANIFESTS . '/packages/' . $packagepath);
		}
		else
		{
			throw new RuntimeException(
				JText::sprintf(
					'JLIB_INSTALLER_ABORT_PACK_INSTALL_NO_PACK',
					JText::_('JLIB_INSTALLER_' . strtoupper($this->route))
				)
			);
		}
	}

	/**
	 * Method to store the extension to the database
	 *
	 * @return  void
	 *
	 * @since   3.1
	 * @throws  RuntimeException
	 */
	protected function storeExtension()
	{
		if ($this->currentExtensionId)
		{
			if (!$this->parent->isOverwrite())
			{
				// Install failed, roll back changes
				throw new RuntimeException(
					JText::sprintf(
						'JLIB_INSTALLER_ABORT_PLG_INSTALL_ALLREADY_EXISTS',
						JText::_('JLIB_INSTALLER_' . $this->route),
						$this->name
					)
				);
			}

			$this->extension->load($this->currentExtensionId);

			$this->extension->name = $this->name;
		}
		else
		{
			$this->extension->name = $this->name;
			$this->extension->type = 'package';
			$this->extension->element = $this->element;

			// There is no folder for modules
			$this->extension->folder = '';
			$this->extension->enabled = 1;
			$this->extension->protected = 0;
			$this->extension->access = 1;
			$this->extension->client_id = 0;

			// Custom data
			$this->extension->custom_data = '';
			$this->extension->params = $this->parent->getParams();
		}

		// Update the manifest cache for the entry
		$this->extension->manifest_cache = $this->parent->generateManifestCache();

		if (!$this->extension->store())
		{
			// Install failed, roll back changes
			throw new RuntimeException(
				JText::sprintf(
					'JLIB_INSTALLER_ABORT_PACK_INSTALL_ROLLBACK',
					$this->extension->getError()
				)
			);
		}

		// Since we have created a package item, we add it to the installation step stack
		// so that if we have to rollback the changes we can undo it.
		$this->parent->pushStep(array('type' => 'extension', 'id' => $this->extension->extension_id));
	}

	/**
	 * Executes a custom install script method
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
					$this->parent->manifestClass->$method($this->route, $this, $this->results);
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
