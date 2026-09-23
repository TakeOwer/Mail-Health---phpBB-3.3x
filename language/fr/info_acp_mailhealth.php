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
	'ACP_MAILHEALTH_SETTINGS'	=> 'Paramètres',
	'ACP_MAILHEALTH_BOUNCES'	=> 'Journal des retours',
	'ACP_MAILHEALTH_CHECK'	=> 'Bilan',
	'ACP_MAILHEALTH_SUPPRESS'	=> 'Liste de suppression',
	'ACP_MAILHEALTH_USERS'	=> 'Membres concernés',
	'LOG_MAILHEALTH_TRIGGERED'	=> '<strong>Mail Health : problème de remise</strong><br />» Membre : %1$s - adresse : %2$s - état : %3$s',
	'LOG_MAILHEALTH_SETTINGS'	=> '<strong>Paramètres de Mail Health mis à jour</strong>',
	'LOG_MAILHEALTH_ALERT'	=> '<strong>Mail Health : alerte retours</strong><br />» %1$d retours au cours des 24 dernières heures (%2$d définitifs), seuil d’alerte %3$d',
	'LOG_MAILHEALTH_BULK'	=> '<strong>Mail Health : action groupée sur le journal</strong><br />» %1$s - %2$d enregistrement(s)',
	'LOG_MAILHEALTH_USER_RESET'	=> '<strong>Mail Health : membre réinitialisé à la main</strong><br />» %s',
]);
