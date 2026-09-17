# OPERATOR — hwdesk-drupal

## Co a proč
HW Desk jako modul Drupalu 11: evidence hardwaru, předávací protokoly, štítky

## Kde jsme
Viz STATE.md (generuje harness). Poslední shrnutí operátora: —

## Rozhodnutí
- 2026-09-17: projekt založen.

## Pravidla projektu
- Stack: PHP 8.5 / Drupal 11 / PHPUnit kernel tests / PHPStan
- Testy: `php vendor/bin/phpunit -c phpunit.xml`
- Nic nad rámec harnessu; obecná pravidla jsou v ~/factory/docs.

## Jak spustit
- `factory run` — spustí běh (kontrakty → workeři → brány → checkpointy)
- `factory status` — stav, otevřené balíčky
- `factory packets` — balíčky čekající na rozhodnutí; `factory answer <id> …`
