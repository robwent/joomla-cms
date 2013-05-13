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
	 * The install client ID
	 *
	 * @var    integer
	 * @since  3.1
	 */
	protected $clientId;

	/**
	 * Method to check if the extension is already present in the database
	 *
	 * @return  void
	 *
	 * @since   3.1
	 * @throws  RuntimeException
	 */
	protected function checkExistingExtension()
	{
		try
		{
			$this->currentExtensionId = $this->extension->find(array('element' => $this->element, 'type' => $this->type, 'client_id' => $this->clientId));
		}
		catch (RuntimeException $e)
		{
			// Install failed, roll back changes
			throw new RuntimeException(
				JText::sprintf(
					'JLIB_INSTALLER_ABORT_TPL_INSTALL_ROLLBACK',
					JText::_('JLIB_INSTALLER_' . $this->route),
					$e->getMessage()
				)
			);
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
				'client_id' => $this->clientId
			)
		);

		if ($uid)
		{
			$update->delete($uid);
		}

		// Lastly, we will copy the manifest file to its appropriate place.
		if (!$this->parent->copyManifest(-1))
		{
			// Install failed, rollback changes
			throw new RuntimeException(JText::_('JLIB_INSTALLER_ABORT_TPL_INSTALL_COPY_SETUP'));
		}
	}

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
			$this->db = $this->parent->getDbo();
			$query = $this->db->getQuery(true);
			$query->select('COUNT(*)')
				->from($this->db->quoteName('#__template_styles'))
				->where($this->db->quoteName('home') . ' = ' . $this->db->quote('1'))
				->where($this->db->quoteName('template') . ' = ' . $this->db->quote($this->element));
			$this->db->setQuery($query);

			if ($this->db->loadResult() != 0)
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
			. ' WHERE s.template = ' . $this->db->quote(strtolower($this->element)) . ' AND s.client_id = ' . $this->extension->client_id . ')';

		$this->db->setQuery($query);
		$this->db->execute();

		$query = $this->db->getQuery(true);
		$query->delete($this->db->quoteName('#__template_styles'))
			->where($this->db->quoteName('client_id') . ' = ' . $this->extension->client_id)
			->where($this->db->quoteName('template') . ' = ' . $this->db->quote($this->element));

		$this->db->setQuery($query);
		$this->db->execute();

		$this->extension->delete($this->extension->extension_id);

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
			$extension->folder = '';
			$extension->name = $template;
			$extension->state = -1;
			$extension->manifest_cache = json_encode($manifest_details);
			$extension->params = '{}';
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
			$extension->folder = '';
			$extension->name = $template;
			$extension->state = -1;
			$extension->manifest_cache = json_encode($manifest_details);
			$extension->params = '{}';
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
			$this->db = $this->parent->getDbo();
			$query = $this->db->getQuery(true);
			$query->insert($this->db->quoteName('#__template_styles'));
			$lang = JFactory::getLanguage();
			$debug = $lang->setDebug(false);
			$columns = array($this->db->quoteName('template'),
				$this->db->quoteName('client_id'),
				$this->db->quoteName('home'),
				$this->db->quoteName('title'),
				$this->db->quoteName('params')
			);
			$query->columns($columns)
				->values(
					$this->db->quote($this->element)
					. ',' . $this->db->quote($this->parent->extension->client_id)
					. ',' . $this->db->quote(0)
					. ',' . $this->db->quote(JText::sprintf('JLIB_INSTALLER_DEFAULT_STYLE', $this->parent->extension->name))
					. ',' . $this->db->quote($this->parent->extension->params)
			);
			$lang->setDebug($debug);
			$this->db->setQuery($query);
			$this->db->execute();

			return $this->parent->extension->extension_id;
		}
		else
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_TPL_DISCOVER_STORE_DETAILS'), JLog::WARNING, 'jerror');

			return false;
		}
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
		$this->parent->parseMedia($this->manifest->media);
		$this->parent->parseLanguages($this->manifest->languages, $this->clientId);
	}

	/**
	 * Overloaded method to parse queries for template installations
	 *
	 * @return  void
	 *
	 * @since   3.1
	 * @throws  RuntimeException
	 */
	protected function parseQueries()
	{
		if ($this->route == 'install')
		{
			$lang  = JFactory::getLanguage();
			$debug = $lang->setDebug(false);

			$columns = array($this->db->quoteName('template'),
				$this->db->quoteName('client_id'),
				$this->db->quoteName('home'),
				$this->db->quoteName('title'),
				$this->db->quoteName('params')
			);

			$values = array(
				$this->db->Quote($this->extension->element), $clientId, $this->db->Quote(0),
				$this->db->Quote(JText::sprintf('JLIB_INSTALLER_DEFAULT_STYLE', JText::_($this->name))),
				$this->db->Quote($this->extension->params) );

			$lang->setDebug($debug);

			// Insert record in #__template_styles
			$query = $this->db->getQuery(true);
			$query->insert($this->db->quoteName('#__template_styles'))
				->columns($columns)
				->values(implode(',', $values));

			$this->db->setQuery($query);

			// There is a chance this could fail but we don't care...
			$this->db->execute();
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
			$this->clientId = $client->id;
		}
		else
		{
			// No client attribute was found so we assume the site as the client
			$basePath = JPATH_SITE;
			$this->clientId = 0;
		}

		// Set the template root path
		if (!empty($this->element))
		{
			$this->parent->setPath('extension_root', $basePath . '/templates/' . $this->element);
		}
		else
		{
			throw new RuntimeException(
				JText::sprintf(
					'JLIB_INSTALLER_ABORT_MOD_INSTALL_NOFILE',
					JText::_('JLIB_INSTALLER_' . $this->route)
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
		// Was there a template already installed with the same name?
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

			// Load the entry and update the manifest_cache
			$this->extension->load($this->currentExtensionId);
		}
		else
		{
			$this->extension->type = 'template';
			$this->extension->element = $this->element;

			// There is no folder for templates
			$this->extension->folder = '';
			$this->extension->enabled = 1;
			$this->extension->protected = 0;
			$this->extension->access = 1;
			$this->extension->client_id = $this->clientId;
			$this->extension->params = $this->parent->getParams();

			// Custom data
			$this->extension->custom_data = '';
		}

		// Name might change in an update
		$this->extension->name = $this->name;
		$this->extension->manifest_cache = $this->parent->generateManifestCache();

		if (!$this->extension->store())
		{
			// Install failed, roll back changes
			throw new RuntimeException(
				JText::sprintf(
					'JLIB_INSTALLER_ABORT_TPL_INSTALL_ROLLBACK',
					$this->extension->getError()
				)
			);
		}
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
