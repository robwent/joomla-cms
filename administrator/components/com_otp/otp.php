<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_otp
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

// Load the Joomla! RAD layer
if (!defined('FOF_INCLUDED'))
{
	include_once JPATH_LIBRARIES . '/fof/include.php';
}

// Load and dispatch the component
FOFDispatcher::getTmpInstance('com_otp')->dispatch();