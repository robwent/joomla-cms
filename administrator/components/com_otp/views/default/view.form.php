<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_otp
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

class OtpViewDefault extends FOFViewForm
{
	public function __construct($config = array())
	{
		// Default to the Joomla! linkbar styling
		if (!array_key_exists('linkbar_style', $config))
		{
			$config['linkbar_style'] = 'joomla';
		}

		parent::__construct($config);
	}
}