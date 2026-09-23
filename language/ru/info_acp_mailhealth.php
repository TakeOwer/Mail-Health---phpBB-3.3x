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
	'ACP_MAILHEALTH_SETTINGS'	=> 'Настройки',
	'ACP_MAILHEALTH_BOUNCES'	=> 'Журнал возвратов',
	'ACP_MAILHEALTH_CHECK'	=> 'Проверка',
	'ACP_MAILHEALTH_SUPPRESS'	=> 'Список исключений',
	'ACP_MAILHEALTH_USERS'	=> 'Затронутые пользователи',
	'LOG_MAILHEALTH_TRIGGERED'	=> '<strong>Mail Health: проблема с доставкой</strong><br />» Пользователь: %1$s - адрес: %2$s - код: %3$s',
	'LOG_MAILHEALTH_SETTINGS'	=> '<strong>Настройки Mail Health обновлены</strong>',
	'LOG_MAILHEALTH_ALERT'	=> '<strong>Mail Health: оповещение о возвратах</strong><br />» возвратов за последние 24 часа: %1$d (окончательных: %2$d), порог оповещения %3$d',
	'LOG_MAILHEALTH_BULK'	=> '<strong>Mail Health: групповое действие в журнале</strong><br />» %1$s - записей: %2$d',
	'LOG_MAILHEALTH_USER_RESET'	=> '<strong>Mail Health: пользователь сброшен вручную</strong><br />» %s',
]);
