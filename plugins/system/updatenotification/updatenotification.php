<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.updatenotification
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die();

class plgSystemUpdatenotification extends JPlugin
{
	/**
	 * Executes after Joomla! has rendered its page
	 *
	 * @return  voide
	 */
	public function onAfterRender()
	{
		// Get the timeout for Joomla! updates
		jimport('joomla.application.component.helper');
		$component = JComponentHelper::getComponent('com_installer');
		$params = $component->params;
		$cache_timeout = $params->get('cachetimeout', 6, 'int');
		$cache_timeout = 3600 * $cache_timeout;

		// Do we have to run an update fetch?
		$lastRunTimestamp = $this->params->get('lastrun', 0);
		$currentTimestamp = time();

		$mustRun = abs($currentTimestamp - $lastRunTimestamp) < $cache_timeout;

		if (!defined('PLG_SYSTEM_UPDATENOTIFICATION_DEBUG') && !$mustRun)
		{
			return;
		}

		// Update last run timestamp
		$params->set('lastrun', $now);
		$db = JFactory::getDBO();
		$data = $params->toString('JSON');
		$sql = $db->getQuery(true)
			->update($db->qn('#__extensions'))
			->set($db->qn('params').' = '.$db->q($data))
			->where($db->qn('type').' = '.$db->q('plugin'))
			->where($db->qn('folder').' = '.$db->q('system'))
			->where($db->qn('element').' = '.$db->q('updatenotification'));
		$db->setQuery($sql);

		try
		{
			$result = $db->execute();
		}
		catch (Exception $exc)
		{
			$result = false;
		}

		if (!$result)
		{
			return;
		}

		// This is the hard-coded extension ID for Joomla! itself (files_joomla)
		$eid = 700;

		// Fetch available updates
		$updater = JUpdater::getInstance();
		$results = $updater->findUpdates($eid, $cache_timeout);

		if (!$results)
		{
			return;
		}

		// If we do not have to send out emails, quit
		if (!$this->params->get('sendemail', 1))
		{
			return;
		}

		// Check if we have any Joomla! updates
		require_once JPATH_ADMINISTRATOR . '/components/com_installer/models/update.php';
		$model = JModelLegacy::getInstance('Update','InstallerModel');

		$model->setState('filter.extension_id', $eid);
		$updates = $model->getItems();

		if (empty($updates))
		{
			return;
		}

		$update = array_pop($updates);

		// If we're here, we have updates. Let's get the Super Administrator emails
		$superAdmins = array();
		$superAdminEmail = $this->params->get('email', '');

		if (!empty($superAdminEmail))
		{
			$superAdmins = $this->_getSuperAdministrators($superAdminEmail);
		}

		if (empty($superAdmins))
		{
			$superAdmins = $this->_getSuperAdministrators();
		}

		if (empty($superAdmins))
		{
			return;
		}

		// Get the template message
		$this->loadLanguage();
		$email_subject	= JText::_('PLG_SYSTEM_UPDATENOTIFICATION_EMAIL_SUBJECT');
		$email_body		= JText::_('PLG_SYSTEM_UPDATENOTIFICATION_EMAIL_BODY');

		// Get the new Joomla! version number
		$newVersion = $update->version;

		// Get the current Joomla! version number
		$jVersion = new JVersion;
		$currentVersion = $jVersion->getShortVersion();

		// Get the site's name
		$jconfig = JFactory::getConfig();
		$sitename = $jconfig->get('sitename');

		// Get the verification phrase
		$defaultPhrase = JText::_('PLG_SYSTEM_UPDATENOTIFICATION_VERIFICATION_DEFAULT');
		$verification = $this->params->get('verification', $defaultPhrase);

		// Get the link to Joomla! Update
		$uri = JURI::base();
		$uri = rtrim($uri,'/');
		$uri .= (substr($uri,-13) != 'administrator') ? '/administrator/' : '/';
		$link = 'index.php?option=com_joomlaupdate';

		// Perform the necessary subsctitutions in the email template
		$substitutions = array(
			'[NEWVERSION]'		=> $newVersion,
			'[CURVERSION]'		=> $currentVersion,
			'[SITENAME]'		=> $sitename,
			'[VERIFICATION]'	=> $verification,
			'[LINK]'			=> $link,
		);

		foreach ($substitutions as $k => $v)
		{
			$email_subject = str_replace($k, $v, $email_subject);
			$email_body = str_replace($k, $v, $email_body);
		}

		// Send an email to each Super Administrator on our list
		foreach($superAdmins as $sa)
		{
			$mailer = JFactory::getMailer();
			$mailfrom = $jconfig->get('mailfrom');
			$fromname = $jconfig->get('fromname');
			$mailer->setSender(array( $mailfrom, $fromname ));
			$mailer->addRecipient($sa->email);
			$mailer->setSubject($email_subject);
			$mailer->setBody($email_body);
			$mailer->Send();
		}
	}

	private function _getSuperAdministrators($email = null)
	{
		$db = JFactory::getDBO();

		$sql = $db->getQuery(true)
			->select(array(
				$db->qn('u').'.'.$db->qn('id'),
				$db->qn('u').'.'.$db->qn('email')
			))->from($db->qn('#__user_usergroup_map').' AS '.$db->qn('g'))
			->join(
				'INNER',
				$db->qn('#__users').' AS '.$db->qn('u').' ON ('.
				$db->qn('g').'.'.$db->qn('user_id').' = '.$db->qn('u').'.'.$db->qn('id').')'
			)->where($db->qn('g').'.'.$db->qn('group_id').' = '.$db->q('8'))
			->where($db->qn('u').'.'.$db->qn('sendEmail').' = '.$db->q('1'))
		;

		if (!empty($email))
		{
			$sql->where($db->qn('u').'.'.$db->qn('email').' = '.$db->q($email));
		}

		$db->setQuery($sql);

		return $db->loadObjectList();
	}
}