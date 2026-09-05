<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Plugin\EmailSmtp2go\Provider\Smtp2goProvider;
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
            'onEmailProviders'    => ['onEmailProviders', 0],
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

    /**
     * Tell the Email plugin what this plugin knows about SMTP2GO.
     *
     * How its delivery webhooks are verified and read, how one is created from
     * the API key already pasted in above, and what a sending domain's DNS has
     * to say. Anything that wants those asks the Email plugin for the provider
     * rather than carrying a copy of them.
     *
     * The version guard is not decoration. The contract's classes use readonly
     * promoted properties, which are PHP 8.1, and this plugin still supports
     * the 7.4 sites Grav 1.7 supports. `Email::supportsFeature('providers')`
     * answers false below 8.1 for the same reason, so a caller that asks
     * properly never gets here — but `Email::providers()` can be called
     * directly, and a fatal parse error on an old site would be a poor way to
     * find that out.
     */
    public function onEmailProviders(Event $e): void
    {
        if (PHP_VERSION_ID < 80100) {
            return;
        }

        $providers = $e['providers'] ?? null;
        if (!is_object($providers) || !method_exists($providers, 'add')) {
            return;
        }

        $providers->add(new Smtp2goProvider((array)$this->config->get('plugins.email-smtp2go', [])));
    }
}
