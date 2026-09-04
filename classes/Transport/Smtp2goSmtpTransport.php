<?php

namespace Grav\Plugin\EmailSmtp2go\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class Smtp2goSmtpTransport extends EsmtpTransport
{
    public function __construct(string $username, string $password, int $port = 2525, bool $tls = false, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
    {
        parent::__construct('mail.smtp2go.com', $port, $tls, $dispatcher, $logger);

        $this->setUsername($username);
        $this->setPassword($password);
    }
}
