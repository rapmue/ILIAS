<?php declare(strict_types=1);
/* Copyright (c) 1998-2021 ILIAS open source, Extended GPL, see docs/LICENSE */

use Psr\Http\Message\ServerRequestInterface;
use ILIAS\HTTP\GlobalHttpState;
use ILIAS\Refinery\Factory as Refinery;

/**
* Class ilAccountMail
*
* Sends e-mail to newly created accounts.
*
* @author Stefan Schneider <stefan.schneider@hrz.uni-giessen.de>
* @author Alex Killing <alex.killing@hrz.uni-giessen.de>
*
*/
class ilAccountMail
{
    private GlobalHttpState $http;
    private Refinery $refinery;
    public string $u_password = "";

    /**
    * user object (instance of ilObjUser)
    * @var	object
    */
    public $user = "";
    /**
    * repository item target (e.g. "crs_123")
    */
    public string $target = "";
    private bool $lang_variables_as_fallback = false;
    /**
     * @var string[]
     */
    private array $attachments = [];
    private bool $attachConfiguredFiles = false;
    /** @var array{
     *  lang: string,
     *  subject: string|null,
     *  body: string|null,
     *  salf_m: string|null
     *  sal_f: string|null,
     *  sal_g: string|null,
     *  type: string,
     *  att_file:
     *  string|null
     * }
     */
    private array $amail = [];

    public function __construct()
    {
        global $DIC;
        $this->http = $DIC->http();
        $this->refinery = $DIC->refinery();
    }
    
    public function useLangVariablesAsFallback(bool $a_status) : void
    {
        $this->lang_variables_as_fallback = $a_status;
    }
    
    public function areLangVariablesUsedAsFallback() : bool
    {
        return $this->lang_variables_as_fallback;
    }

    
    public function shouldAttachConfiguredFiles() : bool
    {
        return $this->attachConfiguredFiles;
    }

    
    public function setAttachConfiguredFiles(bool $attachConfiguredFiles) : void
    {
        $this->attachConfiguredFiles = $attachConfiguredFiles;
    }

    public function setUserPassword(string $a_pwd) : void
    {
        $this->u_password = $a_pwd;
    }

    public function getUserPassword() : string
    {
        return $this->u_password;
    }

    /**
    * Set user. The user object should provide email, language
    * login, gender, first and last name
    *
    * @param	object	$a_user		user object
    */
    public function setUser(&$a_user) : void
    {
        if (
            $this->user instanceof ilObjUser &&
            $a_user instanceof ilObjUser &&
            $a_user->getId() !== $this->user->getId()
        ) {
            $this->attachments = [];
        }

        $this->user = &$a_user;
    }

    /**
    * @return	object		user object
    */
    public function &getUser() : object
    {
        return $this->user;
    }

    /**
    * @return	string		repository item target
    */
    public function getTarget() : string
    {
        return $this->target;
    }

    /**
    * reset all values
    */
    public function reset() : void
    {
        unset($this->u_password, $this->user, $this->target);
    }

    /**
     * @param string[]
     * @return string[]
     */
    private function ensureValidMailDataShape(array $mailData) : array
    {
        foreach (['lang', 'subject', 'body', 'sal_f', 'sal_g', 'sal_m', 'type'] as $key) {
            if (!isset($mailData[$key])) {
                $mailData[$key] = '';
            }
        }

        $mailData['subject'] = trim($mailData['subject']);
        $mailData['body'] = trim($mailData['body']);

        return $mailData;
    }

    /**
     * @return string[]
     */
    private function readAccountMail(string $a_lang) : array
    {
        if (!is_array($this->amail[$a_lang])) {
            $this->amail[$a_lang] = $this->ensureValidMailDataShape(
                ilObjUserFolder::_lookupNewAccountMail($a_lang)
            );
        }

        return $this->amail[$a_lang];
    }

    private function addAttachments($mailData) : void
    {
        if ($this->shouldAttachConfiguredFiles() && isset($mailData['att_file'])) {
            $fs = new ilFSStorageUserFolder(USER_FOLDER_ID);
            $fs->create();

            $pathToFile = '/' . implode('/', array_map(static function (string $pathPart) : string {
                return trim($pathPart, '/');
            }, [
                $fs->getAbsolutePath(),
                $mailData['lang'],
            ]));

            $this->addAttachment($pathToFile, $mailData['att_file']);
        }
    }
    
    /**
    * Sends the mail with its object properties as MimeMail
    * It first tries to read the mail body, subject and sender address from posted named formular fields.
    * If no field values found the defaults are used.
    * Placehoders will be replaced by the appropriate data.
     *
    */
    public function send() : bool
    {
        global $ilSetting;
        
        $user = &$this->getUser();
        
        if (!$user->getEmail()) {
            return false;
        }
        
        // determine language and get account mail data
        // fall back to default language if acccount mail data is not given for user language.
        $amail = $this->readAccountMail($user->getLanguage());
        $lang = $user->getLanguage();
        if ($amail['body'] === '' || $amail['subject'] === '') {
            $amail = $this->readAccountMail($ilSetting->get('language'));
            $lang = $ilSetting->get('language');
        }
        
        // fallback if mail data is still not given
        if ($this->areLangVariablesUsedAsFallback() && ($amail['body'] === '' || $amail['subject'] === '')) {
            $lang = $user->getLanguage();
            $tmp_lang = new ilLanguage($lang);
                        
            // mail subject
            $mail_subject = $tmp_lang->txt('reg_mail_subject');
            
            $timelimit = "";
            if (!$user->checkTimeLimit()) {
                $tmp_lang->loadLanguageModule("registration");
                
                // #6098
                $timelimit_from = new ilDateTime($user->getTimeLimitFrom(), IL_CAL_UNIX);
                $timelimit_until = new ilDateTime($user->getTimeLimitUntil(), IL_CAL_UNIX);
                $timelimit = ilDatePresentation::formatPeriod($timelimit_from, $timelimit_until);
                $timelimit = "\n" . sprintf($tmp_lang->txt('reg_mail_body_timelimit'), $timelimit) . "\n\n";
            }

            // mail body
            $mail_body = $tmp_lang->txt('reg_mail_body_salutation') . ' ' . $user->getFullname() . ",\n\n" .
                $tmp_lang->txt('reg_mail_body_text1') . "\n\n" .
                $tmp_lang->txt('reg_mail_body_text2') . "\n" .
                ILIAS_HTTP_PATH . '/login.php?client_id=' . CLIENT_ID . "\n";
            $mail_body .= $tmp_lang->txt('login') . ': ' . $user->getLogin() . "\n";
            $mail_body .= $tmp_lang->txt('passwd') . ': ' . $this->u_password . "\n";
            $mail_body .= "\n" . $timelimit;
            $mail_body .= $tmp_lang->txt('reg_mail_body_text3') . "\n\r";
            $mail_body .= $user->getProfileAsString($tmp_lang);
        } else {
            $this->addAttachments($amail);

            // replace placeholders
            $mail_subject = $this->replacePlaceholders($amail['subject'], $user, $amail, $lang);
            $mail_body = $this->replacePlaceholders($amail['body'], $user, $amail, $lang);
        }

        /** @var ilMailMimeSenderFactory $senderFactory */
        $senderFactory = $GLOBALS["DIC"]["mail.mime.sender.factory"];

        // send the mail
        $mmail = new ilMimeMail();
        $mmail->From($senderFactory->system());
        $mmail->Subject($mail_subject, true);
        $mmail->To($user->getEmail());
        $mmail->Body($mail_body);
        
        foreach ($this->attachments as $filename => $display_name) {
            $mmail->Attach($filename, "", "attachment", $display_name);
        }
        /*
        echo "<br><br><b>From</b>:".$ilSetting->get("admin_email");
        echo "<br><br><b>To</b>:".$user->getEmail();
        echo "<br><br><b>Subject</b>:".$mail_subject;
        echo "<br><br><b>Body</b>:".$mail_body;
        return true;*/
        $mmail->Send();
        
        return true;
    }
    
    public function replacePlaceholders(string $a_string, $a_user, array $a_amail, $a_lang)
    {
        global $ilSetting, $tree;
        
        // determine salutation
        switch ($a_user->getGender()) {
            case "f":	$gender_salut = $a_amail["sal_f"];
                        break;
            case "m":	$gender_salut = $a_amail["sal_m"];
                        break;
            default:	$gender_salut = $a_amail["sal_g"];
        }
        $gender_salut = trim($gender_salut);

        // BEGIN Mail Include E-Mail Address in account mail
        // END Mail Include E-Mail Address in account mail
        $a_string = str_replace(
            [
                "[MAIL_SALUTATION]",
                "[LOGIN]",
                "[FIRST_NAME]",
                "[LAST_NAME]",
                "[EMAIL]",
                "[PASSWORD]",
                "[ILIAS_URL]",
                "[CLIENT_NAME]",
                "[ADMIN_MAIL]",
            ],
            [
                $gender_salut,
                $a_user->getLogin(),
                $a_user->getFirstname(),
                $a_user->getLastname(),
                $a_user->getEmail(),
                $this->getUserPassword(),
                ILIAS_HTTP_PATH . "/login.php?client_id=" . CLIENT_ID,
                CLIENT_NAME,
                $ilSetting->get("admin_email"),
            ],
            $a_string
        );
            
        // (no) password sections
        if ($this->getUserPassword() === "") {
            // #12232
            $a_string = preg_replace(
                "/\[IF_PASSWORD\].*\[\/IF_PASSWORD\]/imsU",
                "",
                $a_string
            );
            $a_string = preg_replace(
                "/\[IF_NO_PASSWORD\](.*)\[\/IF_NO_PASSWORD\]/imsU",
                "$1",
                $a_string
            );
        } else {
            $a_string = preg_replace(
                "/\[IF_NO_PASSWORD\].*\[\/IF_NO_PASSWORD\]/imsU",
                "",
                $a_string
            );
            $a_string = preg_replace(
                "/\[IF_PASSWORD\](.*)\[\/IF_PASSWORD\]/imsU",
                "$1",
                $a_string
            );
        }
                
        // #13346
        if (!$a_user->getTimeLimitUnlimited()) {
            // #6098
            $a_string = preg_replace(
                "/\[IF_TIMELIMIT\](.*)\[\/IF_TIMELIMIT\]/imsU",
                "$1",
                $a_string
            );
            $timelimit_from = new ilDateTime($a_user->getTimeLimitFrom(), IL_CAL_UNIX);
            $timelimit_until = new ilDateTime($a_user->getTimeLimitUntil(), IL_CAL_UNIX);
            $timelimit = ilDatePresentation::formatPeriod($timelimit_from, $timelimit_until);
            $a_string = str_replace("[TIMELIMIT]", $timelimit, $a_string);
        } else {
            $a_string = preg_replace(
                "/\[IF_TIMELIMIT\](.*)\[\/IF_TIMELIMIT\]/imsU",
                "",
                $a_string
            );
        }
        
        // target
        $tar = false;
        if ($this->http->wrapper()->query()->has('target') &&
            $this->http->wrapper()->query()->retrieve('target', $this->refinery->kindlyTo()->string()) !== ""
        ) {
            $target = $this->http->wrapper()->query()->retrieve("target", $this->refinery->kindlyTo()->string());
            $tarr = explode("_", $target);
            if ($tree->isInTree($tarr[1])) {
                $obj_id = ilObject::_lookupObjId($tarr[1]);
                $type = ilObject::_lookupType($obj_id);
                if ($type === $tarr[0]) {
                    $a_string = str_replace(
                        ["[TARGET_TITLE]", "[TARGET]"],
                        [
                            ilObject::_lookupTitle($obj_id),
                            ILIAS_HTTP_PATH .
                            "/goto.php?client_id=" .
                            CLIENT_ID .
                            "&target=" .
                            $target
                        ],
                        $a_string
                    );
                        
                    // this looks complicated, but we may have no initilised $lng object here
                    // if mail is send during user creation in authentication
                    $a_string = str_replace(
                        "[TARGET_TYPE]",
                        ilLanguage::_lookupEntry($a_lang, "common", "obj_" . $tarr[0]),
                        $a_string
                    );
                        
                    $tar = true;
                }
            }
        }

        // (no) target section
        if (!$tar) {
            $a_string = preg_replace("/\[IF_TARGET\].*\[\/IF_TARGET\]/imsU", "", $a_string);
        } else {
            $a_string = preg_replace("/\[IF_TARGET\](.*)\[\/IF_TARGET\]/imsU", "$1", $a_string);
        }

        return $a_string;
    }
    
    public function addAttachment(string $a_filename, string $a_display_name) : void
    {
        $this->attachments[$a_filename] = $a_display_name;
    }
}
