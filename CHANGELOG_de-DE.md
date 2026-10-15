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
