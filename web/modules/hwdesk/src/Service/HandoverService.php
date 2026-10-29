<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\hwdesk\AssetStatus;
use Drupal\hwdesk\Entity\Asset;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\user\UserInterface;

/**
 * The handover/return workflow: request, e-mail, confirm, protocol.
 */
final class HandoverService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly HandoverTokens $tokens,
    private readonly ProtocolNumbers $protocolNumbers,
    private readonly ProtocolPdf $protocolPdf,
    private readonly MailManagerInterface $mailManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * The open (pending) request for this asset, if any.
   */
  public function openFor(Asset $asset): ?Handover {
    $storage = $this->entityTypeManager->getStorage('hwdesk_handover');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('asset', $asset->id())
      ->condition('status', HandoverStatus::Pending->value)
      ->range(0, 1)
      ->execute();
    if ($ids === []) {
      return NULL;
    }
    $handover = $storage->load(reset($ids));
    return $handover instanceof Handover ? $handover : NULL;
  }

  /**
   * Opens a handover or return request and mails the employee.
   */
  public function request(Asset $asset, UserInterface $user, HandoverKind $kind, AccountInterface $requestedBy): Handover {
    if ($this->openFor($asset) !== NULL) {
      throw new \DomainException('Pro toto zařízení už čeká na potvrzení jiná žádost.');
    }
    if ($kind === HandoverKind::Handover) {
      if ($asset->getStatus() !== AssetStatus::InStock) {
        throw new \DomainException('Předat lze jen zařízení, které je skladem.');
      }
    }
    else {
      if ($asset->getStatus() !== AssetStatus::Assigned || (int) $asset->getHolder()?->id() !== (int) $user->id()) {
        throw new \DomainException('Vrátit může jen zaměstnanec, kterému je zařízení přiděleno.');
      }
    }

    $config = $this->configFactory->get('hwdesk.settings');
    $now = $this->time->getRequestTime();
    $expires = $now + (int) $config->get('handover_token_hours') * 3600;

    $created = $this->entityTypeManager->getStorage('hwdesk_handover')->create([
      'asset' => $asset->id(),
      'user' => $user->id(),
      'kind' => $kind->value,
      'status' => HandoverStatus::Pending->value,
      'requested_by' => $requestedBy->id(),
      'expires' => $expires,
    ]);
    if (!$created instanceof Handover) {
      throw new \LogicException('The hwdesk_handover entity could not be created.');
    }
    $created->save();
    $handover = $created;

    if ($kind === HandoverKind::Handover) {
      $asset->setStatus(AssetStatus::PendingHandover)->setHolder($user)->save();
    }
    else {
      $asset->setStatus(AssetStatus::PendingReturn)->save();
    }

    $key = $kind === HandoverKind::Handover ? 'handover_request' : 'return_request';
    $subject = $kind === HandoverKind::Handover
      ? sprintf('Převzetí zařízení %s – potvrďte prosím', $asset->getTag())
      : sprintf('Vrácení zařízení %s – potvrďte prosím', $asset->getTag());
    $body = [
      sprintf('Dobrý den, %s,', $user->getDisplayName()),
      '',
      $kind === HandoverKind::Handover
        ? sprintf('zařízení %s (inventární číslo %s) má být předáno vám.', $asset->getDisplayName(), $asset->getTag())
        : sprintf('zařízení %s (inventární číslo %s) se má vrátit do skladu.', $asset->getDisplayName(), $asset->getTag()),
      'Prosím, potvrďte kliknutím na odkaz (nikam se nemusíte přihlašovat, odkaz platí jen jednou):',
      $this->confirmUrl($handover),
      '',
      sprintf('Odkaz platí do %s.', $this->dateFormatter->format($expires, 'custom', 'j. n. Y H:i')),
      '',
      sprintf('Zadal: %s', $requestedBy->getDisplayName()),
    ];
    $this->mailManager->mail('hwdesk', $key, $user->getEmail(), $this->languageManager->getDefaultLanguage()->getId(), ['subject' => $subject, 'body' => $body], NULL, TRUE);

    return $handover;
  }

  /**
   * The single-use confirmation link for a pending handover.
   */
  public function confirmUrl(Handover $handover): string {
    $token = $this->tokens->issue((int) $handover->id(), (int) $handover->getUser()->id(), $handover->getExpires());
    $base = rtrim((string) $this->configFactory->get('hwdesk.settings')->get('base_url'), '/');
    if ($base === '') {
      return Url::fromRoute('hwdesk.handover.confirm', ['token' => $token], ['absolute' => TRUE])->toString();
    }
    return $base . '/hvd/' . $token;
  }

  /**
   * Confirms the handover: protocol number, PDF, asset state, e-mails.
   */
  public function confirm(Handover $handover, AccountInterface $actor): Handover {
    if ($handover->getStatus() !== HandoverStatus::Pending) {
      throw new \DomainException('Tuto žádost už bylo rozhodnuto.');
    }
    if ((int) $handover->getUser()->id() !== (int) $actor->id()) {
      throw new \DomainException('Potvrdit může jen zaměstnanec, kterému je zařízení určeno.');
    }
    $now = $this->time->getRequestTime();
    if ($handover->getExpires() < $now) {
      $handover->setStatus(HandoverStatus::Expired)->set('decided', $now)->save();
      $this->restoreAsset($handover);
      throw new \DomainException('Platnost žádosti vypršela; prosím, požádejte znovu.');
    }

    $handover->setStatus(HandoverStatus::Confirmed)
      ->set('decided', $now)
      ->set('protocol_number', $this->protocolNumbers->next())
      ->save();
    $file = $this->protocolPdf->store($handover);
    $handover->set('protocol_file', $file->id())->save();

    $asset = NULL;
    $referenced = $handover->getAsset();
    if ($referenced !== NULL) {
      $loaded = $this->entityTypeManager->getStorage('hwdesk_asset')->load($referenced->id());
      if ($loaded instanceof Asset) {
        $asset = $loaded;
      }
    }
    if ($asset !== NULL) {
      if ($handover->getKind() === HandoverKind::Handover) {
        $asset->setStatus(AssetStatus::Assigned)->save();
      }
      else {
        $asset->setStatus(AssetStatus::InStock)->setHolder(NULL)->save();
      }
    }

    $user = $handover->getUser();
    $requester = $handover->getRequestedBy();
    $recipients = [];
    if ($user !== NULL && $user->getEmail() !== '') {
      $recipients[$user->getEmail()] = TRUE;
    }
    if ($requester instanceof UserInterface && $requester->getEmail() !== NULL && $requester->getEmail() !== '') {
      $recipients[$requester->getEmail()] = TRUE;
    }
    $copyTo = (string) $this->configFactory->get('hwdesk.settings')->get('protocol_copy_to');
    if ($copyTo !== '') {
      $recipients[$copyTo] = TRUE;
    }

    $protocolNumber = $handover->getProtocolNumber();
    $subject = sprintf('Protokol %s – zařízení %s', $protocolNumber, $asset?->getTag() ?? '');
    $body = [
      sprintf('Protokol č. %s byl vytvořen pro zařízení %s (inventární číslo %s).', $protocolNumber, $asset?->getDisplayName() ?? '', $asset?->getTag() ?? ''),
      sprintf('Zaměstnanec: %s', $user !== NULL ? $user->getDisplayName() : ''),
      sprintf('PDF protokolu: %s', $file->createFileUrl(FALSE)),
    ];
    foreach (array_keys($recipients) as $to) {
      $this->mailManager->mail('hwdesk', 'protocol', $to, $this->languageManager->getDefaultLanguage()->getId(), ['subject' => $subject, 'body' => $body], NULL, TRUE);
    }

    return $handover;
  }

  /**
   * The employee refuses the handover; the asset goes back to its state.
   */
  public function reject(Handover $handover, AccountInterface $actor, string $reason): Handover {
    $reason = trim($reason);
    if ($reason === '') {
      throw new \InvalidArgumentException('Důvod odmítnutí nesmí být prázdný.');
    }
    if ($handover->getStatus() !== HandoverStatus::Pending) {
      throw new \DomainException('Tuto žádost už bylo rozhodnuto.');
    }
    if ((int) $handover->getUser()->id() !== (int) $actor->id()) {
      throw new \DomainException('Odmítnout může jen zaměstnanec, kterému je zařízení určeno.');
    }

    $handover->setStatus(HandoverStatus::Rejected)->set('decided', $this->time->getRequestTime())->set('reason', $reason)->save();
    $this->restoreAsset($handover);

    $requester = $handover->getRequestedBy();
    if ($requester instanceof UserInterface && $requester->getEmail() !== NULL && $requester->getEmail() !== '') {
      $asset = $handover->getAsset();
      $subject = sprintf('Žádost o zařízení %s byla odmítnuta', $asset?->getTag() ?? '');
      $body = [
        sprintf('Zaměstnanec %s odmítl(a) žádost o zařízení %s (inventární číslo %s).', $handover->getUser()?->getDisplayName() ?? '', $asset?->getDisplayName() ?? '', $asset?->getTag() ?? ''),
        sprintf('Důvod: %s', $reason),
      ];
      $this->mailManager->mail('hwdesk', 'rejected', $requester->getEmail(), $this->languageManager->getDefaultLanguage()->getId(), ['subject' => $subject, 'body' => $body], NULL, TRUE);
    }

    return $handover;
  }

  /**
   * The administrator cancels a pending request; no e-mail is sent.
   */
  public function cancel(Handover $handover, AccountInterface $_actor): Handover {
    if ($handover->getStatus() !== HandoverStatus::Pending) {
      throw new \DomainException('Zrušit lze jen žádost, která ještě čeká na potvrzení.');
    }
    $handover->setStatus(HandoverStatus::Cancelled)->set('decided', $this->time->getRequestTime())->save();
    $this->restoreAsset($handover);
    return $handover;
  }

  /**
   * Expires every pending request past its deadline; returns the count.
   */
  public function expireOverdue(): int {
    $storage = $this->entityTypeManager->getStorage('hwdesk_handover');
    $now = $this->time->getRequestTime();
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', HandoverStatus::Pending->value)
      ->condition('expires', $now, '<')
      ->execute();
    $count = 0;
    foreach ($ids as $id) {
      $handover = $storage->load($id);
      if (!$handover instanceof Handover) {
        continue;
      }
      $handover->setStatus(HandoverStatus::Expired)->set('decided', $now)->save();
      $this->restoreAsset($handover);
      $count++;
    }
    return $count;
  }

  /**
   * Puts the asset back the way it was before the request.
   *
   * The asset is always reloaded first, so a stale copy is never saved.
   */
  private function restoreAsset(Handover $handover): void {
    $asset = $handover->getAsset();
    if ($asset === NULL) {
      return;
    }
    $fresh = $this->entityTypeManager->getStorage('hwdesk_asset')->load($asset->id());
    if (!$fresh instanceof Asset) {
      return;
    }
    if ($handover->getKind() === HandoverKind::Handover) {
      $fresh->setStatus(AssetStatus::InStock)->setHolder(NULL);
    }
    else {
      $fresh->setStatus(AssetStatus::Assigned);
    }
    $fresh->save();
  }

}
