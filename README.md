Mail Health - phpBB 3.3x

![Version](https://img.shields.io/badge/version-1.0.21-105080)
![phpBB](https://img.shields.io/badge/phpBB-3.3.x-377a33)
![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-377a33)
![License](https://img.shields.io/badge/license-GPL--2.0--only-7f7f7f)

Bounce message management and email health for phpBB 3.3.x.
The forum sends emails continuously but knows nothing about their delivery status. Mail Health closes the loop: it reads a dedicated mailbox, recognizes error messages, distinguishes permanent failures from temporary ones, stops sending to dead addresses, and tracks every affected user until the issue is resolved.

Version: 1.0.24
Requirements: phpBB 3.3.0+, PHP 7.2+ (also verified on PHP 8.3)

No server installations required: Mail Health connects to the mailbox on its own.

Installation:
Copy the folder to ext/salvocortesiano/mailhealth/
ACP → Customise → Extension management → Mail Health → Enable
ACP → Extensions → Mail Health → Settings

Updating from a previous version:
phpBB updates an extension's database only when it is enabled. Overwriting files on an active extension is not enough. The correct procedure:
ACP → Customise → Extension management → Mail Health → Disable
(do not click "Delete data", or you will lose your logs, lists, and settings)

Upload the new files, overwriting the old ones.
Enable the extension again.
ACP → General → Purge the cache
If you skip a step, the first row of the Check-up tab will alert you, and in the meantime, the Mail Health cron job will safely remain paused instead of throwing an error.

Minimal configuration:
There is no need to create a new mailbox. phpBB sends every email using the board contact address (General → Email settings), so bounce notifications always return there: Mail Health must read that mailbox. It only deletes messages recognized as bounces and leaves all other email intact.
For bounces to properly return to that address, the forum must send emails via SMTP (General → Email settings → Use SMTP server for email: Yes). Using default hosting mail functions without "Force $from email address" will route bounces to an internal server mailbox where they will go unseen.
In Mail Health settings, enter your incoming mail server parameters, along with the username and password of the forum's email address.
Click Auto-detect: it automatically finds the port and security protocol (SSL/TLS or STARTTLS).
Click Test connection.
Enable bounce management.
Open Check-up: the "Where bounces return" line should be green.

Recommended first week action: set to Log only so you can observe what gets intercepted without modifying user accounts.

The five tabs:
Settings: Mailbox, access mode (automatic, ext-imap, or built-in client), thresholds, retention period, trial period for new addresses, action handling, alerts, DKIM selector, scheduler. Status panels for password encryption and Newsletter integration.
Bounce Log: 12-week chart broken down by permanent vs. temporary failures. Address search, type filtering, pagination, CSV export. Bulk action with three options: add to suppression list, reset address counters, or delete records.
Affected Users: Lists every impacted user and their status, featuring filtering and manual restoration options.
Check-up: Environment, credentials, configuration, data, scheduling, detection, integration. On-demand features: live IMAP login test, immediate cron execution, sender DNS record verification, and downloadable text report for support requests.
Suppression List: Addresses the forum no longer sends emails to. Manual addition, search, pagination, CSV export. Removing an address restores the user's original account settings.

User notices and address verification:
When a user's address stops working, email cannot be used to notify them: the warning is sent as a Private Message containing a link to update their address, remaining safely in their forum inbox. The sender can be selected in settings; if left unspecified, the oldest founder account is used.
As soon as they update their email, a verification email containing a confirmation link is sent to the new address. Opening that link proves the mailbox can receive mail, confirming the address immediately without waiting out the trial period. The link token is a random 32-character code valid for a single address; clicking it multiple times will not trigger an error.

Languages:
Complete translations in Italian, English, French, Spanish, German, and Russian: ACP interface, user-facing text, verification emails, and alert notifications.
Each user receives notices and emails in the language selected in their profile, rather than the board's default language. If a user's language pack is missing, phpBB falls back to English. Admin bounce alerts stay in the default board language.
Note: Translations other than Italian and English have not been reviewed by native speakers. Reviewing them is recommended if you have users in those languages. Language files are located in language/<lang>/.

Most affected domains:
Above the Bounce Log list, a summary table analyzes recipient domains: permanent failures, temporary failures, total count, and unique addresses. A single bouncing address is an isolated user problem; twenty addresses bouncing simultaneously from the same provider indicates a board-wide issue, such as the server IP being blacklisted.

Testing:
php tests/run.php

Tests cover user notifications: PM recipient assignment, verification emails sent to the new address rather than the bouncing one, correct link token generation, profile language targeting, and account deactivation settings. Actual mail delivery is mocked so tests run without an active mail server.
To test actual delivery, use "Send test messages" in the Check-up tab: it sends real test PMs and verification emails in your profile language, reporting if anything was skipped and why.
No dependencies to install: runs anywhere PHP is available, including basic hosting environments. Validates extension packaging (ensuring every cron task is named correctly), bounce detection, human-readable reason mapping, password encryption, SPF/DMARC rules, and IMAP protections. Returns 0 on success and 1 if any test fails.

State Machine:

(normal) ──threshold reached──▶ BOUNCED ──address updated──▶ ADDRESS UPDATED ──N days without bounces──▶ CONFIRMED
                                   ▲                                                              │
                                   └──────── new address bounces too ◀─────────────────────────────┘


BOUNCED: Mass emails and notifications disabled (and account deactivated, if configured). Original user settings are backed up.
ADDRESS UPDATED: As soon as the user — or an administrator — updates the email, the cron job restores original account settings. The new address enters a trial period.
CONFIRMED: Zero bounces registered during the trial period (default: 30 days).

An account deactivated by Mail Health is only reactivated if it remains inactive for that specific reason: if an admin manually deactivated it for another reason in the meantime, it stays deactivated. Founder accounts are never deactivated.

Alerts:
Because phpBB does not log total outbound email volume, an exact bounce percentage cannot be calculated. Alerts trigger based on total bounces received within the last 24 hours (default: 20). Alerts are written to the critical log and optionally emailed to the board contact address (capped at maximum one alert per day).

DNS Checks:
Scans SPF, DKIM, and DMARC records for the sender domain, alongside MX records for the bounce mailbox. Flags missing records, duplicate SPF records (hard error), overly permissive SPF rules (+all), monitor-only DMARC configurations, and revoked DKIM keys. If no DKIM selector is provided, common default selectors are checked.
Note: DNS checks cannot verify if the outbound server IP matches the SPF record due to variable hosting infrastructure routing.

Mailbox Access:
Starting with PHP 8.4, the imap extension is no longer part of PHP core and is omitted by many hosting providers. Mail Health includes a custom socket-based IMAP client tailored for required operations: login, folder selection, reading messages without marking them as read, deleting, and closing connections. It automatically defaults to ext-imap when available, falling back to the built-in client otherwise.

Mailbox Password Security:
Passwords are stored encrypted in the database (sodium_crypto_secretbox or AES-256-GCM via OpenSSL), decrypted strictly upon connection, and immediately cleared from memory.
The encryption key is never stored in the database. It is retrieved in the following order:
From the MAILHEALTH_KEY constant in config.php (32-byte base64 string), if defined;
From store/salvocortesiano_mailhealth/mailhealth_key.php, generated on initial setup.

// Generate a key using PHP: php -r "echo base64_encode(random_bytes(32));"
define('MAILHEALTH_KEY', 'your-base64-key-here');

Security scope: Protects credentials in the event of database leaks. It does not protect against full filesystem compromises, as the key must reside on the server for automated background cron processing.
Lost key recovery: If key decryption fails, the ACP cleanly prompts you to re-enter the mailbox password. Ensure store/salvocortesiano_mailhealth/ is included in your routine backups.

Newsletter Extension Integration:
Mail Health detects the status of salvocortesiano/newsletter (active, disabled, or uninstalled) and displays it under Settings and Check-up. As of Newsletter version 2.8.2, filtering is natively integrated: suppressed addresses are excluded from recipient counts and delivery queues automatically.

Soft-dependency API snippet for other extensions:

if ($phpbb_container->has('salvocortesiano.mailhealth.integration'))
{
	$mh = $phpbb_container->get('salvocortesiano.mailhealth.integration');

	$recipients = $mh->filter_recipients($recipients, 'user_email'); // array or DB rows
	$blocked    = $mh->get_blocked_emails();                         // for SQL filtering
	$count      = $mh->count_blocked();
}


Architectural Design:
No Core Events Modified: Address suppression operates directly on native user_notify and user_allow_massemail fields (and user_type when set to high security), which phpBB respects globally. No core patches required; no update compatibility risks.
Strict Bounce Filtering: Because shared mailboxes contain routine emails, messages are only parsed if they match bounce signatures: sent from system agents (MAILER-DAEMON, postmaster), standard delivery reports, X-Failed-Recipients headers, or typical subject lines ("Undelivered", "Delivery Status Notification"...). Delivery codes must strictly conform to RFC 3463. Delivery success notifications (DSNs) are ignored. All other non-bounce emails remain unread and untouched.
Conservative Hard-Bounce Classification: Uses SMTP error mapping: 5.x.x as permanent, 4.x.x as temporary. Full mailboxes (5.2.2, 5.3.4) and DNS/routing glitches (5.4.x) are safely categorized as temporary failures. If an address is truly abandoned, repeated bounces will hit the temporary threshold regardless.
Performance Optimized: Cron runs during user page visits and must execute swiftly. Mail Health fetches message headers first: routine emails and attachments are never fully downloaded. Bounces cap at 256 KB max payload. Messages process sequentially (~2 MB memory footprint) within a strict 10-second ceiling (or 1/3 of the server max_execution_time). Unprocessed items queue for the next execution cycle.
UID Tracking: Remembers the last processed IMAP Unique Identifier (UID) to read only newly arrived messages sequentially. Large mailboxes with historical mail cause zero performance overhead.
CSV Injection Protection: CSV exports use semicolon delimiters and UTF-8 BOM encoding for compatibility. Cells starting with =, +, -, or @ are sanitized to prevent formula injection when opening exported logs in spreadsheet applications.

Verification & Quality Assurance:
Every core method invocation cross-referenced against phpBB 3.3 source code.
PHP syntax validated against PHP 7.2 minimum up to PHP 8.3 execution environments.
Check-up routines verified on both fresh and migrated database schema states.
Handled error responses for connection failures, invalid credentials, or unreachable hosts tested for clear admin-facing feedback.
Encryption key round-trips, tamper-rejection tests, and file permissions (0600) validated.
RFC 3464 parser tested against various bounce formats (Exim headers, mailbox full notices, delayed delivery warnings, and non-bounce noise).
Built-in socket IMAP client tested against live IMAP servers with non-ASCII credentials and targeted bounce deletions.
State machine logic validated on SQLite database backends across all lifecycle transitions.


# Mail Health — phpBB 3.3

Gestione dei messaggi di mancato recapito (bounce) e salute delle e-mail per phpBB 3.3.x.

Il forum invia e-mail continuamente ma non sa nulla del loro esito. Mail Health chiude il
cerchio: legge una casella dedicata, riconosce i messaggi di errore, distingue i guasti
definitivi da quelli temporanei, smette di scrivere agli indirizzi morti e segue ogni utente
coinvolto finché il problema non è risolto.

- Versione: 1.0.24
- Requisiti: phpBB 3.3.0+, PHP 7.2+ (verificato anche su PHP 8.3)
- Non serve installare nulla sul server: Mail Health si collega alla casella da sola

## Installazione

1. Copia la cartella in `ext/salvocortesiano/mailhealth/`
2. ACP → Personalizza → Gestione estensioni → Mail Health → Attiva
3. ACP → Estensioni → Mail Health → Impostazioni

## Aggiornamento da una versione precedente

phpBB aggiorna il database di un'estensione **solo quando viene attivata**. Caricare i file nuovi
sopra un'estensione attiva non basta. La procedura corretta:

1. ACP → Personalizza → Gestione estensioni → Mail Health → **Disattiva**
   (non "Elimina i dati", altrimenti perdi registro, lista e impostazioni)
2. Carica i file nuovi sovrascrivendo quelli vecchi
3. **Attiva** di nuovo l'estensione
4. ACP → Generale → **Svuota la cache**

Se salti un passaggio, la prima riga della scheda Check-up te lo segnala, e nel frattempo il cron
di Mail Health resta fermo invece di andare in errore.

## Configurazione minima

**Non serve creare una casella nuova.** phpBB spedisce ogni e-mail con mittente l'indirizzo
e-mail del forum (Generale → Impostazioni e-mail), quindi i rimbalzi tornano sempre lì: Mail
Health deve leggere **quella** casella. Cancella solo i messaggi che riconosce come rimbalzi e
lascia intatta tutta l'altra posta.

Perché i rimbalzi tornino davvero a quell'indirizzo, il forum deve spedire tramite **SMTP**
(Generale → Impostazioni e-mail → Usa un server SMTP: Sì). Con la funzione di posta
dell'hosting e senza «Forza indirizzo mittente», i rimbalzi finiscono in una casella interna
del server e nessuno li vedrà mai.

1. Nelle impostazioni di Mail Health inserisci il server della posta in arrivo, e come nome
   utente e password quelli dell'indirizzo e-mail del forum
2. Premi **Rileva automaticamente**: trova da solo porta e protezione (SSL/TLS o STARTTLS)
3. Premi **Prova la connessione**
4. Attiva la gestione dei rimbalzi
5. Apri il **Check-up**: la riga «Dove tornano i rimbalzi» deve essere verde

Prima settimana consigliata con l'azione impostata su **Solo registrazione**: osservi cosa
intercetta senza toccare nessun account.

## Le cinque schede

**Impostazioni.** Casella, accesso (automatico, ext-imap o client integrato), soglie, periodo
di conservazione, periodo di prova dei nuovi indirizzi, azione, allarmi, selettore DKIM,
pianificazione. Riquadri di stato per cifratura della password e integrazione con la Newsletter.

**Registro rimbalzi.** Grafico delle ultime 12 settimane, diviso fra definitivi e
temporanei. Ricerca per indirizzo, filtro per tipo, paginazione, esportazione CSV.
Selezione multipla con tre azioni: aggiungi alla lista di soppressione, azzera i contatori degli
indirizzi, elimina i record.

**Utenti interessati.** Ogni utente coinvolto e il suo stato, con filtro e ripristino manuale.

**Check-up.** Ambiente, credenziali, configurazione, dati, pianificazione, riconoscimento,
integrazione. Su richiesta: login IMAP reale, esecuzione immediata del cron, controllo dei record
DNS del mittente, download del referto in testo da allegare alle richieste di supporto.

**Lista di soppressione.** Indirizzi a cui il forum non scrive più. Aggiunta manuale, ricerca,
paginazione, esportazione CSV. Togliere un indirizzo restituisce all'utente le sue impostazioni.

## Avvisi all'utente e verifica dell'indirizzo

Quando l'indirizzo di un utente smette di funzionare, l'e-mail è proprio ciò che non si può
usare: l'avviso parte come **messaggio privato**, con il link per aggiornare l'indirizzo, e resta
nella sua posta in arrivo. Il mittente si sceglie nelle impostazioni; se non lo indichi viene
usato il fondatore più vecchio.

Appena cambia indirizzo, al **nuovo** indirizzo parte un'e-mail con un link di conferma. Aprire
quel link dimostra che la casella riceve davvero, quindi l'indirizzo viene confermato subito
senza aspettare il periodo di prova. Il codice del link è casuale, di 32 caratteri, valido per
un solo indirizzo, e premerlo due volte non dà errore.

## Lingue

**Italiano, inglese, francese, spagnolo, tedesco e russo, complete**: interfaccia ACP, testi
visti dagli utenti, e-mail di verifica ed e-mail di allarme.

Ogni utente riceve avvisi ed e-mail nella lingua scelta nel proprio profilo, non in quella del
forum. Se manca la lingua di un utente, phpBB ripiega sull'inglese, non sulla lingua del forum.
L'allarme rimbalzi che arriva all'amministratore resta nella lingua predefinita del forum.

Le traduzioni oltre a italiano e inglese non sono state riviste da madrelingua: se hai utenti in
quelle lingue, una loro rilettura è utile. I file sono in `language/<lingua>/`.

## Domini più colpiti

Nel registro, sopra l'elenco, una tabella riassume i domini dei destinatari: definitivi,
temporanei, totale e quanti indirizzi distinti. Un indirizzo che rimbalza è un problema
dell'utente; venti indirizzi dello stesso provider che rimbalzano insieme sono un problema del
forum, per esempio l'IP del server finito in una lista nera.

## Test

```
php tests/run.php
```

Comprende gli avvisi agli utenti: a chi va il messaggio privato, che l'e-mail di verifica parta
verso il **nuovo** indirizzo e non verso quello che rimbalzava, che il link contenga il codice
giusto, che ogni utente riceva nella lingua del proprio profilo e che le impostazioni di
disattivazione vengano rispettate. La consegna vera e propria è l'unico punto sostituito, così
i test girano senza server di posta.

Per provare la consegna reale, nella scheda Check-up c'è **«Inviami i messaggi di prova»**: invia
a te stesso il messaggio privato e l'e-mail di verifica con i testi veri, nella lingua del tuo
profilo, e dice se qualcosa è stato saltato e perché.

Nessuna dipendenza da installare: gira ovunque ci sia PHP, anche sull'hosting. Controlla il
pacchetto (compreso che ogni compito cron abbia un nome, l'errore che nel settembre 2026 ha
riempito di errori fatali un forum vero), il riconoscimento dei rimbalzi, i motivi in chiaro,
la cifratura della password, le regole SPF e DMARC e le protezioni del protocollo IMAP.
Restituisce 0 se è tutto a posto e 1 se qualcosa fallisce.

## La macchina a stati

```
(normale) ──soglia raggiunta──▶ RIMBALZATO ──indirizzo cambiato──▶ INDIRIZZO CAMBIATO ──N giorni senza rimbalzi──▶ CONFERMATO
                                    ▲                                         │
                                    └──────── rimbalza anche il nuovo ◀───────┘
```

- **Rimbalzato**: notifiche ed e-mail di massa disattivate (e, se scelto, account disattivato).
  I valori precedenti dell'utente vengono memorizzati.
- **Indirizzo cambiato**: appena l'utente — o un amministratore — cambia indirizzo, il cron
  restituisce le impostazioni originali. Il nuovo indirizzo resta in prova.
- **Confermato**: nessun rimbalzo per il periodo di prova (predefinito 30 giorni).

Un account disattivato da Mail Health viene riattivato solo se è ancora inattivo *per quel motivo*:
se nel frattempo un amministratore l'ha disattivato per altro, resta com'è. I fondatori non
vengono mai disattivati.

## Allarmi

phpBB non conta le e-mail che invia, quindi una vera *percentuale* di rimbalzi non è
calcolabile. L'allarme scatta sul numero di rimbalzi ricevuti nelle ultime 24 ore (predefinito
20), viene scritto nel registro critico e, se scelto, inviato via e-mail all'indirizzo di
contatto del forum. Al massimo uno al giorno.

## Controllo DNS

Legge SPF, DKIM, DMARC del dominio mittente e l'MX della casella dei rimbalzi. Segnala record
mancanti, SPF doppi (errore permanente), SPF permissivi (`+all`), DMARC in sola osservazione,
chiavi DKIM revocate. Se il selettore DKIM non è impostato prova quelli più comuni.

Non dice se l'IP da cui parte la posta è coperto dall'SPF: dipende dal percorso della posta
dentro l'hosting e l'estensione non può saperlo, quindi non lo afferma.

## Accesso alla casella

Dal PHP 8.4 l'estensione `imap` non fa più parte di PHP e molti hosting non la offrono più.
Mail Health include un client IMAP scritto su socket che fa esattamente ciò che serve: login,
selezione della cartella, lettura dei messaggi senza segnarli come letti, cancellazione,
chiusura. In automatico usa `ext-imap` se c'è, altrimenti il client integrato.

## Password della casella

Salvata **cifrata** nel database (`sodium_crypto_secretbox`, oppure AES-256-GCM via OpenSSL),
decifrata solo al momento della connessione e azzerata subito dopo.

**La chiave non sta nel database.** Viene letta, in quest'ordine:

1. dalla costante `MAILHEALTH_KEY` in `config.php` (base64 di 32 byte), se definita;
2. da `store/salvocortesiano_mailhealth/mailhealth_key.php`, generata al primo salvataggio.

```php
// php -r "echo base64_encode(random_bytes(32));"
define('MAILHEALTH_KEY', 'la-chiave-base64-qui');
```

**Cosa protegge e cosa no.** Difende da chi ottiene una copia del *solo database*. Non difende
da chi ha accesso al filesystem, perché la chiave deve stare sullo stesso server affinché il
cron possa girare da solo.

**Se perdi la chiave** la decifratura fallisce in modo pulito e l'ACP chiede di reinserire la
password. Includi `store/salvocortesiano_mailhealth/` nei backup.

## Integrazione con l'estensione Newsletter

Mail Health rileva lo stato di `salvocortesiano/newsletter` (attiva, disattivata, assente) e lo
mostra nelle Impostazioni e nel Check-up. Dalla Newsletter 2.8.2 il filtro è già integrato: gli
indirizzi in lista di soppressione vengono esclusi sia dal conteggio dei destinatari sia dalla
coda di invio. Senza Mail Health la Newsletter funziona identica.

API per altre estensioni, sempre come dipendenza morbida:

```php
if ($phpbb_container->has('salvocortesiano.mailhealth.integration'))
{
	$mh = $phpbb_container->get('salvocortesiano.mailhealth.integration');

	$recipients = $mh->filter_recipients($recipients, 'user_email'); // lista o righe
	$blocked    = $mh->get_blocked_emails();                         // per filtri SQL
	$count      = $mh->count_blocked();
}
```

## Scelte progettuali

**Niente eventi del core.** La soppressione agisce sui campi nativi `user_notify` e
`user_allow_massemail` (e su `user_type` al livello più alto), che phpBB rispetta ovunque invii
posta. Nessuna patch, nessun rischio agli aggiornamenti.

**Solo i veri rimbalzi.** La casella letta è spesso quella di tutti i giorni, piena di posta
normale. Un messaggio viene esaminato solo se ha l'aspetto di un rimbalzo: inviato da un sistema
di posta (MAILER-DAEMON, postmaster), con un rapporto di recapito standard, con l'header
`X-Failed-Recipients` o con un oggetto tipico ("Undelivered", "Mancato recapito"…). I codici
devono essere validi secondo l'RFC 3463. Le notifiche di consegna riuscita vengono ignorate.
Tutto il resto non viene né registrato né cancellato.

**Prudenza sul definitivo.** La classe SMTP comanda: `5.x.x` definitivo, `4.x.x` temporaneo. Sono
trattati come temporanei anche le caselle piene (`5.2.2`, `5.3.4`) e gli errori DNS o di
instradamento (`5.4.x`), che quasi sempre sono un intoppo momentaneo: se l'indirizzo è davvero
morto, i rimbalzi continuano e la soglia dei temporanei lo intercetta comunque.

**Leggero sul forum.** Il cron del forum gira dentro la visita di un utente, quindi deve durare
poco. Di ogni messaggio Mail Health scarica prima solo l'intestazione: la posta normale non viene
mai scaricata per intero, allegati compresi, e dei rimbalzi prende al massimo i primi 256 KB. I
messaggi sono elaborati uno alla volta (memoria costante, circa 2 MB) ed entro un tempo massimo:
10 secondi, o un terzo del limite dell'hosting se è più basso. Quello che resta si fa al giro
dopo. L'ora dell'esecuzione viene annotata all'inizio, così un'eventuale interruzione non fa
ripartire il lavoro a ogni pagina visitata; il Check-up segnala le esecuzioni interrotte.

**Solo i messaggi nuovi.** Mail Health ricorda l'identificativo IMAP (UID) dell'ultimo messaggio
esaminato e a ogni esecuzione legge solo quelli arrivati dopo, dal più vecchio. La posta
normale non viene riletta, e in una casella con migliaia di messaggi nessun rimbalzo viene
saltato. Se si cambia casella, o il server ricrea la cartella, la lettura riparte da capo.

**Esportazioni sicure.** Il CSV usa il punto e virgola e il BOM UTF-8 per Excel in italiano. Le
celle che iniziano con `= + - @` vengono neutralizzate: il testo diagnostico arriva da server di
posta esterni e non deve poter diventare una formula all'apertura del file.

## Verifiche fatte

- Ogni metodo del core chiamato dall'estensione confrontato con il sorgente di phpBB 3.3.17
- Sintassi di tutti i file PHP con un interprete PHP 8.3, nessuna sintassi successiva a PHP 7.2
- Scheda Check-up eseguita dall'inizio alla fine, con database aggiornato e non aggiornato
- Messaggi di errore del collegamento (password errata, server irraggiungibile, dati mancanti)
  verificati così come appaiono all'amministratore
- Cifratura: andata e ritorno, rifiuto di un valore manomesso, permessi 0600 del file chiave
- Parser: RFC 3464, casella piena, `delayed`, header Exim, testo italiano, messaggi non-rimbalzo
- Client IMAP integrato contro un server IMAP di prova: login con password non ASCII, lettura,
  cancellazione del solo rimbalzo, chiusura
- Macchina a stati su database SQLite: tutte le transizioni, fondatore protetto, allarme
  limitato a uno al giorno, registro amministrativo disattivabile
- Regole SPF e DMARC su record di esempio
- Testi: ogni chiave usata esiste in inglese e in italiano con gli stessi insiemi nei due pacchetti,
  nessun testo fisso nei template, nessuna frase in chiaro inviata a video dal codice
- Template: blocchi `IF`/`BEGIN` bilanciati

## Da fare prima della pubblicazione sul CDB

- Passare l'estensione all'**EPV** (non installabile nell'ambiente in cui è stata scritta)
- Prova completa su un forum reale, incluso almeno un ciclo cron con una casella vera
- Eventuali traduzioni oltre a inglese e italiano
