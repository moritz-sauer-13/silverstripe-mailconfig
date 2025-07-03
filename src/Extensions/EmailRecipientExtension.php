<?php

namespace MailConfig\Extensions;

use SilverStripe\Core\Extension;

class EmailRecipientExtension extends Extension
{
    public function populateDefaults()
    {
        if($this->owner->EmailFrom == null){
            $mailConfig = SiteConfigMailExtension::getEffectiveMailConfig();
            if($mailConfig && $mailConfig['AdminEmail'] != ''){
                $this->owner->EmailFrom = $mailConfig['AdminEmail'];
            }
        }
    }
}