<?php

namespace Drupal\custom_example\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileUsage\DatabaseFileUsageBackend;

/**
 * Media Storage Manager Class.
 *
 * @package Drupal\custom_example
 */
class MediaStorageManager {
  use StringTranslationTrait;

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
   * File system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface $fileSystem
   */
  protected $fileSystem;

  /**
   * File usage service.
   *
   * @var \Drupal\file\FileUsage\DatabaseFileUsageBackend $fileUsage
   */
  protected $fileUsage;

  /**
   * Constructs a new MediaStorageManager.
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
    FileSystemInterface $file_system,
    DatabaseFileUsageBackend $databaseFileUsageBackend
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('media_profile');
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->fileUsage = $databaseFileUsageBackend;
  }

  /**
   * Create new media storage profile row
   *
   * @param string $title
   * @param string $description
   * @param string $media_location
   * @param string $max_storage_limit
   * @param string $current_storage
   * @param int $schema_type
   * @param int $is_default
   * @param int $status
   * @return int|string|null
   * @throws \Exception
   */
  public function insertMediaStorageProfile(string $title, string $description, string $media_location, string $max_storage_limit, int $schema_type, int $is_default, int $status) {
    // When is_default is 1, reset all profile is_default value to 0. Because, there should be only one profile in default
    if ($is_default == 1) {
      $num_of_rows = $this->database->update('bbsradio_media_storage_profiles')
        ->fields([
          'is_default' => 0,
          'last_update' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }

    $profile_id = $this->database->insert('bbsradio_media_storage_profiles')
      ->fields([
        'title' => $title,
        'description' => $description,
        'media_location' => $media_location,
        'max_storage_limit' => $max_storage_limit,
        'current_storage' => '',
        'schema_type' => $schema_type,
        'is_default' => $is_default,
        'uid' => $this->account->id(),
        'last_update' => \Drupal::time()->getRequestTime(),
        'status' => $status,
      ])
      ->execute();

    return $profile_id;
  }

  /**
   * Edit media storage profile row
   *
   * @param int $profile_id
   * @param string $title
   * @param string $description
   * @param string $media_location
   * @param string $max_storage_limit
   * @param int $schema_type
   * @param int $status
   * @return int|null
   */
  public function updateMediaStorageProfile(int $profile_id, string $title, string $description, string $media_location, string $max_storage_limit, int $schema_type, int $is_default, int $status) {
    // When is_default is 1, reset all profile is_default value to 0. Because, there should be only one profile in default
    if ($is_default == 1) {
      $num_of_rows = $this->database->update('bbsradio_media_storage_profiles')
        ->fields([
          'is_default' => 0,
          'last_update' => \Drupal::time()->getRequestTime(),
        ])
        ->execute();
    }

    $num_of_rows = $this->database->update('bbsradio_media_storage_profiles')
      ->fields([
        'title' => $title,
        'description' => $description,
        'media_location' => $media_location,
        'max_storage_limit' => $max_storage_limit,
        'schema_type' => $schema_type,
        'is_default' => $is_default,
        'uid' => $this->account->id(),
        'last_update' => \Drupal::time()->getRequestTime(),
        'status' => $status,
      ])
      ->condition('profile_id', $profile_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Update media location current storage status
   *
   * @param int $profile_id
   * @param string $byte_format
   * @return int|null
   */
  public function setMediaCurrentStorage(int $profile_id, string $byte_format) {
    $num_of_rows = $this->database->update('bbsradio_media_storage_profiles')
      ->fields([
        'current_storage' => $byte_format,
        'last_update' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('profile_id', $profile_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Delete media storage profile row
   *
   * @param int $profile_id
   * @return int|string|null
   * @throws \Exception
   */
  public function deleteMediaStorageProfile(int $profile_id) {
    $num_of_rows = $this->database->delete('bbsradio_media_storage_profiles')
      ->condition('profile_id', $profile_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Get media storage profile row
   *
   * @param int $profile_id
   * @return int|string|null
   * @throws \Exception
   */
  public function getMediaStorageProfile(int $profile_id) {
    $result = $this->database->select('bbsradio_media_storage_profiles', 'sp')
      ->fields('sp')
      ->condition('sp.profile_id', $profile_id)
      ->execute()->fetchObject();

    return $result;
  }

  /**
   * Get default media storage profile row
   *
   * @return int|string|array|object
   * @throws \Exception
   */
  public function getDefaultMediaStorageProfile() {
    $result = $this->database->select('bbsradio_media_storage_profiles', 'sp')
      ->fields('sp')
      ->condition('sp.is_default', 1)
      ->range(0,1)
      ->execute()->fetchObject();

    return $result;
  }

  /**
   * Get media storage profiles by status
   *
   * @param int $status
   * @return array
   * @throws \Exception
   */
  public function getMediaStorageProfiles(int $status = 1) {
    $results = $this->database->select('bbsradio_media_storage_profiles', 'sp')
      ->fields('sp')
      ->condition('sp.status', $status)
      ->execute()->fetchAll();

    return $results;
  }

  /**
   * Get the directory size
   *
   * @param  string $directory
   * @return integer
   */
  function dirSize ($dir, $do_format = 1) {
    $size = 0;

    foreach (glob(rtrim($dir, '/') . '/*', GLOB_NOSORT) as $each) {
      $size += is_file($each) ? filesize($each) : $this->dirSize($each);
    }

    return $size;
  }

  /**
   * Get the byte format
   *
   * @param int $bytes
   * @return string
   */
  function byteFormat (int $bytes) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');

    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);

    // Uncomment one of the following alternatives
    $bytes /= pow(1024, $pow);
    // $bytes /= (1 << (10 * $pow));

    return round($bytes, 0) . ' ' . $units[$pow];
  }

  /**
   * Get the media folder name
   *
   * @param string $title
   * @param int $is_unique
   * @return string|array
   */
  public function getMediaDirName(string $title, int $is_unique = 1) {
    $dir_name = strtolower($title);
    $dir_name = substr($dir_name, 0, 50);

    $search_chars = [',', '@', '#', '$', '%', '^', '&', '*', '+', '!', '(', ')', '{', '}', '[', ']', '"', ';', ':', '.', '<', '>', '/', '\\', '?', '=', '~', '`'];
    $dir_name = str_ireplace($search_chars, '', $dir_name);
    $dir_name = str_ireplace('  ', '-', $dir_name);
    $dir_name = str_ireplace(' ', '-', $dir_name);

    $media_profile = $this->getDefaultMediaStorageProfile();
    if (empty($media_profile)) {
      return '';
    }

    $media_location = $media_profile->media_location;
    $schema_type = $media_profile->schema_type;

    $media_location = rtrim($media_location, "/");
    $media_location = rtrim($media_location, "\\");
    if ($schema_type == 2) {
      $media_location = 'private://' . $media_location;
    }
    else {
      $media_location = 'public://' . $media_location;
    }

    $media_path = $media_location . '/' . $dir_name;
    $new_dir_name = $dir_name;
    $count = 1;

    if ($is_unique) {
      do {
        if (file_exists($media_path) && is_dir($media_path)) {
          $media_path = $media_location . '/' . $new_dir_name;
          $new_dir_name = $dir_name . $count;
          ++$count;
        }
        else {
          break;
        }

      } while (true);
    }

    // Prepare the directory
    $this->fileSystem->prepareDirectory($media_path, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    return ['uri' => $media_path, 'dir' => $new_dir_name];
  }

  /**
   * Get the media folder info
   *
   * @param $title
   * @return string|array
   */
  public function getMediaDirInfo($dir_name) {
    $media_profile = $this->getDefaultMediaStorageProfile();
    if (empty($media_profile)) {
      return '';
    }

    $media_location = $media_profile->media_location;
    $schema_type = $media_profile->schema_type;

    $media_location = rtrim($media_location, "/");
    $media_location = rtrim($media_location, "\\");
    if ($schema_type == 2) {
      $media_location = 'private://' . $media_location;
    }
    else {
      $media_location = 'public://' . $media_location;
    }

    $media_path = $media_location . '/' . $dir_name;

    // Prepare the directory
    $this->fileSystem->prepareDirectory($media_path, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    return ['uri' => $media_path, 'dir' => $dir_name];
  }

  /**
   * Prepare the directory recursively
   *
   * @param string $media_path
   * @return bool
   */
  function prepareDir(string $media_path) {
    // Prepare the directory
    return $this->fileSystem->prepareDirectory($media_path, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  }

    /**
   * Download the remote media file and add to file system
   *
   * @param $remote_url
   * @param $destination_abs_file_name
   * @param $destination_file_name_uri
   * @param $file_name
   * @return false|int|mixed|string|null
   */
  function downloadExternalArchiveMedia($remote_url, $destination_abs_file_name, $destination_file_name_uri, $file_name) {
    // Delete the file, if exists
    if (file_exists($destination_abs_file_name)) {
      unlink($destination_abs_file_name);
    }

    // Fetch and download the file
    $options = [
      CURLOPT_FILE => is_resource($destination_abs_file_name) ? $destination_abs_file_name : fopen($destination_abs_file_name, 'w'),
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_URL => $remote_url,
      CURLOPT_FAILONERROR => true, // HTTP code > 400 will throw curl error
      CURLOPT_TIMEOUT => 0,
      CURLOPT_CONNECTTIMEOUT => 0,
    ];

    try {
      $ch = curl_init();
      curl_setopt_array($ch, $options);
      $response_status = curl_exec($ch);

      if ($response_status === false) {
        $this->logger->error(print_r(curl_error($ch), true));
        return [
          'status' => 0,
          'error_message' => print_r(curl_error($ch), true),
          'fid' => 0,
        ];
      }
    }
    catch(\Exception $ex) {
      $this->logger->error($ex->getMessage());
      return [
        'status' => 0,
        'error_message' => $ex->getMessage(),
        'fid' => 0,
      ];
    }

    // Create the file
    $file = File::create([
      'filename' => $file_name,
      'uri' => $destination_file_name_uri,
      'status' => 1,
      'uid' => 1,
    ]);
    $file->setPermanent();
    $file->save();

    // Add to usage
    $this->fileUsage->add($file, 'custom_example', 'node', 1);

    return [
      'status' => 1,
      'error_message' => '',
      'fid' => $file->id(),
    ];
  }

}

