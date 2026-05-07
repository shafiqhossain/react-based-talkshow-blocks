<?php

namespace Drupal\custom_example\Service;

use DateTime;
use DateTimeZone;
use DOMDocument;
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
 * External Feed Import Manager Class.
 *
 * @package Drupal\custom_example
 */
class ExternalFeedImportManager {
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
   * Media storage manager service.
   *
   * @var \Drupal\custom_example\Service\MediaStorageManager
   */
  protected $mediaStorageManager;

  /**
   * Constructs a new ExternalFeedImportManager.
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
    MediaStorageManager $mediaStorageManager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('bbs_feed');
    $this->configFactory = $config_factory;
    $this->mediaStorageManager = $mediaStorageManager;
  }

  /**
   * Parse the external feed url
   *
   * @param $url
   * @return array
   */
  public function parseExternalFeedUrl($url) {
    $dom = new DOMDocument;
    $dom->preserveWhiteSpace = FALSE;
    $dom->load( $url );

    // Get the channel information
    $xml_channel_node = $dom->getElementsByTagName("channel")->item(0);
    $channel = [];
    $categories = [];
    if ($xml_channel_node) {
      foreach ($xml_channel_node->childNodes as $xml_child_node) {
        $tagName = $xml_child_node->nodeName;
        if ($tagName == 'title') {
          $channel['title'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'description') {
          $channel['description'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'pubDate') {
          $channel['publish_date'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'copyright') {
          $channel['copyright'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'category') {
          $categories[] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'itunes:subtitle') {
          $channel['sub_title'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'itunes:keywords') {
          $channel['keywords'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'itunes:summary') {
          $channel['summary'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'itunes:owner') {
          $owner = [];
          foreach ($xml_child_node->childNodes as $xml_owner_node) {
            $childTagName = $xml_owner_node->nodeName;
            if ($childTagName == 'itunes:name') {
              $owner['name'] = $xml_owner_node->nodeValue;
            }
            elseif ($childTagName == 'itunes:email') {
              $owner['email'] = $xml_owner_node->nodeValue;
            }
          }
          if (!empty($owner)) {
            $channel['owner'] = $owner;
          }
        }
        elseif ($tagName == 'itunes:author') {
          $channel['author'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'itunes:explicit') {
          $channel['explicit'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'itunes:type') {
          $channel['type'] = $xml_child_node->nodeValue;
        }
        elseif ($tagName == 'image') {
          $image = [];
          foreach ($xml_child_node->childNodes as $xml_image_node) {
            $childTagName = $xml_image_node->nodeName;
            if ($childTagName == 'url') {
              $image['url'] = $xml_image_node->nodeValue;
            }
            elseif ($childTagName == 'title') {
              $image['title'] = $xml_image_node->nodeValue;
            }
          }
          if (!empty($image)) {
            $channel['image'] = $image;
          }
        }
        elseif ($tagName == 'link') {
          $channel['link'] = $xml_child_node->nodeValue;
        }
      }
    }
    if (!empty($categories)) {
      $channel['category'] = $categories;
    }

    // Get the channel items information
    $xml_item_nodes = $dom->getElementsByTagName("item");
    $items = [];
    if ($xml_item_nodes) {
      foreach ($xml_item_nodes as $xml_node) {
        //$xml_node_item = $xml_node->item(0);
        $item = [];
        foreach ($xml_node->childNodes as $xml_child_node) {
          $tagName = $xml_child_node->nodeName;
          if ($tagName == 'title') {
            $item['title'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'description') {
            $item['description'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'pubDate') {
            $item['publish_date'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'guid') {
            $item['guid'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'author') {
            $item['author'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'link') {
            $item['link'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'itunes:author') {
            $item['itunes_author'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'itunes:subtitle') {
            $item['sub_title'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'itunes:keywords') {
            $item['keywords'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'itunes:duration') {
            $item['media_duration'] = $xml_child_node->nodeValue;
          }
          elseif ($tagName == 'enclosure') {
            $url = $xml_child_node->getAttribute('url');
            if (!empty($url)) {
              $item['media_url'] = $url;
            }
          }
        }
        if (!empty($item)) {
          $items[] = $item;
        }
      }
    }

    return [
      'channel' => $channel,
      'items' => $items
    ];
  }

  /**
   * Create new external feed row
   *
   * @param string $feed_title
   * @param string $feed_url
   * @param int $fetch_schedule
   * @param int $talkshow_nid
   * @param int $status
   * @return int|string|null
   * @throws \Exception
   */
  public function insertExternalFeed(string $feed_title, string $feed_url, int $fetch_schedule, int $talkshow_nid, int $status) {
      $feed_id = $this->database->insert('bbsradio_external_feeds')
      ->fields([
        'feed_title' => $feed_title,
        'feed_url' => $feed_url,
        'fetch_schedule' => $fetch_schedule,
        'talkshow_nid' => $talkshow_nid,
        'uid' => $this->account->id(),
        'last_fetched' => 0,
        'last_update' => \Drupal::time()->getRequestTime(),
        'status' => $status,
      ])
      ->execute();

    return $feed_id;
  }

  /**
   * Edit external feed row
   *
   * @param int $feed_id
   * @param string $feed_title
   * @param string $feed_url
   * @param int $fetch_schedule
   * @param int $talkshow_nid
   * @param int $status
   * @return int|string|null
   * @throws \Exception
   */
  public function updateExternalFeed(int $feed_id, string $feed_title, string $feed_url, int $fetch_schedule, int $talkshow_nid, int $status) {
    $num_of_rows = $this->database->update('bbsradio_external_feeds')
      ->fields([
        'feed_title' => $feed_title,
        'feed_url' => $feed_url,
        'fetch_schedule' => $fetch_schedule,
        'talkshow_nid' => $talkshow_nid,
        'uid' => $this->account->id(),
        'last_update' => \Drupal::time()->getRequestTime(),
        'status' => $status,
      ])
      ->condition('feed_id', $feed_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Delete external feed row
   *
   * @param int $feed_id
   * @return int|string|null
   * @throws \Exception
   */
  public function deleteExternalFeed(int $feed_id) {
    $num_of_rows = $this->database->delete('bbsradio_external_feeds')
      ->condition('feed_id', $feed_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Get external feed row
   *
   * @param int $feed_id
   * @return int|string|null
   * @throws \Exception
   */
  public function getExternalFeed(int $feed_id) {
    $result = $this->database->select('bbsradio_external_feeds', 'ef')
      ->fields('ef')
      ->condition('ef.feed_id', $feed_id)
      ->execute()->fetchObject();

    return $result;
  }

  /**
   * Get external feeds by status
   *
   * @param int $status
   * @return array
   * @throws \Exception
   */
  public function getExternalFeeds(int $status = 1) {
    $results = $this->database->select('bbsradio_external_feeds', 'ef')
      ->fields('ef')
      ->condition('ef.status', $status)
      ->execute()->fetchAll();

    return $results;
  }

  /**
   * Get enabled external feeds by status
   *
   * @param int $status
   * @return array
   * @throws \Exception
   */
  public function getActiveExternalFeeds() {
    return $this->getExternalFeeds(1);
  }

  /**
   * Get disabled external feeds by status
   *
   * @param int $status
   * @return array
   * @throws \Exception
   */
  public function getInactiveExternalFeeds() {
    return $this->getExternalFeeds(0);
  }

  /**
   * Update feed fetch date
   *
   * @param int $feed_id
   * @return int|null
   */
  public function updateFeedFetchDate(int $feed_id) {
    $num_of_rows = $this->database->update('bbsradio_external_feeds')
      ->fields([
        'last_fetched' => time(),
        'last_update' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('feed_id', $feed_id)
      ->execute();

    return $num_of_rows;
  }


  /**
   * Create new external talkshow row
   *
   * @param string $title
   * @param string $description
   * @param string $summary
   * @param int $status
   * @param int $feed_id
   * @param array $params
   * @return int|string|null
   * @throws \Exception
   */
  public function insertExternalTalkshow(string $title, string $description, string $summary, int $status, int $feed_id, array $params = []) {
    $data = [
      'fetch_date' => date('Y-m-d H:i:s'),
      'title' => $title,
      'description' => $description,
      'summary' => $summary,
      'status' => $status,
      'feed_id' => $feed_id,
      'last_update' => \Drupal::time()->getRequestTime(),
    ];

    if (isset($params['publish_date'])) {
      $data['publish_date'] = $params['publish_date'];
    }
    if (isset($params['copyright'])) {
      $data['copyright'] = $params['copyright'];
    }
    if (isset($params['sub_title'])) {
      $data['sub_title'] = $params['sub_title'];
    }
    if (isset($params['keywords'])) {
      $data['keywords'] = $params['keywords'];
    }
    if (isset($params['owner_name'])) {
      $data['owner_name'] = $params['owner_name'];
    }
    if (isset($params['owner_email'])) {
      $data['owner_email'] = $params['owner_email'];
    }
    if (isset($params['author'])) {
      $data['author'] = $params['author'];
    }
    if (isset($params['explicit'])) {
      $data['explicit'] = $params['explicit'];
    }
    if (isset($params['type'])) {
      $data['type'] = $params['type'];
    }
    if (isset($params['image_title'])) {
      $data['image_title'] = $params['image_title'];
    }
    if (isset($params['image_url'])) {
      $data['image_url'] = $params['image_url'];
    }
    if (isset($params['link'])) {
      $data['link'] = $params['link'];
    }
    if (isset($params['category'])) {
      $data['category'] = $params['category'];
    }
    if (isset($params['talkshow_nid'])) {
      $data['talkshow_nid'] = $params['talkshow_nid'];
    }

    $talkshow_feed_id = $this->database->insert('bbsradio_import_talkshows')
      ->fields($data)
      ->execute();

    return $talkshow_feed_id;
  }

  /**
   * Merge external talk show row
   *
   * @param int $talkshow_nid
   * @param int $feed_id
   * @param string $title
   * @param string $description
   * @param string $summary
   * @param int $status
   * @param array $params
   * @return int|null
   */
  public function mergeExternalTalkshow(int $talkshow_nid, int $feed_id, string $title, string $description, string $summary, int $status, array $params = []) {
    $data = [
      'title' => $title,
      'description' => $description,
      'summary' => $summary,
      'status' => $status,
      'last_update' => \Drupal::time()->getRequestTime(),
    ];

    if (isset($params['publish_date'])) {
      $data['publish_date'] = $params['publish_date'];
    }
    if (isset($params['copyright'])) {
      $data['copyright'] = $params['copyright'];
    }
    if (isset($params['sub_title'])) {
      $data['sub_title'] = $params['sub_title'];
    }
    if (isset($params['keywords'])) {
      $data['keywords'] = $params['keywords'];
    }
    if (isset($params['owner_name'])) {
      $data['owner_name'] = $params['owner_name'];
    }
    if (isset($params['owner_email'])) {
      $data['owner_email'] = $params['owner_email'];
    }
    if (isset($params['author'])) {
      $data['author'] = $params['author'];
    }
    if (isset($params['explicit'])) {
      $data['explicit'] = $params['explicit'];
    }
    if (isset($params['type'])) {
      $data['type'] = $params['type'];
    }
    if (isset($params['image_title'])) {
      $data['image_title'] = $params['image_title'];
    }
    if (isset($params['image_url'])) {
      $data['image_url'] = $params['image_url'];
    }
    if (isset($params['link'])) {
      $data['link'] = $params['link'];
    }
    if (isset($params['category'])) {
      $data['category'] = $params['category'];
    }

    $insert_data = $data;
    $insert_data['talkshow_nid'] = $talkshow_nid;
    $insert_data['feed_id'] = $feed_id;
    $insert_data['fetch_date'] = date('Y-m-d H:i:s');

    $update_data = $data;

    $num_of_rows = $this->database->merge('bbsradio_import_talkshows')
      ->insertFields($insert_data)
      ->updateFields($update_data)
      ->key('talkshow_nid', $talkshow_nid)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Edit external talk show row
   *
   * @param int $talkshow_feed_id
   * @param string $title
   * @param string $description
   * @param string $summary
   * @param int $status
   * @param array $params
   * @return int|null
   */
  public function updateExternalTalkshow(int $talkshow_feed_id, string $title, string $description, string $summary, int $status, array $params = []) {
    $data = [
      'title' => $title,
      'description' => $description,
      'summary' => $summary,
      'status' => $status,
      'last_update' => \Drupal::time()->getRequestTime(),
    ];

    if (isset($params['publish_date'])) {
      $data['publish_date'] = $params['publish_date'];
    }
    if (isset($params['copyright'])) {
      $data['copyright'] = $params['copyright'];
    }
    if (isset($params['sub_title'])) {
      $data['sub_title'] = $params['sub_title'];
    }
    if (isset($params['keywords'])) {
      $data['keywords'] = $params['keywords'];
    }
    if (isset($params['owner_name'])) {
      $data['owner_name'] = $params['owner_name'];
    }
    if (isset($params['owner_email'])) {
      $data['owner_email'] = $params['owner_email'];
    }
    if (isset($params['author'])) {
      $data['author'] = $params['author'];
    }
    if (isset($params['explicit'])) {
      $data['explicit'] = $params['explicit'];
    }
    if (isset($params['type'])) {
      $data['type'] = $params['type'];
    }
    if (isset($params['image_title'])) {
      $data['image_title'] = $params['image_title'];
    }
    if (isset($params['image_url'])) {
      $data['image_url'] = $params['image_url'];
    }
    if (isset($params['link'])) {
      $data['link'] = $params['link'];
    }
    if (isset($params['category'])) {
      $data['category'] = $params['category'];
    }
    if (isset($params['talkshow_nid'])) {
      $data['talkshow_nid'] = $params['talkshow_nid'];
    }

    $num_of_rows = $this->database->update('bbsradio_import_talkshows')
      ->fields($data)
      ->condition('talkshow_feed_id', $talkshow_feed_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Delete external talk show row
   *
   * @param int $talkshow_feed_id
   * @return int|string|null
   * @throws \Exception
   */
  public function deleteExternalTalkshow(int $talkshow_feed_id) {
    $num_of_rows = $this->database->delete('bbsradio_import_talkshows')
      ->condition('talkshow_feed_id', $talkshow_feed_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Get external talk show row
   *
   * @param int $talkshow_feed_id
   * @return int|string|null
   * @throws \Exception
   */
  public function getExternalTalkshow(int $talkshow_feed_id) {
    $result = $this->database->select('bbsradio_import_talkshows', 't')
      ->fields('t')
      ->condition('t.talkshow_feed_id', $talkshow_feed_id)
      ->execute()->fetchObject();

    return $result;
  }

  /**
   * Get external talk show row by Title and Talk show node id
   *
   * @param string $title
   * @return int|string|null
   * @throws \Exception
   */
  public function getExternalTalkshowByTitle(string $title, int $feed_id = 0) {
    $title = strtolower($title);
    $query = $this->database->select('bbsradio_import_talkshows', 't')
      ->fields('t')
      ->condition('t.title', $title);
    if (!empty($feed_id)) {
      $query->condition('t.feed_id', $feed_id);
    }

    $result = $query->execute()->fetchObject();

    return $result;
  }

  /**
   * Get external talk shows by status
   *
   * @param int $status
   * @return array
   * @throws \Exception
   */
  public function getExternalTalkshows(int $status = 1) {
    $results = $this->database->select('bbsradio_import_talkshows', 't')
      ->fields('t')
      ->condition('t.status', $status)
      ->execute()->fetchAll();

    return $results;
  }

  /**
   * Create new external archive row
   *
   * @param string $title
   * @param string $description
   * @param string $sub_title
   * @param int $status
   * @param int $feed_id
   * @param array $params
   * @return int|string|null
   * @throws \Exception
   */
  public function insertExternalArchive(string $title, string $description, string $sub_title, int $status, int $feed_id, int $talkshow_nid, array $params = []) {
    $data = [
      'fetch_date' => date('Y-m-d H:i:s'),
      'title' => $title,
      'description' => $description,
      'sub_title' => $sub_title,
      'status' => $status,
      'feed_id' => $feed_id,
      'talkshow_nid' => $talkshow_nid,
      'last_update' => \Drupal::time()->getRequestTime(),
    ];

    if (isset($params['publish_date'])) {
      $data['publish_date'] = $params['publish_date'];
    }
    if (isset($params['keywords'])) {
      $data['keywords'] = $params['keywords'];
    }
    if (isset($params['author'])) {
      $data['author'] = $params['author'];
    }
    if (isset($params['link'])) {
      $data['link'] = $params['link'];
    }
    if (isset($params['guid'])) {
      $data['guid'] = $params['guid'];
    }
    if (isset($params['media_duration'])) {
      $data['media_duration'] = $params['media_duration'];
    }
    if (isset($params['media_location'])) {
      $data['media_location'] = $params['media_location'];
    }
    if (isset($params['media_url'])) {
      $data['media_url'] = $params['media_url'];
    }
    if (isset($params['fid'])) {
      $data['fid'] = $params['fid'];
    }
    if (isset($params['archive_nid'])) {
      $data['archive_nid'] = $params['archive_nid'];
    }

    $archive_feed_id = $this->database->insert('bbsradio_import_archives')
      ->fields($data)
      ->execute();

    return $archive_feed_id;
  }

  /**
   * Edit external archive row
   *
   * @param int $archive_feed_id
   * @param string $title
   * @param string $description
   * @param string $sub_title
   * @param int $status
   * @param array $params
   * @return int|null
   */
  public function updateExternalArchive(int $archive_feed_id, string $title, string $description, string $sub_title, int $status, array $params = []) {
    $data = [
      'title' => $title,
      'description' => $description,
      'sub_title' => $sub_title,
      'status' => $status,
      'last_update' => \Drupal::time()->getRequestTime(),
    ];

    if (isset($params['publish_date'])) {
      $data['publish_date'] = $params['publish_date'];
    }
    if (isset($params['keywords'])) {
      $data['keywords'] = $params['keywords'];
    }
    if (isset($params['author'])) {
      $data['author'] = $params['author'];
    }
    if (isset($params['link'])) {
      $data['link'] = $params['link'];
    }
    if (isset($params['guid'])) {
      $data['guid'] = $params['guid'];
    }
    if (isset($params['media_duration'])) {
      $data['media_duration'] = $params['media_duration'];
    }
    if (isset($params['media_location'])) {
      $data['media_location'] = $params['media_location'];
    }
    if (isset($params['media_url'])) {
      $data['media_url'] = $params['media_url'];
    }
    if (isset($params['fid'])) {
      $data['fid'] = $params['fid'];
    }
    if (isset($params['archive_nid'])) {
      $data['archive_nid'] = $params['archive_nid'];
    }

    $num_of_rows = $this->database->update('bbsradio_import_archives')
      ->fields($data)
      ->condition('archive_feed_id', $archive_feed_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Update archive feed info status
   *
   * @param int $archive_feed_id
   * @param int $status
   * @param array $params
   * @return void
   */
  public function setExternalArchiveStatus(int $archive_feed_id, int $status, array $params = []) {
    $data = [
      'status' => $status,
      'last_update' => \Drupal::time()->getRequestTime(),
    ];

    if (isset($params['archive_nid'])) {
      $data['archive_nid'] = $params['archive_nid'];
    }

    $num_of_rows = $this->database->update('bbsradio_import_archives')
      ->fields($data)
      ->condition('archive_feed_id', $archive_feed_id)
      ->execute();
  }

    /**
   * Delete external archive row
   *
   * @param int $archive_feed_id
   * @return int|string|null
   * @throws \Exception
   */
  public function deleteExternalArchive(int $archive_feed_id) {
    $num_of_rows = $this->database->delete('bbsradio_import_archives')
      ->condition('archive_feed_id', $archive_feed_id)
      ->execute();

    return $num_of_rows;
  }

  /**
   * Get external archive row
   *
   * @param int $archive_feed_id
   * @return int|string|null
   * @throws \Exception
   */
  public function getExternalArchive(int $archive_feed_id) {
    $result = $this->database->select('bbsradio_import_archives', 'a')
      ->fields('a')
      ->condition('a.archive_feed_id', $archive_feed_id)
      ->execute()->fetchObject();

    return $result;
  }

  /**
   * Get external archive row by GUID
   *
   * @param string $guid
   * @return int|string|null
   * @throws \Exception
   */
  public function getExternalArchiveByGUID(string $guid, int $feed_id = 0) {
    $query = $this->database->select('bbsradio_import_archives', 'a')
      ->fields('a')
      ->condition('a.guid', $guid);

    if (!empty($feed_id)) {
      $query->condition('a.feed_id', $feed_id);
    }
    $result = $query->execute()->fetchObject();

    return $result;
  }

  /**
   * Get external archive by status
   *
   * @param int $status
   * @return array
   * @throws \Exception
   */
  public function getExternalArchives(int $status = 1) {
    $results = $this->database->select('bbsradio_import_archives', 'a')
      ->fields('a')
      ->condition('a.status', $status)
      ->execute()->fetchAll();

    return $results;
  }

  /**
   * Create archive node from fetched information
   *
   * @param int $archive_feed_id
   * @return int
   * @throws \Exception
   */
  public function createExternalArchiveNode(int $archive_feed_id) {
    $archive_data = $this->getExternalArchive($archive_feed_id);
    if (!$archive_data) {
      return false;
    }

    // check status???

    /** @var \Drupal\node\NodeInterface $talkshow_node */
    $talkshow_node = $this->entityTypeManager->getStorage('node')->load($archive_data->talkshow_nid);
    if (!$talkshow_node) {
      return false;
    }

    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->create([
      'type' => 'archive_descriptions',
      'created' => $archive_data->publish_date,
      'langcode' => 'en',
      'uid' => $talkshow_node->getOwnerId(),
      'status' => $archive_data->media_downloaded == 1 ? 1 : 0,  // Unpublished
    ]);

    $archive_title = $archive_data->title;
    $search_keywords = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    foreach ($search_keywords as $search_keyword) {
      $pos = stripos($archive_title, $search_keyword);
      if ($pos !== false) {
        // e.g. The Bev Moore Show , February 16, 2024
        $archive_title = substr($archive_title, 0, $pos-1);
        $archive_title = trim($archive_title);
        $archive_title = rtrim($archive_title, ',');
        break;
      }
    }

    $node->set('title', $archive_title);
    $node->set('field_archive_includes', ['target_id' => $archive_data->talkshow_nid]);
    $node->set('field_delivered_file_type', 0);
    $node->set('field_file_show_directory', $archive_data->media_location);
    $node->set('field_archive_delivered_file_up', [
      'target_id' => $archive_data->fid,
      'display' => 1,
      'description' => $archive_title,
    ]);
    $node->set('field_authorized_by', $talkshow_node->get('field_include_authorized_by')->value);

    $broadcast_schedules = $talkshow_node->get('field_show_broadcast_schedule')->getValue();
    $broadcast_timedate = '';
    if ($broadcast_schedules) {
      foreach ($broadcast_schedules as $broadcast_schedule) {
        $p = \Drupal\paragraphs\Entity\Paragraph::load($broadcast_schedule['target_id']);
        $time = $p->get('field_schedule_starts')->value;
        if (!empty($time)) {
          $time_arr = explode(' ', $time);
          $time_string = $time_arr[0] . ' ' . $time_arr[1];
          if (isset($time_arr[2]) && $time_arr[2] == 'PT') {
            // Pacific Time (PT)
            $pst = new DateTimeZone('America/Los_Angeles');
            $time_ago = new DateTime($time_string, $pst);
          }
          else {
            // Central Time (CT)
            $pst = new DateTimeZone('America/Chicago');
            $time_ago = new DateTime($time_string, $pst);
          }
          $broadcast_timedate = $time_ago->format('H:i:s');
          break;
        }
      }

      if (empty($broadcast_timedate)) {
        $broadcast_timedate = date('H:i:s');
      }
    }
    else {
      $broadcast_timedate = date('H:i:s');
    }
    $node->set('field_archive_broadcast_timedate', date('Y-m-d\TH:i:s', strtotime($broadcast_timedate)));
    $node->set('field_archive_show_headline', $archive_title);
    $node->set('field_archive_show_sub_headline', $archive_data->sub_title);
    $node->set('field_archive_use_show_banner', ['target_id' => $archive_data->talkshow_nid]);
    $node->set('field_archive_show_story', [
      'value' => $archive_data->description,
      'format' => 'filtered_html',
    ]);
    $node->set('field_arc_feed_des1', [
      'value' => $archive_data->description,
      'format' => 'filtered_html',
    ]);
    $node->set('field_produced_or_delivered_type', 0);
    $node->set('field_archive_show_duration', $archive_data->media_duration);

    $keywords = $archive_data->keywords;
    $keywords_arr = array_map('trim', explode(',', $keywords));
    $targets = [];
    foreach ($keywords_arr as $keyword) {
      $tid = $this->getTagTermID($keyword);
      $targets = ['target_id' => $tid];
    }
    $node->set('field_archive_tags', $targets);
    $node->set('field_archive_category', $talkshow_node->get('field_include_show_categories')->getValue());
    $node->set('field_archive_itunes_category', $talkshow_node->get('field_include_itunes_categories')->getValue());
    $node->set('field_external_archive', 1);
    $node->enforceIsNew();
    $node->save();

    $num_of_rows = $this->database->update('bbsradio_import_archives')
      ->fields([
        'merge_date' => date('Y-m-d H:i:s'),
        'archive_nid' => $node->id(),
        'talkshow_nid' => $talkshow_node->id(),
        'status' => 2,  // Merged
      ])
      ->condition('archive_feed_id', $archive_feed_id)
      ->execute();

    return $node->id();
  }

  /**
   * Get the term id of the keyword
   *
   * @param string $keyword
   * @return int|mixed|string|null
   */
  public function getTagTermID(string $keyword) {
    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', "tags");
    $query->condition('name', $keyword);
    $query->range(0, 1);
    $query->accessCheck(FALSE);
    $tids = $query->execute();

    $tid = 0;
    if (!empty($tids)) {
      $tid = reset($tids);
    }

    // If found return the value
    if ($tid) {
      return $tid;
    }

    // Create the new term, as it is not exists
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
      'vid' => 'tags',
      'name' => $keyword,
    ]);
    $term->enforceIsNew();
    $term->save();

    return $term->id();
  }


}
