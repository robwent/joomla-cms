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
 * File installation adapter
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 */
class JInstallerAdapterFile extends JInstallerAdapter
{
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
		$extension = 'files_' . strtolower(str_replace('files_', '', $this->name));

		$this->doLoadLanguage($extension, $path);
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

		// Set element
		$manifestPath = JPath::clean($this->parent->getPath('manifest'));
		$element = preg_replace('/\.xml/', '', basename($manifestPath));
		$this->element = $element;

		// Check if the extension by the same name is already installed
		if ($this->extensionExists($element, 'file'))
		{
			// Package with same name already exists
			if (!$this->parent->isOverwrite())
			{
				// We're not overwriting so abort
				$this->parent->abort(JText::_('JLIB_INSTALLER_ABORT_FILE_SAME_NAME'));

				return false;
			}
			else
			{
				// Swap to the update route
				$this->route = 'update';
			}
		}
		// Set the file root path
		if ($this->name == 'files_joomla')
		{
			// If we are updating the Joomla core, set the root path to the root of Joomla
			$this->parent->setPath('extension_root', JPATH_ROOT);
		}
		else
		{
			$this->parent->setPath('extension_root', JPATH_MANIFESTS . '/files/' . $this->element);
		}

		/**
		 * ---------------------------------------------------------------------------------------------
		 * Installer Trigger Loading
		 * ---------------------------------------------------------------------------------------------
		 */

		$this->setupScriptfile();
		$this->triggerManifestScript('preflight');

		// Populate File and Folder List to copy
		$this->populateFilesAndFolderList();

		/*
		 * ---------------------------------------------------------------------------------------------
		 * Filesystem Processing Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// Now that we have folder list, lets start creating them
		foreach ($this->folderList as $folder)
		{
			if (!JFolder::exists($folder))
			{
				if (!$created = JFolder::create($folder))
				{
					JLog::add(JText::sprintf('JLIB_INSTALLER_ABORT_FILE_INSTALL_FAIL_SOURCE_DIRECTORY', $folder), JLog::WARNING, 'jerror');

					// If installation fails, rollback
					$this->parent->abort();

					return false;
				}

				// Since we created a directory and will want to remove it if we have to roll back.
				// The installation due to some errors, let's add it to the installation step stack.
				if ($created)
				{
					$this->parent->pushStep(array('type' => 'folder', 'path' => $folder));
				}
			}

		}

		// Now that we have file list, let's start copying them
		$this->parent->copyFiles($this->fileList);

		// Parse optional tags
		$this->parent->parseLanguages($this->manifest->languages);

		/**
		 * ---------------------------------------------------------------------------------------------
		 * Finalization and Cleanup Section
		 * ---------------------------------------------------------------------------------------------
		 */

		// Get a database connector object
		$db = $this->parent->getDbo();

		/*
		 * Check to see if a file extension by the same name is already installed
		 * If it is, then update the table because if the files aren't there
		 * we can assume that it was (badly) uninstalled
		 * If it isn't, add an entry to extensions
		 */
		$query = $db->getQuery(true);
		$query->select($query->qn('extension_id'))
			->from($query->qn('#__extensions'))
			->where($query->qn('type') . ' = ' . $query->q('file'))
			->where($query->qn('element') . ' = ' . $query->q($element));
		$db->setQuery($query);

		try
		{
			$db->execute();
		}
		catch (RuntimeException $e)
		{
			// Install failed, roll back changes
			$this->parent->abort(
				JText::sprintf('JLIB_INSTALLER_ABORT_FILE_ROLLBACK', JText::_('JLIB_INSTALLER_' . $this->route), $db->stderr(true))
			);

			return false;
		}
		$id = $db->loadResult();
		$row = JTable::getInstance('extension');

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
				$this->parent->abort(
					JText::sprintf('JLIB_INSTALLER_ABORT_FILE_ROLLBACK', JText::_('JLIB_INSTALLER_' . $this->route), $db->stderr(true))
				);

				return false;
			}
		}
		else
		{
			// Add an entry to the extension table with a whole heap of defaults
			$row->name = $this->name;
			$row->type = 'file';
			$row->element = $this->element;

			// There is no folder for files so leave it blank
			$row->folder = '';
			$row->enabled = 1;
			$row->protected = 0;
			$row->access = 0;
			$row->client_id = 0;
			$row->params = '';
			$row->system_data = '';
			$row->manifest_cache = $this->parent->generateManifestCache();

			if (!$row->store())
			{
				// Install failed, roll back changes
				$this->parent->abort(JText::sprintf('JLIB_INSTALLER_ABORT_FILE_INSTALL_ROLLBACK', $db->stderr(true)));

				return false;
			}

			// Since we have created a module item, we add it to the installation step stack
			// so that if we have to rollback the changes we can undo it.
			$this->parent->pushStep(array('type' => 'extension', 'extension_id' => $row->extension_id));
		}

		// Let's run the queries for the file
		if ($this->route == 'install')
		{
			if (!$this->doDatabaseTransactions('install'))
			{
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
					$this->parent->abort(JText::sprintf('JLIB_INSTALLER_ABORT_FILE_UPDATE_SQL_ERROR', $db->stderr(true)));

					return false;
				}
			}
		}

		// Try to run the script file's custom method based on the route
		$this->triggerManifestScript($this->route);

		// Lastly, we will copy the manifest file to its appropriate place.
		$manifest = array();
		$manifest['src'] = $this->parent->getPath('manifest');
		$manifest['dest'] = JPATH_MANIFESTS . '/files/' . basename($this->parent->getPath('manifest'));

		if (!$this->parent->copyFiles(array($manifest), true))
		{
			// Install failed, rollback changes
			$this->parent->abort(JText::_('JLIB_INSTALLER_ABORT_FILE_INSTALL_COPY_SETUP'));

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
		// Clobber any possible pending updates
		$update = JTable::getInstance('update');
		$uid = $update->find(
			array('element' => $this->element, 'type' => 'file', 'client_id' => '', 'folder' => '')
		);

		if ($uid)
		{
			$update->delete($uid);
		}

		// And now we run the postflight
		$this->triggerManifestScript('postflight');

		return $row->extension_id;
	}

	/**
	 * Custom uninstall method
	 *
	 * @param   string  $id  The id of the file to uninstall
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.1
	 */
	public function uninstall($id)
	{
		$row = JTable::getInstance('extension');

		if (!$row->load($id))
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_FILE_UNINSTALL_LOAD_ENTRY'), JLog::WARNING, 'jerror');

			return false;
		}

		if ($row->protected)
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_FILE_UNINSTALL_WARNCOREFILE'), JLog::WARNING, 'jerror');

			return false;
		}

		$retval = true;
		$manifestFile = JPATH_MANIFESTS . '/files/' . $row->element . '.xml';

		// Because files may not have their own folders we cannot use the standard method of finding an installation manifest
		if (file_exists($manifestFile))
		{
			// Set the files root path
			$this->parent->setPath('extension_root', JPATH_MANIFESTS . '/files/' . $row->element);

			$xml = simplexml_load_file($manifestFile);

			// If we cannot load the XML file return null
			if (!$xml)
			{
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_FILE_UNINSTALL_LOAD_MANIFEST'), JLog::WARNING, 'jerror');

				return false;
			}

			// Check for a valid XML root tag.
			if ($xml->getName() != 'extension')
			{
				JLog::add(JText::_('JLIB_INSTALLER_ERROR_FILE_UNINSTALL_INVALID_MANIFEST'), JLog::WARNING, 'jerror');

				return false;
			}

			$this->manifest = $xml;

			$this->setupScriptfile();
			$this->triggerManifestScript('uninstall');

			$db = JFactory::getDbo();

			// Let's run the uninstall queries for the extension
			$result = $this->doDatabaseTransactions('uninstall');

			if ($result === false)
			{
				// Install failed, rollback changes
				JLog::add(JText::sprintf('JLIB_INSTALLER_ERROR_FILE_UNINSTALL_SQL_ERROR', $db->stderr(true)), JLog::WARNING, 'jerror');
				$retval = false;
			}

			// Remove the schema version
			$query = $db->getQuery(true);
			$query->delete()
				->from('#__schemas')
				->where('extension_id = ' . $row->extension_id);
			$db->setQuery($query);
			$db->execute();

			// Loop through all elements and get list of files and folders
			foreach ($xml->fileset->files as $eFiles)
			{
				$target = (string) $eFiles->attributes()->target;

				// Create folder path
				if (empty($target))
				{
					$targetFolder = JPATH_ROOT;
				}
				else
				{
					$targetFolder = JPATH_ROOT . '/' . $target;
				}

				$folderList = array();

				// Check if all children exists
				if (count($eFiles->children()) > 0)
				{
					// Loop through all filenames elements
					foreach ($eFiles->children() as $eFileName)
					{
						if ($eFileName->getName() == 'folder')
						{
							$folderList[] = $targetFolder . '/' . $eFileName;

						}
						else
						{
							$fileName = $targetFolder . '/' . $eFileName;
							JFile::delete($fileName);
						}
					}
				}

				// Delete any folders that don't have any content in them.
				foreach ($folderList as $folder)
				{
					$files = JFolder::files($folder);

					if (!count($files))
					{
						JFolder::delete($folder);
					}
				}
			}

			JFile::delete($manifestFile);

			// Lastly, remove the extension_root
			$folder = $this->parent->getPath('extension_root');

			if (JFolder::exists($folder))
			{
				JFolder::delete($folder);
			}
		}
		else
		{
			JLog::add(JText::_('JLIB_INSTALLER_ERROR_FILE_UNINSTALL_INVALID_NOTFOUND_MANIFEST'), JLog::WARNING, 'jerror');

			// Delete the row because its broken
			$row->delete();

			return false;
		}

		$this->parent->removeFiles($xml->languages);

		$row->delete();

		return $retval;
	}

	/**
	 * Function used to populate files and folder list
	 *
	 * @return  boolean  none
	 *
	 * @since   3.1
	 */
	protected function populateFilesAndFolderList()
	{
		// Initialise variable
		$this->folderList = array();
		$this->fileList = array();

		// Set root folder names
		$packagePath = $this->parent->getPath('source');
		$jRootPath = JPath::clean(JPATH_ROOT);

		// Loop through all elements and get list of files and folders
		foreach ($this->manifest->fileset->files as $eFiles)
		{
			// Check if the element is files element
			$folder = (string) $eFiles->attributes()->folder;
			$target = (string) $eFiles->attributes()->target;

			// Split folder names into array to get folder names. This will help in creating folders
			$arrList = preg_split("#/|\\/#", $target);

			$folderName = $jRootPath;

			foreach ($arrList as $dir)
			{
				if (empty($dir))
				{
					continue;
				}

				$folderName .= '/' . $dir;

				// Check if folder exists, if not then add to the array for folder creation
				if (!JFolder::exists($folderName))
				{
					array_push($this->folderList, $folderName);
				}
			}

			// Create folder path
			$sourceFolder = empty($folder) ? $packagePath : $packagePath . '/' . $folder;
			$targetFolder = empty($target) ? $jRootPath : $jRootPath . '/' . $target;

			// Check if source folder exists
			if (!JFolder::exists($sourceFolder))
			{
				JLog::add(JText::sprintf('JLIB_INSTALLER_ABORT_FILE_INSTALL_FAIL_SOURCE_DIRECTORY', $sourceFolder), JLog::WARNING, 'jerror');

				// If installation fails, rollback
				$this->parent->abort();

				return false;
			}

			// Check if all children exists
			if (count($eFiles->children()))
			{
				// Loop through all filenames elements
				foreach ($eFiles->children() as $eFileName)
				{
					$path['src'] = $sourceFolder . '/' . $eFileName;
					$path['dest'] = $targetFolder . '/' . $eFileName;
					$path['type'] = 'file';

					if ($eFileName->getName() == 'folder')
					{
						$folderName = $targetFolder . '/' . $eFileName;
						array_push($this->folderList, $folderName);
						$path['type'] = 'folder';
					}

					array_push($this->fileList, $path);
				}
			}
			else
			{
				$files = JFolder::files($sourceFolder);

				foreach ($files as $file)
				{
					$path['src'] = $sourceFolder . '/' . $file;
					$path['dest'] = $targetFolder . '/' . $file;

					array_push($this->fileList, $path);
				}

			}
		}
	}

	/**
	 * Refreshes the extension table cache
	 *
	 * @return  boolean result of operation, true if updated, false on failure
	 *
	 * @since   3.1
	 */
	public function refreshManifestCache()
	{
		// Need to find to find where the XML file is since we don't store this normally
		$manifestPath = JPATH_MANIFESTS . '/files/' . $this->parent->extension->element . '.xml';

		return $this->doRefreshManifestCache($manifestPath);
	}
}

/**
 * Deprecated class placeholder. You should use JInstallerAdapterFile instead.
 *
 * @package     Joomla.Libraries
 * @subpackage  Installer
 * @since       3.1
 * @deprecated  4.0
 * @codeCoverageIgnore
 */
class JInstallerFile extends JInstallerAdapterFile
{
}
