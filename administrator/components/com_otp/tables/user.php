<?php
/**
 * @package     Joomla.Administrator
 * @subpackage  com_otp
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

class OtpTableUser extends FOFTable
{
	/**
	 * Overriden contructor to map this table to the #__users core table
	 *
	 * @param   string     $table   The table name; ignored
	 * @param   string     $key     The key field; ignored
	 * @param   JDatabase  $db      Database connection
	 * @param   array      $config  Configuration variables
	 */
	public function __construct($table, $key, &$db, $config = array())
	{
		$table = '#__users';
		$key = 'id';

		parent::__construct($table, $key, $db, $config);
	}
}