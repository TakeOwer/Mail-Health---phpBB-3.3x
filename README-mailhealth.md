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
