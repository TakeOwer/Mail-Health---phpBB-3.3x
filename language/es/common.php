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
	'MAILHEALTH_NOTICE_TITLE'	=> 'No conseguimos contactarte por correo electrónico',
	'MAILHEALTH_NOTICE_TEXT'	=> 'Los mensajes enviados a tu dirección vuelven rechazados, por eso se han desactivado las notificaciones por correo de tu cuenta.',
	'MAILHEALTH_NOTICE_LINK'	=> 'Actualiza tu dirección de correo',
	'MAILHEALTH_REASON_HARD'	=> 'La dirección no existe',
	'MAILHEALTH_REASON_SOFT'	=> 'Fallos temporales repetidos',
	'MAILHEALTH_REASON_MANUAL'	=> 'Añadida por un administrador',
	'MAILHEALTH_PM_BOUNCED_SUBJECT'	=> 'Los correos del foro no te llegan',
	'MAILHEALTH_PM_BOUNCED_BODY'	=> 'Hola %1$s:

Los correos que el foro envía a tu dirección ([b]%2$s[/b]) vuelven rechazados, así que las notificaciones por correo están suspendidas por ahora: de este modo el foro deja de escribir a una dirección que no funciona.

Para solucionarlo, actualiza tu dirección de correo aquí:
%3$s

En cuanto la cambies, las notificaciones se reactivan solas y recibirás un mensaje de verificación en la nueva dirección.',
	'MAILHEALTH_PM_CHANGED_SUBJECT'	=> 'Notificaciones por correo reactivadas',
	'MAILHEALTH_PM_CHANGED_BODY'	=> 'Hola %1$s:

Hemos registrado tu nueva dirección ([b]%2$s[/b]) y las notificaciones por correo vuelven a funcionar.

Te hemos enviado allí un mensaje de verificación: ábrelo y haz clic en el enlace, así confirmamos enseguida que la dirección funciona.',
	'MAILHEALTH_VERIFY_TITLE'	=> 'Verificación de la dirección de correo',
	'MAILHEALTH_VERIFY_DONE'	=> 'Gracias: tu dirección de correo está confirmada y las notificaciones del foro funcionan con normalidad.',
	'MAILHEALTH_VERIFY_FAILED'	=> 'Este enlace de confirmación no es válido o ya se ha utilizado. Si has vuelto a cambiar de dirección, recibirás un nuevo mensaje de verificación.',
]);
