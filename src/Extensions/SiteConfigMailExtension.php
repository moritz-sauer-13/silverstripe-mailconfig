<?php

namespace MailConfig\Extensions;

use Throwable;
use Exception;
use LeKoala\CmsActions\CustomAction;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\PasswordField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Model\ArrayData;
use SilverStripe\SiteConfig\SiteConfig;

class SiteConfigMailExtension extends Extension implements Flushable
{
    private static string $subsite_cache_key_prefix = 'mail_config_subsite_';

    private static array $db = [
        'SMTPServer' => 'Text',
        'SMTPPort' => 'Int',
        'SMTPUser' => 'Text',
        'SMTPPassword' => 'Text', // Klartext (ohne Verschlüsselung)
        'AdminEmail' => 'Text',
        'AdminName' => 'Text',
        'CustomDSN' => 'Text', // Benutzerdefinierte DSN für Symfony Mailer
        'TestEmail'    => 'Text', // Temporäre Test-E-Mail-Adresse
    ];

    /**
     * Fügt die E-Mail-Einstellungen zum CMS hinzu
     *
     * @param FieldList $fields Die Liste der CMS-Felder
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $fields->addFieldToTab('Root', Tab::create('EmailSettings', 'Email Einstellungen'));
        $fields->addFieldsToTab('Root.EmailSettings', [
            TextField::create('SMTPServer', 'SMTP Server')
                ->setDescription('z.B. smtp.example.com'),
            TextField::create('SMTPPort', 'SMTP Port')
                ->setDescription('z.B. 587 oder 465'),
            TextField::create('SMTPUser', 'SMTP Benutzername'),
            PasswordField::create('SMTPPassword', 'SMTP Passwort')
                ->setDescription('Das Passwort wird unverschlüsselt gespeichert. Wenn das Feld leer ist, wird das Passwort nicht geändert.')
                ->setAttribute('placeholder', '********')
                ->setAttribute('type', 'password'),
            TextField::create('CustomDSN', 'Benutzerdefinierte DSN')
                ->setDescription('ACHTUNG! Falls gesetzt, wird die SMTP-Konfiguration ignoriert und stattdessen dieser DSN verwendet.<br>Format: <code>smtp://user:password@server:port</code>'),
            EmailField::create('AdminEmail', 'Standard-Absender E-Mail'),
            TextField::create('AdminName', 'Standard-Absender Name')
                ->setDescription('Optional. Falls leer, wird nur die E-Mail-Adresse verwendet.'),
            EmailField::create('TestEmail', 'Test-E-Mail-Adresse')
                ->setDescription('Diese Adresse wird für Test-E-Mails genutzt. Vor dem Senden einer Test-E-Mail bitte speichern.'),
        ]);
    }

    /**
     * Fügt die CMS Action für den Button "Test-E-Mail senden" hinzu.
     *
     * @param FieldList $actions Die Liste der CMS-Aktionen
     */
    public function updateCMSActions(FieldList $actions): void
    {
        if($this->owner->TestEmail){
            $actions->push(
                CustomAction::create('doSendTestEmail', 'Test-E-Mail senden')
                    ->setUseButtonTag(true)
                    ->addExtraClass('btn btn-outline')
            );
        }
    }

    /**
     * Wird aufgerufen, wenn der Button "Test-E-Mail senden" im CMS geklickt wird.
     * 
     * @return string Statusmeldung über Erfolg oder Fehler
     */
    public function doSendTestEmail(): string
    {
        $siteConfig = $this->owner;
        $testEmail = $siteConfig->TestEmail;

        if (empty($testEmail)) {
            return 'Bitte eine Test-E-Mail-Adresse eingeben.';
        }

        if(!Email::is_valid_address($testEmail)){
            return 'Ungültige E-Mail-Adresse.';
        }

        try {
            $mailConfig = self::getEffectiveMailConfig();

            if (empty($mailConfig['AdminEmail'])) {
                return 'Fehler: Keine Absender-E-Mail-Adresse konfiguriert.';
            }

            $email = Email::create()
                ->setTo($testEmail)
                ->setFrom($mailConfig['AdminEmail'], $mailConfig['AdminName'])
                ->setSubject('Mail Test - ' . $siteConfig->Title)
                ->setBody('Der Mailtest war erfolgreich.');

            try{
                $email->send();
                $result = true;
            } catch (Exception $e) {
                $result = false;
            }

            if (!$result) {
                return 'E-Mail konnte nicht gesendet werden. Bitte überprüfen Sie die SMTP-Einstellungen.';
            }

        } catch (Exception $e) {
            return 'Fehler beim Senden: ' . $e->getMessage();
        } catch (NotFoundExceptionInterface $e) {
            return 'Fehler bei der Konfiguration: ' . $e->getMessage();
        } catch (Throwable $e) {
            // Fange alle anderen möglichen Fehler ab
            return 'Unerwarteter Fehler: ' . $e->getMessage();
        }

        return 'Test-E-Mail wurde erfolgreich an ' . $testEmail . ' versendet.';
    }

    /**
     * Prüft, ob das Passwort validiert werden muss und behält das alte Passwort bei,
     * wenn das Feld leer gelassen wurde
     */
    public function onBeforeWrite(): void
    {
        $changedFields = $this->owner->getChangedFields();
        // Wenn das Passwortfeld leer ist, behalte das alte Passwort bei
        if (array_key_exists('SMTPPassword', $changedFields) && isset($changedFields['SMTPPassword']) && ($changedFields['SMTPPassword']['after'] == '' && !empty($changedFields['SMTPPassword']['before']))) {
            $this->owner->SMTPPassword = $changedFields['SMTPPassword']['before'];
        }
    }

    /**
     * Löscht den Cache, wenn die SiteConfig geändert wurde
     */
    public function onAfterWrite(): void
    {
        try {
            $cache = Injector::inst()->get(CacheInterface::class . '.MailConfigCache');
            if($cache){
                $cache->clear();
            }
        } catch (Throwable $throwable) {
            // Fehler beim Löschen des Caches ignorieren
        }
    }

    /**
     * Validiert die SMTP-Konfiguration
     * 
     * @param ValidationResult $validationResult Das ValidationResult-Objekt
     * @return ValidationResult Das aktualisierte ValidationResult-Objekt
     */
    public function validate(ValidationResult $validationResult): ValidationResult
    {
        if(!$this->owner->CustomDSN){
            $fields = [
                'SMTPServer',
                'SMTPUser',
                'SMTPPort',
                'AdminEmail'
            ];

            $hasAnyValue = false;
            $missingFields = [];

            // Prüfe, ob mindestens eines der Felder ausgefüllt ist
            foreach ($fields as $field) {
                if (!empty($this->owner->$field)) {
                    $hasAnyValue = true;
                }
            }

            // Falls ein Wert gesetzt ist, prüfe, ob alle anderen auch gefüllt sind
            if ($hasAnyValue) {
                foreach ($fields as $field) {
                    if (empty($this->owner->$field)) {
                        $missingFields[] = $field;
                    }
                }

                // Falls Felder fehlen, eine Fehlermeldung hinzufügen
                if ($missingFields !== []) {
                    $validationResult->addError(
                        'Wenn eine SMTP-Einstellung gesetzt ist, müssen auch die folgenden Felder ausgefüllt werden: ' .
                        implode(', ', $missingFields)
                    );
                }
            }
        }

        return $validationResult;
    }

    /**
     * Gibt die effektiven SMTP-Einstellungen zurück (Subsite-spezifisch, falls notwendig oder Fallback auf Hauptseite & mail.yml)
     * @throws Exception|NotFoundExceptionInterface
     */
    public static function getEffectiveMailConfig(): array
    {
        try {
            $cache = Injector::inst()->get(CacheInterface::class . '.MailConfigCache');

            // Generiere einen Cache-Key basierend auf der aktuellen Subsite (falls vorhanden)
            $cacheKey = self::$subsite_cache_key_prefix;
            $SubsiteID = 0;

            if(class_exists(SilverStripe\Subsites\Model\Subsite::class)){
                $subsite = SilverStripe\Subsites\Model\Subsite::currentSubsite();
                $SubsiteID = $subsite ? $subsite->ID : 0;
                $cacheKey = self::$subsite_cache_key_prefix . $SubsiteID;
            }

            // Falls Cache vorhanden und gültig, zurückgeben
            if ($cache && $cache->has($cacheKey)) {
                $cachedConfig = $cache->get($cacheKey);
                if (is_array($cachedConfig) && $cachedConfig !== []) {
                    return $cachedConfig;
                }
            }
        } catch (Throwable $throwable) {
            // Bei Cache-Fehlern: Ignorieren und fortfahren ohne Cache
            $cache = null;
        }

        // Aktuelle SiteConfig holen (Hauptseite oder Subsite)
        $siteConfig = SiteConfig::current_site_config();
        $mainSiteConfig = null;

        if(class_exists(SilverStripe\Subsites\Model\Subsite::class)) {
            // Hauptseiten-SiteConfig holen
            $mainSiteConfig = SiteConfig::get()->filter('SubsiteID', 0)->first();
        }

        // Funktion zur Überprüfung, ob eine Konfiguration vollständig ist
        $isComplete = function ($config): bool {
            if(!$config) {
                return false;
            }

            if($config->CustomDSN){
                return true;
            }

            return !empty($config->SMTPServer)
                && !empty($config->SMTPUser)
                && !empty($config->SMTPPassword)
                && !empty($config->SMTPPort)
                && !empty($config->AdminEmail);
        };

        // Bestimmen, welche Konfiguration verwendet wird
        if ($isComplete($siteConfig)) {
            $finalConfig = $siteConfig;
        } elseif ($mainSiteConfig && $isComplete($mainSiteConfig)) {
            $finalConfig = $mainSiteConfig;
        } else {
            $finalConfig = null; // Wir fallen auf `mail.yml` zurück
        }

        // Endgültige Konfiguration zusammenstellen
        $config = [
            'SMTPServer'   => $finalConfig ? $finalConfig->SMTPServer : Email::Config()->smtp_server,
            'SMTPPort'     => $finalConfig ? $finalConfig->SMTPPort : Email::Config()->smtp_port,
            'SMTPUser'     => $finalConfig ? $finalConfig->SMTPUser : Email::Config()->smtp_user,
            'SMTPPassword' => $finalConfig ? $finalConfig->SMTPPassword : Email::Config()->smtp_password,
            'AdminEmail'   => $finalConfig ? $finalConfig->AdminEmail : Email::Config()->admin_email,
            'AdminName'    => $finalConfig ? $finalConfig->AdminName : Email::Config()->admin_name,
            'CustomDSN'    => $finalConfig ? $finalConfig->CustomDSN : null,
        ];

        // Funktion zur Überprüfung, ob irgendeine SMTP-Konfiguration vorhanden ist
        $hasAnyConfig = function (array $config): bool {
            return !empty($config['SMTPServer']) 
                || !empty($config['SMTPUser']) 
                || !empty($config['SMTPPassword']) 
                || !empty($config['SMTPPort']);
        };

        if(!$isComplete(ArrayData::create($config))){
            if (Email::Config()->custom_dsn) {
                $config['CustomDSN'] = Email::Config()->custom_dsn;
            } elseif ($hasAnyConfig($config)) {
                // Nur Exception werfen, wenn teilweise konfiguriert (nicht wenn komplett leer)
                throw new Exception('SMTP-Konfiguration ist unvollständig.');
            }
        }

        if($cache){
            try {
                // Speichere neuen Cache für 24h
                $cache->set($cacheKey, $config, 86400);
            } catch (Throwable $e) {
                // Fehler beim Caching ignorieren - die Konfiguration wird trotzdem zurückgegeben
            }
        }

        return $config;
    }

    /**
     * Implementierung der Flushable-Schnittstelle
     * Löscht den Cache beim Flush der Anwendung
     */
    public static function flush(): void
    {
        try {
            $cache = Injector::inst()->get(CacheInterface::class . '.MailConfigCache');
            if($cache){
                $cache->clear();
            }
        } catch (Throwable $throwable) {
            // Fehler beim Löschen des Caches ignorieren
        }
    }
}
