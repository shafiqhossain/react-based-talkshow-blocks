<?php

namespace Drupal\custom_example\Service;

use Drupal\bbsradio_subscription\Service\SubscriptionAccessManager;
use Drupal\bbsradio_subscription\Service\SubscriptionDataManager;
use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\node\NodeInterface;
use Drupal\symfony_mailer\Address;
use Drupal\symfony_mailer\EmailFactoryInterface;
use Drupal\symfony_mailer\MailerHelperTrait;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Twilio\Rest\Client;

/**
 * Resource Manager Class.
 *
 * @package Drupal\custom_example
 */
class UtilityManager {
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
   * File url generator service
   *
   * @var \Drupal\Core\File\FileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * The subscription data manager.
   *
   * @var \Drupal\bbsradio_subscription\Service\SubscriptionDataManager
   */
  protected $subscriptionDataManager;

  /**
   * Subscription access manager.
   *
   * @var \Drupal\bbsradio_subscription\Service\SubscriptionAccessManager
   */
  protected $subscriptionAccessManager;

  /**
   * Constructs a new ResourceManager.
   *
   * @param EntityTypeManagerInterface $entity_type_manager
   * @param AccountInterface $current_account
   * @param Connection $database
   * @param LoggerChannelFactoryInterface $logger
   * @param ConfigFactoryInterface $config_factory
   * @param FileUrlGeneratorInterface $file_url_generator
   * @param SubscriptionDataManager $subscription_data_manager
   * @param SubscriptionAccessManager $subscription_access_manager
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_account,
    Connection $database,
    LoggerChannelFactoryInterface $logger,
    ConfigFactoryInterface $config_factory,
    FileUrlGeneratorInterface $file_url_generator,
    SubscriptionDataManager $subscription_data_manager,
    SubscriptionAccessManager $subscription_access_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('utility');
    $this->configFactory = $config_factory;
    $this->fileUrlGenerator = $file_url_generator;
    $this->subscriptionDataManager = $subscription_data_manager;
    $this->subscriptionAccessManager = $subscription_access_manager;
  }

  /**
   * Check Archive Media Access
   *
   * @param NodeInterface $node
   * @return int
   */
  public function checkArchiveMediaAccess(NodeInterface $node, $type) {
    $allow_access = 0;

    /**
    * Delivered File Type	(field_delivered_file_type)
    *  field_archive_delivered_file_up
    *  field_archive_delivered_file_up_
    */
    if ($type == 1) {
      $delivered_file_type = $node->hasField('field_delivered_file_type') ? $node->get('field_delivered_file_type')->value : 0;
      if ($delivered_file_type == 1) {
        $has_active_subscription = $this->subscriptionDataManager->userHasActiveSubscription($this->account->id());
        if ($has_active_subscription) {
          $has_permission_mp3 = $this->subscriptionAccessManager->hasArchivePermission(PERM_DELIVERED_FILE_MP3, $node->id());
          if ($has_permission_mp3) {
            $allow_access = 1;
          }
        }
      }
      else {
        $allow_access = 1;
      }
    }

    /**
    * Produced or Delivered Type	(field_produced_or_delivered_type)
    *  field_archive_uploaded_video
     *  field_archive_uploaded_video_
     *  field_produced_or_delivered_pro2
    *  field_produced_or_delivered_pro_
    */
    if ($type == 2) {
      $produced_or_delivered_type = $node->hasField('field_produced_or_delivered_type') ? $node->get('field_produced_or_delivered_type')->value : 0;
      if ($produced_or_delivered_type == 1) {
        $has_active_subscription = $this->subscriptionDataManager->userHasActiveSubscription($this->account->id());
        if ($has_active_subscription) {
          $has_permission_video_pro2 = $this->subscriptionAccessManager->hasArchivePermission(PERM_PRODUCED_OR_DELIVERED_VIDEO, $node->id());
          if ($has_permission_video_pro2) {
            $allow_access = 1;
          }
        }
      }
      else {
        $allow_access = 1;
      }
    }

    return $allow_access;
  }

  /**
   * @param NodeInterface $node
   * @param int $file_type
   * @return string
   *
   * field_delivered_file_type
   *   1-field_archive_delivered_file_up
   *   2-field_archive_delivered_file_up_
   *
   * field_produced_or_delivered_type
   *   3-field_archive_uploaded_video
   *   4-field_archive_uploaded_video_
   *   5-field_produced_or_delivered_pro2
   *   6-field_produced_or_delivered_pro_
   *
   */
  public function getArchiveMediaUrl(NodeInterface $node, int $file_type) {
    $file = false;

    if ($file_type == 1 && !$node->get('field_archive_delivered_file_up')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_archive_delivered_file_up')->first()->entity;
    }
    elseif ($file_type == 2 && !$node->get('field_archive_delivered_file_up_')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_archive_delivered_file_up_')->first()->entity;
    }

    elseif ($file_type == 3 && !$node->get('field_archive_uploaded_video')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_archive_uploaded_video')->first()->entity;
    }
    elseif ($file_type == 4 && !$node->get('field_archive_uploaded_video_')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_archive_uploaded_video_')->first()->entity;
    }
    elseif ($file_type == 5 && !$node->get('field_produced_or_delivered_pro2')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_produced_or_delivered_pro2')->first()->entity;
    }
    elseif ($file_type == 6 && !$node->get('field_produced_or_delivered_pro_')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_produced_or_delivered_pro_')->first()->entity;
    }

    if ($file) {
      // Get file URI
      $file_uri = $file->getFileUri();

      // Get the URL using the file_url_generator service
      $url = $this->fileUrlGenerator->generateAbsoluteString($file_uri);

      // Output or return the URL
      return $url;
    }

    return '';
  }

  /**
   * @param NodeInterface $node
   * @param int $file_type
   * @return string
   *
   * field_delivered_file_type
   *   1-field_archive_delivered_file_up
   *   2-field_archive_delivered_file_up_
   *
   * field_produced_or_delivered_type
   *   3-field_archive_uploaded_video
   *   4-field_archive_uploaded_video_
   *   5-field_produced_or_delivered_pro2
   *   6-field_produced_or_delivered_pro_
   *
   */
  public function getArchiveMediaUri(NodeInterface $node, int $file_type) {
    $file = false;

    if (($file_type == 1 || $file_type == 2) && !$node->get('field_archive_delivered_file_up')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_archive_delivered_file_up')->first()->entity;
    }
    elseif (($file_type == 3 || $file_type == 4) && !$node->get('field_archive_uploaded_video')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_archive_uploaded_video')->first()->entity;
    }
    elseif (($file_type == 5 || $file_type == 6) && !$node->get('field_produced_or_delivered_pro2')->isEmpty()) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $node->get('field_produced_or_delivered_pro2')->first()->entity;
    }

    if ($file) {
      // Get file URI
      $file_uri = $file->getFileUri();

      // Output or return the URL
      return $file_uri;
    }

    return '';
  }

  public function resolveFileToAbsolutePath($file_uri) {
    $filename = basename($file_uri);

    // Correctly get the relative directory under sites/default/files
    // Remove "public://" prefix
    $relative_path = str_replace('public://', '', $file_uri);

    // Get directory part only
    $relative_dir = dirname($relative_path);

    // Prepend Drupal public files directory
    $target_dir = DRUPAL_ROOT . '/sites/default/files/' . $relative_dir;

    // Check if directory exists
    $file_system = \Drupal::service('file_system');
    if (!is_dir($target_dir)) {
      return $file_system->realpath($file_uri);
    }

    // Use Symfony Process to get cwd
    $process = new Process(['pwd']);
    $process->setWorkingDirectory($target_dir);
    $process->run();

    if ($process->isSuccessful()) {
      $current_path = rtrim($process->getOutput(), "\n") . '/' . $filename;
    }
    else {
      $current_path = $file_system->realpath($file_uri);
    }

    return $current_path;
  }

}

