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
Viz STATE.md. 2026-09-17 večer: **10/10 kontraktů zelených, 63 testů,
PHPStan čistý.** Modul ověřen v prohlížeči na lokálním Drupalu (SQLite,
port 8888, `.claude/launch.json` v ~/factory): přehled → příjem 3 notebooků
→ sériová čísla → štítky s QR → předání zaměstnankyni → e-mail s odkazem →
potvrzení → protokol HP-2026-0001 (PDF, česky) → „Moje zařízení“ → vrácení
zaměstnancem → protokol HP-2026-0002; seznam s filtry a hromadnou akcí,
audit, nastavení, ruční založení, QR přesměrování `/a/<tag>`.
Bench coderů: gpt-oss-120b 2/2 v 1 iteraci; nasazen jako coder.
Čeká na Michaela: nasazení na server (postup v README.md), Zebra tiskárna
(ZPL netestováno na hardwaru), SMTP/mailer webu, překlady core UI
(„Revisions“, „- Any -“ jsou z core; s českým překladem webu zmizí).

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

- 2026-09-17: poučení z ověření v prohlížeči: exposed identifier `q` je
  v Drupalu rezervovaný (filtr se vykreslí dvakrát) → `search`;
  `target_bundles` u entity_reference filtru pro typ bez bundlů musí být
  `null`, `{}` = nic; `hwdesk.services.yml` odkazuje na `file.repository`,
  proto každý kernel test zapíná `file` (a `views`, `options`, `datetime`
  kvůli schématu instalované konfigurace); kernel testy nemají `private://`
  → registruje ho `HwdeskFixturesTrait`; SQLite vrací decimal jako float.
- 2026-09-17: modely napsaly 9 z 10 služeb (DashboardStats operátor —
  model 24 iterací tápal v agregačních dotazech); operátor dopsal ručně
  texty e-mailů, přístup zaměstnance k vrácení (`RequestHandoverForm::access`),
  Offboarding, hook_file_download, Views.

- 2026-09-17 (Michael): veřejný repozitář github.com/ChaosPalda42/hwdesk-drupal
  (celý composer projekt; modul v `web/modules/hwdesk`); firemní specialista
  si ho stáhne a připojí podle README. Před každým pushem: `git status`
  nesmí obsahovat settings.php, DB ani `private/`.

- 2026-09-18: všechny role (coder, tester, triage) na openai/gpt-oss-120b,
  context 65536 — sjednoceno s výchozími rolemi harnessu po benchmarku
  factory-73 (4/4 zelené, 50 s/kontrakt); jiné modely nenahrávat bez benchmarku.

- 2026-09-21: LM Studio odstraněno; továrna běží na llama-serveru
  (http://127.0.0.1:8080/v1, jediný model `qwen3.8-flash-next`). factory.toml
  už nemá [roles.*] — platí výchozí role harnessu. Příští běh (pokud bude)
  je tedy s jiným coderem než ten, který modul napsal; před větší dávkou
  kontraktů udělat `factory bench` na C-001/C-002.

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

## Rozhodnutí 27. 10. 2026 (Michael): předávka nahrávek ČSOB jako druhý modul

Předávka nahrávek pro ČSOB, dosud hotová jako samostatná aplikace v Pythonu
(`~/projects/csob-nahravky`, 14 kontraktů, 171 testů, ověřený vzorek nad
26. 10. 2026), se **přepíše do PHP jako modul `csobrec` téhož Drupalu**.
Michael to chce mít v systému, který IT už spravuje, ne jako druhý stack.

Co z toho plyne a co jsem mu k tomu řekl:

- Drupal běží **na jiném stroji** než kam měla jít předávka. Ten stroj proto
  musí dostat `ffmpeg`, místo na disku (vzorek jednoho dne 307 MB) a odchozí
  přístup na Datelu (443) i na SFTP ČSOB (port 2222, ne 22).
- Nahrávky zákazníků tím začnou ležet na webovém serveru. Jdou do
  `private://csobrec/…`, takže je Drupal neservíruje přímo, ale privátní
  souborový systém na tom stroji **musí** být nastaven (`hook_requirements`
  to hlásí jako chybu, když není).
- Běh **nepatří do webového requestu**: 300 MB stahování a ffmpeg poběží jako
  drush příkaz pod systemd timerem, ne `hook_cron`.
- Python verze zůstává, kde je. Je hotová a ověřená, takže ji IT může nasadit
  hned a předávka pojede; modul ji pak vystřídá. Zahodit ji dřív, než modul
  projede ostrým dnem, by znamenalo, že ČSOB mezitím nedostává nic.

### Kde modul žije a jak se chová

Stejné konvence jako hwdesk: modul v `web/modules/csobrec`, každá tabulka
začíná `csobrec_`, soubory v `private://csobrec/`.

- `csobrec_odeslano` (unikát na `hovor`) je jediná pojistka proti druhému
  odeslání téže nahrávky; `hook_uninstall` ji **nemaže**, jen to zaloguje.
- `csobrec_davka` je přehled pro lidi: den, jméno dávky, kolik hovorů, kolik
  nahráno, bajty, stav, chyba. Žádná telefonní čísla ani jména — platí pravidlo
  „na obrazovku jen počty a cesty".
- **Tajemství nejdou do configu.** Config se exportuje do yml a koluje po
  discích, takže token k Datele a heslo k SFTP se čtou ze `settings.php`
  (`$settings['csobrec_daktela_token']`, `$settings['csobrec_sftp_heslo']`).
  V `csobrec.settings` je jen to, co smí být v exportu: adresa, fronty, hodina,
  host/port/uživatel/cíl, otisk klíče a lhůta na mazání dávek.

### Mapa: co se přepisuje z čeho

| Python | PHP v modulu (kontrakt) |
|---|---|
| `jmena.py` | `Service\Naming` — jména souborů v dávce (C-011) |
| `index_xml.py` | `Service\IndexXml` — indexové XML bajtově stejně (C-012) |
| `vyber.py` | `Service\Selection` — pravidla ČSOB (VSE / LOGIKA) (C-013) |
| `prevodnik.py` | `Service\CallMapper` — aktivita → hovor → řádek XML (C-014) |
| (v Pythonu v `sftp.py`) | `Service\HostKeyCheck` — otisk klíče serveru (C-015) |
| `sftp.py` | `Service\SftpUploader` — `.don` naposled (C-016); spojení otevírá `Sftp\PhpseclibSessionFactory` |
| `prevod.py` | `Service\Mp3Converter` — ffmpeg přes symfony/process (C-017) |
| `daktela.py` | `Service\DaktelaClient` — Guzzle (`http_client`) (C-018) |
| `evidence.py` | `Service\SentLedger` — tabulky výš (C-019) |
| `davka.py` | `Service\BatchBuilder` — dávka na disku (MP3 + XML + `.don`) (C-020) |
| `beh.py` | `Service\DayRun` — jeden den od hovorů k dávce (C-021) |
| `aplikace.py` | `Service\Application` — postav, nahraj, zapiš, ukliď (C-022) |
| `nastaveni.py` | `csobrec.settings` + `Form\SettingsForm` + settings.php |
| `cli.py` | drush `csobrec:den|vcera|stav|ukliz` (`src/Drush/Commands`) |
| `sftp_server.py` | v PHP ekvivalent nemá; `Sftp\PhpseclibSession` se neověřuje testem, ale ručně proti zkušebnímu serveru z Python verze |

Co v Pythonu nebylo a tady je: přehled dávek na `/admin/reports/csobrec`,
nastavení na `/admin/config/services/csobrec` a tři kontroly na
`/admin/reports/status` (privátní cesta, ffmpeg, token, cíl na SFTP, ověřování
klíče).

Ranní běh **nevisí na `hook_cron`** — jeden den jsou stovky nahrávek a stovky
megabajtů přes ffmpeg, to nemá co dělat ve webovém požadavku. Pouští to
systémový cron: `drush csobrec:vcera`.

Tři pravidla o tom, co nesmí odejít, patří do kontraktů, ne do hlav:

- **Otisk klíče serveru se kontroluje PŘED přihlášením.** V paramiku to byla
  skrytá past (`Transport.connect()` se přihlašuje sám), v phpseclib je
  `getServerPublicHostKey()` dostupný před `login()` — a tak to má být:
  při neshodě otisku nesmí heslo vůbec odejít.
- **Token nesmí do výjimky ani do logu.** Je v adrese dotazu, takže chyba smí
  jmenovat jen cestu a návratový kód (v Pythonu kvůli tomu nepoužíváme
  `raise_for_status`; v Guzzle platí totéž o `RequestException::getMessage()`).
- **Telefonní číslo nesmí do textu výjimky.** Dílna napsala do `DaktelaClient`
  hlášku „The number %s has fewer than nine digits" — jmenuje se v ní číslo
  zákazníka a ta hláška jde do logu a na terminál. Opraveno na počet číslic;
  stejné pravidlo platí všude, kde se chyba týká konkrétního hovoru: ven smí
  identifikátor aktivity a počty, ne číslo ani jméno.

Otevřené: po `composer require phpseclib/phpseclib symfony/process` hlásí
PHPStan jeden nález ve **starém** testu hwdesku
(`tests/src/Kernel/ProtocolPdfTest.php:50`, `method.alreadyNarrowedType` —
novější phpstan). Bránu kontraktů to neblokuje (strážce analyzuje jen ten
soubor, který dílna napsala), takže to tu zůstává jako úklid na jindy.
