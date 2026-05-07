<?php

namespace Drupal\custom_example\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\symfony_mailer\Address;
use Drupal\symfony_mailer\EmailFactoryInterface;
use Drupal\symfony_mailer\MailerHelperTrait;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Mail\MailManagerInterface;
use Twilio\Rest\Client;

/**
 * Resource Manager Class.
 *
 * @package Drupal\custom_example
 */
class ResourceManager {
  use StringTranslationTrait;
  use MailerHelperTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * @var AccountInterface $account
   */
  protected $account;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * Symfony Mail Factory.
   *
   * @var \Drupal\symfony_mailer\EmailFactory
   */
  protected $mailFactory;

  /**
   * Constructs a new ResourceManager.
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param AccountInterface $current_account
   * @param Connection $database
   * @param LoggerChannelFactoryInterface $logger
   * @param ConfigFactoryInterface $config_factory
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_account,
    Connection $database,
    LoggerChannelFactoryInterface $logger,
    ConfigFactoryInterface $config_factory,
    MailManagerInterface $mailManager,
    EmailFactoryInterface $mail_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('resource');
    $this->configFactory = $config_factory;
    $this->mailManager = $mailManager;
    $this->mailFactory = $mail_factory;
  }

  /**
   * Create email verification log
   *
   * @param AccountInterface $account
   * @return array
   */
  public function createEmailVerificationLog(AccountInterface $account) {
    $user = \Drupal\user\Entity\User::load($account->id());
    $mail = $user->hasField('field_user_alert_email') ? $user->get('field_user_alert_email')->value : '';
    $expire = strtotime('+1 day');
    $hash_code = hash('sha256',time() . $mail . $expire);
    $verify_code = rand(100000, 999999);

    $this->database->merge('bbsradio_verification_log')
      ->insertFields([
        'uid' => $this->account->id(),
        'type' => 'mail',
        'reference_resource' => $mail,
        'hash_code' => $hash_code,
        'verify_code' => $verify_code,
        'expire' => $expire,
        'status' => 0,
      ])
      ->updateFields([
        'reference_resource' => $mail,
        'hash_code' => $hash_code,
        'verify_code' => $verify_code,
        'expire' => $expire,
        'status' => 0,
      ])
      ->keys([
        'uid' => $this->account->id(),
        'type' => 'mail',
      ])
      ->execute();

    return [
      'hash_code' => $hash_code,
      'verify_code' => $verify_code
    ];
  }

  /**
   * Send verification email to user mail
   *
   * @param AccountInterface $account
   * @param string $hash_code
   * @param string $verification_code
   * @return bool
   */
  public function sendVerificationEmail(AccountInterface $account, string $hash_code, string $verification_code) {
    $lang_code = $this->account->getPreferredLangcode();

    $enable_mail_mail_verification = $this->configFactory->get('custom_example.email_verification_settings')->get('enable_mail_mail_verification');
    $max_verification_mail = $this->configFactory->get('custom_example.email_verification_settings')->get('max_verification_mail');

    $subject = $this->configFactory->get('custom_example.email_verification_settings')->get('verification_mail_subject');
    $subject = $this->replaceTokens($account, $hash_code, $verification_code, $subject);

    $body = $this->configFactory->get('custom_example.email_verification_settings')->get('verification_mail_content');
    $body = $this->replaceTokens($account, $hash_code, $verification_code, $body);

    // if mail verification disabled, return
    if (!$enable_mail_mail_verification) {
      $this->logger->notice('Email verification is disabled.');
      return false;
    }

    // if empty return with log
    if (empty($subject) || empty($body)) {
      $this->logger->error('Sending verification mail failed due to empty Subject or Body.');
      return false;
    }

    // return if user reached to the daily limit
    $limit = $this->mailVerificationLimit($account, 0);
    if ($limit >= $max_verification_mail) {
      $this->logger->error('Sending verification mail failed due to max limit reached.');
      return false;
    }

    $user = \Drupal\user\Entity\User::load($this->account->id());
    $mail = $user->hasField('field_user_alert_email') ? $user->get('field_user_alert_email')->value : '';

    // Send mail
    $module = 'custom_example';
    $key = 'user_mail_verification';
    $from_name = $this->configFactory->get('system.site')->get('name');
    $from = $this->configFactory->get('system.site')->get('mail');
    $to = $mail;
    $to_name = $user->hasField('field_user_full_name') && !$user->get('field_user_full_name')->isEmpty() ?
      $user->get('field_user_full_name')->value : $user->getAccountName();

    $parameter = [
      'subject' => $subject,
      'body' => $body,
    ];

    // $this->mailManager->mail($module, $key, $to, $lang_code, $parameter, $from, TRUE);
    $mail = $this->mailFactory->newTypedEmail($module, $key, [
      'id' => $module,
      'send' => TRUE,
      'module' => $module,
      'key' => $key,
      'subject' => $subject,
      'body' => [Markup::create($body)],
    ]);
    $to = new Address($to, $to_name, $lang_code);
    $from = new Address($from, '"' . $from_name . '"', $lang_code);
    $mail->setTo($to);
    $mail->setFrom($from);
    $mail->setSubject($subject, FALSE);
    $mail->setBody(['#markup' => Markup::create($body)]);
    $mail->setTextBody($this->helper()->htmlToText($body));
    $mail_status = $mail->send();

    if (!$mail_status) {
      $this->logger->error('Verification mail failed to send to %mail.',
        [
          '%mail' => $mail,
        ]
      );
    }
    else {
      $this->logger->notice('Verification mail sent to %mail.',
        [
          '%mail' => $mail,
        ]
      );
    }

    // Update count
    $this->mailVerificationLimit($account, 1);

    return true;
  }

  /**
   * Verify user mail with verification code
   *
   * @param AccountInterface $account
   * @param string $verification_code
   * @param int $update
   * @return bool
   */
  public function verifyEmailVerificationCode(AccountInterface $account, string $verification_code, int $update = 0) {
    if ($account == false || empty($verification_code)) {
      return false;
    }

    $user = \Drupal\user\Entity\User::load($account->id());
    $mail = $user->hasField('field_user_alert_email') ? $user->get('field_user_alert_email')->value : '';

    $query = $this->database->select('bbsradio_verification_log', 'v')
      ->condition('v.uid', $account->id(), '=')
      ->condition('v.type', 'mail', '=')
      ->condition('v.reference_resource', $mail, '=')
      ->condition('v.verify_code', $verification_code, '=')
      ->condition('v.expire', time(), '>=')
      ->condition('v.status', 0, '=')
      ->fields('v', ['uid', 'type', 'reference_resource', 'expire'])
      ->range(0, 1);
    $result = $query->execute()->fetchObject();

    if ($result) {
      if ($update) {
        $num_updated = $this->database->update('bbsradio_verification_log')
          ->fields([
            'status' => 1,
          ])
          ->condition('uid', $account->id(), '=')
          ->condition('type', 'mail', '=')
          ->condition('reference_resource', $mail, '=')
          ->condition('verify_code', $verification_code, '=')
          ->condition('status', 0, '=')
          ->execute();
      }
      return true;
    }
    else {
      return false;
    }
  }

  /**
   * Very user email address using the hash code sent over email
   *
   * @param AccountInterface $account
   * @param string $hash_code
   * @param int $update
   * @return bool
   */
  public function verifyEmail(AccountInterface $account, string $hash_code = '', int $update = 0) {
    if (empty($hash_code)) {
      return false;
    }

    $query = $this->database->select('bbsradio_verification_log', 'v')
      ->condition('v.type', 'mail', '=')
      ->condition('v.hash_code', $hash_code, '=')
      ->condition('v.expire', time(), '>=')
      ->condition('v.status', 0, '=')
      ->fields('v', ['uid', 'type', 'reference_resource', 'expire'])
      ->range(0, 1);
    $result = $query->execute()->fetchObject();

    if ($result) {
      if ($update) {
        $num_updated = $this->database->update('bbsradio_verification_log')
          ->fields([
            'status' => 1,
          ])
          ->condition('type', 'mail', '=')
          ->condition('hash_code', $hash_code, '=')
          ->condition('status', 0, '=')
          ->execute();
      }
      return true;
    }
    else {
      return false;
    }
  }


  /**
   * Get log by hash code
   *
   * @param string $hash_code
   * @return bool|array
   */
  public function getLogByHashCode(string $hash_code) {
    if (empty($hash_code)) {
      return false;
    }

    $query = $this->database->select('bbsradio_verification_log', 'v')
      ->condition('v.hash_code', $hash_code, '=')
      ->fields('v', ['uid', 'type', 'reference_resource', 'hash_code', 'verify_code', 'expire', 'status'])
      ->range(0, 1);
    $result = $query->execute()->fetchObject();

    if ($result) {
      return [
        'uid' => $result->uid,
        'type' => $result->uid,
        'reference_resource' => $result->reference_resource,
        'verify_code' => $result->verify_code,
        'expire' => $result->expire,
        'status' => $result->status
      ];
    }

    return false;
  }

  /**
   * Create phone verification log
   *
   * @param AccountInterface $account
   *
   * @return array
   */
  public function setPhoneVerificationLog(AccountInterface $account) {
    $user = \Drupal\user\Entity\User::load($account->id());
    $phone = $user->hasField('field_user_alert_phone') ? $user->get('field_user_alert_phone')->value : rand(100000000000000, 999999999999999);

    $expire = strtotime('+1 hour');
    $hash_code = hash('sha256',time() . $phone . $expire);
    $verify_code = rand(100000, 999999);

    $this->database->merge('bbsradio_verification_log')
      ->insertFields([
        'uid' => $this->account->id(),
        'type' => 'phone',
        'reference_resource' => $phone,
        'hash_code' => $hash_code,
        'verify_code' => $verify_code,
        'expire' => $expire,
        'status' => 0,
      ])
      ->updateFields([
        'reference_resource' => $phone,
        'hash_code' => $hash_code,
        'verify_code' => $verify_code,
        'expire' => $expire,
        'status' => 0,
      ])
      ->keys([
        'uid' => $this->account->id(),
        'type' => 'phone',
      ])
      ->execute();

    return [
      'hash_code' => $hash_code,
      'verify_code' => $verify_code
    ];
  }

  /**
   * @param AccountInterface $account
   * @param string $hash_code
   * @param string $verification_code
   * @param string $phone
   * @return bool
   * @throws \Twilio\Exceptions\ConfigurationException
   * @throws \Twilio\Exceptions\TwilioException
   */
  public function sendVerificationSms(AccountInterface $account, string $hash_code, string $verification_code, string $phone) {
    $enable_sms_verification = $this->configFactory->get('custom_example.phone_verification_settings')->get('enable_sms_verification');
    $max_verification_sms = $this->configFactory->get('custom_example.phone_verification_settings')->get('max_verification_sms');

    $sid = $this->configFactory->get('custom_example.phone_verification_settings')->get('sid');
    $auth_token = $this->configFactory->get('custom_example.phone_verification_settings')->get('auth_token');
    $twilio_number = $this->configFactory->get('custom_example.phone_verification_settings')->get('twilio_number');

    $verification_text = $this->configFactory->get('custom_example.phone_verification_settings')->get('verification_text');
    $verification_text = $this->replaceTokens($account, $hash_code, $verification_code, $verification_text);

    // if sms verification disabled, return
    if (!$enable_sms_verification) {
      $this->logger->notice('SMS verification is disabled.');
      return false;
    }

    if (empty($sid) || empty($auth_token) || empty($twilio_number)) {
      $this->logger->error('Sending verification sms failed due to empty api information.');
      return false;
    }

    // if empty return with log
    if (empty($verification_text)) {
      $this->logger->error('Sending verification sms failed due to empty text.');
      return false;
    }

    // return if user reached to the daily limit
    $limit = $this->phoneVerificationLimit($account, 0);
    if ($limit >= $max_verification_sms) {
      $this->logger->error('Sending verification sms failed due to max limit reached.');
      return false;
    }

    try {
      $client = new \Twilio\Rest\Client($sid, $auth_token);
      $message = $client->messages->create(
        $phone, // Text this number
        [
          'from' => $twilio_number, // From Twilio number
          'body' => $verification_text
        ]
      );

      // Update count
      $this->phoneVerificationLimit($account, 1);

      return true;
    }
    catch (\Exception $ex) {
      \Drupal::logger('verification')->error($ex->getMessage());
    }

    return false;
  }

  /**
   * Verify user phone with verification code
   *
   * @param AccountInterface $account
   * @param string $verification_code
   * @param int $update
   * @return bool
   */
  public function verifyPhoneVerificationCode(AccountInterface $account, string $verification_code, int $update = 0) {
    if ($account == false || empty($verification_code)) {
      return false;
    }

    $user = \Drupal\user\Entity\User::load($account->id());
    $phone = $user->hasField('field_user_alert_phone') ? $user->get('field_user_alert_phone')->value : '';

    $query = $this->database->select('bbsradio_verification_log', 'v')
      ->condition('v.uid', $account->id(), '=')
      ->condition('v.type', 'phone', '=')
      ->condition('v.reference_resource', $phone, '=')
      ->condition('v.verify_code', $verification_code, '=')
      ->condition('v.expire', time(), '>=')
      ->condition('v.status', 0, '=')
      ->fields('v', ['uid', 'type', 'reference_resource', 'expire'])
      ->range(0, 1);
    $result = $query->execute()->fetchObject();

    if ($result) {
      if ($update) {
        $num_updated = $this->database->update('bbsradio_verification_log')
          ->fields([
            'status' => 1,
          ])
          ->condition('uid', $account->id(), '=')
          ->condition('type', 'phone', '=')
          ->condition('reference_resource', $phone, '=')
          ->condition('verify_code', $verification_code, '=')
          ->condition('status', 0, '=')
          ->execute();
      }
      return true;
    }
    else {
      return false;
    }
  }

  /**
   * Replace tokens for mail or sms body
   *
   * @param AccountInterface $account
   * @param $hash_code
   * @param $verification_code
   * @param $text
   * @return array|string|string[]
   */
  public function replaceTokens(AccountInterface $account, $hash_code, $verification_code, $text) {
    // Tokens
    $site_url = \Drupal::request()->getSchemeAndHttpHost();
    $site_name = \Drupal::config('system.site')->get('name');
    $verify_url = $site_url . '/bbsradio/verify/email/' . $hash_code;

    // Replace
    $text = str_replace('%site-url%', $site_url, $text);
    $text = str_replace('%site-name%', $site_name, $text);
    $text = str_replace('%verify-url%', $verify_url, $text);
    $text = str_replace('%verification-code%', $verification_code, $text);

    return $text;
  }

  /**
   * Get or/and update the daily mail sending limit for each user
   *
   * @param AccountInterface $account
   * @param int $update
   * @return int
   */
  public function mailVerificationLimit(AccountInterface $account, int $update = 0) {
    $query = $this->database->select('bbsradio_user_verification_limits', 'b')
      ->condition('b.uid', $account->id(), '=')
      ->fields('b', ['uid', 'mail_date', 'mail_count'])
      ->range(0, 1);
    $result = $query->execute()->fetchObject();
    $mail_date = !empty($result->mail_date) ? $result->mail_date : '';
    $mail_count = !empty($result->mail_count) ? $result->mail_count : 0;

    if ($update && !$result) {
      $this->database->merge('bbsradio_user_verification_limits')
        ->insertFields([
          'uid' => $account->id(),
          'mail_date' => date('Y-m-d'),
          'mail_count' => 1,
        ])
        ->updateFields([
          'mail_date' => date('Y-m-d'),
          'mail_count' => 1,
        ])
        ->keys([
          'uid' => $account->id(),
        ])
        ->execute();
      $mail_count = 1;
    }
    elseif ($update && $result && $mail_date == date('Y-m-d')) {
      $this->database->merge('bbsradio_user_verification_limits')
        ->insertFields([
          'uid' => $account->id(),
          'mail_date' => date('Y-m-d'),
          'mail_count' => 1,
        ])
        ->updateFields([
          'mail_date' => date('Y-m-d'),
        ])
        ->expression('mail_count', 'mail_count + :inc', [':inc' => 1])
        ->keys([
          'uid' => $account->id(),
        ])
        ->execute();
      $mail_count = $mail_count + 1;
    }
    elseif ($update && $result && $mail_date != date('Y-m-d')) {
      $this->database->merge('bbsradio_user_verification_limits')
        ->insertFields([
          'uid' => $account->id(),
          'mail_date' => date('Y-m-d'),
          'mail_count' => 1,
        ])
        ->updateFields([
          'mail_date' => date('Y-m-d'),
          'mail_count' => 1,
        ])
        ->keys([
          'uid' => $account->id(),
        ])
        ->execute();
      $mail_count = 1;
    }
    elseif (!$update && $mail_date == date('Y-m-d')) {
      // return the count
    }
    elseif (!$update && $mail_date != date('Y-m-d')) {
      $mail_count = 0;
    }

    return $mail_count;
  }

  /**
   * Get or/and update the daily sms sending limit for each user
   *
   * @param AccountInterface $account
   * @param int $update
   * @return int
   */
  public function phoneVerificationLimit(AccountInterface $account, int $update = 0) {
    $query = $this->database->select('bbsradio_user_verification_limits', 'b')
      ->condition('b.uid', $account->id(), '=')
      ->fields('b', ['uid', 'sms_date', 'sms_count'])
      ->range(0, 1);
    $result = $query->execute()->fetchObject();
    $sms_date = !empty($result->sms_date) ? $result->sms_date : '';
    $sms_count = !empty($result->sms_count) ? $result->sms_count : 0;

    if ($update && !$result) {
      $this->database->merge('bbsradio_user_verification_limits')
        ->insertFields([
          'uid' => $account->id(),
          'sms_date' => date('Y-m-d'),
          'sms_count' => 1,
        ])
        ->updateFields([
          'sms_date' => date('Y-m-d'),
          'sms_count' => 1,
        ])
        ->keys([
          'uid' => $account->id(),
        ])
        ->execute();
      $sms_count = 1;
    }
    elseif ($update && $result && $sms_date == date('Y-m-d')) {
      $this->database->merge('bbsradio_user_verification_limits')
        ->insertFields([
          'uid' => $account->id(),
          'sms_date' => date('Y-m-d'),
          'sms_count' => 1,
        ])
        ->updateFields([
          'sms_date' => date('Y-m-d'),
        ])
        ->expression('sms_count', 'sms_count + :inc', [':inc' => 1])
        ->keys([
          'uid' => $account->id(),
        ])
        ->execute();
      $sms_count = $sms_count + 1;
    }
    elseif ($update && $result && $sms_date != date('Y-m-d')) {
      $this->database->merge('bbsradio_user_verification_limits')
        ->insertFields([
          'uid' => $account->id(),
          'sms_date' => date('Y-m-d'),
          'sms_count' => 1,
        ])
        ->updateFields([
          'sms_date' => date('Y-m-d'),
          'sms_count' => 1,
        ])
        ->keys([
          'uid' => $account->id(),
        ])
        ->execute();
      $sms_count = 1;
    }
    elseif (!$update && $sms_date == date('Y-m-d')) {
      // return the count
    }
    elseif (!$update && $sms_date != date('Y-m-d')) {
      $sms_count = 0;
    }

    return $sms_count;
  }

  /**
   * Validate the phone number
   *
   * @param string $phone
   * @return bool
   */
  public function validatePhone(string $phone) {
    if (empty($phone)) {
      return false;
    }

    $sid = $this->configFactory->get('custom_example.phone_verification_settings')->get('sid');
    $auth_token = $this->configFactory->get('custom_example.phone_verification_settings')->get('auth_token');
    try {
      $twilio = new Client($sid, $auth_token);
      $phone_number = $twilio->lookups->v1->phoneNumbers($phone)
        ->fetch();
      if(isset($phone_number->nationalFormat) && !empty($phone_number->nationalFormat)) {
        return true;
      }
    }
    catch (Exception $e) {
      return false;
    }

    return false;
  }

}
