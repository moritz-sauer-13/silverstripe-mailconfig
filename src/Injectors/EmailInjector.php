<?php

namespace MailConfig\Injectors;

use SilverStripe\Control\Email\Email;
use MailConfig\Extensions\SiteConfigMailExtension;
use SilverStripe\View\ViewableData;

class EmailInjector extends Email
{
    public function __construct(
        string|array $from = '',
        string|array $to = '',
        string $subject = '',
        string $body = '',
        string|array $cc = '',
        string|array $bcc = '',
        string $returnPath = ''
    ) {
        parent::__construct();

        // Hole die SMTP-Konfiguration aus SiteConfig oder mail.yml
        $mailConfig = SiteConfigMailExtension::getEffectiveMailConfig();
        $defaultEmail = $mailConfig['AdminEmail'] ?? '';
        $defaultName  = $mailConfig['AdminName'] ?? '';

        // Falls kein `from` übergeben wurde, setze den Standard-Absender mit Name
        if ($from) {
            $this->setFrom($from);
        } else {
            if ($defaultEmail) {
                // Falls ein Name vorhanden ist, als Array setzen (['Name' => 'Email'])
                if (!empty($defaultName)) {
                    $this->setFrom([$defaultEmail => $defaultName]);
                } else {
                    $this->setFrom($defaultEmail);
                }
            }
        }

        if ($to) {
            $this->setTo($to);
        }
        if ($subject) {
            $this->setSubject($subject);
        }
        if ($body) {
            $this->setBody($body);
        }
        if ($cc) {
            $this->setCC($cc);
        }
        if ($bcc) {
            $this->setBCC($bcc);
        }
        if ($returnPath) {
            $this->setReturnPath($returnPath);
        }

        $this->data = ViewableData::create();
    }
}