---
agent: devin-local
session: rural-raft
created: 2026-10-06T06:28:15Z
---
# Plan: WooCommerce + POS-backoffice combineren in één productie/orderdashboard

Uitbreiden van de bestaande plugin met een importeer-, merge- en historiemodule die dagelijks (handmatig en via WP-Cron) een backoffice/POS-CSV binnenhaalt en samenvoegt met het WooCommerce-afhaal-/bezorgoverzicht, met alle data in eigen database-tabellen.

## Huidige situatie

De bestaande plugin (`bakery-fulfilment-dashboard`) heeft twee functies:

1. **Analytics-datumfilter** – schakelt WooCommerce Analytics om van besteldatum naar afhaal-/bezorgdatum via meta-keys `pickup_date` / `delivery_date`.
2. **Productie overzicht** – adminpagina onder *WooCommerce → Productie overzicht* die per dag toont:
   - welke producten gemaakt moeten worden (gegroepeerd per categorie);
   - welke orders met tijdslot en notities afgehaald/bezorgd moeten worden;
   - met print- en CSV-export.

De nieuwe vraag is om een **derde laag** toe te voegen: een backoffice/POS-export (CSV) importeren en samenvoegen met bovenstaande WooCommerce-data, zodat er één dashboard ontstaat met de **volledige orderverwerking van de dag** (webshop + fysieke verkoop), met automatische dagelijkse verwerking en historie in de database.

## Doelstelling

- Een **generieke CSV-import** voor de backoffice/POS-export (FTP-map of handmatige upload).
- **Eigen database-tabellen** voor geïmporteerde POS-data en samenvoegresultaten, zodat historie bewaard blijft.
- **Dagelijkse automatisering** via WP-Cron (met handmatige trigger als fallback).
- Een **nieuw admin-dashboard** dat per dag toont:
  - WooCommerce-webshoporders op afhaal-/bezorgdatum;
  - POS/backoffice orders/regels;
  - gecombineerde totalen per product en per order.
- CSV-export van het gecombineerde resultaat.

## Belangrijke aannames en openheid

Omdat de concrete structuur van de backoffice-export en de koppelmethode nog niet bekend is, wordt het systeem **configurabel** opgezet:

- Kolom-mapping (datum, ordernummer, productnaam, aantal, bedrag, betaalstatus, etc.) via admin-instellingen.
- Productmatching via SKU/artikelnummer, productnaam of een mapping-tabel.
- Ordermatching via ordernummer, e-mail, of datum+bedrag.
- Ruwe importbestanden kunnen na verwerking verwijderd worden; verwerkte data blijft in de database.

## Architectuur

### Database-tabellen (WordPress `wpdb`)

1. **`{prefix}_bfd_pos_imports`**
   - `id`, `import_date`, `source` (FTP/handmatig), `filename`, `raw_rows`, `status`, `created_at`.
   - Eén rij per geïmporteerd bestand.

2. **`{prefix}_bfd_pos_lines`**
   - `id`, `import_id`, `pos_order_id`, `line_date`, `product_sku`, `product_name`, `quantity`, `total`, `payment_status`, `payment_method`, `customer_email`, `raw_data` (JSON).
   - Elke regel uit de CSV.

3. **`{prefix}_bfd_daily_summary`**
   - `summary_date`, `source` (woocommerce/pos/merged), `product_sku`, `product_name`, `category`, `total_qty`, `order_count`, `total_value`, `last_updated`.
   - Voor snelle dagelijkse overzichten en historie.

### Backend-klassen (nieuwe files)

- `includes/class-bfd-importer.php` – verantwoordelijk voor CSV-inlezen, valideren, database-inserts en ruwe bestanden opruimen.
- `includes/class-bfd-ftp-client.php` – veilige FTP/SFTP-verbinding, bestand downloaden, eventueel alleen nieuwe bestanden.
- `includes/class-bfd-merger.php` – haalt WooCommerce-orders op (hergebruik logica uit `class-bfd-production-page.php`) en combineert deze met POS-regels.
- `includes/class-bfd-cron.php` – registreert WP-Cron hooks en biedt handmatige trigger via admin.
- `includes/class-bfd-admin-settings.php` – instellingenpagina voor FTP-pad, mapping, matching en opschoning.
- `includes/class-bfd-combined-dashboard.php` – nieuw admin-dashboard dat de samenvoeging toont.

### Frontend / admin

- Nieuw menu-item: **WooCommerce → Combinatie overzicht** (naast *Productie overzicht*).
- Filters: datum, type (alles/webshop/POS), productcategorie, zoeken op order/product.
- Tabellen:
  - Producten per dag (aantal, categorie, bron).
  - Orders per dag (tijdslot, klant, bron, betaalstatus).
- Knoppen: *Importeer nu*, *Merge nu*, *Download CSV*, *Bekijk historie*.

## Implementatiestappen

### 1. Database schema aanmaken
- Voeg een `register_activation_hook` en `bfd_maybe_update_db()` toe in het hoofdbestand.
- Maak de drie tabellen hierboven met `dbDelta()`.

### 2. Instellingenpagina
- Nieuwe klasse `BFD_Admin_Settings` onder *WooCommerce → Instellingen → Bakkerij merge* of een eigen submenu.
- Velden:
  - FTP/SFTP host, gebruikersnaam, wachtwoord/private key, pad/masker (bijv. `export_*.csv`).
  - Handmatig uploadveld.
  - Kolom-mapping (dropdowns: datum, ordernummer, productnaam, product SKU, aantal, bedrag, betaalstatus, betaalmethode, klant e-mail).
  - Matching-modus (product: SKU/naam/mapping-tabel; order: ordernummer/e-mail/datum+bedrag).
  - Automatisch verwijderen van ruwe importbestanden na verwerking (ja/nee).
  - Cron-tijdstip.

### 3. Importer
- `BFD_Importer::upload_and_parse( $file )` voor handmatige uploads.
- `BFD_Importer::import_from_path( $path, $source )` voor FTP-gedownloade bestanden.
- Validatie: minimaal verplichte kolommen, datumformaat herkenning (`d-m-Y`, `Y-m-d`, etc.).
- Insert in `bfd_pos_imports` + `bfd_pos_lines`.
- Opschoning: na succes verwijderen van het ruwe bestand indien ingesteld.

### 4. FTP-client
- `BFD_FTP_Client::fetch_latest()` maakt verbinding, zoekt het nieuwste bestand op basis van masker, downloadt naar een tijdelijke map en retourneert pad.
- Ondersteuning voor FTP en SFTP (via `phpseclib` indien beschikbaar, anders `ftp_*` functies). Voor de eerste iteratie kan pure FTP voldoende zijn.

### 5. Merger
- `BFD_Merger::get_combined_day( $date )`:
  - Haalt WooCommerce-orders op voor die dag via bestaande `BFD_Util::order_fulfilment_date()` logica.
  - Haalt POS-regels op voor die dag uit `bfd_pos_lines`.
  - Combineert producttotalen per SKU/naam + categorie.
  - Combineert orderlijst met bron-indicatie (webshop/POS).
- Update `bfd_daily_summary` zodat historie snel opvraagbaar is.

### 6. WP-Cron automatisering
- `BFD_Cron::init()` registreert een custom cron interval (bijv. `daily_at_01:00`).
- Hook `bfd_daily_pos_import`: download FTP-bestand → importeer → merge → mail/log bij fouten.
- Hook `bfd_daily_pos_summary`: bouwt samenvattingen op voor komende dagen.
- Admin-knop "Voer import/taak nu uit" triggert dezelfde functies handmatig.

### 7. Nieuw dashboard "Combinatie overzicht"
- `BFD_Combined_Dashboard` toont per dag de gecombineerde tabellen.
- Hergebruikt zoveel mogelijk styling uit `assets/css/production.css`.
- CSV-export van gecombineerd resultaat.

### 8. Herstructurering bestaande code
- Extraheer de orderophaal- en organisatie-logica uit `class-bfd-production-page.php` naar een nieuwe helper `BFD_Production_Data` zodat `BFD_Production_Page` en `BFD_Merger` dezelfde code gebruiken.
- Dit voorkomt dubbele queries en bugs.

### 9. Foutafhandeling en logging
- Eigen tabel of WordPress `WP_DEBUG_LOG` gebruik voor import-/FTP-fouten.
- Admin-notice bij mislukte cron of handmatige import.

### 10. Beveiliging
- Capabilities: `edit_shop_orders` of `manage_woocommerce`.
- Nonces voor handmatige uploads en triggers.
- FTP-wachtwoord opslaan met `wp_hash_password` of encryptie via WordPress constants; geen plaintext in opties.

## Files die gewijzigd of toegevoegd worden

### Wijzigen
- `bakery-fulfilment-dashboard.php`
  - Activation hook voor database-tabellen.
  - Laden van nieuwe klassen.
- `includes/class-bfd-production-page.php`
  - Refactor data-ophaallogica naar `BFD_Production_Data`.

### Toevoegen
- `includes/class-bfd-admin-settings.php`
- `includes/class-bfd-importer.php`
- `includes/class-bfd-ftp-client.php`
- `includes/class-bfd-merger.php`
- `includes/class-bfd-cron.php`
- `includes/class-bfd-combined-dashboard.php`
- `includes/class-bfd-production-data.php` (geëxtraheerde data-laag)
- `assets/css/combined-dashboard.css` (indien nodig, anders hergebruik van bestaande CSS)

## Verificatie

- [ ] Plugin activeert zonder fatal errors; tabellen worden aangemaakt.
- [ ] Handmatige upload van een test-CSV slaat regels op in `bfd_pos_lines`.
- [ ] FTP-download (met een testserver of lokale stub) werkt en start de import.
- [ ] Productie overzicht blijft ongewijzigd functioneren na refactor.
- [ ] Combinatie overzicht toont correcte totalen voor een testdag.
- [ ] WP-Cron hook kan handmatig getriggerd worden en produceert geen fouten.
- [ ] CSV-export van het gecombineerde overzicht is leesbaar in Excel.

## Risico's / aandachtspunten

- **Backoffice-export is nog onbekend**: zonder voorbeeld moeten we een zeer generieke mapping bouwen. De eerste werkende versie kan minder geavanceerd zijn dan een latere versie.
- **Product- en ordermatching**: zonder duidelijke koppelsleutel kan matching op naam foutgevoelig zijn. Een mapping-tabel in de database is nodig als SKU's niet overeenkomen.
- **Grote datasets**: dagelijks importeren van veel POS-regels kan traag worden. We moeten importeren in batches en indexen toevoegen op datum/SKU.
- **FTP-beveiliging**: wachtwoorden moeten versleuteld opgeslagen worden. SFTP/private-key wordt aanbevolen.
- **Dataverlies**: "ruwe bestanden verwijderen na verwerking" betekent dat de database de enige bron wordt; regelmatige backups zijn essentieel.
- **WP-Cron betrouwbaarheid**: op lage-traffic sites loopt WP-Cron niet exact op tijd. Een externe cron die `wp-cron.php` aanroept is aanbevolen.

## Volgende actie na goedkeuring

Zodra het plan goed is, begin ik met stap 1 en 2 (database schema + instellingenpagina) en werk dan stapsgewijs verder. Ik zou daarbij graag zo snel mogelijk een voorbeeld-CSV ontvangen zodat de mapping echt getest kan worden.
