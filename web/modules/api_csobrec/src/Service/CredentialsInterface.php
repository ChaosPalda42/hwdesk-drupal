<?php

declare(strict_types=1);

namespace Drupal\api_csobrec\Service;

/**
 * The credentials of the hand-over: Daktela's token and ČSOB's SFTP.
 *
 * Everything secret this module needs comes through here, from one key of the
 * Key module (type "Authentication (Multivalue)", a JSON object). Nothing is
 * kept in this module's own config, because config is exported to yml and
 * travels between machines and disks.
 *
 * The field names in that JSON are the ones the methods below are named
 * after: daktela_url, daktela_token, sftp_host, sftp_port, sftp_uzivatel,
 * sftp_heslo, sftp_klic.
 *
 * The rule for every implementation: a value must never end up in an
 * exception message, in a log or in a form. That is why missing() answers
 * with field NAMES and never with what is or is not in them.
 */
interface CredentialsInterface {

  /**
   * The id of the key the settings point at, '' when none is chosen.
   */
  public function keyId(): string;

  /**
   * The base URL of the Daktela instance, without a trailing slash.
   */
  public function daktelaUrl(): string;

  /**
   * The API token of Daktela; it travels in the query string.
   */
  public function daktelaToken(): string;

  /**
   * The address of ČSOB's SFTP.
   */
  public function sftpHost(): string;

  /**
   * The port of ČSOB's SFTP; 22 when the key does not say.
   */
  public function sftpPort(): int;

  /**
   * The login name on ČSOB's SFTP.
   */
  public function sftpUser(): string;

  /**
   * The password to ČSOB's SFTP, '' when a private key is used instead.
   */
  public function sftpPassword(): string;

  /**
   * The private key for ČSOB's SFTP in PEM, '' when a password is used.
   */
  public function sftpPrivateKey(): string;

  /**
   * The names of the fields the hand-over cannot run without and does not
   * have.
   *
   * @return array<int, string>
   *   Field names only — never a value, not even a part of one. An empty
   *   array means everything needed is there.
   */
  public function missing(): array;

}
