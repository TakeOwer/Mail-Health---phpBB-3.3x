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
	'MAILHEALTH_NOTICE_TITLE'	=> 'Non riusciamo a contattarti via e-mail',
	'MAILHEALTH_NOTICE_TEXT'	=> 'I messaggi inviati al tuo indirizzo continuano a tornare indietro, quindi le notifiche per il tuo account sono state disattivate.',
	'MAILHEALTH_NOTICE_LINK'	=> 'Aggiorna il tuo indirizzo e-mail',

	'MAILHEALTH_REASON_HARD'	=> 'Indirizzo inesistente',
	'MAILHEALTH_REASON_SOFT'	=> 'Errori temporanei ripetuti',
	'MAILHEALTH_REASON_MANUAL'	=> 'Aggiunto da un amministratore',
	'MAILHEALTH_PM_BOUNCED_SUBJECT'	=> 'Le e-mail del forum non ti arrivano',
	'MAILHEALTH_PM_BOUNCED_BODY'	=> 'Ciao %1$s,

le e-mail che il forum invia al tuo indirizzo ([b]%2$s[/b]) tornano indietro, quindi per ora le notifiche via e-mail sono sospese: così il forum smette di scrivere a un indirizzo che non funziona.

Per risolvere, aggiorna il tuo indirizzo e-mail qui:
%3$s

Appena lo cambi le notifiche vengono riattivate da sole, e ricevi un messaggio di verifica al nuovo indirizzo.',
	'MAILHEALTH_PM_CHANGED_SUBJECT'	=> 'Notifiche e-mail riattivate',
	'MAILHEALTH_PM_CHANGED_BODY'	=> 'Ciao %1$s,

abbiamo registrato il tuo nuovo indirizzo ([b]%2$s[/b]) e le notifiche via e-mail sono di nuovo attive.

Ti abbiamo inviato lì un messaggio di verifica: aprilo e fai clic sul link, così confermiamo subito che l\'indirizzo funziona.',
	'MAILHEALTH_VERIFY_TITLE'	=> 'Verifica dell\'indirizzo e-mail',
	'MAILHEALTH_VERIFY_DONE'	=> 'Grazie: il tuo indirizzo e-mail è stato confermato e le notifiche del forum funzionano regolarmente.',
	'MAILHEALTH_VERIFY_FAILED'	=> 'Questo link di conferma non è valido oppure è già stato usato. Se hai cambiato di nuovo indirizzo, riceverai un nuovo messaggio di verifica.',
]);
