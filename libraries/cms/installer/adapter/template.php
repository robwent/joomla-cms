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
 * Template installation adapter
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 */
class JInstallerAdapterTemplate extends JInstallerAdapter
{
	/**
	 * Load language from a path
	 *
	 * @param   string  $path  The path of the language.
	 *
	 * @return  JInstallerTemplate
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
				($this->parent->extension->client_id ? JPATH_ADMINISTRATOR : JPATH_SITE) . '/templates/' . $this->parent->extension->element
			);
		}

		$client = (string) $this->manifest->attributes()->client;

		// Load administrator language if not set.
		if (!$client)
		{
			$client = 'ADMINISTRATOR';
		}

		$extension = 'tpl_' . strtolower($this->name);
		$source = $path ? $path : ($this->parent->extension->client_id ? JPATH_ADMINISTRATOR : JPATH_SITE) . '/templates/' . $this->element;
		$this->doLoadLanguage($extension, $source, constant('JPATH_' . strtoupper($client)));
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

		$lang = JFactory::getLanguage();

		// Get the client application target
		$cname = (string) $this->manifest->attributes()->client;
		if ($cname)
		{
			// Attempt to map the client to a base path
			$client = JApplicationHelper::getClientInfo($cname, true);

			if ($client === false)
			{
				throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_TPL_INSTALL_UNKNOWN_CLIENT', $cname));
			}
			$basePath = $client->path;
			$clientId = $client->id;
		}
		else
		{
			// No client attribute was found so we assume the site as the client
			$cname = 'site';
			$basePath = JPATH_SITE;
			$clientId = 0;
		}

		// Check to see if a template by the same name is already installed.
		try
		{
			$id = JTable::getInstance('extension')->find(array('type' => 'template', 'element' => $this->element, 'client_id'=>$clientId));
		}
		catch (RuntimeException $e)
		{
			// Install failed, roll back changes
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_TPL_INSTALL_ROLLBACK'), $e->getMessage());
		}

		// Set the template root path
		$this->parent->setPath('extension_root', $basePath . '/templates/' . $this->element);

		// If it's on the fs...
		if (file_exists($this->parent->getPath('extension_root')) && (!$this->parent->isOverwrite() || $this->parent->isUpgrade()))
		{
			$updateElement = $this->manifest->update;

			// Upgrade manually set or update tag detected
			if ($this->parent->isUpgrade() || $updateElement)
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
				// Overwrite is not set
				// If we didn't have overwrite set, find an update function or find an update tag so let's call it safe
				throw new RuntimeException(
					JText::sprintf(
						'JLIB_INSTALLER_ABORT_TPL_INSTALL_ANOTHER_TEMPLATE_USING_DIRECTORY', JText::_('JLIB_INSTALLER_' . $this->route),
						$this->parent->getPath('extension_root')
					)
				);
			}
		}

		/*
		 * If the template directory already exists, then we will assume that the template is already
		 * installed or another template is using that directory.
		 */
		if (file_exists($this->parent->getPath('extension_root')) && !$this->parent->isOverwrite())
		{
			throw new RuntimeException(
				JText::sprintf('JLIB_INSTALLER_ABORT_TPL_INSTALL_ANOTHER_TEMPLATE_USING_DIRECTORY', $this->parent->getPath('extension_root'))
			);
		}

		// If the template directory does not exist, let's create it
		$created = false;

		if (!file_exists($this->parent->getPath('extension_root')))
		{
			if (!$created = JFolder::create($this->parent->getPath('extension_root')))
			{
				throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_TPL_INSTALL_FAILED_CREATE_DIRECTORY', $this->parent->getPath('extension_root')));
			}
		}

		// If we created the template directory and will want to remove it if we have to roll back
		// the installation, let's add it to the installation step stack
		if ($created)
		{
			$this->parent->pushStep(array('type' => 'folder', 'path' => $this->parent->getPath('extension_root')));
		}

		// Copy all the necessary files
		if ($this->parent->parseFiles($this->manifest->files, -1) === false)
		{
			// TODO: throw exception
			return false;
		}

		if ($this->parent->parseFiles($this->manifest->images, -1) === false)
		{
			// TODO: throw exception
			return false;
		}

		if ($this->parent->parseFiles($this->manifest->css, -1) === false)
		{
			// TODO: throw exception
			return false;
		}

		// Parse optional tags
		$this->parent->parseMedia($this->manifest->media);
		$this->parent->parseLanguages($this->manifest->languages, $clientId);

		// Lastly, we will copy the manifest file to its appropriate place.
		if (!$this->parent->copyManifest(-1))
		{
			// Install failed, rollback changes
			throw new RuntimeException(JText::_('JLIB_INSTALLER_ABORT_TPL_INSTALL_COPY_SETUP'));
		}

		/**
		 * ---------------------------------------------------------------------------------------------
		 * Extension Registration
		 * ---------------------------------------------------------------------------------------------
		 */

		$row = JTable::getInstance('extension');

		if ($this->route == 'update' && $id)
		{
			$row->load($id);
		}
		else
		{
			$row->type = 'template';
			$row->element = $this->element;

			// There is no folder for templates
			$row->folder = '';
			$row->enabled = 1;
			$row->protected = 0;
			$row->access = 1;
			$row->client_id = $clientId;
			$row->params = $this->parent->getParams();

			// Custom data
			$row->custom_data = '';
		}

		// Name might change in an update
		$row->name = $this->name;
		$row->manifest_cache = $this->parent->generateManifestCache();

		if (!$row->store())
		{
			// Install failed, roll back changes
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_TPL_INSTALL_ROLLBACK', $db->stderr(true)));
		}

		if ($this->route == 'install')
		{
			$debug = $lang->setDebug(false);

			$columns = array($db->quoteName('template'),
				$db->quoteName('client_id'),
				$db->quoteName('home'),
				$db->quoteName('title'),
				$db->quoteName('params')
			);

			$values = array(
				$db->Quote($row->element), $clientId, $db->Quote(0),
				$db->Quote(JText::sprintf('JLIB_INSTALLER_DEFAULT_STYLE', JText::_($this->name))),
				$db->Quote($row->params) );

			$lang->setDebug($debug);

			// Insert record in #__template_styles
			$query = $db->getQuery(true);
			$query->insert($db->quoteName('#__template_styles'))
				->columns($columns)
				->values(implode(',', $values));

			$db->setQuery($query);

			// There is a chance this could fail but we don't care...
			$db->execute();
		}

		return $row->extension_id;
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
			// For a template the id will be the template name which represents the subfolder of the templates folder that the template resides in.
			if (!$this->element)
			{
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_TPL_UNINSTALL_TEMPLATE_ID_EMPTY'), JLog::WARNING, 'jerror');

				return false;
			}

			// Deny remove default template
			$db = $this->parent->getDbo();
			$query = $db->getQuery(true);
			$query->select('COUNT(*)')
				->from($db->quoteName('#__template_styles'))
				->where($db->quoteName('home') . ' = ' . $db->quote('1'))
				->where($db->quoteName('template') . ' = ' . $db->quote($this->element));
			$db->setQuery($query);

			if ($db->loadResult() != 0)
			{
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_TPL_UNINSTALL_TEMPLATE_DEFAULT'), JLog::WARNING, 'jerror');

				return false;
			}

			// Get the template root path
			$client = JApplicationHelper::getClientInfo($this->extension->client_id);

			if (!$client)
			{
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_TPL_UNINSTALL_INVALID_CLIENT'), JLog::WARNING, 'jerror');

				return false;
			}

			$this->parent->setPath('extension_root', $client->path . '/templates/' . strtolower($this->element));
			$this->parent->setPath('source', $this->parent->getPath('extension_root'));
		}

		return true;
	}

	/**
	 * Custom uninstall method
	 *
	 * @param   integer  $id  The extension ID
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	public function uninstall($id)
	{
		// Prepare the uninstaller for action
		$this->setupUninstall((int) $id);

		// We do findManifest to avoid problem when uninstalling a list of extensions: getManifest cache its manifest file
		$this->parent->findManifest();
		$manifest = $this->parent->getManifest();

		if (!($manifest instanceof SimpleXMLElement))
		{
			// Kill the extension entry
			$this->extension->delete($this->extension->extension_id);
			unset($this->extension);

			// Make sure we delete the folders
			JFolder::delete($this->parent->getPath('extension_root'));
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_TPL_UNINSTALL_INVALID_NOTFOUND_MANIFEST'), JLog::WARNING, 'jerror');

			return false;
		}

		// Remove files
		$this->parent->removeFiles($manifest->media);
		$this->parent->removeFiles($manifest->languages, $this->extension->client_id);

		// Delete the template directory
		if (JFolder::exists($this->parent->getPath('extension_root')))
		{
			$retval = JFolder::delete($this->parent->getPath('extension_root'));
		}
		else
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_TPL_UNINSTALL_TEMPLATE_DIRECTORY'), JLog::WARNING, 'jerror');
			$retval = false;
		}

		// Set menu that assigned to the template back to default template
		$query = 'UPDATE #__menu'
			. ' SET template_style_id = 0'
			. ' WHERE template_style_id in ('
			. '	SELECT s.id FROM #__template_styles s'
			. ' WHERE s.template = ' . $db->Quote(strtolower($this->element)) . ' AND s.client_id = ' . $this->extension->client_id . ')';

		$db->setQuery($query);
		$db->execute();

		$query = $db->getQuery(true);
		$query->delete($db->quoteName('#__template_styles'))
			->where($db->quoteName('client_id') . ' = ' . $this->extension->client_id)
			->where($db->quoteName('template') . ' = ' . $db->quote($this->element));

		$db->setQuery($query);
		$db->execute();

		$this->extension->delete($this->extension->extension_id);
		unset($this->extension);

		return $retval;
	}

	/**
	 * Discover existing but uninstalled templates
	 *
	 * @return  array  JExtensionTable list
	 */
	public function discover()
	{
		$results = array();
		$site_list = JFolder::folders(JPATH_SITE . '/templates');
		$admin_list = JFolder::folders(JPATH_ADMINISTRATOR . '/templates');
		$site_info = JApplicationHelper::getClientInfo('site', true);
		$admin_info = JApplicationHelper::getClientInfo('administrator', true);

		foreach ($site_list as $template)
		{
			if ($template == 'system')
			{
				// Ignore special system template
				continue;
			}
			$manifest_details = JInstaller::parseXMLInstallFile(JPATH_SITE . "/templates/$template/templateDetails.xml");
			$extension = JTable::getInstance('extension');
			$extension->type = 'template';
			$extension->client_id = $site_info->id;
			$extension->element = $template;
			$extension->name = $template;
			$extension->state = -1;
			$extension->manifest_cache = json_encode($manifest_details);
			$results[] = $extension;
		}

		foreach ($admin_list as $template)
		{
			if ($template == 'system')
			{
				// Ignore special system template
				continue;
			}

			$manifest_details = JInstaller::parseXMLInstallFile(JPATH_ADMINISTRATOR . "/templates/$template/templateDetails.xml");
			$extension = JTable::getInstance('extension');
			$extension->type = 'template';
			$extension->client_id = $admin_info->id;
			$extension->element = $template;
			$extension->name = $template;
			$extension->state = -1;
			$extension->manifest_cache = json_encode($manifest_details);
			$results[] = $extension;
		}

		return $results;
	}

	/**
	 * Discover_install
	 * Perform an install for a discovered extension
	 *
	 * @return boolean
	 *
	 * @since 3.1
	 */
	public function discover_install()
	{
		$this->element = $this->parent->extension->element;

		// Templates are one of the easiest
		// If its not in the extensions table we just add it
		$client = JApplicationHelper::getClientInfo($this->parent->extension->client_id);
		$manifestPath = $client->path . '/templates/' . $this->element . '/templateDetails.xml';
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
		$this->parent->extension->manifest_cache = json_encode($manifest_details);
		$this->parent->extension->state = 0;
		$this->parent->extension->name = $manifest_details['name'];
		$this->parent->extension->enabled = 1;

		$this->parent->extension->params = $this->parent->getParams();

		if ($this->parent->extension->store())
		{
			// Insert record in #__template_styles
			$db = $this->parent->getDbo();
			$query = $db->getQuery(true);
			$query->insert($db->quoteName('#__template_styles'));
			$lang = JFactory::getLanguage();
			$debug = $lang->setDebug(false);
			$columns = array($db->quoteName('template'),
				$db->quoteName('client_id'),
				$db->quoteName('home'),
				$db->quoteName('title'),
				$db->quoteName('params')
			);
			$query->columns($columns);
			$query->values(
				$db->Quote($this->element)
				. ',' . $db->Quote($this->parent->extension->client_id)
				. ',' . $db->Quote(0)
				. ',' . $db->Quote(JText::sprintf('JLIB_INSTALLER_DEFAULT_STYLE', $this->parent->extension->name))
				. ',' . $db->Quote($this->parent->extension->params)
			);
			$lang->setDebug($debug);
			$db->setQuery($query);
			$db->execute();

			return $this->parent->extension->extension_id;
		}
		else
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_TPL_DISCOVER_STORE_DETAILS'), JLog::WARNING, 'jerror');

			return false;
		}
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
		// Need to find to find where the XML file is since we don't store this normally.
		$client = JApplicationHelper::getClientInfo($this->parent->extension->client_id);
		$manifestPath = $client->path . '/templates/' . $this->parent->extension->element . '/templateDetails.xml';

		return $this->doRefreshManifestCache($manifestPath);
	}
}

/**
 * Deprecated class placeholder. You should use JInstallerAdapterTemplate instead.
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 * @deprecated  4.0
 * @codeCoverageIgnore
 */
class JInstallerTemplate extends JInstallerAdapterTemplate
{
}
