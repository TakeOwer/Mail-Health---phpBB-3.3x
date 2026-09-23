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
	'MAILHEALTH_NOTICE_TITLE'	=> 'Nous n\'arrivons pas à vous joindre par e-mail',
	'MAILHEALTH_NOTICE_TEXT'	=> 'Les messages envoyés à votre adresse nous reviennent, les notifications par e-mail ont donc été désactivées pour votre compte.',
	'MAILHEALTH_NOTICE_LINK'	=> 'Mettre à jour votre adresse e-mail',
	'MAILHEALTH_REASON_HARD'	=> 'Adresse inexistante',
	'MAILHEALTH_REASON_SOFT'	=> 'Échecs temporaires répétés',
	'MAILHEALTH_REASON_MANUAL'	=> 'Ajoutée par un administrateur',
	'MAILHEALTH_PM_BOUNCED_SUBJECT'	=> 'Les e-mails du forum ne vous parviennent pas',
	'MAILHEALTH_PM_BOUNCED_BODY'	=> 'Bonjour %1$s,

Les e-mails que le forum envoie à votre adresse ([b]%2$s[/b]) nous reviennent : les notifications par e-mail sont donc suspendues pour l\'instant, afin que le forum cesse d\'écrire à une adresse qui ne fonctionne pas.

Pour résoudre le problème, mettez votre adresse e-mail à jour ici :
%3$s

Dès que vous la changerez, les notifications seront réactivées automatiquement et un message de vérification sera envoyé à la nouvelle adresse.',
	'MAILHEALTH_PM_CHANGED_SUBJECT'	=> 'Notifications par e-mail réactivées',
	'MAILHEALTH_PM_CHANGED_BODY'	=> 'Bonjour %1$s,

Votre nouvelle adresse ([b]%2$s[/b]) a bien été enregistrée et les notifications par e-mail fonctionnent de nouveau.

Un message de vérification vous y a été envoyé : ouvrez-le et cliquez sur le lien, afin de confirmer tout de suite que l\'adresse fonctionne.',
	'MAILHEALTH_VERIFY_TITLE'	=> 'Vérification de l\'adresse e-mail',
	'MAILHEALTH_VERIFY_DONE'	=> 'Merci : votre adresse e-mail est confirmée et les notifications du forum fonctionnent normalement.',
	'MAILHEALTH_VERIFY_FAILED'	=> 'Ce lien de confirmation n\'est pas valide ou a déjà été utilisé. Si vous avez de nouveau changé d\'adresse, un nouveau message de vérification vous parviendra.',
]);
