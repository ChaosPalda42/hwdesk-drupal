# OPERATOR — hwdesk-drupal

## Co a proč
HW Desk jako modul Drupalu 11 (`web/modules/hwdesk`): přepis interní
aplikace hwdesk (Flask, ~/projects/hwdesk) do firemního Drupalu, ve kterém
už žijí zaměstnanci (HR). Evidence zařízení (inventární čísla, štítky,
lokality, stav kusu, záruka, faktury, přílohy), přidělování zaměstnancům,
předávací a vratné protokoly potvrzované odkazem z e-mailu (PDF s číslem),
příjem zboží → sériová čísla → štítky s QR (tisk z prohlížeče / ZPL na
Zebru), CSV import/export, přehled „moje zařízení“, audit. Co Drupal umí
sám (uživatelé, přihlášení, role, seznamy s filtry, soubory, JSON:API,
revize), se nepíše znovu.

## Kde jsme
Viz STATE.md. 2026-09-17: prostředí postaveno (PHP 8.5, Drupal 11.4.7,
PHPUnit kernel testy na SQLite ~0,6 s/test, PHPStan + phpstan-drupal level 5),
harness umí PHP (`~/factory/src/factory/phplang.py`). Bench dvou kontraktů
(C-001 TagGenerator — služba s DI a kernel testem, C-002 ZplLabel — čisté PHP
s unit testem) přes lokální modely běží; výsledek rozhodne o coderu.
Referenční implementace obou (operátor) prošly testy i PHPStanem a leží
mimo repozitář, dokud bench neskončí.

## Rozhodnutí
- 2026-09-17 (Michael): Drupal 11, composer, vlastní Linux server, **stejná
  instance jako HR** → zaměstnanci = uživatelé Drupalu, HR sync a vlastní
  přihlášení z Flask verze zanikají; offboarding = zablokovaný účet.
- 2026-09-17 (Michael): modul žije v `web/modules/hwdesk` (ne `custom/`);
  **každá tabulka, kterou modul vytvoří, začíná `hwdesk_`** (entity typy
  `hwdesk_asset`, `hwdesk_invoice`, `hwdesk_handover`, `hwdesk_tag`,
  `hwdesk_location`, `hwdesk_audit`; žádná taxonomie, aby data nebyla v
  cizích tabulkách); **soubory v `private://hwdesk/…`** (přílohy
  `private://hwdesk/attachments/`, protokoly `private://hwdesk/protocols/`).
  Private file system musí být na serveru nastaven (`$settings['file_private_path']`).
- 2026-09-17: rozvržení repozitáře = drupal/recommended-project (composer.json
  v kořeni, `web/` docroot, `vendor/` a `web/core/` mimo git); do produkce se
  nasazuje adresář modulu + `composer require` knihoven, které modul potřebuje.
- 2026-09-17: testy = PHPUnit kernel testy (`Drupal\KernelTests\KernelTestBase`,
  SQLite přes `SIMPLETEST_DB` v `phpunit.xml`) a unit testy
  (`Drupal\Tests\UnitTestCase`); browser testy jen výjimečně (pomalé).
  Metadata testů atributy (`#[Group]`), ne docblocky (PHPUnit 12).
- 2026-09-17: statická brána = `php -l` + PHPStan level 5 s phpstan-drupal
  (`phpstan.neon` v kořeni); `\Drupal::` v třídách je nález → služby přes DI.
- 2026-09-17: dělba práce jako u hwdesku: kontrakty na služby (čistá logika,
  DI, kernel/unit testy), operátor píše glue ručně: entity třídy, formuláře,
  routy/controllery, Views (export z UI), permissions, services.yml, šablony.
- 2026-09-17: architektura modulu:
  - `hwdesk_asset` (revisionable = historie kusu): tag (inventární číslo,
    unikátní), type (klíč z `hwdesk.settings:asset_types`), manufacturer,
    model, serial_number, status (in_stock → pending_handover → assigned →
    pending_return → in_stock; retired/lost ručně), condition
    (new/good/worn/broken), location →`hwdesk_location`, tags →`hwdesk_tag`
    (N:N), invoice →`hwdesk_invoice`, warranty_until, cost_center, price,
    notes, attachments (file, multi), holder → user.
  - `hwdesk_invoice`: number, supplier, issued_on, total, currency,
    attachments, notes. `hwdesk_tag`: name, color. `hwdesk_location`: name, note.
  - `hwdesk_handover`: asset, user, kind (handover|return), status
    (pending|confirmed|rejected|expired|cancelled), requested_by, expires,
    decided, reason, protocol_number (`HP-<rok>-<pořadí>`), protocol_file.
    Potvrzení: odkaz s HMAC tokenem (hash salt Drupalu), platnost
    `handover_token_hours`; potvrdit může jen přihlášený uživatel = adresát.
  - `hwdesk_audit`: kdo, co, kdy (entity_type, entity_id, action, message, uid).
  - Inventární čísla: prefix podle typu (`tag_prefixes`) + pořadí per prefix
    ve State (`hwdesk.tag_seq.<PREFIX>`), formát `NB-0001`; ruční číslo přes
    `reserve()`. Štítek 50×25 mm, QR na `/a/<tag>`.
  - PDF protokol: dompdf z Twig šablony, uložen jako file entity v
    `private://hwdesk/protocols/`. E-maily přes `hook_mail` + `plugin.manager.mail`
    (SMTP je věc webu). Cron: expirace nepotvrzených žádostí.
  - Seznamy: Views nad `hwdesk_asset` s exposed filtry a bulk formulářem
    (core actions), „každé číslo je odkaz na seznam s filtrem“ (Michael).
    JSON:API core modul nahrazuje vlastní REST.
  - Oprávnění: `administer hwdesk` (správce), `view own hwdesk assets`,
    `confirm own hwdesk handovers` (zaměstnanec, role authenticated).

## Pravidla projektu
- Stack: PHP 8.5, Drupal 11.4, PHPUnit 11 (kernel + unit testy), PHPStan level 5 + phpstan-drupal.
- Testy: `php vendor/bin/phpunit -c phpunit.xml` (celá sada) nebo s cestou k jednomu testu.
- Modul `web/modules/hwdesk`, namespace `Drupal\hwdesk`, testy `Drupal\Tests\hwdesk\{Kernel,Unit}`.
- Každý soubor začíná `<?php` + `declare(strict_types=1);`; třídy `final`, konstruktorová promoce, typy všude.
- Služby dostávají závislosti konstruktorem a jsou registrované v `hwdesk.services.yml`; nikdy `\Drupal::` uvnitř tříd.
- Konfigurace: `hwdesk.settings` (`config/install` + `config/schema`); čte se při každém volání, ne v konstruktoru.
- Entity typy a tabulky s prefixem `hwdesk_`; soubory v `private://hwdesk/…`; časy jako UNIX timestamp (int) v entitách, ISO 8601 v exportech.
- E-maily uživatelů malými písmeny; UI česky (`t()` s českým textem), kód, identifikátory a komentáře anglicky.
- Testy: kernel test instaluje jen moduly, které potřebuje (`system`, `user`, `hwdesk`, …) a `installEntitySchema()` pro entity, které používá; metadata atributy (`#[Group('hwdesk')]`).

## Jak spustit
- `uv run factory run --project ~/projects/hwdesk-drupal` — běh Factory
- `uv run factory status --project ~/projects/hwdesk-drupal` — stav
- `uv run factory packets --project ~/projects/hwdesk-drupal` — balíčky; `factory answer <id> …`
- Testy ručně: `cd ~/projects/hwdesk-drupal && php vendor/bin/phpunit -c phpunit.xml`
- PHPStan ručně: `php vendor/bin/phpstan analyse --no-progress web/modules/hwdesk`
- Web lokálně (po `drush site:install`): `php -S localhost:8888 -t web web/.ht.router.php`
