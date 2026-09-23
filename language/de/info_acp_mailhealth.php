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
	'ACP_MAILHEALTH_TITLE'	=> 'Mail Health',
	'ACP_MAILHEALTH_SETTINGS'	=> 'Einstellungen',
	'ACP_MAILHEALTH_BOUNCES'	=> 'Protokoll der Rückläufer',
	'ACP_MAILHEALTH_CHECK'	=> 'Prüfung',
	'ACP_MAILHEALTH_SUPPRESS'	=> 'Sperrliste',
	'ACP_MAILHEALTH_USERS'	=> 'Betroffene Mitglieder',
	'LOG_MAILHEALTH_TRIGGERED'	=> '<strong>Mail Health: Zustellproblem</strong><br />» Mitglied: %1$s - Adresse: %2$s - Status: %3$s',
	'LOG_MAILHEALTH_SETTINGS'	=> '<strong>Einstellungen von Mail Health aktualisiert</strong>',
	'LOG_MAILHEALTH_ALERT'	=> '<strong>Mail Health: Warnung bei Rückläufern</strong><br />» %1$d Rückläufer in den letzten 24 Stunden (%2$d endgültig), Warngrenze %3$d',
	'LOG_MAILHEALTH_BULK'	=> '<strong>Mail Health: Sammelaktion im Protokoll</strong><br />» %1$s - %2$d Einträge',
	'LOG_MAILHEALTH_USER_RESET'	=> '<strong>Mail Health: Mitglied von Hand zurückgesetzt</strong><br />» %s',
]);
