<?php

namespace MailConfig\Extensions;

use Exception;
use LeKoala\CmsActions\CustomAction;
use Psr\Container\NotFoundExceptionInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Debug;
use SilverStripe\Forms\PasswordField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\EmailField;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Subsites\Model\Subsite;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\ArrayData;

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
     */
    public function updateCMSActions(FieldList $actions)
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
     */
    public function doSendTestEmail()
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

            $email = Email::create()
                ->setTo($testEmail)
                ->setFrom($mailConfig['AdminEmail'], $mailConfig['AdminName'])
                ->setSubject('Mail Test - ' . $siteConfig->Title)
                ->setBody('Der Mailtest war erfolgreich.');

            $email->send();

        } catch (\Exception $e) {
            return 'Fehler beim Senden: ' . $e->getMessage();
        } catch (NotFoundExceptionInterface $e) {
            return 'Fehler beim Senden: ' . $e->getMessage();
        }

        return 'Test-E-Mail wurde versendet.';
    }

    /*
     * Prüft, ob das Passwort validiert werden muss
     * */
    public function onBeforeWrite()
    {
        $changedFields = $this->owner->getChangedFields();
        if (array_key_exists('SMTPPassword', $changedFields) && isset($changedFields['SMTPPassword'])) {
            if($changedFields['SMTPPassword']['after'] == ''){
                $this->owner->SMTPPassword = Convert::raw2sql($changedFields['SMTPPassword']['before']);
            }
        }
    }

    /**
     * Löscht den Cache, wenn die SiteConfig geändert wurde
     */
    public function onAfterWrite(): void
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.MailConfigCache');
        if($cache){
            $cache->clear();
        }
    }

    public function validate(ValidationResult $validationResult): ValidationResult
    {
        if(!$this->owner->CustomDSN){
            $fields = [
                'SMTPServer',
                'SMTPUser',
                'SMTPPort'
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
                if (!empty($missingFields)) {
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
     * @return array
     */
    public static function getEffectiveMailConfig(): array
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.MailConfigCache');

        $cacheKey = self::$subsite_cache_key_prefix;

        if(class_exists(Subsite::class)){
            $subsite = Subsite::currentSubsite();
            $SubsiteID = $subsite ? $subsite->ID : 0;

            $cacheKey = self::$subsite_cache_key_prefix . $SubsiteID;
        }

        // Falls Cache vorhanden, zurückgeben
        if ($cache && $cache->has($cacheKey)) {
            return $cache->get($cacheKey);
        }

        // Aktuelle SiteConfig holen (Hauptseite oder Subsite)
        $siteConfig = SiteConfig::current_site_config();

        if(class_exists(Subsite::class)) {
            // Hauptseiten-SiteConfig holen
            $mainSiteConfig = SiteConfig::get()->filter('SubsiteID', 0)->first();
        }

        // Funktion zur Überprüfung, ob eine Konfiguration vollständig ist
        $isComplete = function ($config) {
            if($config->CustomDSN){
                return true;
            }
            return $config
                && !empty($config->SMTPServer)
                && !empty($config->SMTPUser)
                && !empty($config->SMTPPassword)
                && !empty($config->SMTPPort)
                && !empty($config->AdminEmail);
        };

        // Bestimmen, welche Konfiguration verwendet wird
        if ($isComplete($siteConfig)) {
            $finalConfig = $siteConfig;
        } elseif (class_exists(Subsite::class) && $isComplete($mainSiteConfig)) {
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
        $hasAnyConfig = function ($config) {
            return !empty($config['SMTPServer']) 
                || !empty($config['SMTPUser']) 
                || !empty($config['SMTPPassword']) 
                || !empty($config['SMTPPort']);
        };

        if(!$isComplete(ArrayData::create($config))){
            if(Email::Config()->custom_dsn){
                $config['CustomDSN'] = Email::Config()->custom_dsn;
            } else if($hasAnyConfig($config)) {
                // Nur Exception werfen, wenn teilweise konfiguriert (nicht wenn komplett leer)
                throw new Exception('SMTP-Konfiguration ist unvollständig.');
            }
        }

        if($cache){
            // Speichere neuen Cache für 24h
            $cache->set($cacheKey, $config, 86400);
        }

        return $config;
    }

    public static function flush(): void
    {
        $cache = Injector::inst()->get(CacheInterface::class . '.MailConfigCache');
        if($cache){
            $cache->clear();
        }
    }
}
