# csobrec — ranní předávka nahrávek hovorů ČSOB

Modul do Drupalu, který každé ráno sám předá ČSOB nahrávky hovorů za předchozí
den. Nahrazuje to, co dosud dělala Daktela: hovory se vyberou podle pravidel
ČSOB, nahrávky se stáhnou přes API Daktely, převedou na MP3, složí do dávky
s indexovým XML a nahrají na SFTP ČSOB.

Napsané tak, aby se zdroj dal později vyměnit (VCC Live místo Daktely) bez
přepisování zbytku: o Daktele ví jedna služba.

---

## 1. Co musí umět server

| Co | Proč |
| --- | --- |
| PHP 8.3+ s `gmp` **nebo** `bcmath`, dál `mbstring` a `openssl` | phpseclib je bez gmp/bcmath o řád pomalejší |
| `ffmpeg` v PATH (nebo cesta v nastavení) | převod na MP3 8 kHz / 32 kb/s — ten tvar ČSOB přijímá, není to naše preference |
| composer: `phpseclib/phpseclib:^3.0`, `symfony/process:^7.4` | SFTP a spouštění ffmpegu |
| `$settings['file_private_path']` | nahrávky zákazníků nesmí ležet pod `web/` |
| ~10 GB místa | jeden den je kolem 300 MB, drží se 30 dní, uklízí si samo |
| odchozí 443 na `conectart.daktela.com` a **2222** na `ext.csobpoj.cz` | SFTP ČSOB neběží na obvyklé 22 — tohle prosím projít na firewallu |
| systémový cron (**ne** Drupalový) | viz část 5 |

---

## 2. Instalace

```bash
composer require phpseclib/phpseclib:^3.0 symfony/process:^7.4
# modul do web/modules/custom/csobrec (nebo přes váš deploy)
drush en csobrec
drush cr
```

Do `settings.php` dvě tajemství. **Do configu nepatří** — config se exportuje
do yml a koluje mezi stroji a disky:

```php
$settings['csobrec_daktela_token'] = '…';   // API token Daktely
$settings['csobrec_sftp_heslo']    = '…';   // heslo k SFTP ČSOB
```

Obě hodnoty dostanete jiným kanálem, ne v repozitáři a ne v tomhle souboru.
Místo hesla lze použít privátní klíč — pak se do nastavení vyplní cesta ke
klíči a heslo se neuvádí.

Práva: kdo to nastavuje, potřebuje `administer csobrec`; kdo se jen kouká na
přehled dávek, `view csobrec batches`.

Kontrola, že nic nechybí: **/admin/reports/status**, sekce „Předávka ČSOB" —
vypíše chybějící privátní cestu, nenalezený ffmpeg, nenastavený token, prázdný
seznam front, prázdnou cílovou cestu a vypnuté ověřování klíče serveru.

---

## 3. Nastavení — /admin/config/services/csobrec

**Daktela**

- `URL` — `https://conectart.daktela.com`
- token je v `settings.php`; formulář jen řekne, jestli ho vidí

**Fronty a jejich režim** — jedna fronta na řádek ve tvaru `číslo|režim`:

```
5003|VSE
5004|LOGIKA
```

- `VSE` — předá se **každý** hovor té fronty.
- `LOGIKA` — jen hovor s dispozicí `[CSOB] Nový prodej` nebo
  `[CSOB] Nový prodej TZ` a k němu **starší** historie téhož telefonního čísla
  z téže fronty (pozdější hovory se neberou).

Fronta, která v seznamu není, se Daktely vůbec neptá. Překlep v režimu
formulář odmítne — nesmí tiše znamenat „posílej všechno".

**SFTP ČSOB**

- adresa `ext.csobpoj.cz`, port `2222`, uživatel `Conectart`
- **cílová cesta** na jejich serveru — zbývá doplnit, viz část 8
- **otisk klíče serveru** + „bez ověřeného klíče nic neposílat" — viz část 8

**Provoz**

- hodina předávky — jen pro přehled, běh plánuje cron
- cesta k ffmpegu — prázdné = hledá se v PATH
- po kolika dnech mazat dávky z disku — 30 (`0` = nemazat). Maže se jen disk;
  evidence toho, co ČSOB dostal, se nemaže nikdy.

---

## 4. Drush příkazy

| příkaz | co dělá |
| --- | --- |
| `drush csobrec:vcera` | předá včerejší den — tohle volá cron |
| `drush csobrec:den 2026-10-26` | jeden konkrétní den |
| `drush csobrec:den 2026-10-26 --bez-nahrani` | zkouška: dávku složí na disk, **nic neodejde** a do evidence se nezapíše |
| `drush csobrec:den 2026-10-26 --znovu` | pustí den znovu, i když už odešel (dávka dostane vlastní složku a jméno) |
| `drush csobrec:stav` | co ČSOB má a jak skončila poslední dávka |
| `drush csobrec:ukliz` | smaže z disku dávky starší než lhůta |

Na obrazovku jdou **jen počty a cesty** — žádná telefonní čísla ani jména, aby
se nic citlivého neobjevilo v logu ani v cron mailu.

---

## 5. Ranní běh patří systémovému cronu

Jeden den je stovky nahrávek a stovky megabajtů přes ffmpeg. To nemá co dělat
ve webovém požadavku, takže to **nevisí na `hook_cron`**. Do crontabu uživatele
webserveru:

```cron
5 5 * * * cd /cesta/k/drupalu && ./vendor/bin/drush csobrec:vcera >> /var/log/csobrec.log 2>&1
```

---

## 6. První zkušební běh

```bash
./vendor/bin/drush csobrec:den 2026-10-26 --bez-nahrani
```

Dávka zůstane v `private://csobrec/davky/2026-10-26/<čas>/` — tam je vidět, co
by ČSOB dostal: MP3, indexové XML a prázdný `.don`, který jim říká „dávka je
celá". Tenhle běh se **nezapisuje do evidence**, takže se tentýž den dá potom
poslat normálně.

Přehled běhů je na **/admin/reports/csobrec**: den, jméno dávky, stav, kolik
hovorů, kolik souborů odešlo a případná chyba.

---

## 7. Kde co leží

- `private://csobrec/prac/<den>/<čas>/` — pracovní soubory (stažené nahrávky
  a MP3). Po úspěšném nahrání se **mažou**; po chybě zůstávají, je to to
  jediné, z čeho se dá chyba diagnostikovat.
- `private://csobrec/davky/<den>/<čas>/` — hotová dávka tak, jak odešla.
  Doklad o předání, maže se po 30 dnech.
- tabulka `csobrec_odeslano` — který hovor odešel ve které dávce. Jediná
  pojistka proti druhému odeslání téhož hovoru, takže se **nemaže ani při
  odinstalaci modulu**.
- tabulka `csobrec_davka` — jedna řádka na jeden běh (den, jméno, stav, počty).

Ani jedna z tabulek neobsahuje telefonní čísla ani jména — jen identifikátory
hovorů z Daktely a počty.

---

## 8. Co ještě čekáme od ČSOB

1. **Cílová cesta na jejich SFTP.** Bez ní ostrý běh skončí chybou a tu cestu
   vypíše; nic neodejde. Složku na jejich straně modul schválně **nezakládá**:
   překlep v cestě by jinak dávku „nahrál" někam, kam se nikdo nekouká, běh by
   skončil úspěchem a evidence by tvrdila, že to ČSOB má.
2. **Otisk klíče jejich serveru.** Každý běh si zapíše do logu
   `Otisk klíče serveru ext.csobpoj.cz:2222 je SHA256:…`. Ten otisk si musíme
   nechat potvrdit **od ČSOB**, ne z našeho logu — log by potvrdil i
   podvodníka. Jakmile ho potvrdí, vyplní se v nastavení a zaškrtne se „bez
   ověřeného klíče serveru nic neposílat". Od té chvíle běh při výměně jejich
   klíče spadne a dávka neodejde; to je správné chování, nový otisk se musí
   znovu potvrdit.

---

## 9. Co je zaručené, když něco spadne

Pořadí kroků je schválně takové, aby se nedalo lhát o tom, co ČSOB má:

- **dávka se nahraje dřív, než se zapíše do evidence** — když přenos selže,
  evidence je nedotčená a ráno to zkusí znovu,
- **`.don` jde nahoru jako poslední** — když spojení spadne v polovině, ČSOB
  nedostane značku „dávka je celá" a nezpracuje půlku,
- **nahrávky se z disku mažou až po úspěšném přenosu**,
- **den, který už odešel, se neposílá dvakrát** (jejich import druhou dávku
  téhož dne odmítne); vynutit se to dá jen `--znovu`,
- **jedna rozbitá nahrávka nezdrží celé ráno** — spočítá se jako chyba, zbytek
  dne odejde,
- **klíč serveru se ověří před přihlášením**, ne po něm.

---

## 10. Jak je to uvnitř (pro údržbu)

Každá služba umí jednu věc a je na ni test. Vrstvy odspodu:

| služba | co dělá |
| --- | --- |
| `csobrec.call_mapper` | přeloží aktivitu z Daktely na `Call` (co nejde přečíst, odmítne) |
| `csobrec.selection` | pravidla ČSOB: co se předává a co dostane druhou kopii (X-sell) |
| `csobrec.naming` | jména souborů v dávce |
| `csobrec.index_xml` | indexové XML, bajtově jako to, co ČSOB přijímá |
| `csobrec.daktela` | hovory dne, historie čísla, stažení nahrávky |
| `csobrec.mp3_converter` | ffmpeg v parametrech, které ČSOB přijímá |
| `csobrec.batch_builder` | složení dávky na disku, `.don` jako poslední |
| `csobrec.sent_ledger` | evidence odeslaného a řádka o každém běhu |
| `csobrec.host_key` | otisk klíče serveru a rozhodnutí, jestli se smí poslat |
| `csobrec.sftp_sessions` | spojení přes phpseclib (klíč se ověří **před** přihlášením) |
| `csobrec.sftp_uploader` | nahrání hotové dávky |
| `csobrec.day_run` | jeden den: z Daktely do hotové dávky na disku |
| `csobrec.application` | celé ráno: den + nahrání + evidence + úklid |

Tři pravidla o tom, co nesmí odejít — platí i pro další úpravy:

1. **Klíč serveru se ověřuje před přihlášením.** phpseclib se přihlašuje sám
   při `login()`, takže ověření musí být nad ním, ne za ním.
2. **Token ani heslo nesmí být v textu výjimky.** Token jde Daktele v adrese,
   takže se nepoužívá nic, co by celou adresu vypsalo do chyby; výjimka smí
   jmenovat cestu a návratový kód.
3. **Telefonní číslo nesmí být v textu výjimky ani v logu.** Chyby o příliš
   krátkém čísle mluví o **počtu číslic**, ne o čísle.

### Testy

```bash
./vendor/bin/phpunit -c phpunit.xml --group csobrec
```

167 testů: jednotkové nad pravidly a jmény, kernelové nad celým denním během
i nad celým ránem (nahrání, evidence, úklid, mazání starých dávek).

Falšují se jen okraje: Daktela (odpovědi podle adresy), ffmpeg (closure) a SFTP
(fake sezení). Výběr, jména, XML, mapper, evidence i práce se soubory se testují
doopravdy. **Žádný test nikdy neviděl ostrá data** — fronty i hovory jsou
vymyšlené.
