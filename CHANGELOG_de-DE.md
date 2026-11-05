# 1.4.0
- Storno-Abgleich: Eine importierte Bestellung, die danach bei everysize storniert wird, bekommt einen Hinweis nur für den Händler – ein Warnzeichen in der Spalte „everysize“ der Bestellliste, eine Meldung im Reiter „everysize“ der Bestellung und eine Warnung im Protokoll: „Bestellung <Nummer> wurde bei everysize storniert. Bitte nicht versenden und im Shop stornieren.“ Zeigt der Shop die Bestellung schon als versendet (Versanddatum, Sendungsnummer), sagt der Hinweis das und bittet um Prüfung. Ist die Bestellung im Shop schon storniert oder vollständig erstattet, gibt es keinen Hinweis (eine Teilerstattung, z. B. aus Kulanz, zählt nicht), und der Hinweis verschwindet, sobald die Bestellung danach storniert oder vollständig erstattet wird. Der Hinweis steht nicht in der Anmerkung der Bestellung (das ist der Text der Kundin), und es geht keine E-Mail raus. Das Modul ändert dabei nichts (kein Storno, keine Bestandsbuchung, keine Erstattung, keine Meldung an everysize). Es läuft nach Import und Export, betrachtet die letzten 30 Tage (höchstens 500 Zeilen je Lauf, bei mehr die jüngsten), jede Bestellung bekommt den Hinweis genau einmal, und ein Fehler dabei ist höchstens eine Warnung je Tag und Fehlerart im Protokoll (der Rest geht ins OXID-Log) – Import und Export bleiben unberührt. Das Protokoll kennt einen neuen Status „Warnung“

# 1.3.0
- Import-Meldung: Vor dem Schreiben einer Bestellung liest das Modul Shop-Preis und Bestand jedes bestellten Artikels. Abweichungen stehen als Warnung im Protokoll, und everysize erfährt sie einmal je Position. Die Bestellung wird immer zum bei everysize bezahlten Preis importiert; eine gescheiterte Meldung stoppt den Import nicht und wird nicht wiederholt
- Erstattungen abgesichert: Nur eine klare Ablehnung der Erstattungsanfrage selbst (422 PAYPAL_REFUND_FAILED oder REFUND_AMOUNT_INVALID, 400 ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS) gilt als „kein Geld geflossen“; jede andere Antwort (Timeout, 401, 403, 404, 429, 5xx, ...) lässt das Ergebnis unklar, ebenso ein 2xx ohne Refund-ID. Der offene Eintrag (eine Zeile je Bestellung, eigene Tabelle, mit Migration; Einträge des früheren Entwurfs werden übernommen) wird zuerst reserviert, danach wird die PayPal-Liste gelesen und mit ihm gespeichert; eine nicht lesbare oder veränderte Liste (veralteter Tab) oder ein Fehler vor der Anfrage nimmt den neuen Eintrag zurück. Das Senden wird atomar übernommen und ab der letzten Übergabe gezählt (150 s), parallele Klicks senden also einmal, und es kann keine Sperre ohne Ausweg stehen bleiben. „Wiederholen“ liest zuerst die Liste: eine seit dem Anlegen neue, abgeschlossene oder ausstehende Erstattung mit gleichem Betrag (beim „Rest“ jeder), die der Shop nicht kennt, schließt den Eintrag als Erfolg ab; eine alte oder fremde nie. Eine abgelehnte Wiederholung lässt den Eintrag offen (die erste Anfrage kann durchgegangen sein). „Als geklärt markieren“ sendet nichts, löscht nur den Eintrag, den der Händler sah, und erst nach 150 s, und hält die automatische Erstattung fern; der offene Eintrag wird auch bei nicht lesbarer Liste angezeigt. Erfolg: erst die Refund-ID im Eintrag, dann der Bericht, dann das Löschen; ein gescheitertes Löschen führt nie zu einem zweiten Senden. Eine Erstattungsliste gilt nur, wenn jeder Eintrag Refund-ID und Status hat, Beträge werden mit Tausendertrenner gelesen („1,234.56“). „Bezahlt“ im Reiter ist, was everysize abgebucht hat (grossPrice): Ein Positionsstorno in OXID, der die Bestellsumme senkt, versteckt weder den offenen Betrag noch das Formular; bei nicht lesbarer Liste wird das Formular nicht angeboten. Die automatische Erstattung nach einem Storno sendet höchstens einmal: sie fragt Einstellung und alle Bedingungen für genau diese Bestellung neu, wiederholt nie einen offenen Eintrag (sie liest nur die Liste und schließt ihn bei einer neuen passenden Erstattung ab, das Protokoll zeigt Einträge über 15 Minuten), sperrt bei Anzeichen für Versand (Versanddatum, Sendungsnummer), einem in OXID gegebenen Rabatt oder Gutschein oder unlesbarem gezahltem Betrag und schreibt einmal einen Hinweis für Stornos, die sie still übergeht. Fehler werden je Bestellung abgefangen. Eine gescheiterte Versandmeldung wird vor einer Retoure nachgeholt. Ein Rabatt oder Gutschein auf einer Bestellung wird (Bericht DISCOUNT) festgehalten, bevor OXID die Bestellung beim Positionsstorno neu berechnet - die Neuberechnung setzt beide Felder zurück -, die automatische Erstattung bleibt damit dauerhaft aus (neue Erweiterung des Bestellmodells; Update wie in der README). Ein unfertiges Update (Tabelle oder Erweiterung fehlt) schaltet die automatische Erstattung mit einer Fehlermeldung im Protokoll aus. Eine Erstattungsliste mit abgeschlossener oder ausstehender Erstattung ohne lesbaren Betrag gilt als nicht lesbar. Grenze: Eine nur im Shop gebuchte Gutschrift wird nicht erkannt


# 1.2.0
- Reiter „everysize“ neu gegliedert: drei Abschnitte mit Überschrift – everysize-Bestellung als Infotabelle, Erstattung über everysize mit Kacheln (Bezahlt / erstattet / noch offen), Erstattungsliste mit Spaltenköpfen, beschriftetem Formular und Hinweisbox, Rückmeldungen an everysize als Tabelle (Storno und Retoure getrennt benannt, Beträge mit Währung, leere Werte als „–“)
- Zeiten im Reiter und im Protokoll einheitlich formatiert (deutsch 14.10.2026 15:05), in der Zeitzone des Shops
- Versand wird auch gemeldet, wenn die Bestellung zwischen „Jetzt versenden“ und dem nächsten Lauf storniert wurde: erst Versand mit Sendungsnummer, dann Retoure (vorher: Storno `MERCHANT_DECLINE`, Sendungsnummer ging verloren)
- Automatische Erstattung nach Storno vor dem Versand nur noch, wenn über everysize noch gar nichts erstattet wurde (vorher wurde nach einer Teilerstattung im Reiter der Rest automatisch erstattet)

# 1.1.3
- Testumgebung: `EVERYSIZE_CHECKOUT_API_URL` nimmt jede `https://`-Adresse unter `everysize.com` an (ohne Port und Pfad) statt einer festen Liste; die Admin-Seite zeigt dann „Testumgebung“
- Dokumentation und Kommentare überarbeitet

# 1.1.2
- Lizenz: MIT (vorher proprietär)
- Installation über Packagist: `composer require everysize/checkout-oxid`

# 1.1.1
- Hinweis zu den Zugangsdaten nennt den richtigen Ort in everysize Business („Shop-Integration“ → OXID eShop statt „API-Management“)
- Reiter „everysize“: Abstand zwischen den Spalten, Bezeichnung und Wert liefen zusammen

# 1.1.0
- Erstattung aus dem Shop: Button im Reiter „everysize“ der Bestellung, ganz oder teilweise; everysize erstattet die PayPal-Zahlung im Namen des Händlers. Doppelt abgeschickte Formulare erstatten nur einmal
- Storno vor dem Versand wird automatisch voll erstattet (Einstellung, standardmäßig an; nur Stornos ab dem Einschalten)
- Bestellliste: neue Spalte „everysize“ (vor Storno/Löschen) mit der everysize-Bestellnummer, Filter „alle / nur everysize / ohne everysize“ und Suche nach der everysize-Bestellnummer
- Eine nachträglich eingetragene oder korrigierte Sendungsnummer wird bis 14 Tage nach dem Versand einmal nachgemeldet, wenn everysize sie noch nicht hat
- Reiter „everysize“ zeigt die Original-E-Mail-Adresse der Kundin (nur zur Information; Mails des Shops gehen weiter an die everysize-Weiterleitungsadresse)
- Eigener User-Agent `everysizeCheckoutPlugin-OXID/<Version>`
- Update: Modul nach dem Einspielen einmal deaktivieren und wieder aktivieren

# 1.0.0
- Erste Version für OXID eShop CE 7.0 bis 7.5
- Importiert everysize-Bestellungen (mit den bei everysize bezahlten Preisen, Bestandsbuchung, eigener Zahlart) und meldet die Bestellnummer zurück
- Meldet Versand (Versanddatum gesetzt, mit Sendungsnummer und eingestelltem Versanddienstleister), Storno (MERCHANT_DECLINE) und Retoure (RETOUR, Storno nach dem Versand) genau einmal je Position
- Für Bestellungen, die bei everysize bereits storniert sind, wird nichts gemeldet
- Die API-Umgebung lässt sich über die Umgebungsvariable `EVERYSIZE_CHECKOUT_API_URL` auf eine everysize-Testumgebung umstellen; Standard bleibt Produktion, nur bekannte everysize-Adressen werden akzeptiert
- Zugangsdaten liegen in der Shop-Datenbank, nicht in den Konfigurationsdateien der Module
- Admin-Seite mit Zugangsdaten, Verbindungstest und Protokoll; Reiter „everysize“ in der Bestellung
- Konsolenbefehle `everysize:sync`, `everysize:order:import`, `everysize:order:export` für den Cronjob
