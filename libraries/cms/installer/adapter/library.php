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
 * Library installation adapter
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 */
class JInstallerAdapterLibrary extends JInstallerAdapter
{
	/**
	 * Path to the manifest file, used by the uninstall method
	 *
	 * @var    string
	 * @since  3.1
	 */
	protected $manifestFile;

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
			$manifestPath = JPath::clean($this->parent->getPath('manifest'));
			$element = preg_replace('/\.xml/', '', basename($manifestPath));
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
			$this->parent->setPath('source', JPATH_PLATFORM . '/' . $this->element);
		}

		$extension = 'lib_' . $this->element;
		$librarypath = (string) $this->manifest->libraryname;
		$source = $path ? $path : JPATH_PLATFORM . '/' . $librarypath;

		$this->doLoadLanguage($extension, $source);
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

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Manifest Document Setup Section
		 * ---------------------------------------------------------------------------------------------
		 */

		$extension_id = JTable::getInstance('extension')->find(array('element' => $this->element, 'type' => 'library'));
		if ($extension_id)
		{
			// Already installed, can we upgrade?
			if ($this->parent->isOverwrite() || $this->parent->isUpgrade())
			{
				// We can upgrade, so uninstall the old one
				$installer = new JInstaller; // we don't want to compromise this instance!
				$installer->uninstall('library', $extension_id);
			}
			else
			{
				// Abort the install, no upgrade possible
				throw new RuntimeException(JText::_('JLIB_INSTALLER_ABORT_LIB_INSTALL_ALREADY_INSTALLED'));
			}
		}

		// Set the installation path
		$librarypath = (string) $this->manifest->libraryname;

		if (!$librarypath)
		{
			throw new RuntimeException(JText::_('JLIB_INSTALLER_ABORT_LIB_INSTALL_NOFILE'));
		}
		else
		{
			$this->parent->setPath('extension_root', JPATH_PLATFORM . '/' . $librarypath);
		}

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Filesystem Processing Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// If the library directory does not exist, let's create it
		$created = false;

		if (!file_exists($this->parent->getPath('extension_root')))
		{
			if (!$created = JFolder::create($this->parent->getPath('extension_root')))
			{
				throw new RuntimeException(
					JText::sprintf('JLIB_INSTALLER_ABORT_LIB_INSTALL_FAILED_TO_CREATE_DIRECTORY', $this->parent->getPath('extension_root'))
				);
			}
		}

		/*
		 * If we created the library directory and will want to remove it if we
		 * have to roll back the installation, let's add it to the installation
		 * step stack
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

		// Parse optional tags
		$this->parent->parseLanguages($this->manifest->languages);
		$this->parent->parseMedia($this->manifest->media);

		// Extension Registration
		$row = JTable::getInstance('extension');
		$row->name = $this->name;
		$row->type = 'library';
		$row->element = $this->element;

		// There is no folder for libraries
		$row->folder = '';
		$row->enabled = 1;
		$row->protected = 0;
		$row->access = 1;
		$row->client_id = 0;
		$row->params = $this->parent->getParams();

		// Custom data
		$row->custom_data = '';
		$row->manifest_cache = $this->parent->generateManifestCache();

		if (!$row->store())
		{
			// Install failed, roll back changes
			throw new RuntimeException(JText::sprintf('JLIB_INSTALLER_ABORT_LIB_INSTALL_ROLLBACK', $row->getError()));
		}

		/**
		 * ---------------------------------------------------------------------------------------------
		 * Finalization and Cleanup Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// Lastly, we will copy the manifest file to its appropriate place.
		$manifest = array();
		$manifest['src'] = $this->parent->getPath('manifest');
		$manifest['dest'] = JPATH_MANIFESTS . '/libraries/' . basename($this->parent->getPath('manifest'));

		if (!$this->parent->copyFiles(array($manifest), true))
		{
			// Install failed, rollback changes
			throw new RuntimeException(JText::_('JLIB_INSTALLER_ABORT_LIB_INSTALL_COPY_SETUP'));
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
	 * @note    Due to non-standard processing, the manifest is also set in this extended method
	 * @since   3.1
	 */
	protected function setupUninstall($id)
	{
		// Run the common parent methods
		if (parent::setupUninstall($id))
		{
			$this->manifestFile = JPATH_MANIFESTS . '/libraries/' . $this->element . '.xml';

			// Because files may not have their own folders we cannot use the standard method of finding an installation manifest
			if (file_exists($this->manifestFile))
			{
				$manifest = new JInstallerManifestLibrary($this->manifestFile);

				// Set the library root path
				$this->parent->setPath('extension_root', JPATH_PLATFORM . '/' . $manifest->libraryname);

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
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_FILE_UNINSTALL_INVALID_NOTFOUND_MANIFEST'), JLog::WARNING, 'jerror');

				// Delete the row because its broken
				$this->extension->delete();

				return false;
			}
		}

		return true;
	}

	/**
	 * Custom uninstall method
	 *
	 * @param   string  $id  The id of the library to uninstall.
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

		$this->parent->removeFiles($this->manifest->files, -1);
		JFile::delete($this->manifestFile);

		// TODO: Change this so it walked up the path backwards so we clobber multiple empties
		// If the folder is empty, let's delete it
		if (JFolder::exists($this->parent->getPath('extension_root')))
		{
			if (is_dir($this->parent->getPath('extension_root')))
			{
				$files = JFolder::files($this->parent->getPath('extension_root'));

				if (!count($files))
				{
					JFolder::delete($this->parent->getPath('extension_root'));
				}
			}
		}

		$this->parent->removeFiles($this->manifest->media);
		$this->parent->removeFiles($this->manifest->languages);

		$this->extension->delete($this->extension->extension_id);
		unset($this->extension);

		return $retval;
	}

	/**
	 * Custom discover method
	 *
	 * @return  array  JExtension  list of extensions available
	 *
	 * @since   3.1
	 */
	public function discover()
	{
		$results = array();
		$file_list = JFolder::files(JPATH_MANIFESTS . '/libraries', '\.xml$');

		foreach ($file_list as $file)
		{
			$manifest_details = JInstaller::parseXMLInstallFile(JPATH_MANIFESTS . '/libraries/' . $file);
			$file = JFile::stripExt($file);
			$extension = JTable::getInstance('extension');
			$extension->type = 'library';
			$extension->client_id = 0;
			$extension->element = $file;
			$extension->name = $file;
			$extension->state = -1;
			$extension->manifest_cache = json_encode($manifest_details);
			$results[] = $extension;
		}
		return $results;
	}

	/**
	 * Custom discover_install method
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	public function discover_install()
	{
		/* Libraries are a strange beast; they are actually references to files
		 * There are two parts to a library which are disjunct in their locations
		 * 1) The manifest file (stored in /JPATH_MANIFESTS/libraries)
		 * 2) The actual files (stored in /JPATH_PLATFORM/libraryname)
		 * Thus installation of a library is the process of dumping files
		 * in two different places. As such it is impossible to perform
		 * any operation beyond mere registration of a library under the presumption
		 * that the files exist in the appropriate location so that come uninstall
		 * time they can be adequately removed.
		 */

		$manifestPath = JPATH_MANIFESTS . '/libraries/' . $this->parent->extension->element . '.xml';
		$this->parent->manifest = $this->parent->isManifest($manifestPath);
		$this->parent->setPath('manifest', $manifestPath);
		$manifest_details = JInstaller::parseXMLInstallFile($this->parent->getPath('manifest'));
		$this->parent->extension->manifest_cache = json_encode($manifest_details);
		$this->parent->extension->state = 0;
		$this->parent->extension->name = $manifest_details['name'];
		$this->parent->extension->enabled = 1;
		$this->parent->extension->params = $this->parent->getParams();

		if ($this->parent->extension->store())
		{
			return true;
		}
		else
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_LIB_DISCOVER_STORE_DETAILS'), JLog::WARNING, 'jerror');

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
		// Need to find to find where the XML file is since we don't store this normally
		$manifestPath = JPATH_MANIFESTS . '/libraries/' . $this->parent->extension->element . '.xml';

		return $this->doRefreshManifestCache($manifestPath);
	}
}

/**
 * Deprecated class placeholder. You should use JInstallerAdapterLibrary instead.
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 * @deprecated  4.0
 * @codeCoverageIgnore
 */
class JInstallerLibrary extends JInstallerAdapterLibrary
{
}
