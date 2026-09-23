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
	'ACP_MAILHEALTH_SETTINGS'	=> 'Impostazioni',
	'ACP_MAILHEALTH_BOUNCES'	=> 'Registro rimbalzi',
	'ACP_MAILHEALTH_CHECK'		=> 'Check-up',
	'ACP_MAILHEALTH_SUPPRESS'	=> 'Lista di soppressione',

	'LOG_MAILHEALTH_TRIGGERED'	=> '<strong>Mail Health: problema di recapito</strong><br />» Utente: %1$s - indirizzo: %2$s - stato: %3$s',
	'LOG_MAILHEALTH_SETTINGS'	=> '<strong>Impostazioni Mail Health aggiornate</strong>',

	'ACP_MAILHEALTH_USERS'		=> 'Utenti interessati',
	'LOG_MAILHEALTH_ALERT'		=> '<strong>Mail Health: allarme rimbalzi</strong><br />» %1$d rimbalzi nelle ultime 24 ore (%2$d definitivi), soglia di allarme %3$d',
	'LOG_MAILHEALTH_BULK'		=> '<strong>Mail Health: azione di massa sul registro</strong><br />» %1$s - %2$d record',
	'LOG_MAILHEALTH_USER_RESET'	=> '<strong>Mail Health: utente ripristinato a mano</strong><br />» %s',
]);
