# everysize Checkout — Modul für OXID eShop 7

Bindet einen OXID-Shop an den everysize-Direktkauf an: Bestellungen, die
Kundinnen auf everysize.com aufgeben, landen als Bestellung im Shop;
Bestellnummer, Versand, Storno, Retoure und Erstattungen gehen zurück an
everysize.

Composer-Paket `everysize/checkout-oxid`, Modul-ID `everysize_checkout`.

## Voraussetzungen

* OXID eShop CE 7.0 bis 7.5 (getestet mit 7.0.5/PHP 8.1 und 7.5.1/PHP 8.4)
* PHP ≥ 8.0 mit `curl`
* ein Cronjob (OXID hat keinen eigenen Scheduler)
* Admin-Seiten nur mit dem Twig-Admin. OXID 7.0 mit dem optionalen
  Smarty-Admin zeigt die Seiten nicht an; der Abgleich läuft trotzdem.

Kein PayPal-Modul nötig: bezahlt wird bei everysize. Erstattet wird im
Reiter „everysize“ der Bestellung über everysize (ab 1.1.0) oder direkt im
PayPal-Konto des Händlers.

## Ablauf

| Richtung | Was | Wann |
|---|---|---|
| everysize → Shop | neue Bestellungen (`PROCESSING`, auch bereits stornierte `REVOKED`) ohne Händler-Bestellnummer | jeder Lauf |
| Shop → everysize | OXID-Bestellnummer | direkt nach dem Import |
| Shop → everysize | Versand mit Sendungsnummer und Versanddienstleister | Versanddatum gesetzt („Jetzt versenden“), auch wenn die Bestellung inzwischen storniert ist |
| Shop → everysize | Sendungsnummer nachgetragen oder korrigiert | bis 14 Tage nach der Versandmeldung, nur wenn everysize sie noch nicht hat |
| Shop → everysize | Erstattung (ganz oder teilweise) | Button im Reiter „everysize“ |
| Shop → everysize | volle Erstattung | automatisch nach einem Storno **vor** dem Versand (abschaltbar) |
| Shop → everysize | Storno `MERCHANT_DECLINE` | Position oder Bestellung storniert, **bevor** der Versand gemeldet war |
| Shop → everysize | Retoure `RETOUR` | Position oder Bestellung storniert, **nachdem** der Versand gemeldet war |

* **Zuordnung:** Bestellpositionen über `sku` = OXID-**Artikelnummer**
  (`OXARTNUM`) der Variante. Exakter Treffer vor Groß-/Kleinschreibung,
  aktiver vor inaktivem Artikel; zwei gleich gute Treffer sind ein Fehler.
* **Preise:** Bestellung mit den bei everysize bezahlten Bruttopreisen.
  Netto und Mehrwertsteuer rechnet das Modul mit dem Steuersatz aus OXID
  zurück (Artikel → Kategorie → Standard).
* **Bestand:** wird beim Import reduziert, ein Storno in OXID bucht ihn wie
  gewohnt zurück.
* **Kunde:** `customer.email` ist eine Weiterleitungsadresse von everysize,
  stabil je Kundin; ein Wiederkauf landet am selben (Gast-)Konto. Mails des
  Shops gehen dorthin und laufen über everysize. Die Original-Adresse
  (`customer.originalEmail`) steht nur zur
  Information im Reiter „everysize“ (`everysize_checkout_order.ORIGINAL_EMAIL`).
* **Zahlart:** eigene Zahlart „everysize (PayPal)“, beim Aktivieren angelegt
  und keiner Versandart zugeordnet, also im Shop nie wählbar. Die
  PayPal-Capture-ID steht in `OXTRANSID` und im Reiter „everysize“.
* **Atomar:** Kunde, Bestellung, Positionen, Bestand und Verknüpfung entstehen
  in einer Transaktion; ein Fehler hinterlässt nichts, die Bestellung bleibt
  bei everysize und wird im nächsten Lauf erneut versucht.
* **Genau einmal:** jede Meldung steht mit eindeutigem Schlüssel (Bestellung,
  Art, SKU) in `everysize_checkout_report`; ein zweiter Lauf meldet nichts
  doppelt.
* **Storno bei everysize:** vor jeder Meldung fragt das Modul den Stand bei
  everysize ab. Ist die Bestellung dort schon storniert (PayPal-Erstattung,
  everysize Business), meldet es nichts: ein Versand würde sie auf
  `COMPLETED` zurücksetzen, ein Storno den Grund von everysize überschreiben.
  Beim Versand steht das als Fehler im Protokoll.
* **Sendungsnummer nachmelden:** jede gemeldete Nummer steht als Rückmeldung
  `tracking` (Nummer in der SKU-Spalte). Steht in OXID später eine andere
  Nummer, geht sie einmal per erneuter Versandmeldung raus — bis 14 Tage nach
  dem Versand, nur bei `COMPLETED` und nur, wenn everysize sie nicht schon hat
  (dann nur gemerkt, „known“). everysize nimmt die wiederholte Meldung als
  Aktualisierung; die Kundin bekommt keine Mail.
* **Versand und Storno im selben Lauf:** Ist eine Bestellung versendet
  (Versanddatum gesetzt) und vor dem nächsten Lauf schon storniert, meldet das
  Modul erst den Versand mit Sendungsnummer und im selben Lauf die Retoure
  (`RETOUR`) — nicht `MERCHANT_DECLINE`, und die Sendungsnummer geht nicht
  verloren.
* **Reiter „everysize“:** drei Abschnitte — die everysize-Bestellung
  (Bestellnummer, Import, Meldung der Bestellnummer, Capture-ID,
  Original-E-Mail), die Erstattung über everysize (Bezahlt / erstattet / noch
  offen, Liste der Erstattungen, Formular) und die Rückmeldungen an everysize
  als Tabelle. Zeiten in der Zeitzone des Shops (PHP-Standardzeitzone), auch
  im Protokoll.
* **Erstattung:** Reiter „everysize“ → Betrag (vorbelegt mit dem noch offenen
  Betrag) → „Erstatten“. everysize erstattet die PayPal-Zahlung im Namen des
  Händlers (External API `POST …/orders/{id}/refunds`); jedes Formular trägt
  einen eigenen `Idempotency-Key`, ein doppelt abgeschicktes Formular erstattet
  einmal.
* **Automatische Erstattung:** Nach einem gemeldeten Storno **vor** dem Versand
  (`MERCHANT_DECLINE`) erstattet das Modul im nächsten Lauf den vollen Betrag,
  sobald keine Position der Bestellung mehr aktiv ist (eine everysize-Bestellung
  hat ein Produkt; ein im Admin hinzugefügter Artikel verhindert die Erstattung)
  — es wurde nichts versendet, der Kundin steht alles zu. Nur, wenn über
  everysize noch **gar nichts** erstattet wurde (weder automatisch noch im
  Reiter): nach einer bewussten Teilerstattung wird der Rest nicht obendrauf
  gezahlt. Retouren nicht (der
  Händler erstattet nach Wareneingang, ggf. mit Abzug). Standard: an
  (Einstellung „Storno vor dem Versand automatisch erstatten“); erstattet
  werden nur Stornos ab dem Einschalten (`autoRefundSince`, beim Update per
  Migration gesetzt), höchstens 14 Tage zurück. Fester `Idempotency-Key`
  `auto-decline-<everysize-ID>` je Bestellung; Verbindungs- und Serverfehler
  versucht der nächste Lauf erneut, eine Ablehnung (nicht erstattbar, PayPal
  lehnt ab) steht einmal im Protokoll und im Reiter.
* **Bestellliste:** Spalte „everysize“ (vor den Storno-/Löschen-Symbolen) mit der everysize-Bestellnummer,
  in der Filterzeile „alle / nur everysize / ohne everysize“ und die Suche
  nach der everysize-Nummer (Erweiterung von `OrderList` und
  `views/twig/extensions/themes/admin_twig/order_list.html.twig`).
* **Protokoll:** nur Läufe, die etwas übertragen haben oder fehlgeschlagen
  sind; 31 Tage.
* **Selbstauskunft:** jeder API-Aufruf trägt `ERP-Shop-System: OXID`, die
  OXID-Version, `Interface-Version` (Modulversion) und den User-Agent
  `everysizeCheckoutPlugin-OXID/<Version>`.

Eingehende Endpunkte gibt es keine — der Checkout ruft Shops nicht auf.

## API-Umgebung

Standard ist **immer Produktion** (`https://ext-checkout.everysize.com`) —
eine Installation beim Händler braucht dafür nichts einzustellen.

Für Test- und Entwicklungsshops nennt everysize auf Anfrage die Adresse
einer Testumgebung samt Test-Zugangsdaten. Sie wird in der Umgebung des
PHP-Prozesses gesetzt (Webserver **und** Cron); OXID 7.5 lädt zusätzlich die
`.env`/`.env.local` im Projektverzeichnis (7.0 noch nicht):

```dotenv
EVERYSIZE_CHECKOUT_API_URL=https://<von everysize genannt>.everysize.com
```

Erlaubt ist nur `https://` mit einem Host unter `everysize.com`, ohne Port und
Pfad. Ein anderer Wert ist ein Fehler — es gibt bewusst **keinen** stillen
Rückfall auf Produktion, damit ein Testshop mit Tippfehler keine Bestellungen
an den Live-Checkout schickt. Bewusst keine Einstellung im Admin. Die aktive
Umgebung zeigt die Admin-Seite an.

## Installation

Im Projektverzeichnis des Shops, aus [Packagist](https://packagist.org/packages/everysize/checkout-oxid):

```bash
composer require everysize/checkout-oxid
vendor/bin/oe-console oe:module:install vendor/everysize/checkout-oxid
vendor/bin/oe-console oe:module:activate everysize_checkout   # legt Tabellen und Zahlart an
```

Ohne Packagist: ZIP aus everysize Business (Shop-Integration → OXID eShop)
nach `./modules/` entpacken und als Path-Repository einbinden, dann wie oben:

```bash
composer config repositories.everysize-checkout '{"type": "path", "url": "./modules/everysize-checkout", "options": {"symlink": false}}'
```

Update: `composer update everysize/checkout-oxid` (beim ZIP vorher die
Quellen ersetzen), `vendor/bin/oe-console oe:module:install vendor/everysize/checkout-oxid`,
dann Modul **deaktivieren und wieder aktivieren**
(`oe:module:deactivate` / `oe:module:activate everysize_checkout`): das
führt die Migrationen aus und übernimmt neue Klassenerweiterungen (ab 1.1.0
die Bestellliste). Deaktivieren löscht keine Daten.

## Einrichtung

1. Admin → **Bestellungen → everysize Checkout**: Client-ID und Client-Secret
   aus everysize Business (Shop-Integration → OXID eShop) eintragen, **Verbindung testen**,
   Versandart und Versanddienstleister wählen, speichern.
2. Cronjob, empfohlen alle 5 Minuten:

   ```cron
   */5 * * * * cd /pfad/zum/shop && vendor/bin/oe-console everysize:sync
   ```

   `everysize:order:import` und `everysize:order:export` laufen je eine
   Hälfte (Test, Fehlersuche). Zwei Läufe gleichzeitig verhindert eine
   MySQL-Sperre (`GET_LOCK`).

Die Zugangsdaten liegen in der Shop-Datenbank (`everysize_checkout_settings`),
nicht in OXIDs Modul-Einstellungen: die stehen in `var/configuration/*.yaml`,
und das Verzeichnis gehört laut OXID in die Versionsverwaltung — das Secret
landete im Git des Händlers. Ohne Zugangsdaten macht das Modul keine
API-Aufrufe.

Unter OXID 7.0 mit PHP 8.1 gibt der OXID-Kern selbst viele
PHP-Deprecations aus (z. B. `ListModel`); OXID empfiehlt, sie per
`error_reporting` auszublenden — für den Cron z. B.
`php -d error_reporting=24575 vendor/bin/oe-console everysize:sync`.

## Entwicklung

```bash
composer install
vendor/bin/phpunit                                   # Unit-Tests (reine Logik, ohne OXID)
docker run --rm -v "$PWD":/app -w /app php:8.0-cli vendor/bin/phpunit   # Untergrenze PHP 8.0
```

Der Code muss unter PHP 8.0 laufen (OXID 7.0): keine Enums, keine
`readonly`-Properties.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
