<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\EmailSmtp2go\Transport\Smtp2goApiTransport;
use Grav\Plugin\EmailSmtp2go\Transport\Smtp2goSmtpTransport;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class EmailSmtp2goPlugin
 * @package Grav\Plugin
 */
class EmailSmtp2goPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines'      => ['onEmailEngines', 0],
            'onEmailTransportDsn' => ['onEmailTransportDsn', 0],
        ];
    }

    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onEmailEngines(Event $e): void
    {
        $engines = $e['engines'];
        $engines->smtp2go = 'SMTP2GO';
    }

    public function onEmailTransportDsn(Event $e): void
    {
        $engine = $e['engine'];
        if ($engine !== 'smtp2go') {
            return;
        }

        $options = $this->config->get('plugins.email-smtp2go');
        $transport = $options['transport'] ?? 'api';

        if ($transport === 'api') {
            $dsn = new Smtp2goApiTransport($options['api_key'] ?? '');
        } else {
            $port = (int)($options['port'] ?? 2525);
            $tls = !empty($options['tls']);
            $dsn = new Smtp2goSmtpTransport(
                $options['username'] ?? '',
                $options['password'] ?? '',
                $port,
                $tls
            );
        }

        $e['dsn'] = $dsn;
        $e->stopPropagation();
    }
}
