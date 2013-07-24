<?php
/**
 * @package     Joomla.Platform
 * @subpackage  Amazons3
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('JPATH_PLATFORM') or die;

/**
 * Defines the POST operations on objects
 *
 * @package     Joomla.Platform
 * @subpackage  Amazons3
 * @since       ??.?
 */
class JAmazons3OperationsObjectsPost extends JAmazons3OperationsObjects
{
	/**
	 * Deletes multiple objects from a bucket
	 *
	 * @param   string  $bucket     The bucket name
	 * @param   array   $objects    An array of objects to be deleted
	 * @param   array   $quiet      In quiet mode the response includes only keys
	 *                              where the delete operation encountered an error
	 * @param   string  $serialNr   The serial number is generated using either a hardware or
	 *                              a virtual MFA device. Required for MfaDelete
	 * @param   string  $tokenCode  Also required for MfaDelete
	 *
	 * @return string  The response body
	 *
	 * @since   ??.?
	 */
	public function deleteMultipleObjects($bucket, $objects, $quiet = false, $serialNr = null, $tokenCode = null)
	{
		$url = "https://" . $bucket . "." . $this->options->get("api.url") . "/?delete";
		$content = "";
		$headers = array(
			"Date" => date("D, d M Y H:i:s O"),
		);

		if (is_array($objects))
		{
			$content = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. "<Delete>\n";

			if ($quiet)
			{
				$content .= "<Quiet>true</Quiet>\n";
			}

			foreach ($objects as $object)
			{
				$content .= "<Object>\n";

				foreach ($object as $key => $value)
				{
					$content .= "<" . $key . ">"
						. $value
						. "</" . $key . ">\n";
				}

				$content .= "</Object>\n";
			}

			$content .= "</Delete>";
		}

		if (! is_null($serialNr))
		{
			$headers["x-amz-mfa"] = $serialNr . " " . $tokenCode;
		}

		// Set the content related headers
		$headers["Content-type"] = "application/x-www-form-urlencoded; charset=utf-8";
		$headers["Content-Length"] = strlen($content);
		$headers["Content-MD5"] = base64_encode(md5($content, true));
		$authorization = $this->createAuthorization("POST", $url, $headers);
		$headers["Authorization"] = $authorization;
		unset($headers["Content-type"]);

		// Send the http request
		$response = $this->client->post($url, $content, $headers);

		// Process the response
		$response_body = $this->processResponse($response);

		return $response_body;
	}
}
