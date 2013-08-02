<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Twofactorauth.totp.tmpl
 *
 * @copyright   Copyright (C) 2005 - 2013 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;
?>
<input type="hidden" name="totp[key]" value="<?php echo $secret ?>" />

<div class="well">
	<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_INTRO') ?>
</div>

<fieldset>
	<legend>
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP1_HEAD') ?>
	</legend>
	<p>
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP1_TEXT') ?>
	</p>
	<ul>
		<li>
			<a href="http://support.google.com/accounts/bin/answer.py?hl=en&answer=1066447" target="_blank">
				<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP1_ITEM1') ?>
			</a>
		</li>
		<li>
			<a href="http://en.wikipedia.org/wiki/Google_Authenticator#Implementation" target="_blank">
				<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP1_ITEM2') ?>
			</a>
		</li>
	</ul>
	<div class="alert">
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP1_WARN') ?>
	</div>
</fieldset>

<fieldset>
	<legend>
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP2_HEAD') ?>
	</legend>
	<p>
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP2_TEXT') ?>
	</p>
	<table class="table table-striped">
		<tr>
			<td>
				<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP2_ACCOUNT') ?>
			</td>
			<td>
				<?php echo $username ?>@<?php echo $hostname ?>
			</td>
		</tr>
		<tr>
			<td>
				<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP2_KEY') ?>
			</td>
			<td>
				<?php echo $secret ?>
			</td>
		</tr>
	</table>
	<p>
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP2_ALTTEXT') ?>
		<br />
		<img src="<?php echo $url ?>" style="float: none;" />
	</p>
	<div class="alert alert-info">
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP2_RESET') ?>
	</div>
</fieldset>

<fieldset>
	<legend>
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP3_HEAD') ?>
	</legend>
	<p>
		<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP3_TEXT') ?>
	</p>
	<div class="control-group">
		<label class="control-label" for="totpsecuritycode">
			<?php echo JText::_('PLG_TWOFACTORAUTH_TOTP_STEP3_SECURITYCODE') ?>
		</label>
		<div class="controls">
			<input type="text" class="input-small" name="totp[securitycode]" id="totpsecuritycode" autocomplete="0" autofocus="autofocus">
		</div>
	</div>
</fieldset>