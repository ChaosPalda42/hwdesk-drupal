<?php

declare(strict_types=1);

namespace Drupal\Tests\hwdesk\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Šablony se píšou rukou a žádná brána nad markupem nerozhodne — tahle ale
 * rozhodne aspoň o tom, že jsou přeložitelné. Nic nerenderuje, jen parsuje:
 * najde neuzavřený blok, překlep v názvu filtru nebo funkce a špatné závorky.
 * Chyba v Twigu se jinak pozná teprve bílou stránkou v prohlížeči.
 */
#[Group('hwdesk')]
final class TemplatesParseTest extends UnitTestCase {

  /**
   * Funkce a filtry, které do Twigu přidává Drupal.
   */
  private function twig(): Environment {
    $twig = new Environment(new ArrayLoader([]), ['cache' => FALSE, 'autoescape' => 'html', 'debug' => TRUE]);
    foreach (['path', 'url', 'link', 'file_url', 'attach_library', 'active_theme', 'render_var', 'create_attribute'] as $funkce) {
      $twig->addFunction(new TwigFunction($funkce, fn(...$a) => ''));
    }
    $twig->addFunction(new TwigFunction('t', fn(...$a) => '', ['is_safe' => ['html']]));
    foreach (['t', 'trans', 'placeholder', 'clean_class', 'clean_id', 'format_date', 'safe_join', 'without', 'render', 'add_suggestion', 'drupal_escape'] as $filtr) {
      $twig->addFilter(new TwigFilter($filtr, fn(...$a) => ''));
    }
    return $twig;
  }

  /**
   * @return array<int, array<int, string>>
   */
  public static function sablony(): array {
    $koren = dirname(__DIR__, 3);
    $nalezene = array_merge(
      glob($koren . '/templates/*.twig') ?: [],
      glob($koren . '/themes/*/templates/*.twig') ?: [],
    );
    sort($nalezene);
    return array_map(static fn(string $cesta): array => [$cesta], $nalezene);
  }

  public function testNejakeSablonyVubecJsou(): void {
    $this->assertGreaterThan(10, count(self::sablony()), 'Nula šablon z nuly nalezených vypadá jako pořádek.');
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('sablony')]
  public function testSablonaSePrelozi(string $cesta): void {
    $twig = $this->twig();
    $jmeno = basename($cesta);
    try {
      $twig->parse($twig->tokenize(new Source((string) file_get_contents($cesta), $jmeno, $cesta)));
    }
    catch (\Twig\Error\Error $e) {
      $this->fail(sprintf('%s: %s (řádek %d)', $jmeno, $e->getRawMessage(), $e->getTemplateLine()));
    }
    $this->assertTrue(TRUE);
  }

}
