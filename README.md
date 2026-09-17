# HW Desk — modul pro Drupal 11

Správa firemního hardwaru přímo ve firemním Drupalu: evidence zařízení
(inventární čísla, štítky, lokality, stav kusu, záruka, faktury, přílohy),
předávání zaměstnancům s potvrzením odkazem z e-mailu a PDF protokolem,
příjem zboží → sériová čísla → štítky s QR (tisk z prohlížeče nebo ZPL na
Zebru), CSV import/export, „Moje zařízení“ pro každého zaměstnance, audit.
Zaměstnanci jsou uživatelé Drupalu; zablokovaný účet (offboarding) pošle
správcům seznam zařízení k vrácení.

Modul: `web/modules/hwdesk`. Všechny tabulky začínají `hwdesk_`, soubory
leží v `private://hwdesk/…`.

## Nasazení do existujícího Drupalu 11

1. Knihovny (v kořeni composer projektu):

       composer require dompdf/dompdf:^3.1 chillerlan/php-qrcode:^6.0

2. Zkopírovat adresář `web/modules/hwdesk` do `web/modules/hwdesk` cílového webu
   (nebo jako git subtree / symlink z tohoto repozitáře).

3. Privátní souborový systém: v `settings.php`
   `$settings['file_private_path'] = '/var/www/private';` (adresář mimo
   docroot, zapisovatelný pro PHP). Bez něj se přílohy a protokoly neuloží;
   stav webu (`/admin/reports/status`) to hlásí.

4. Zapnout modul: `drush en hwdesk` (zapne i `file`, `options`, `datetime`,
   `views`). Instalace přidělí roli *authenticated* oprávnění „Vidět svá
   zařízení“ a „Potvrzovat vlastní předání a vrácení“.

5. Správci: přidělit oprávnění **Spravovat HW Desk** (`administer hwdesk`)
   roli správců hardwaru.

6. Nastavení `/admin/config/hwdesk`: typy zařízení s prefixy inventárních
   čísel, název firmy na protokolech, kopie protokolů na e-mail, základní
   URL (pro QR kódy a odkazy v e-mailech), adresa síťové tiskárny štítků.

7. Cron webu musí běžet (expirace nepotvrzených výzev; `hook_cron`).

8. E-maily odcházejí přes mailer webu (core PHP mail, `symfony_mailer`,
   `smtp` …). JSON:API modul (core) vystaví entity `hwdesk_*` bez dalšího
   kódu — oprávnění platí stejná jako v UI.

## Odkud kam

| kde | co |
|---|---|
| `/hwdesk` | přehled (každé číslo je odkaz na seznam s filtrem) |
| `/hwdesk/assets` | seznam zařízení: filtry, hromadné akce (štítky, vyřadit, ztraceno, zpět na sklad), export |
| `/hwdesk/asset/add`, `/hwdesk/intake` | jedno zařízení / příjem N kusů → sériová čísla → štítky |
| `/hwdesk/asset/{id}` | detail, historie (revize), Předat / vrátit, štítek |
| `/hwdesk/confirm/{token}` | stránka z e-mailu: potvrdit / odmítnout |
| `/hwdesk/my` | zaměstnanec: co má u sebe a co má potvrdit |
| `/hwdesk/handovers`, `/hwdesk/audit`, `/hwdesk/invoices` | předání, audit, faktury |
| `/a/{tag}` | cíl QR kódu na štítku |
| `/admin/config/hwdesk` (+ štítky, lokality) | nastavení a číselníky |

## Vývoj

    composer install
    php vendor/bin/phpunit -c phpunit.xml          # kernel + unit testy (SQLite)
    php vendor/bin/phpstan analyse web/modules/hwdesk

Lokální web pro klikání: `drush site:install minimal --db-url=sqlite://sites/default/files/.ht.sqlite`,
`drush en hwdesk`, `php -S localhost:8888 -t web web/.ht.router.php`.
