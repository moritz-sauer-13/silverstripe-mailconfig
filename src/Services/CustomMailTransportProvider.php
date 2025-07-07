<?php

namespace MailConfig\Services;

use Psr\Container\NotFoundExceptionInterface;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Dev\Debug;
use MailConfig\Extensions\SiteConfigMailExtension;
use Symfony\Component\Mailer\Transport;

class CustomMailTransportProvider implements Factory
{
    /**
     * @throws \Exception
     * @throws NotFoundExceptionInterface
     */
    public function create($service, array $params = [])
    {
        // Effektive Mail-Konfiguration holen (Subsite oder Fallback)
        $mailConfig = SiteConfigMailExtension::getEffectiveMailConfig();

        if($mailConfig['CustomDSN']){
            // Symfony Mailer Transport-Objekt erstellen
            return Transport::fromDsn($mailConfig['CustomDSN']);
        }

        // Check if we have valid SMTP configuration before building the DSN
        if(empty($mailConfig['SMTPServer']) ||
            empty($mailConfig['SMTPUser']) ||
            empty($mailConfig['SMTPPassword']) ||
            empty($mailConfig['SMTPPort']) ||
            $mailConfig['SMTPPort'] === 0) {
            // Return null transport when no valid SMTP configuration is available
            return Transport::fromDsn('null://null');
        }

        // SMTP-DSN für Symfony Mailer erstellen
        $dsn = sprintf(
            'smtp://%s:%s@%s:%d',
            urlencode($mailConfig['SMTPUser']),
            urlencode($mailConfig['SMTPPassword']),
            $mailConfig['SMTPServer'],
            $mailConfig['SMTPPort']
        );

        // Symfony Mailer Transport-Objekt erstellen
        return Transport::fromDsn($dsn);
    }
}
