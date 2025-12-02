<?php

namespace MailConfig\Extensions;

use Exception;
use SilverStripe\Core\Extension;
use MailConfig\Extensions\SiteConfigMailExtension;

/**
 * Extension for Email class that integrates with the moritz-sauer-13/silverstripe-mailconfig module
 * to provide alternative From addresses from the mail configuration.
 */
class EmailMailConfigExtension extends Extension
{
    /**
     * Hook into the updateDefaultFrom method to provide alternative From addresses
     * from the mailconfig module if available.
     *
     * @param string|array $defaultFrom The current default from address
     */
    public function updateDefaultFrom(&$defaultFrom): void
    {
        try {
            // Get the effective mail configuration from the mailconfig module
            $mailConfig = SiteConfigMailExtension::getEffectiveMailConfig();

            // Check if we have AdminEmail and optionally AdminName from the mailconfig
            if (!empty($mailConfig['AdminEmail'])) {
                // If we have both email and name, create an array format
                if (!empty($mailConfig['AdminName'])) {
                    $defaultFrom = [$mailConfig['AdminEmail'] => $mailConfig['AdminName']];
                } else {
                    // Just use the email address
                    $defaultFrom = $mailConfig['AdminEmail'];
                }
            }

            // If no AdminEmail is configured in mailconfig, keep the original $defaultFrom

        } catch (Exception $exception) {
            // In case of any error, we simply do not modify the defaultFrom
        }
    }
}
