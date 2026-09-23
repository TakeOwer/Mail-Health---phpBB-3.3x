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
	'ACP_MAILHEALTH_TITLE'		=> 'Mail Health',
	'ACP_MAILHEALTH_SETTINGS'	=> 'Settings',
	'ACP_MAILHEALTH_BOUNCES'	=> 'Bounce log',
	'ACP_MAILHEALTH_CHECK'		=> 'Check-up',
	'ACP_MAILHEALTH_SUPPRESS'	=> 'Suppression list',

	'LOG_MAILHEALTH_TRIGGERED'	=> '<strong>Mail Health: delivery problem</strong><br />» User: %1$s - address: %2$s - status: %3$s',
	'LOG_MAILHEALTH_SETTINGS'	=> '<strong>Mail Health settings updated</strong>',

	'ACP_MAILHEALTH_USERS'		=> 'Affected members',
	'LOG_MAILHEALTH_ALERT'		=> '<strong>Mail Health: bounce alert</strong><br />» %1$d bounces in the last 24 hours (%2$d hard), alert threshold %3$d',
	'LOG_MAILHEALTH_BULK'		=> '<strong>Mail Health: bulk action on the bounce log</strong><br />» %1$s - %2$d record(s)',
	'LOG_MAILHEALTH_USER_RESET'	=> '<strong>Mail Health: member reset by hand</strong><br />» %s',
]);
