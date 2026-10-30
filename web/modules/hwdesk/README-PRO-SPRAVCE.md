# HW Desk — modul pro Drupal 11

Evidence firemního hardwaru: zařízení a jejich parametry, předávání a vracení
s potvrzením jedinečným odkazem, předávací protokoly v PDF, štítky s QR kódem
a příjem zboží. Vlastní vzhled, takže uživatel nechodí do administrace Drupalu.

Modul je samostatný, všechno své drží pod `hwdesk`: tabulky, cesty, oprávnění,
soubory i nastavení. Nic v jádru ani v jiných modulech nemění.

## Co modul potřebuje

- **Drupal 11.4** nebo novější, **PHP 8.3** nebo novější.
- **Už nainstalovaný a běžící web.** Modul se přidává do hotové instalace, sám
  Drupal neinstaluje. V `settings.php` tedy musí být databáze a hlavně
  `$settings['trusted_host_patterns']` — bez něj Drupal požadavky odmítne
  a vypadá to jako chyba modulu.
- **Drush.** Projekt `drupal/recommended-project` ho v sobě nemá:
  `composer require drush/drush`. Bez něj se dají kroky níž udělat i přes
  `/admin/modules` a `/update.php`, ale drush je rychlejší a spolehlivější.
- **Privátní souborový systém, a to ještě před zapnutím modulu.**
  V `settings.php`: `$settings['file_private_path'] = '/cesta/mimo/webroot/private';`
  Ta cesta **musí existovat a být zapisovatelná pro uživatele webserveru už
  v okamžiku zapnutí** — jinak modul sice nastartuje, ale první předávací
  protokol se nemá kam uložit a skončí chybou. Podadresáře
  `hwdesk/attachments`, `hwdesk/invoices` a `hwdesk/protocols` si modul
  vytvoří sám.
- **Moduly jádra:** user, file, options, datetime, views, toolbar (všechny jsou
  v jádru, jen je potřeba mít je zapnuté).
- **Běžící cron.** Modul na něm nestojí, ale vypršení neprovedených výzev
  k převzetí se dělá v cronu: bez něj zůstane zařízení ve stavu „Čeká na
  převzetí“ a v přehledu se počítá jako čekající, i když odkaz z e-mailu už
  dávno propadl (potvrdit se jím nedá, čas se kontroluje při otevření).
  Stačí zapnutý modul jádra **Automated Cron**, nebo cron na systému.
- **Contrib modul [Key](https://www.drupal.org/project/key)** — v něm leží heslo
  k SMTP. Do konfigurace Drupalu se heslo nikdy nezapisuje, takže ho neodnese
  ani export konfigurace, ani záloha konfiguračních souborů.
- **Knihovny přes composer:** `dompdf/dompdf` (PDF protokoly)
  a `chillerlan/php-qrcode` (QR na štítky).

## Instalace

Modul se instaluje rozbalením archivu; na Packagistu není, takže
`composer require bypalda/hwdesk` **nefunguje**. `composer.json` uvnitř modulu
jen vypisuje, co modul potřebuje — slouží k vložení modulu jako `path`
repozitář a jako doklad závislostí, ne k instalaci z internetu.

1. Rozbalte archiv do `web/modules/` (nebo `modules/`, podle toho, kde máte
   webroot). V archivu je adresář `hwdesk`, takže po rozbalení má vzniknout
   `…/modules/hwdesk/hwdesk.info.yml`. Tak je modul vedený i u nás — kdyby vám
   víc vyhovovalo `modules/custom/`, funguje to stejně, Drupal si ho najde.
2. Z korene projektu (tam, kde je `composer.json` webu) dotáhněte závislosti:

       composer require dompdf/dompdf:^3.1 chillerlan/php-qrcode:^6.0 drupal/key:^1.22

3. Ověřte, že privátní cesta z předchozí kapitoly existuje a web do ní smí
   zapisovat. Tohle je nejčastější důvod, proč potom spadne první protokol:

       php -r 'var_dump(is_writable("/cesta/mimo/webroot/private"));'

4. Zapněte modul:

       drush en hwdesk -y

   Zapne se s ním i vlastní vzhled `hwdesk_ui` (jen pro cesty modulu, zbytek
   webu si nechá svoji šablonu).
5. Spusťte aktualizace databáze (zakládá tabulky, lokalitu „Home office“
   a výchozí nastavení):

       drush updb -y
       drush cr

6. Zapněte cron, pokud na webu nejede:

       drush en automated_cron -y

7. V `/hwdesk/settings` vyplňte **základní URL webu**. Odkazy v e-mailech se
   skládají z ní; když se e-maily posílají z cronu nebo z drushe, žádná adresa
   requestu neexistuje a bez tohoto nastavení by odkaz vedl nikam. Drush se
   proto volá s `--uri=https://vas.web`, pokud se posílá pošta z příkazové
   řádky ručně.

## Nastavení po instalaci

Všechno je pod `/hwdesk/settings` (potřebuje oprávnění *Spravovat HW Desk*):

| Kde | Co nastavit |
|---|---|
| `/hwdesk/settings` | Název firmy na protokolech, základní URL webu (jde do odkazů v e-mailu), kopie protokolů na e-mail, platnost výzvy k potvrzení v hodinách |
| `/hwdesk/settings/email` | Odesílatel (např. asistentka@…), odpovídat na, SMTP server, port, šifrování, uživatel a **klíč s heslem** (vybírá se z modulu Key, ne se píše). Je tam i zkouška (tlačítko „Poslat zkušební e-mail“): zprávu pošle, a když se to nepovede, napíše to a konkrétní důvod i místo v kódu zapíše do záznamu chyb webu (`/admin/reports/dblog`, kanál hwdesk) — bez hesla. |
| `/hwdesk/settings/protokol` | Text podmínek převzetí a vrácení (to, s čím zaměstnanec souhlasí), a text zaškrtávacího políčka |
| `/hwdesk/settings/stitky` | Typy zařízení a jejich předpony v inventárním čísle, minimální počet číslic, síťová tiskárna štítků (Zebra, port 9100) |

Heslo k SMTP se zadává takhle: v `/admin/config/system/keys` vytvořte klíč
(doporučený poskytovatel **File** nebo **Environment**, ne *Configuration* —
jen tak se heslo nedostane do exportu konfigurace) a pak ho v nastavení pošty
HW Desku vyberte ze seznamu.

## Oprávnění

| Oprávnění | Komu |
|---|---|
| **Spravovat HW Desk** | IT / správce evidence. Vidí a mění všechno. |
| **Vidět svá zařízení** | Všichni zaměstnanci. Pohled `/hwdesk/my`: jen svá zařízení a své protokoly. |
| **Potvrzovat vlastní předání a vrácení** | Všichni zaměstnanci. |

Potvrzení převzetí přes odkaz z e-mailu (`/hvd/{token}`) **nepotřebuje
přihlášení** — odkaz sám dokládá totožnost, platí jednou a má omezenou
platnost (výchozí 72 hodin, nastavitelné). V databázi se z něj ukládá jen
kontrolní součet, ne odkaz samotný.

## Adresy

| Cesta | K čemu |
|---|---|
| `/hwdesk` | Přehled: počty podle stavu, typu a lokality, co čeká na potvrzení, komu končí záruka, poslední děje |
| `/hwdesk/assets` | Evidence zařízení, filtry, hromadné úpravy, export CSV |
| `/hwdesk/my` | Pohled zaměstnance |
| `/hwdesk/intake` | Příjem zboží (i hromadně, s načítáním výrobních čísel) |
| `/hwdesk/labels` | Listy se štítky (PDF) a tisk na ZPL tiskárnu |
| `/hwdesk/handovers` | Předávky a jejich protokoly |
| `/hwdesk/audit` | Kdo co kdy změnil |
| `/hvd/{token}` | Potvrzení převzetí z e-mailu (bez přihlášení) |
| `/a/{tag}` | Co je to za zařízení — cíl QR kódu na štítku |

## Co modul ukládá

Šest tabulek, všechny s předponou `hwdesk_`: `hwdesk_asset`, `hwdesk_handover`,
`hwdesk_invoice`, `hwdesk_location`, `hwdesk_tag`, `hwdesk_audit`.
Soubory jdou do `private://hwdesk/…`, nikdy do veřejného adresáře.
Nastavení je v konfiguraci `hwdesk.settings` (bez hesel).

## Co je dobré vědět

- **Pošta modulu** chodí vlastním zapisovatelem (`system.mail`,
  `interface.hwdesk`), takže SMTP nastavení HW Desku platí jen pro jeho
  zprávy. Ostatní pošta webu se nemění.
- **Mrtvé položky** se neevidují jako zvláštní stav: zařízení se vyřadí
  (`retired`) nebo označí za ztracené (`lost`) a přestane se počítat do součtů
  a hodnoty majetku.
- **Home office** je běžná lokalita; zakládá se při instalaci, aby se dalo
  rovnou říct, že zařízení je u člověka doma.
- **Cron** modul používá na jedinou věc: zavřít výzvy k převzetí, které nikdo
  nepotvrdil ani neodmítl do nastavené platnosti, a vrátit zařízení do
  původního stavu. Když cron nejede, zařízení zůstane viset na „Čeká na
  převzetí“ (potvrdit se už nedá, platnost se kontroluje při otevření odkazu).
- **Odkazy v e-mailech** se skládají ze základní URL v nastavení. Když je
  prázdná, vezme se adresa z právě probíhajícího požadavku — což v cronu
  a v drushi není žádná. Proto ji vyplňte.
- **Modul mluví česky sám od sebe** — jeho texty jsou česky přímo v kódu,
  žádný překladový soubor k nim není potřeba. Hlášky Drupalu samotného se ale
  řídí překladem jádra: bez nainstalovaného českého překladu zůstanou anglicky
  (typicky „You are not authorized to access this page." na stránce, kam
  uživatel nesmí). Řeší to modul **Interface Translation** a čeština jako jazyk
  webu.
- **Černá lišta Drupalu se kreslí jen tomu, kdo má oprávnění „Access
  administration pages“.** Komu chybí, tomu se nekreslí vůbec — a to je
  zaměstnanec, ale i váš správce HW Desku, jestli mu Drupalí administraci
  nedáte. Důvod: celé administrační menu je pro takového člověka nepřístupné,
  zbyl by mu v liště panel vlastního účtu, a ten se otevírá přes obsah na
  každé stránce (na mobilu přes půl obrazovky). O nic nepřijde, účet
  i odhlášení má v levém sloupci HW Desku. Kdyby lištu chtěl, přidejte jeho
  roli právě „Access administration pages“. Platí to pro lištu z modulu
  Toolbar; pokud web používá novější modul Navigation, řídí se jeho sloupec
  oprávněním `access navigation` a zaměstnancům se nedává.
- **Nepřihlášený člověk nevidí v levém sloupci žádné odkazy.** Je to schválně:
  stránku `/hvd/{token}` otevírá zaměstnanec z e-mailu, do zbytku aplikace
  nemusí mít přístup, a nabídka, která vede jen na „nemáte oprávnění", je
  horší než žádná. Sloupec zůstává kvůli vzhledu.
- **Jádro si držte aktuální.** Modul je vyzkoušený na Drupalu 11.4.7 a nic
  v jádru nemění, takže aktualizaci jádra snese. Jestli mezitím vyšlo něco
  novějšího, neví se tu odsud — podívejte se do `/admin/reports/updates`;
  `composer update drupal/core-recommended --with-dependencies` a potom
  `drush updb -y` je rozumný první krok po nasazení.
