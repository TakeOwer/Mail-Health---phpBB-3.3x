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
	'MAILHEALTH_NOTICE_TITLE'	=> 'Wir können dich per E-Mail nicht erreichen',
	'MAILHEALTH_NOTICE_TEXT'	=> 'Die an deine Adresse gesendeten Nachrichten kommen zurück, deshalb wurden die E-Mail-Benachrichtigungen für dein Konto abgeschaltet.',
	'MAILHEALTH_NOTICE_LINK'	=> 'E-Mail-Adresse aktualisieren',
	'MAILHEALTH_REASON_HARD'	=> 'Adresse existiert nicht',
	'MAILHEALTH_REASON_SOFT'	=> 'Wiederholte vorübergehende Fehler',
	'MAILHEALTH_REASON_MANUAL'	=> 'Von einem Administrator hinzugefügt',
	'MAILHEALTH_PM_BOUNCED_SUBJECT'	=> 'Die E-Mails des Forums erreichen dich nicht',
	'MAILHEALTH_PM_BOUNCED_BODY'	=> 'Hallo %1$s,

die E-Mails, die das Forum an deine Adresse ([b]%2$s[/b]) sendet, kommen zurück. Die E-Mail-Benachrichtigungen sind deshalb vorerst ausgesetzt, damit das Forum nicht weiter an eine Adresse schreibt, die nicht funktioniert.

Aktualisiere dazu deine E-Mail-Adresse hier:
%3$s

Sobald du sie änderst, werden die Benachrichtigungen automatisch wieder aktiviert und du erhältst an der neuen Adresse eine Bestätigungsnachricht.',
	'MAILHEALTH_PM_CHANGED_SUBJECT'	=> 'E-Mail-Benachrichtigungen wieder aktiv',
	'MAILHEALTH_PM_CHANGED_BODY'	=> 'Hallo %1$s,

deine neue Adresse ([b]%2$s[/b]) wurde gespeichert und die E-Mail-Benachrichtigungen funktionieren wieder.

Wir haben dir dorthin eine Bestätigungsnachricht geschickt: öffne sie und klicke auf den Link, damit wir sofort bestätigen können, dass die Adresse funktioniert.',
	'MAILHEALTH_VERIFY_TITLE'	=> 'Bestätigung der E-Mail-Adresse',
	'MAILHEALTH_VERIFY_DONE'	=> 'Danke: deine E-Mail-Adresse ist bestätigt und die Benachrichtigungen des Forums funktionieren wieder normal.',
	'MAILHEALTH_VERIFY_FAILED'	=> 'Dieser Bestätigungslink ist ungültig oder wurde bereits verwendet. Wenn du deine Adresse erneut geändert hast, erhältst du eine neue Bestätigungsnachricht.',
]);
