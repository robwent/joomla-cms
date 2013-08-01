<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_otp
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * This class handles the toolbar generation for com_otp's back-end
 */
class OtpToolbar extends FOFToolbar
{
	/**
	 * Modify the list view's toolbar
	 *
	 * @return  void
	 */
	public function onUsersBrowse()
	{
		if (FOFPlatform::getInstance()->isBackend() || $this->renderFrontendSubmenu)
		{
			$this->renderSubmenu();
		}

		if (!FOFPlatform::getInstance()->isBackend() && !$this->renderFrontendButtons)
		{
			return;
		}

		JToolBarHelper::title(JText::_('COM_OTP'), 'otp');

		JToolBarHelper::editList();
	}
}