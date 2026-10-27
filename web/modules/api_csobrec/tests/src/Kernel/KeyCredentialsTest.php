<?php

declare(strict_types=1);

namespace Drupal\Tests\api_csobrec\Kernel;

use Drupal\Component\Serialization\Json;
use Drupal\KernelTests\KernelTestBase;
use Drupal\api_csobrec\Service\CredentialsInterface;
use Drupal\key\Entity\Key;
use PHPUnit\Framework\Attributes\Group;

/**
 * The credentials out of one key of the Key module.
 *
 * Why a key and not settings.php: the developer who runs the Drupal asked for
 * it — the Key module is where that installation keeps security data, it holds
 * them as JSON, and the addresses and the login name belong in there with
 * them. Nothing secret is in this module's own config, because config is
 * exported to yml and travels between machines and disks.
 *
 * The other rule this test holds down: a key that is not set up, not there or
 * not JSON is a state the status report names, NEVER an exception in the
 * middle of the morning run and never a value in a message. That is why
 * missing() answers with field names.
 */
#[Group('api_csobrec')]
final class KeyCredentialsTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'file', 'key', 'api_csobrec'];

  /**
   * Every field name this module ever asks a key for.
   */
  private const FIELDS = [
    'daktela_url',
    'daktela_token',
    'sftp_host',
    'sftp_port',
    'sftp_uzivatel',
    'sftp_heslo',
    'sftp_klic',
  ];

  /**
   * What missing() may ever say, and nothing else.
   */
  private const NAMES = [
    'daktela_url',
    'daktela_token',
    'sftp_host',
    'sftp_uzivatel',
    'sftp_heslo nebo sftp_klic',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'api_csobrec']);
  }

  private function credentials(): CredentialsInterface {
    return $this->container->get('api_csobrec.credentials');
  }

  /**
   * Puts a key of the asked-for type in place and points the settings at it.
   *
   * The config provider is the one a test can write; in production it is the
   * file or the environment one, and the README says why.
   *
   * @param string|array<string, mixed> $value
   *   The JSON the key carries, or the fields to encode into it.
   */
  private function key(string|array $value, string $id = 'csob', string $type = 'authentication_multivalue'): void {
    Key::create([
      'id' => $id,
      'label' => 'ČSOB',
      'key_type' => $type,
      'key_type_settings' => [],
      'key_provider' => 'config',
      'key_provider_settings' => ['key_value' => is_array($value) ? Json::encode($value) : $value],
      'key_input' => 'textarea_field',
    ])->save();
    $this->config('api_csobrec.settings')->set('klic', $id)->save();
  }

  public function testNoKeyChosenMeansEmptyValuesAndEveryFieldIsNamed(): void {
    $credentials = $this->credentials();

    $this->assertSame('', $credentials->keyId());
    $this->assertSame('', $credentials->daktelaUrl());
    $this->assertSame('', $credentials->daktelaToken());
    $this->assertSame('', $credentials->sftpHost());
    $this->assertSame('', $credentials->sftpUser());
    $this->assertSame('', $credentials->sftpPassword());
    $this->assertSame('', $credentials->sftpPrivateKey());
    // Without a key there is still a default port: a missing port must not
    // read as 'port 0' anywhere.
    $this->assertSame(22, $credentials->sftpPort());
    $this->assertSame(self::NAMES, $credentials->missing());
  }

  public function testAKeyIdNothingAnswersToIsTheSameAsNone(): void {
    $this->config('api_csobrec.settings')->set('klic', 'neexistuje')->save();
    $credentials = $this->credentials();

    // The id is what the settings say — the status report has to be able to
    // name the key nobody can find.
    $this->assertSame('neexistuje', $credentials->keyId());
    $this->assertSame('', $credentials->daktelaToken());
    $this->assertSame(22, $credentials->sftpPort());
    $this->assertSame(self::NAMES, $credentials->missing());
  }

  public function testTheKeyAnswersEveryFieldAndNothingIsMissing(): void {
    $this->key([
      // The trailing slash is how an operator types an address; the client
      // builds paths onto it, so it must not survive.
      'daktela_url' => 'https://conectart.daktela.com/',
      'daktela_token' => 'TOKEN-Z-KLICE',
      'sftp_host' => 'ext.csobpoj.cz',
      // JSON holds it as a string, and phpseclib takes an int.
      'sftp_port' => '2222',
      'sftp_uzivatel' => 'Conectart',
      'sftp_heslo' => 'heslo-ze-zkousky',
    ]);
    $credentials = $this->credentials();

    $this->assertSame('csob', $credentials->keyId());
    $this->assertSame('https://conectart.daktela.com', $credentials->daktelaUrl());
    $this->assertSame('TOKEN-Z-KLICE', $credentials->daktelaToken());
    $this->assertSame('ext.csobpoj.cz', $credentials->sftpHost());
    $this->assertSame(2222, $credentials->sftpPort());
    $this->assertSame('Conectart', $credentials->sftpUser());
    $this->assertSame('heslo-ze-zkousky', $credentials->sftpPassword());
    $this->assertSame('', $credentials->sftpPrivateKey());
    $this->assertSame([], $credentials->missing());
  }

  public function testAKeyWithoutAPortGetsTwentyTwo(): void {
    $this->key([
      'daktela_url' => 'https://conectart.daktela.com',
      'daktela_token' => 'TOKEN-Z-KLICE',
      'sftp_host' => 'ext.csobpoj.cz',
      'sftp_uzivatel' => 'Conectart',
      'sftp_heslo' => 'heslo-ze-zkousky',
    ]);

    $this->assertSame(22, $this->credentials()->sftpPort());
    $this->assertSame([], $this->credentials()->missing());
  }

  public function testAPrivateKeyCountsInsteadOfAPasswordAndKeepsItsLines(): void {
    $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaA==\n-----END OPENSSH PRIVATE KEY-----";
    $this->key([
      'daktela_url' => 'https://conectart.daktela.com',
      'daktela_token' => 'TOKEN-Z-KLICE',
      'sftp_host' => 'ext.csobpoj.cz',
      'sftp_uzivatel' => 'Conectart',
      // The key itself, not a path to it: the whole point of the Key module is
      // that the secret is in the key and not on our disk.
      'sftp_klic' => $pem . "\n",
    ]);
    $credentials = $this->credentials();

    $this->assertSame('', $credentials->sftpPassword());
    $this->assertSame($pem, $credentials->sftpPrivateKey());
    $this->assertSame([], $credentials->missing());
  }

  public function testOnlyTheFieldsThatAreNotThereAreNamed(): void {
    $this->key([
      'daktela_url' => 'https://conectart.daktela.com',
      'sftp_host' => 'ext.csobpoj.cz',
      'sftp_uzivatel' => 'Conectart',
      'sftp_heslo' => 'heslo-ze-zkousky',
    ]);

    $this->assertSame(['daktela_token'], $this->credentials()->missing());
  }

  public function testAFieldThatIsThereButEmptyCountsAsNotThere(): void {
    $this->key([
      'daktela_url' => 'https://conectart.daktela.com',
      'daktela_token' => '   ',
      'sftp_host' => 'ext.csobpoj.cz',
      'sftp_uzivatel' => '',
      'sftp_heslo' => '',
      'sftp_klic' => '',
    ]);
    $credentials = $this->credentials();

    $this->assertSame('', $credentials->daktelaToken());
    $this->assertSame(['daktela_token', 'sftp_uzivatel', 'sftp_heslo nebo sftp_klic'], $credentials->missing());
  }

  public function testAKeyThatIsNotJsonIsEmptyAndDoesNotThrow(): void {
    $this->key('tohle neni JSON');
    $credentials = $this->credentials();

    $this->assertSame('', $credentials->daktelaToken());
    $this->assertSame('', $credentials->sftpHost());
    $this->assertSame(22, $credentials->sftpPort());
    $this->assertSame(self::NAMES, $credentials->missing());
  }

  public function testAKeyOfTheWrongTypeIsEmptyAndDoesNotLeakItsValue(): void {
    // An operator can pick a plain authentication key, and getKeyValues() then
    // hands over the whole secret as element 0 of a list. Reading by name is
    // what keeps it out of everything.
    $this->key('heslo-ze-zkousky', 'csob', 'authentication');
    $credentials = $this->credentials();

    $this->assertSame('', $credentials->daktelaToken());
    $this->assertSame('', $credentials->sftpPassword());
    $this->assertSame(self::NAMES, $credentials->missing());
  }

  public function testMissingNamesFieldsAndNeverTheirValues(): void {
    $secret = 'TAJNE-heslo-ze-zkousky';
    $this->key([
      'daktela_url' => '',
      'daktela_token' => $secret,
      'sftp_host' => '',
      'sftp_uzivatel' => '',
      'sftp_heslo' => $secret,
    ]);

    $missing = $this->credentials()->missing();
    $this->assertNotSame([], $missing, 'Three fields are empty, so there is something to name.');
    foreach ($missing as $name) {
      $this->assertContains($name, self::NAMES, 'missing() says field names and nothing else.');
      $this->assertStringNotContainsString($secret, $name);
    }
    // And the names it does not say are exactly the fields that are filled.
    $this->assertSame(['daktela_url', 'sftp_host', 'sftp_uzivatel'], $missing);
  }

  public function testEveryFieldNameTheModuleAsksForIsReadByName(): void {
    // One key where every field carries its own name as its value: a getter
    // that reads the wrong field, or reads by position, cannot pass this.
    $fields = [];
    foreach (self::FIELDS as $field) {
      $fields[$field] = $field === 'sftp_port' ? '2222' : 'hodnota-' . $field;
    }
    $this->key($fields);
    $credentials = $this->credentials();

    $this->assertSame('hodnota-daktela_url', $credentials->daktelaUrl());
    $this->assertSame('hodnota-daktela_token', $credentials->daktelaToken());
    $this->assertSame('hodnota-sftp_host', $credentials->sftpHost());
    $this->assertSame(2222, $credentials->sftpPort());
    $this->assertSame('hodnota-sftp_uzivatel', $credentials->sftpUser());
    $this->assertSame('hodnota-sftp_heslo', $credentials->sftpPassword());
    $this->assertSame('hodnota-sftp_klic', $credentials->sftpPrivateKey());
  }

}
