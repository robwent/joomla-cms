<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Twofactorauth.totp
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/**
 * Joomla! Two Factor Authentication using Google Authenticator TOTP Plugin
 *
 * @package     Joomla.Plugin
 * @subpackage  Twofactorauth.totp
 */
class PlgTwofactorauthTotp extends JPlugin
{
	protected $methodName = 'totp';

	/**
	 * Constructor
	 *
	 * @param   object  &$subject  The object to observe
	 * @param   array   $config    An optional associative array of configuration settings.
	 *                             Recognized key values include 'name', 'group', 'params', 'language'
	 *                             (this list is not meant to be comprehensive).
	 */
	public function __construct(&$subject, $config = array())
	{
		parent::__construct($subject, $config);

		// Load the Joomla! RAD layer
		if (!defined('FOF_INCLUDED'))
		{
			include_once JPATH_LIBRARIES . '/fof/include.php';
		}

		// Load the translation files
		$this->loadLanguage();
	}

	/**
	 * This method returns the identification object for this two factor
	 * authentication plugin.
	 *
	 * @return  stdClass  An object with public properties method and title
	 */
	public function onUserTwofactorIdentify()
	{
		return (object)array(
			'method'	=> $this->methodName,
			'title'		=> JText::_('PLG_TWOFACTORAUTH_TOTP_METHOD_TITLE'),
		);
	}

	/**
	 * Shows the configuration page for this two factor authentication method.
	 *
	 * @param   object  $otpConfig  The two factor auth configuration object
	 *
	 * @see UsersModelUser::getOtpConfig
	 *
	 * @return  boolean|string  False if the method is not ours, the HTML of the configuration page otherwise
	 */
	public function onUserTwofactorShowConfiguration($otpConfig)
	{
		// Create a new TOTP class with Google Authenticator compatible settings
		$totp = new FOFEncryptTotp(30, 6, 10);

		if ($otpConfig->method == $this->methodName)
		{
			// This method is already activated. Reuse the same secret key.
			$secret = $otpConfig->config['code'];
		}
		else
		{
			// This methods is not activated yet. Create a new secret key.
			$secret = $totp->generateSecret();
		}

		// These are used by Google Authenticator to tell accounts apart
		$username = JFactory::getUser()->username;
		$hostname = JFactory::getURI()->getHost();

		// This is the URL to the QR code for Google Authenticator
		$url = $totp->getUrl($username, $hostname, $secret);

		// Start output buffering
		@ob_start();

		// Include the form.php from a template override. If none is found use the default.
		$path = FOFPlatform::getInstance()->getTemplateOverridePath('plg_twofactorauth_totp', true);

		JLoader::import('joomla.filesystem.file');

		if (JFile::exists($path . 'form.php'))
		{
			include_once $path . 'form.php';
		}
		else
		{
			include_once __DIR__ . '/tmpl/form.php';
		}

		// Stop output buffering and get the form contents
		$html = @ob_get_clean();

		// Return the form contents
		return array(
			'method'	=> $this->methodName,
			'form'		=> $html,
		);
	}

	/**
	 * The save handler of the two factor configuration method's configuration
	 * page.
	 *
	 * @param   string  $method  The two factor auth method for which we'll show the config page
	 *
	 * @see UsersModelUser::setOtpConfig
	 *
	 * @return  boolean|stdClass  False if the method doesn't match or we have an error, OTP config object if it succeeds
	 */
	public function onUserTwofactorApplyConfiguration($method)
	{
		if ($method != $this->methodName)
		{
			return false;
		}

		// Get a reference to the input data object
		$input = JFactory::getApplication()->input;

		// Load raw data
		$rawData = $input->get('jform', array(), 'array');
		$data = $rawData['twofactor']['totp'];

		// Create a new TOTP class with Google Authenticator compatible settings
		$totp = new FOFEncryptTotp(30, 6, 10);

		// Check the security code entered by the user (exact time slot match)
		$code = $totp->getCode($data['key']);
		$check = $code == $data['securitycode'];

		// If the check fails, test the previous 30 second slot. This allow the
		// user to enter the security code when it's becoming red in Google
		// Authenticator app (reaching the end of its 30 second lifetime)
		if (!$check)
		{
			$time = time() - 30;
			$code = $totp->getCode($data['key'], $time);
			$check = $code == $data['securitycode'];
		}

		// If the check fails, test the next 30 second slot. This allows some
		// time drift between the authentication device and the server
		if (!$check)
		{
			$time = time() + 30;
			$code = $totp->getCode($data['key'], $time);
			$check = $code == $data['securitycode'];
		}

		if (!$check)
		{
			// Check failed. Do not change two factor authentication settings.
			return false;
		}

		// Check succeedeed; return an OTP configuration object
		$otpConfig = (object)array(
			'method'	=> 'totp',
			'config'	=> array(
				'code'	=> $data['key']
			),
			'otep'		=> array()
		);

		return $otpConfig;
	}

	/**
	 * This method should handle any two factor authentication and report back
	 * to the subject.
	 *
	 * @param   array   $credentials  Array holding the user credentials
	 * @param   array   $options      Array of extra options
	 *
	 * @return  boolean  True if the user is authorised with this two-factor authentication method
	 *
	 * @since   3.2.0
	 */
	public function onUserTwofactorAuthenticate($credentials, $options)
	{
		// Get the OTP configuration object
		$otpConfig = $options['otp_config'];

		// Make sure it's an object
		if (empty($otpConfig) || !is_object($otpConfig))
		{
			return false;
		}

		// Check if we have the correct method
		if ($otpConfig->method != $this->methodName)
		{
			return false;
		}

		// Check if there is a security code
		if (empty($credentials['secretkey']))
		{
			return false;
		}

		// Create a new TOTP class with Google Authenticator compatible settings
		$totp = new FOFEncryptTotp(30, 6, 10);

		// Check the code
		$code = $totp->getCode($otpConfig->config['code']);
		$check = $code == $credentials['secretkey'];

		// If the check fails, test the previous 30 second slot. This allow the
		// user to enter the security code when it's becoming red in Google
		// Authenticator app (reaching the end of its 30 second lifetime)
		if (!$check)
		{
			$time = time() - 30;
			$code = $totp->getCode($otpConfig->config['code'], $time);
			$check = $code == $credentials['secretkey'];
		}

		// If the check fails, test the next 30 second slot. This allows some
		// time drift between the authentication device and the server
		if (!$check)
		{
			$time = time() + 30;
			$code = $totp->getCode($otpConfig->config['code'], $time);
			$check = $code == $credentials['secretkey'];
		}

		if (!$check && array_key_exists('user_id', $options))
		{
			// Did the user use an OTEP instead?
			if (empty($otpConfig->otep))
			{
				if (empty($otpConfig->method) || ($otpConfig->method == 'none'))
				{
					// Two factor authentication is not enabled on this account.
					// Any string is assumed to be a valid OTEP.
					return true;
				}
				else
				{
					// Two factor authentication enabled and no OTEPs defined. The
					// user has used them all up. Therefore anything he enters is
					// an invalid OTEP.
					return false;
				}
			}

			// Did we find a valid OTEP?
			if (in_array($otep, $otpConfig->otep))
			{
				// Remove the OTEP from the array
				$array_key = array_search($otep, $otpConfig->otep);
				unset($otpConfig->otep[$array_key]);

				// Save the now modified OTP configuration
				require_once JPATH_ADMINISTRATOR . '/components/com_users/models/user.php';
				$model = new UsersModelUser;
				$model->setOtpConfig($options['user_id'], $otpConfig);

				// Return true; the OTEP was a valid one
				$check = true;
			}

			$check = false;
		}

		return $check;
	}
}
