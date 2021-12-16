<?php
namespace TymFrontiers\OTP;
use \Mailgun\Mailgun,
    \TymFrontiers\Data,
    \TymFrontiers\Generic,
    \TymFrontiers\InstanceError,
    \TymFrontiers\MySQLDatabase,
    \TymFrontiers\MultiForm;

class Email{
  private static $_mg_api_domain;
  private static $_mg_api_key;

  public $sender = [];
  public $receiver = [];
  public $sender_url;

  public $errors = [];

  function __construct (string $mg_api_domain, string $mg_api_key, $sender = "", string $sender_url = "") {
    if (empty($mg_api_domain) || empty($mg_api_key)) {
      throw new \Exception("Kindly parse Mailgun API-domain and API-key. Mailgun credentials can be found: https://www.mailgun.com/", 1);
    } else {
      self::$_mg_api_domain = $mg_api_domain;
      self::$_mg_api_key = $mg_api_key;
    }
    if (!empty($sender)) $this->_prep_email_info("sender", $sender);
    if (!empty($sender_url) && !$url = \filter_var($sender_url, FILTER_VALIDATE_URL)) {
      throw new \Exception("Kindly provide URL where your recipient can learn more about you.", 1);
    }
    if (!empty($sender_url)) $this->sender_url = $url;
  }

  protected function _prep_email_info (string $prop, string $email) {
    $email_r = Generic::splitEmailName($email);
    if (empty($email_r['email'])) {
      throw new \Exception("[{$prop}]: Invalid email input", 1);
    }
    $this->$prop = $email_r;
    return $email_r;
  }
  public function send (string $email_address, int $code_len = 6, string $code_variant = Data::RAND_NUMBERS, string $custom_message = "", int $expiry = 0) {
    $this->_prep_email_info("receiver", $email_address);
    $otp_code = Data::uniqueRand('', $code_len, $code_variant, false);
    $otp_ref = Data::uniqueRand('', 16, Data::RAND_MIXED_LOWER);
    $otp_qid = NULL;
    $data = new Data;
    $otp_expiry = $expiry > 0
      ? $expiry
      : \strtotime("+2 Days", \time());
    $otp_expiry = \strftime("%Y-%m-%d %H:%M:%S",$otp_expiry);
    $greeting = !empty($this->receiver['name'])
      ? ("Hello " . \explode(' ', $this->receiver['name'])[0] . ",")
      : "Hello there,";
    $subject = "[SECRET] One Time Password";
    $message = "<html><div style=\"max-width: 500px; min-width:380px; margin: 0 auto; padding: 12px;\"><p>{$greeting}</p>";
      $message .= "<p style=\"margin-top:5px; margin-bottom:5px\"> <span style=\"background-color:#e4e4e4; border: solid 1px #cbcbcb; padding: 8px; margin-right: 3px; color:#000; letter-spacing: 4px; font-size: 1.5em; font-family: 'Courier New', Monospace; font-weight: bold; border-radius: 3px; -moz-border-radius: 3px; -webkit-border-radius: 3px; \">{$data->charSplit($otp_code,($code_len <= 6 ? 3 : 4), " ")}</span> is your OTP Code.</p>";
      if (!empty($custom_message)) {
        $message .= ("<p>" . \strip_tags($custom_message, "<br>") ."</p>");
      }
      $message .= "<p>[NOTE]: DO NOT SHARE THIS MESSAGE OR ITS CONTENTS WITH ANYONE, YOU WILL BE AT YOUR OWN RISK!</p>";
      $message .= "<p>For more info visit: {$this->sender_url}</p>";
    $message .= "</div></html>";
    $message_text = "{$otp_code} is your OTP code.";
    // send message
    $mgClient = Mailgun::create(self::$_mg_api_key);
    try {
      $result = $mgClient->messages()->send(self::$_mg_api_domain, [
        'from' => (
          !empty($this->sender['name'])
            ? "{$this->sender['name']} <{$this->sender['email']}>"
            : $this->sender['email']
        ),
        'to' => (
          !empty($this->receiver['name'])
            ? "{$this->receiver['name']} <{$this->receiver['email']}>"
            : $this->receiver['email']
        ),
        'subject' => $subject,
        'text' => $message_text,
        'html' => $message
      ]);
      if(
        \is_object($result) &&
        !empty($result->getId()) &&
        \strpos($result->getId(), self::$_mg_api_domain) !== false
      ){
        $otp_qid = $result->getId();
      }
    } catch (\Exception $e) {
      $this->errors['send'][] = [
        0, 256, "Failed to send message at this time due to third-party errors", __FILE__, __LINE__
      ];
      $this->errors['send'][] = [
        7, 256, "[Mailgun]: API may be incorrectly configured.", __FILE__, __LINE__
      ];
      return false;
    }
    // message was sent
    // save detail
    $otp = new MultiForm(MYSQL_LOG_DB, 'otp_email', 'id');
    $otp->ref = $otp_ref;
    $otp->user = $this->receiver['email'];
    $otp->qid = $otp_qid;
    $otp->code = $otp_code;
    $otp->expiry = $otp_expiry;
    $otp->subject = $subject;
    $otp->message = $message;
    $otp->message_text = $message_text;
    $otp->sender = !empty($this->sender['name'])
      ? "{$this->sender['name']} <{$this->sender['email']}>"
      : $this->sender['email'];
    $otp->receiver = !empty($this->receiver['name'])
      ? "{$this->receiver['name']} <{$this->receiver['email']}>"
      : $this->receiver['email'];
    if (!$otp->create()) {
      if (!empty($otp->errors['query'])) {
        $errs = (new InstanceError($otp, true))->get("query");
        foreach ($errs as $err) {
          $this->errors['query'][] = $err;
        }
      }
      $this->errors['send'][] = [
        0, 256, "Failed to save OTP record, contact Developer.", __FILE__, __LINE__
      ];
      return false;
    }
    return $otp_ref;
  }
  public function resend (string $ref) {
    global $database;
    if (!$otp = (new MultiForm(MYSQL_LOG_DB, 'otp_email', 'id'))
      ->findBySql("SELECT *
        FROM :db:.:tbl:
        WHERE ref='{$database->escapeValue($ref)}'
        LIMIT 1")){
      $this->errors["resend"][] = [
        7, 256, "No valid OTP record found for given [ref].",
        __FILE__, __LINE__
      ];
      return false;
    }
    $otp = $otp[0];
    $mgClient = Mailgun::create(self::$_mg_api_key);
    try {
      $result = $mgClient->messages()->send(self::$_mg_api_domain, [
        'from' => $otp->sender,
        'to' => $otp->receiver,
        'subject' => $otp->subject,
        'text' => $otp->message_text,
        'html' => $otp->message
      ]);
      if(
        \is_object($result) &&
        !empty($result->getId()) &&
        \strpos($result->getId(), self::$_mg_api_domain) !== false
      ){
        // $otp_qid = $result->getId();
        // update expiry
        if (!empty($otp->expiry) && \strtotime($otp->expiry) < \strtotime("+ 2 Hours", \time())) {
          $otp_expiry = \strtotime("+2 Hours", \time());
          $otp_expiry = \strftime("%Y-%m-%d %H:%M:%S",$otp_expiry);
          // change to developer mysql account and update
          if (!\defined("MYSQL_DEVELOPER_USERNAME")) {
            $this->errors['resend'][] = [
              7, 256, "MYSQL_DEVELOPER_USERNAME/MYSQL_DEVELOPER_PASS not defined.", __FILE__, __LINE__
            ];
            return false;
          }
          $conn = new MySQLDatabase(MYSQL_SERVER, MYSQL_DEVELOPER_USERNAME, MYSQL_DEVELOPER_PASS);
          if ($conn) {
            $log_db = MYSQL_LOG_DB;
            return $conn->query("UPDATE `{$log_db}`.`otp_email` SET expiry = '{$conn->escapeValue($otp_expiry)}' WHERE id={$otp->id} LIMIT 1");
          }
          return false;
        }
      }
    } catch (\Exception $e) {
      $this->errors['resend'][] = [
        0, 256, "Failed to re-send message at this time.", __FILE__, __LINE__
      ];
      $this->errors['resend'][] = [
        7, 256, "[Mailgun]: API may be incorrectly configured.", __FILE__, __LINE__
      ];
      return false;
    }
    return true;
  }
  public function verify (string $user, string $code) {
    global $database;
    if ($otp = (new MultiForm(MYSQL_LOG_DB, 'otp_email', 'id'))
      ->findBySql("SELECT *
                   FROM :db:.:tbl:
                   WHERE `user`='{$database->escapeValue($user)}'
                   AND `code`='{$database->escapeValue($code)}'
                   AND (
                     expiry IS NULL
                     OR expiry >= NOW()
                   )
                   LIMIT 1")) {
      // return
      return true;
    }
    return false;
  }

}
