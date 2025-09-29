# DataPageLister (ProcessWire-Modul)

> **Status: BETA – Nutzung auf eigene Gefahr.**  
> Dieses Modul rendert auf einer **Elternseite** (Container) eine **tabellarische Übersicht aller Kindseiten** – z. B. für Ansprechpartner, Projekte, Termine, Dokumente.  
> Live-Suche (während des Tippens), Filter, Sortierung und Pagination sind integriert. Viele Einstellungen erkennt das Modul **automatisch** (z. B. sichtbare Felder/Spalten).

---

## Was macht das Modul?

- Zeigt im Admin (auf der **Elternseite**) eine Liste aller **direkten Kindseiten**.
- **Live-Suche** (debounced), **Filter** nach Feld, **Sortierung** (Feld/Richtung) und **Pagination**.
- **Bearbeiten**-Link je Zeile; optional **Ansehen**-Link (Frontend).
- Keine verschachtelten Formulare – läuft stabil innerhalb von `ProcessPageEdit`.

---

## Typische Einsatzfälle

- **Adress-/Kontaktverzeichnisse** (z. B. „Ansprechpartner“)
- **Team-/Personenverzeichnisse**, **Projekt-/Angebotslisten**, **Material-/Dokumentenlisten**
- **Event-/Terminlisten** oder andere strukturierte Datensammlungen als Kindseiten

---

## Templates: Was wird benötigt?

Du brauchst **mindestens**:

1. **Eltern-Template (Container)**  
   - Eine Seite mit diesem Template dient als **Container** (z. B. `/ansprechpartner/`).
   - Unter dieser Seite liegen die eigentlichen Datensätze als Kindseiten.

2. **Kind-Template(s) (Daten)**  
   - Ein oder mehrere Templates für die **Daten-Seiten** (z. B. `ansprechpartner`).
   - Felder frei wählbar (z. B. `email`, `phone`, `tags`, …).

> Das Modul erkennt **automatisch**:
> - Sichtbare Spalten aus den vorhandenen Feldern (Whitelist-Logik ist integriert).
> - Sortier- und Suchfelder (Fallback auf `title`, falls ein Feld nicht passt).

---

## Einrichtung & Nutzung

1. **Installieren**  
   - Modul in `site/modules/DataPageLister/` ablegen und in **Modules → Refresh** aktivieren.

2. **Seitenstruktur**  
   - Eine **Elternseite** mit Container-Template erstellen (oder bestehende nutzen).
   - Darunter **Kindseiten** mit dem/den vorgesehenen Kind-Template(s) anlegen.

3. **Anwenden**  
   - Öffne im Admin die **Elternseite** → die **Tabellenansicht** erscheint automatisch.
   - Nutze Suche, Feld-Auswahl, Sortierung und Pagination direkt in der Ansicht.

---

## Hinweise

- **Automatisch erkannt:**  
  Sichtbare Spalten basieren auf den in den Kind-Templates vorhandenen Feldern. Nicht unterstützte/ungeeignete Felder werden übersprungen oder fallbacken auf `title`.
- **Performance:**  
  Für sehr große Datenmengen ggf. Paginierungs-Limit anpassen und Felder übersichtlich halten.
- **Rechte:**  
  Benutzer benötigen Bearbeitungsrechte für den Container/die Kindseiten, um Links/Buttons sinnvoll zu nutzen.

---

## Kompatibilität

- Getestet mit **ProcessWire 3.x** und **AdminThemeUikit**  
- **PHP 8.x** empfohlen

---

## Haftung / Status

- **BETA** – Änderungen am Verhalten und an der API sind möglich.  
- Einsatz **auf eigene Gefahr**; bitte vor Produktivnutzung in einer **Testumgebung** prüfen.

---
