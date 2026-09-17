<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\hwdesk\Entity\Handover;
use Drupal\hwdesk\Entity\Asset;
use Drupal\user\UserInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\hwdesk\HandoverKind;
use Drupal\hwdesk\HandoverStatus;
use Drupal\hwdesk\AssetStatus;
use Drupal\file\FileInterface;

/**
 * Service handling handover and return workflows.
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
   * Find an open handover for the given asset.
   */
  public function openFor(Asset $asset): ?Handover {
    $storage = $this->entityTypeManager->getStorage('hwdesk_handover');
    $ids = $storage->getQuery()
      ->condition('asset', $asset->id())
      ->condition('status', HandoverStatus::Pending->value)
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();
    if (empty($ids)) {
      return NULL;
    }
    /** @var \Drupal\hwdesk\Entity\Handover $handover */
    $handover = $storage->load(reset($ids));
    return $handover instanceof Handover ? $handover : NULL;
  }

  public function request(Asset $asset, UserInterface $user, HandoverKind $kind, AccountInterface $requestedBy): Handover {
    if ($this->openFor($asset) !== NULL) {
      throw new \DomainException('Existuje nevyřešené předání pro toto zařízení.');
    }
    // Validate asset status.
    $status = $asset->getStatus();
    if ($kind === HandoverKind::Handover && $status !== AssetStatus::InStock) {
      throw new \DomainException('Zařízení není skladem, nelze zahájit předání.');
    }
    if ($kind === HandoverKind::ReturnItem) {
      if ($status !== AssetStatus::Assigned || $asset->getHolder()?->id() !== $user->id()) {
        throw new \DomainException('Zařízení není přiřazeno uživateli, nelze zahájit vrácení.');
      }
    }

    $config = $this->configFactory->get('hwdesk.settings');
    $hours = (int) $config->get('handover_token_hours');
    $now = $this->time->getRequestTime();
    $expires = $now + $hours * 3600;

    /** @var \Drupal\hwdesk\Entity\Handover $handover */
    $handover = $this->entityTypeManager->getStorage('hwdesk_handover')->create([
      'asset' => $asset->id(),
      'user' => $user->id(),
      'requested_by' => $requestedBy->id(),
      'kind' => $kind->value,
      'status' => HandoverStatus::Pending->value,
      'expires' => $expires,
    ]);
    $handover->save();

    // Update asset.
    if ($kind === HandoverKind::Handover) {
      $asset->setStatus(AssetStatus::PendingHandover)
        ->setHolder($user)
        ->save();
    }
    else {
      $asset->setStatus(AssetStatus::PendingReturn)
        ->save();
    }

    // Send mail.
    $key = $kind === HandoverKind::Handover ? 'handover_request' : 'return_request';
    $url = $this->confirmUrl($handover);
    $expiryFormatted = $this->dateFormatter->format($expires, 'custom', 'j. n. Y H:i');
    $company = (string) $this->configFactory->get('hwdesk.settings')->get('company_name');
    if ($kind === HandoverKind::Handover) {
      $subject = sprintf('[HW Desk] Převzetí zařízení %s – potvrďte prosím', $asset->getTag());
      $bodyLines = [
        sprintf('Dobrý den, %s,', $user->getDisplayName()),
        '',
        sprintf('bylo vám přiděleno zařízení %s (inventární číslo %s%s).', $asset->getDisplayName(), $asset->getTag(), $asset->getSerialNumber() !== '' ? ', S/N ' . $asset->getSerialNumber() : ''),
        'Převzetí prosím potvrďte kliknutím na odkaz (po přihlášení svým účtem):',
        $url,
        '',
        sprintf('Odkaz platí do %s. Pokud zařízení nepřebíráte, můžete na stejné stránce převzetí odmítnout a uvést důvod.', $expiryFormatted),
        '',
        sprintf('Zadal: %s', $requestedBy->getDisplayName()),
        $company !== '' ? $company : 'HW Desk',
      ];
    }
    else {
      $subject = sprintf('[HW Desk] Vrácení zařízení %s – potvrďte prosím', $asset->getTag());
      $bodyLines = [
        sprintf('Dobrý den, %s,', $user->getDisplayName()),
        '',
        sprintf('zařízení %s (inventární číslo %s) se vrací do skladu.', $asset->getDisplayName(), $asset->getTag()),
        'Vrácení prosím potvrďte kliknutím na odkaz (po přihlášení svým účtem):',
        $url,
        '',
        sprintf('Odkaz platí do %s. Pokud zařízení nevracíte, můžete na stejné stránce vrácení odmítnout a uvést důvod.', $expiryFormatted),
        '',
        sprintf('Zadal: %s', $requestedBy->getDisplayName()),
        $company !== '' ? $company : 'HW Desk',
      ];
    }
    $this->mailManager->mail(
      'hwdesk',
      $key,
      $user->getEmail(),
      $this->languageManager->getDefaultLanguage()->getId(),
      ['subject' => $subject, 'body' => $bodyLines],
      NULL,
      TRUE
    );

    return $handover;
  }

  public function confirmUrl(Handover $handover): string {
    $token = $this->tokens->issue((int) $handover->id(), (int) $handover->getUser()->id(), $handover->getExpires());
    $base = rtrim((string) $this->configFactory->get('hwdesk.settings')->get('base_url'), '/');
    if ($base === '') {
      // Use Drupal's Url service to generate the absolute URL without static calls.
    return \Drupal\Core\Url::fromRoute('hwdesk.handover.confirm', ['token' => $token], ['absolute' => TRUE])->toString();
    }
    return $base . '/hwdesk/confirm/' . $token;
  }

  public function confirm(Handover $handover, AccountInterface $actor): Handover {
    if ($handover->getStatus() !== HandoverStatus::Pending) {
      throw new \DomainException('Předání není ve stavu čeká na potvrzení.');
    }
    if ((int) $handover->getUser()->id() !== (int) $actor->id()) {
      throw new \DomainException('Potvrzovat může pouze určený uživatel.');
    }
    $now = $this->time->getRequestTime();
    if ($handover->getExpires() < $now) {
      // Expire.
      $handover->setStatus(HandoverStatus::Expired)
        ->set('decided', $now);
      $handover->save();
      $this->restoreAsset($handover);
      throw new \DomainException('Platnost požadavku vypršela.');
    }

    // Confirm.
    $handover->setStatus(HandoverStatus::Confirmed)
      ->set('decided', $now)
      ->set('protocol_number', $this->protocolNumbers->next());
    $handover->save();

    // Store PDF.
    $file = $this->protocolPdf->store($handover);
    $handover->set('protocol_file', $file->id())->save();

    // Update asset based on kind.
    $asset = $handover->getAsset();
    if ($asset) {
      if ($handover->getKind() === HandoverKind::Handover) {
        $asset->setStatus(AssetStatus::Assigned)->save();
      }
      else { // ReturnItem
        $asset->setStatus(AssetStatus::InStock)
          ->setHolder(NULL)
          ->save();
      }
    }

    // Send protocol mail to relevant parties.
    $recipients = [];
    $userEmail = $handover->getUser()?->getEmail();
    if ($userEmail) { $recipients[$userEmail] = TRUE; }
    $requester = $handover->getRequestedBy();
    if ($requester && $requester->getEmail()) {
      $recipients[$requester->getEmail()] = TRUE;
    }
    $copyTo = (string) $this->configFactory->get('hwdesk.settings')->get('protocol_copy_to');
    if ($copyTo !== '') { $recipients[$copyTo] = TRUE; }

    $isReturn = $handover->getKind() === HandoverKind::ReturnItem;
    $subject = sprintf('[HW Desk] %s %s – protokol %s', $isReturn ? 'Vrácení' : 'Předání', $asset?->getTag() ?? '', $handover->getProtocolNumber());
    $bodyLines = [
      sprintf('%s zařízení %s (inventární číslo %s) bylo potvrzeno %s.', $isReturn ? 'Vrácení' : 'Převzetí', $asset?->getDisplayName() ?? '', $asset?->getTag() ?? '', $this->dateFormatter->format($now, 'custom', 'j. n. Y H:i')),
      sprintf('Zaměstnanec: %s (%s)', $handover->getUser()?->getDisplayName() ?? '', $handover->getUser()?->getEmail() ?? ''),
      sprintf('Protokol č. %s (PDF, po přihlášení): %s', $handover->getProtocolNumber(), $file->createFileUrl(FALSE)),
    ];

    foreach (array_keys($recipients) as $to) {
      $this->mailManager->mail(
        'hwdesk',
        'protocol',
        $to,
        $this->languageManager->getDefaultLanguage()->getId(),
        ['subject' => $subject, 'body' => $bodyLines],
        NULL,
        TRUE
      );
    }

    return $handover;
  }

  public function reject(Handover $handover, AccountInterface $actor, string $reason): Handover {
    $trimmed = trim($reason);
    if ($trimmed === '') {
      throw new \InvalidArgumentException('Důvod odmítnutí nesmí být prázdný.');
    }
    if ($handover->getStatus() !== HandoverStatus::Pending) {
      throw new \DomainException('Předání není ve stavu čeká na potvrzení.');
    }
    if ((int) $handover->getUser()->id() !== (int) $actor->id()) {
      throw new \DomainException('Odmítnout může pouze určený uživatel.');
    }
    $now = $this->time->getRequestTime();
    $handover->setStatus(HandoverStatus::Rejected)
      ->set('decided', $now)
      ->set('reason', $trimmed);
    $handover->save();
    $this->restoreAsset($handover);

    // Mail to requester.
    $requester = $handover->getRequestedBy();
    if ($requester && $requester->getEmail()) {
      $subject = sprintf('[HW Desk] %s zařízení %s odmítnuto', $handover->getKind() === HandoverKind::ReturnItem ? 'Vrácení' : 'Převzetí', $handover->getAsset()?->getTag() ?? '');
      $bodyLines = [
        sprintf('%s odmítl(a) %s zařízení %s (inventární číslo %s).', $handover->getUser()?->getDisplayName() ?? '', $handover->getKind() === HandoverKind::ReturnItem ? 'vrácení' : 'převzetí', $handover->getAsset()?->getDisplayName() ?? '', $handover->getAsset()?->getTag() ?? ''),
        sprintf('Důvod: %s', $trimmed),
        'Zařízení je zpět v původním stavu.',
      ];
      $this->mailManager->mail(
        'hwdesk',
        'rejected',
        $requester->getEmail(),
        $this->languageManager->getDefaultLanguage()->getId(),
        ['subject' => $subject, 'body' => $bodyLines],
        NULL,
        TRUE
      );
    }
    return $handover;
  }

  public function cancel(Handover $handover, AccountInterface $actor): Handover {
    if ($handover->getStatus() !== HandoverStatus::Pending) {
      throw new \DomainException('Předání není ve stavu čeká na potvrzení.');
    }
    // Any actor can cancel per spec? It says just status check.
    $now = $this->time->getRequestTime();
    $handover->setStatus(HandoverStatus::Cancelled)
      ->set('decided', $now);
    $handover->save();
    $this->restoreAsset($handover);
    return $handover;
  }

  public function expireOverdue(): int {
    $storage = $this->entityTypeManager->getStorage('hwdesk_handover');
    $now = $this->time->getRequestTime();
    $ids = $storage->getQuery()
      ->condition('status', HandoverStatus::Pending->value)
      ->condition('expires', $now, '<')
      ->accessCheck(FALSE)
      ->execute();
    $count = 0;
    foreach ($ids as $id) {
      /** @var \Drupal\hwdesk\Entity\Handover $handover */
      $handover = $storage->load($id);
      if (!$handover) { continue; }
      $handover->setStatus(HandoverStatus::Expired)
        ->set('decided', $now)
        ->save();
      $this->restoreAsset($handover);
      $count++;
    }
    return $count;
  }

  /**
   * Restore asset to its state after a cancelled/rejected/expired handover.
   */
  private function restoreAsset(Handover $handover): void {
    $asset = $handover->getAsset();
    if (!$asset) { return; }
    // Reload fresh copy.
    $fresh = $this->entityTypeManager->getStorage('hwdesk_asset')->load($asset->id());
    if (!$fresh instanceof Asset) {
      return;
    }
    if ($handover->getKind() === HandoverKind::Handover) {
      $fresh->setStatus(AssetStatus::InStock)
        ->setHolder(NULL);
    }
    else { // ReturnItem
      $fresh->setStatus(AssetStatus::Assigned);
      // holder unchanged.
    }
    $fresh->save();
  }
}
