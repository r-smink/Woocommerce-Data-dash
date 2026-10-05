# Bakkerij Productie & Analytics

WordPress-plugin die WooCommerce Analytics laat filteren op **afhaal-/bezorgdatum**
(in plaats van besteldatum) en een **Productie overzicht**-pagina toevoegt.

Gebouwd voor shops die de plugin *Delivery & Pickup Date Time (Pro)* van
CodeRockz gebruiken. Deze plugin leest de order-meta `pickup_date`,
`delivery_date`, `pickup_time`, `delivery_time` en `delivery_type`.

## Installatie

1. Zip de map `bakery-fulfilment-dashboard` (rechtermuisknop → *Verzenden naar →
   Gecomprimeerde map*, of met 7-Zip).
2. WP Admin → **Plugins → Nieuwe plugin → Plugin uploaden** → zip kiezen →
   Installeren → Activeren.
3. Vereisten: WooCommerce actief, en de CodeRockz delivery/pickup-plugin.
4. Werkt met én zonder HPOS (High Performance Order Storage).

## Gebruik

### 1. Productie overzicht (nieuw menu-item)

**WooCommerce → Productie overzicht**

- Kies een dag of periode (knoppen: *Vandaag*, *Morgen*, *Komende 7 dagen*).
- Filter op ordertype (afhalen/bezorgen) en orderstatussen.
- Per dag: tabel met te maken producten (aantal × product, gegroepeerd per
  categorie, aflopend op aantal) + orderlijst met tijdsloten en notities.
- **Printen** geeft een nette keukenbrief (één dag per pagina);
  **CSV-export** downloadt een bestand voor Excel.

### 2. Datumtype-filter in Analytics

In elk Analytics-rapport (Overzicht, Producten, Orders, Omzet, Categorieën,
Variaties, Coupons, Belastingen) staat nu een filter **"Datumtype"**:

- **Besteldatum** (standaard) — ongewijzigd gedrag.
- **Afhaal-/bezorgdatum** — het gekozen datumbereik geldt voor de dag waarop de
  order gepland staat. Orders zonder afhaal-/bezorgdatum tellen mee op hun
  besteldatum.

Zo toont het productenrapport bij "afhaaldatum = vrijdag" welke producten er
vrijdag daadwerkelijk gemaakt moeten worden — ongeacht wanneer ze besteld zijn.

## Hoe het werkt (technisch)

- De afhaaldag wordt berekend als
  `COALESCE(pickup_date, delivery_date, orderdatum)`.
- In Analytics worden de SQL-clauses van de rapportqueries herschreven via
  `woocommerce_analytics_clauses_{type}_{context}`-filters: elke referentie
  naar `date_created`/`date_paid` op de order-lookup-tabellen wordt vervangen
  door die COALESCE-expressie. De rapportcache wordt alleen uitgeschakeld
  zolang het fulfilment-filter actief is.
- Geen build-stap: JS draait via `wp.hooks` filters op
  `woocommerce_admin_*_report_filters`.

## Bekende beperkingen

- Het KPI-kaartje "producten/coupons" (unieke tellingen) op het
  orders-overzicht gebruikt een interne hulpquery zonder clause-filter en blijft
  op besteldatum tellen. De tabellen, grafieken en totalen zijn wél correct.
- Werkt de afhaaldatum-filter niet? Controleer op een order (orderdetails →
  meta/aangepaste velden) of de Pro-versie dezelfde keys gebruikt
  (`pickup_date`, `delivery_date`). Zo niet: pas de constanten in
  `includes/class-bfd-util.php` aan.
