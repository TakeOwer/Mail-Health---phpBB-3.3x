<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'MAILHEALTH_NOTICE_TITLE'	=> 'We cannot reach you by e-mail',
	'MAILHEALTH_NOTICE_TEXT'	=> 'Messages sent to your address keep coming back, so notifications have been turned off for your account.',
	'MAILHEALTH_NOTICE_LINK'	=> 'Update your e-mail address',

	'MAILHEALTH_REASON_HARD'	=> 'Address does not exist',
	'MAILHEALTH_REASON_SOFT'	=> 'Repeated temporary failures',
	'MAILHEALTH_REASON_MANUAL'	=> 'Added by an administrator',
	'MAILHEALTH_PM_BOUNCED_SUBJECT'	=> 'The board’s e-mails are not reaching you',
	'MAILHEALTH_PM_BOUNCED_BODY'	=> 'Hello %1$s,

The e-mails the board sends to your address ([b]%2$s[/b]) keep coming back, so e-mail notifications are suspended for now: this stops the board writing to an address that does not work.

To fix it, update your e-mail address here:
%3$s

As soon as you change it notifications come back on their own, and a verification message is sent to the new address.',
	'MAILHEALTH_PM_CHANGED_SUBJECT'	=> 'E-mail notifications are back on',
	'MAILHEALTH_PM_CHANGED_BODY'	=> 'Hello %1$s,

Your new address ([b]%2$s[/b]) has been recorded and e-mail notifications are working again.

A verification message has been sent there: open it and click the link, so we can confirm at once that the address works.',
	'MAILHEALTH_VERIFY_TITLE'	=> 'E-mail address verification',
	'MAILHEALTH_VERIFY_DONE'	=> 'Thank you: your e-mail address is confirmed and board notifications are working normally.',
	'MAILHEALTH_VERIFY_FAILED'	=> 'This confirmation link is not valid or has already been used. If you changed your address again, a new verification message will reach you.',
]);
