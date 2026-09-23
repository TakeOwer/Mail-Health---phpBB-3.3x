<?php
/**
 *
 * Mail Health. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * Test suite. No external dependency: run it from the extension folder with
 *
 *     php tests/run.php
 *
 * It exits with 0 when everything passes and 1 when something fails, so it
 * can also be used in a build script.
 */

define('IN_PHPBB', true);
define('MH_ROOT', dirname(__DIR__) . '/');

// The services first: the helpers in framework.php extend them
require MH_ROOT . 'service/dsn_parser.php';
require MH_ROOT . 'service/crypto.php';
require MH_ROOT . 'service/dns_check.php';
require MH_ROOT . 'service/imap_native.php';
require MH_ROOT . 'service/notifier.php';
require MH_ROOT . 'tests/framework.php';

use salvocortesiano\mailhealth\service\dsn_parser;
use salvocortesiano\mailhealth\service\crypto;

$t = new mh_tests();

/* ---------------------------------------------------------------------------
 * Packaging: the mistakes that only show up on a live board
 * ------------------------------------------------------------------------ */

$t->group('Pacchetto');

$composer = json_decode(file_get_contents(MH_ROOT . 'composer.json'), true);
$t->ok(is_array($composer), 'composer.json è JSON valido');
$t->same('phpbb-extension', $composer['type'], 'composer.json dichiara il tipo giusto');
$t->ok(preg_match('/^\d+\.\d+\.\d+$/', $composer['version']), 'la versione ha la forma x.y.z');

$services = file_get_contents(MH_ROOT . 'config/services.yml');

// Every cron task must have a name, otherwise phpBB cannot build the cron
// link and every page of the board dies with a fatal error.
preg_match_all('/^    ([\w.]+):\s*\n(.*?)(?=^    [\w.]+:|\z)/ms', $services, $blocks, PREG_SET_ORDER);
$cron_services = 0;

foreach ($blocks as $block)
{
	if (strpos($block[2], 'name: cron.task') === false)
	{
		continue;
	}

	$cron_services++;
	$named = strpos($block[2], 'set_name') !== false;

	if (!$named && preg_match('/class:\s*([\w\\\\]+)/', $block[2], $m))
	{
		$file = MH_ROOT . str_replace('\\', '/', preg_replace('/^salvocortesiano\\\\mailhealth\\\\/', '', $m[1])) . '.php';
		$named = file_exists($file) && strpos(file_get_contents($file), 'function get_name') !== false;
	}

	$t->ok($named, 'il compito cron ' . $block[1] . ' ha un nome (set_name o get_name)');
}

$t->ok($cron_services > 0, 'almeno un compito cron è dichiarato');

$t->group('Lingue');

// English is the reference for every file: a missing key or a placeholder
// that does not match produces a wrong sentence in front of somebody.
foreach (glob(MH_ROOT . 'language/*', GLOB_ONLYDIR) as $dir)
{
	$iso = basename($dir);

	foreach (['common.php', 'mailhealth_acp.php', 'info_acp_mailhealth.php'] as $name)
	{
		$reference = mh_lang_strings(MH_ROOT . 'language/en/' . $name);
		$strings = mh_lang_strings($dir . '/' . $name);

		$t->same(array_keys($reference), array_keys($strings), $iso . '/' . $name . ': stesse chiavi dell\'inglese');

		$mismatched = [];

		foreach ($reference as $key => $text)
		{
			if (isset($strings[$key]) && mh_placeholders($text) !== mh_placeholders($strings[$key]))
			{
				$mismatched[] = $key;
			}
		}

		$t->same([], $mismatched, $iso . '/' . $name . ': segnaposto coerenti');
	}

	foreach (['mailhealth_verify.txt', 'mailhealth_alert.txt'] as $name)
	{
		$mail = $dir . '/email/' . $name;

		$t->ok(file_exists($mail) && strpos(file_get_contents($mail), 'Subject: ') === 0, $iso . '/' . $name . ': presente, con l\'oggetto sulla prima riga');
		$t->same(mh_mail_vars(MH_ROOT . 'language/en/email/' . $name), mh_mail_vars($mail), $iso . '/' . $name . ': stesse variabili dell\'inglese');
	}

	// Stray characters from another writing system slip in unnoticed
	foreach (glob($dir . '/*.php') as $file)
	{
		$t->ok(!preg_match('/[\x{4e00}-\x{9fff}\x{3040}-\x{30ff}]/u', file_get_contents($file)), $iso . '/' . basename($file) . ': nessun carattere estraneo');
	}
}

$t->group('Pacchetto (seguito)');

/* ---------------------------------------------------------------------------
 * Bounce recognition
 * ------------------------------------------------------------------------ */

$t->group('Riconoscimento dei rimbalzi');

$parser = new dsn_parser();
$n = "\r\n";

$ordinary = "From: Lilith <lilith74@netshadows.de>{$n}Subject: Aggiornamento{$n}{$n}Ho installato la versione 4.97.1, scrivimi a <lilith74@netshadows.de>{$n}";
$t->same(false, $parser->parse($ordinary), 'una e-mail normale con un numero simile a un codice non è un rimbalzo');

$autoreply = "From: lilith74@netshadows.de{$n}Return-Path: <>{$n}Auto-Submitted: auto-replied{$n}Subject: Fuori sede{$n}{$n}Assente fino al 4.10.1{$n}";
$t->same(false, $parser->parse($autoreply), 'una risposta automatica non è un rimbalzo');

$delivered = "From: MAILER-DAEMON@mail.it{$n}Content-Type: multipart/report; report-type=delivery-status{$n}Subject: Delivery Report{$n}{$n}Content-Type: message/delivery-status{$n}{$n}Final-Recipient: rfc822; tizio@libero.it{$n}Action: delivered{$n}Status: 2.0.0{$n}";
$t->same(false, $parser->parse($delivered), 'una notifica di consegna riuscita non è un rimbalzo');

$hard = "From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>{$n}Subject: Delivery Status Notification (Failure){$n}Content-Type: multipart/report; report-type=delivery-status{$n}{$n}Content-Type: message/delivery-status{$n}{$n}Final-Recipient: rfc822; Nessuno@Gmail.com{$n}Action: failed{$n}Status: 5.1.1{$n}Diagnostic-Code: smtp; 550 5.1.1 User unknown{$n}";
$parsed = $parser->parse($hard);
$t->same('nessuno@gmail.com', $parsed['email'], 'il destinatario viene letto e normalizzato in minuscolo');
$t->same(dsn_parser::TYPE_HARD, $parsed['type'], '5.1.1 è un rimbalzo definitivo');

$full = "From: MAILER-DAEMON@relay.it{$n}Subject: Undelivered Mail Returned to Sender{$n}Content-Type: multipart/report; report-type=delivery-status{$n}{$n}Content-Type: message/delivery-status{$n}{$n}Final-Recipient: rfc822; pieno@alice.it{$n}Action: failed{$n}Status: 5.2.2{$n}";
$t->same(dsn_parser::TYPE_SOFT, $parser->parse($full)['type'], 'la casella piena resta temporanea anche con codice 5');

$dns = "From: MAILER-DAEMON@mailgw01.host.it{$n}Subject: Undelivered Mail{$n}Content-Type: multipart/report; report-type=delivery-status{$n}{$n}Content-Type: message/delivery-status{$n}{$n}Final-Recipient: rfc822; tizio@vodafone.it{$n}Action: failed{$n}Status: 5.0.0{$n}Diagnostic-Code: x-error; DNS error resolving vodafone.it{$n}";
$t->same(dsn_parser::TYPE_SOFT, $parser->parse($dns)['type'], 'un errore DNS resta temporaneo');

$exim = "From: Mail Delivery System <Mailer-Daemon@server.it>{$n}X-Failed-Recipients: sparito@tiscali.it{$n}Subject: Mail delivery failed{$n}{$n}  SMTP error: 550 5.1.1 User unknown{$n}";
$t->same('sparito@tiscali.it', $parser->parse($exim)['email'], 'il formato Exim viene riconosciuto');

$italian = "From: postmaster@aruba.it{$n}Subject: Messaggio non consegnato{$n}{$n}Il messaggio per <mario@aruba.it> non è stato consegnato: destinatario sconosciuto{$n}";
$t->same(dsn_parser::TYPE_HARD, $parser->parse($italian)['type'], 'un rimbalzo italiano senza codice viene riconosciuto');

// Header fields fold across lines. Gmail's reply is long enough to be folded,
// and matching one physical line used to cut it at "...you tried to reach does".
$folded = "From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>{$n}Subject: Delivery Status Notification (Failure){$n}Content-Type: multipart/report; report-type=delivery-status{$n}{$n}Content-Type: message/delivery-status{$n}{$n}Final-Recipient: rfc822; tagliato@gmail.com{$n}Action: failed{$n}Status: 5.1.1{$n}Diagnostic-Code: smtp; 550-5.1.1 The email account that you tried to reach does{$n} not exist. Please try double-checking the recipient's email address for typos{$n} or unnecessary spaces.{$n}{$n}";
$parsed = $parser->parse($folded);
$t->same(
	"smtp; 550-5.1.1 The email account that you tried to reach does not exist. Please try double-checking the recipient's email address for typos or unnecessary spaces.",
	$parsed['diagnostic'],
	'una diagnostica ripiegata su piu\' righe viene ricomposta per intero'
);
$t->same(dsn_parser::TYPE_HARD, $parsed['type'], 'la diagnostica ricomposta resta un rimbalzo definitivo');

$single = "From: MAILER-DAEMON@relay.it{$n}Subject: Undelivered Mail{$n}Content-Type: multipart/report; report-type=delivery-status{$n}{$n}Content-Type: message/delivery-status{$n}{$n}Final-Recipient: rfc822; tizio@relay.it{$n}Action: failed{$n}Status: 5.1.1{$n}Diagnostic-Code: smtp; 550 5.1.1 User Unknown (in reply to RCPT command){$n}{$n}";
$t->same(
	'smtp; 550 5.1.1 User Unknown (in reply to RCPT command)',
	$parser->parse($single)['diagnostic'],
	'una diagnostica su una riga sola resta invariata'
);

$t->group('Motivo in chiaro');

$t->same('MAILHEALTH_WHY_FULL', $parser->describe('4.2.2', ''), '4.2.2 è casella piena');
$t->same('MAILHEALTH_WHY_FULL', $parser->describe('5.0.0', 'mailbox over quota'), 'il motivo si ricava anche dal testo del server');
$t->same('MAILHEALTH_WHY_NO_MAILBOX', $parser->describe('5.1.1', ''), '5.1.1 è indirizzo inesistente');
$t->same('MAILHEALTH_WHY_DNS', $parser->describe('5.0.0', 'DNS error'), 'un errore DNS viene riconosciuto');
$t->same('MAILHEALTH_WHY_UNKNOWN', $parser->describe('', ''), 'senza dati non si inventa un motivo');

foreach (['MAILHEALTH_WHY_FULL', 'MAILHEALTH_WHY_NO_MAILBOX', 'MAILHEALTH_WHY_DNS', 'MAILHEALTH_WHY_UNKNOWN'] as $key)
{
	$t->ok(in_array($key, mh_lang_keys(MH_ROOT . 'language/it/mailhealth_acp.php'), true), 'la chiave ' . $key . ' è tradotta');
}

/* ---------------------------------------------------------------------------
 * Stored credentials
 * ------------------------------------------------------------------------ */

$t->group('Cifratura della password');

$dir = sys_get_temp_dir() . '/mh-test-' . getmypid() . '/';
@mkdir($dir, 0777, true);

$c = new crypto($dir, 'php');

if (!$c->is_available())
{
	$t->skip('nessun cifrario disponibile su questo PHP');
}
else
{
	$secret = 'Pàss"w0rd\\€';
	$stored = $c->encrypt($secret);

	$t->ok(strpos($stored, 'mh1:') === 0, 'il valore salvato è riconoscibile');
	$t->ok(strpos($stored, $secret) === false, 'la password non compare in chiaro');
	$t->same($secret, $c->decrypt($stored), 'la password torna identica');

	$tampered = substr($stored, 0, -4) . 'AAAA';
	$t->same(false, (new crypto($dir, 'php'))->decrypt($tampered), 'un valore manomesso viene rifiutato');

	$t->ok($c->key_exists(), 'la chiave viene creata');
	$t->same('store/salvocortesiano_mailhealth/mailhealth_key.php', $c->get_key_display_path(), 'il percorso mostrato è quello leggibile');
	$t->same('0600', substr(sprintf('%o', fileperms($c->get_key_path())), -4), 'il file della chiave non è leggibile da altri');
	$t->same('', $c->decrypt(''), 'un valore vuoto non è un errore');
}

/* ---------------------------------------------------------------------------
 * Sender DNS records
 * ------------------------------------------------------------------------ */

$t->group('Controllo DNS');

$dns_check = new mh_dns_probe();

$t->same('ok', $dns_check->spf('v=spf1 mx -all')[1], 'un SPF chiuso con -all va bene');
$t->same('warn', $dns_check->spf('v=spf1 mx ?all')[1], 'un SPF neutro viene segnalato');
$t->same('fail', $dns_check->spf('v=spf1 +all')[1], 'un SPF che autorizza tutti è un errore');
$t->same('fail', $dns_check->spf(['v=spf1 -all', 'v=spf1 a -all'])[1], 'due record SPF sono un errore');
$t->same('ok', $dns_check->dmarc('v=DMARC1; p=none')[1], 'un DMARC in sola osservazione va bene');
$t->same('fail', $dns_check->dmarc('v=DMARC1; rua=x')[1], 'un DMARC senza politica è un errore');
$t->same('x', $dns_check->selector('x._domainkey.netshadows.de.'), 'dal nome intero si ricava il selettore');
$t->same('default', $dns_check->selector(' Default '), 'il selettore viene ripulito');

/* ---------------------------------------------------------------------------
 * IMAP protocol details
 * ------------------------------------------------------------------------ */

$t->group('Client IMAP');

$imap = new mh_imap_probe();

$t->same('"abc"', $imap->quote('abc'), 'una stringa semplice viene messa fra virgolette');
$t->same('"a\\"b\\\\c"', $imap->quote('a"b\\c'), 'virgolette e barre vengono protette');
$t->ok(is_array($imap->quote('pàss')), 'una password non ASCII viene inviata come letterale');

/* ---------------------------------------------------------------------------
 * Notices to members: what goes out, to whom, in which language
 * ------------------------------------------------------------------------ */

$t->group('Avviso all\'utente (messaggio privato)');

$settings = [
	'mailhealth_pm_enabled'		=> 1,
	'mailhealth_verify_enabled'	=> 1,
	'mailhealth_confirm_days'	=> 30,
	'email_enable'				=> 1,
	'default_lang'				=> 'it',
	'privmsg_disable'			=> 0,
];

$member = ['user_id' => 7, 'username' => 'Pierre', 'user_email' => 'pierre@orange.fr', 'user_lang' => 'fr'];

$probe = new mh_notifier_probe($settings);
$probe->notify_bounced($member);

$t->same(1, count($probe->pms), 'un rimbalzo definitivo produce un messaggio privato');
$t->same(7, $probe->pms[0]['to'], 'il messaggio va al destinatario giusto');
$t->same('MAILHEALTH_PM_BOUNCED_SUBJECT', $probe->pms[0]['subject'], 'usa il testo previsto per l\'oggetto');
$t->ok(strpos($probe->pms[0]['body'], 'pierre@orange.fr') !== false, 'il messaggio contiene l\'indirizzo che non funziona');
$t->ok(strpos($probe->pms[0]['body'], 'ucp.php?i=ucp_profile&mode=reg_details') !== false, 'il messaggio contiene il link per cambiare indirizzo');
$t->same(0, count($probe->mails), 'nessuna e-mail: sarebbe inutile, l\'indirizzo non funziona');

// The language of the recipient, not of the board
$probe = new mh_notifier_probe($settings);
$probe->notify_changed($member, 'pierre@gmail.com');
$t->same('fr', $probe->mails[0]['lang'], 'l\'e-mail di verifica parte nella lingua dell\'utente');

$probe = new mh_notifier_probe($settings);
$probe->notify_changed(['user_id' => 8, 'username' => 'Ivan', 'user_email' => 'a@b.ru', 'user_lang' => 'ru'], 'ivan@yandex.ru');
$t->same('ru', $probe->mails[0]['lang'], 'un altro utente riceve nella propria lingua');

$probe = new mh_notifier_probe($settings);
$probe->notify_changed(['user_id' => 9, 'username' => 'Senza', 'user_email' => 'a@b.it', 'user_lang' => ''], 'nuovo@libero.it');
$t->same('it', $probe->mails[0]['lang'], 'senza lingua nel profilo si usa quella del forum');

$t->group('Verifica del nuovo indirizzo (e-mail)');

$probe = new mh_notifier_probe($settings);
$token = $probe->notify_changed($member, 'pierre@gmail.com');

$t->ok((bool) preg_match('/^[a-f0-9]{32}$/', $token), 'il codice di verifica è casuale e della forma attesa');
$t->same(1, count($probe->mails), 'il cambio di indirizzo produce una e-mail di verifica');
$t->same('pierre@gmail.com', $probe->mails[0]['to'], 'l\'e-mail va al NUOVO indirizzo, non a quello che rimbalzava');
$t->same(30, $probe->mails[0]['vars']['CONFIRM_DAYS'], 'il messaggio dice quanti giorni dura la prova');
$t->ok(strpos($probe->mails[0]['vars']['U_CONFIRM'], $token) !== false, 'il link contiene il codice di verifica');
$t->ok(strpos($probe->mails[0]['vars']['U_CONFIRM'], '/mailhealth/confirm/7/') !== false, 'il link punta alla pagina di conferma di quell\'utente');
$t->same(1, count($probe->pms), 'l\'utente riceve anche un messaggio privato che lo avvisa');

$second = (new mh_notifier_probe($settings))->notify_changed($member, 'pierre@gmail.com');
$t->ok($token !== $second, 'due utenti non ricevono mai lo stesso codice');

$t->group('Le impostazioni vengono rispettate');

$probe = new mh_notifier_probe(['mailhealth_pm_enabled' => 0] + $settings);
$probe->notify_bounced($member);
$t->same(0, count($probe->pms), 'con i messaggi privati disattivati non parte nulla');

$probe = new mh_notifier_probe(['mailhealth_verify_enabled' => 0] + $settings);
$probe->notify_changed($member, 'pierre@gmail.com');
$t->same(0, count($probe->mails), 'con la verifica disattivata non parte l\'e-mail');
$t->same(1, count($probe->pms), 'ma il messaggio privato parte lo stesso');

$probe = new mh_notifier_probe(['email_enable' => 0] + $settings);
$probe->notify_changed($member, 'pierre@gmail.com');
$t->same(0, count($probe->mails), 'con le e-mail del forum spente non si tenta l\'invio');

$probe = new mh_notifier_probe(['privmsg_disable' => 1] + $settings);
$probe->notify_bounced($member);
$t->same(0, count($probe->pms), 'con i messaggi privati disattivati nel forum non si tenta l\'invio');

$t->group('Prova manuale dall\'ACP');

$probe = new mh_notifier_probe($settings);
$result = $probe->send_test($member, 'pierre@orange.fr');

$t->ok($result['pm'] && $result['email'], 'il pulsante di prova invia entrambi i messaggi');
$t->same('fr', $result['lang'], 'la prova usa la lingua di chi la richiede');
$t->same('pierre@orange.fr', $probe->mails[0]['to'], 'la prova arriva a chi l\'ha chiesta');

exit($t->summary());
