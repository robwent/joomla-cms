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
 * The model of the Two Factor Authentication component for Joomla!
 */
class OtpModelUsers extends FOFModel
{
	/**
	 * Applies the custom filters to the query used to retrieve a list of records
	 *
	 * @param   boolean  $overrideLimits  When true all limits are overriden
	 *
	 * @return  JDatabaseQuery
	 */
	public function buildQuery($overrideLimits = false)
	{
		$query = parent::buildQuery($overrideLimits);

		$db = $this->getDbo();

		// Apply the name, username and email search
		$filterSearch = $this->getState('search', null, 'string');

		if (!empty($filterSearch))
		{
			$query->where(
				'(' . $db->qn('name') . ' LIKE ' . $db->q('%' . $filterSearch . '%') . ') OR ' .
				'(' . $db->qn('username') . ' LIKE ' . $db->q('%' . $filterSearch . '%') . ') OR ' .
				'(' . $db->qn('email') . ' LIKE ' . $db->q('%' . $filterSearch . '%') . ')'
			);
		}

		// Apply the OTP enabled search
		$filterEnabled = $this->getState('otpEnabled', null, 'string');

		if (($filterEnabled !== '') && !is_null($filterEnabled))
		{
			$filterEnabled = (int)$filterEnabled;

			if ($filterEnabled)
			{
				$query->where($db->qn('otpKey') . ' <> ' . $db->q(''));
			}
			else
			{
				$query->where($db->qn('otpKey') . ' = ' . $db->q(''));
			}
		}

		// Apply the OTP method search
		$filterMethod = $this->getState('otpMethod', null, 'string');

		if (!empty($filterMethod))
		{
			$query->where($db->qn('otpKey') . ' LIKE ' . $db->q($filterMethod . ':%'));
		}

		return $query;
	}

	/**
	 * Post-processes the list of records, adding virtual fields which denote
	 * if two factor authentication is enabled and which is the preferred
	 * method for generating and validating a TOTP.
	 *
	 * @param   array  $resultArray  An array of OtpTableUser objects
	 *
	 * @return  void  This method modifies the $resultArray directly
	 */
	public function onProcessList(&$resultArray)
	{
		if (empty($resultArray))
		{
			return;
		}

		foreach ($resultArray as $index => &$row)
		{
			if (!empty($row->otpKey))
			{
				$row->otpEnabled = 1;
				$parts = explode($row->otpKey, ':', 2);
				$row->otpMethod = $parts[0];
			}
			else
			{
				$row->otpEnabled = 0;
				$row->otpMethod = 'none';
			}
		}
	}
}