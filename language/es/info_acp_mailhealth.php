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
	'ACP_MAILHEALTH_SETTINGS'	=> 'Ajustes',
	'ACP_MAILHEALTH_BOUNCES'	=> 'Registro de rebotes',
	'ACP_MAILHEALTH_CHECK'	=> 'Revisión',
	'ACP_MAILHEALTH_SUPPRESS'	=> 'Lista de supresión',
	'ACP_MAILHEALTH_USERS'	=> 'Usuarios afectados',
	'LOG_MAILHEALTH_TRIGGERED'	=> '<strong>Mail Health: problema de entrega</strong><br />» Usuario: %1$s - dirección: %2$s - estado: %3$s',
	'LOG_MAILHEALTH_SETTINGS'	=> '<strong>Ajustes de Mail Health actualizados</strong>',
	'LOG_MAILHEALTH_ALERT'	=> '<strong>Mail Health: alerta de rebotes</strong><br />» %1$d rebotes en las últimas 24 horas (%2$d definitivos), umbral de alerta %3$d',
	'LOG_MAILHEALTH_BULK'	=> '<strong>Mail Health: acción en bloque sobre el registro</strong><br />» %1$s - %2$d registro(s)',
	'LOG_MAILHEALTH_USER_RESET'	=> '<strong>Mail Health: usuario restablecido a mano</strong><br />» %s',
]);
