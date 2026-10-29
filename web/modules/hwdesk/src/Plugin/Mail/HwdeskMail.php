<?php

declare(strict_types=1);

namespace Drupal\hwdesk\Plugin\Mail;

use Drupal\Core\Mail\Attribute\Mail;
use Drupal\Core\Mail\MailInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hwdesk\Service\MailTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends mail over the transport configured in the module settings.
 *
 * Unlike the site default plugin this one honours the sender and reply-to
 * addresses the company wants, attaches the protocol PDF passed through
 * $message['params']['attachment'], and when sending fails it writes one log
 * record a human can act on: the reason, the place in the application and
 * the place in the code, without the SMTP password.
 */
#[Mail(
  id: 'hwdesk_mail',
  label: new TranslatableMarkup('HW Desk (SMTP z nastavení modulu)'),
)]
final class HwdeskMail implements MailInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly MailTransport $transport,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $_configuration, $_plugin_id, $_plugin_definition): static {
    return new static(
      $container->get('hwdesk.mail_transport'),
      $container->get('logger.factory')->get('hwdesk'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function format(array $message): array {
    $message['body'] = implode("\n\n", array_map(static fn ($part): string => (string) $part, (array) $message['body']));
    $message['subject'] = str_replace(["\r", "\n"], ' ', (string) $message['subject']);
    return $message;
  }

  /**
   * {@inheritdoc}
   */
  public function mail(array $message): bool {
    try {
      $email = (new Email())
        ->to((string) $message['to'])
        ->subject((string) $message['subject'])
        ->text((string) $message['body']);

      $from = $this->transport->from();
      if ($from !== []) {
        $email->from(new Address($from['address'], (string) ($from['name'] ?? '')));
      }
      else {
        $email->from((string) $message['from']);
      }

      $reply = $this->transport->replyTo();
      if ($reply !== '') {
        $email->replyTo($reply);
      }

      $attachment = $message['params']['attachment'] ?? NULL;
      if (is_string($attachment) && $attachment !== '') {
        $email->attachFromPath($attachment);
      }
      elseif (is_array($attachment) && isset($attachment['filepath'])) {
        $filename = $attachment['filename'] ?? NULL;
        if ($filename !== NULL) {
          $email->attachFromPath($attachment['filepath'], $filename);
        }
        else {
          $email->attachFromPath($attachment['filepath']);
        }
      }

      $mailer = new Mailer(Transport::fromDsn($this->transport->dsn()));
      $mailer->send($email);
      return TRUE;
    }
    catch (\Throwable $e) {
      $this->logger->error('@popis', [
        '@popis' => $this->transport->describeFailure($e, sprintf('odeslani zpravy %s na %s', (string) $message['id'], (string) $message['to'])),
      ]);
      return FALSE;
    }
  }

}
