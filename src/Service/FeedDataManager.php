<?php

namespace Drupal\custom_example\Service;

use DateTime;
use Drupal\bbsradio_base\Service\BaseHelper;
use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Feed Data Manager Class.
 *
 * @package Drupal\custom_example
 */
class FeedDataManager {
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
   * Base helper service.
   *
   * @var \Drupal\bbsradio_base\Service\BaseHelper
   */
  protected $baseHelper;

  /**
   * Constructs a new FeedDataManager.
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
    BaseHelper $base_helper
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('bbs_feed');
    $this->configFactory = $config_factory;
    $this->baseHelper = $base_helper;
  }

  /**
   * Get the stations list
   *
   * @param int $station_type
   * @return mixed
   */
  public function getStations(int $station_type = 0) {
    $query = $this->database->select('node__field_show_broadcast_schedule', 'n');
    $query->innerJoin('paragraph__field_schedule_station', 'p', 'p.entity_id = n.field_show_broadcast_schedule_target_id');

    if ($station_type == 1) {
      $orGroup = $query->orConditionGroup()
        ->condition('p.field_schedule_station_value', 'Station 1', '=')
        ->condition('p.field_schedule_station_value', 'BBS Station 1', '=');
    }
    elseif ($station_type == 2) {
      $orGroup = $query->orConditionGroup()
        ->condition('p.field_schedule_station_value', 'Station 2', '=')
        ->condition('p.field_schedule_station_value', 'BBS Station 2', '=');
    }
    elseif ($station_type == 3) {
      $orGroup = $query->orConditionGroup()
        ->condition('p.field_schedule_station_value', 'Faith Network', '=')
        ->condition('p.field_schedule_station_value', 'Faith Stream Network', '=');
    }
    else {
      $orGroup = $query->orConditionGroup()
        ->condition('p.field_schedule_station_value', 'Station 1', '=')
        ->condition('p.field_schedule_station_value', 'BBS Station 1', '=')
        ->condition('p.field_schedule_station_value', 'Station 2', '=')
        ->condition('p.field_schedule_station_value', 'BBS Station 2', '=')
        ->condition('p.field_schedule_station_value', 'Faith Stream Network', '=');
    }


    $query->condition($orGroup);
    $query->fields('n', ['entity_id']);

    $results = $query->execute()->fetchCol();

    if ($results) {
      return $results;
    }
    else {
      return false;
    }
  }

  /**
   * Using getid3 library, get duration
   *
   * @param FileInterface $file
   * @return string
   */
  function getVideoDuration(FileInterface $file){
    return '00:45:00';  //disable for now
  }

  /**
   * Format duration to AA::BB:CC
   * @param $duration string
   */
  function formatDuration($duration){
    if (empty($duration)) {
      return '00:00:00';
    }

    // Case "B"
    if(strlen($duration) == 1){
      return '00:00:0' . $duration;
    }
    // Case "BB"
    else if(strlen($duration) == 2){
      return '00:00:' . $duration;
    }
    // Case "A:BB"
    else if(strlen($duration) == 4){
      return '00:0' . $duration;
    }
    // Case AA:BB
    else if(strlen($duration) == 5){
      return '00:' . $duration;
    }
    // Case A:BB:CC
    else if(strlen($duration) == 7){
      return '0' . $duration;
    }

    return $duration;
  }

  /**
   * Get the rss archive descriptions nodes for a Talk show
   * These three Talk show nodes are special : 65124, 65186, 273434, 67046
   * When user fetch these nodes, it will display those archives linked to respective Stations
   */
  public function getRssArchives(int $nid) {
    // Check if the node id does not belong to Station 1 or Station 2
    if (!in_array($nid, [65124, 65186, 273434, 67046])) {
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $nids = $query->condition('type', 'archive_descriptions')
        ->condition('field_archive_includes.target_id', $nid, '=')
        ->condition('status', 1, '=')
        ->sort('field_archive_broadcast_timedate.value', 'DESC')
        ->range(0, 20)
        ->accessCheck(FALSE)
        ->execute();
    }

    // Check if the node id belong to station 1 (nid = 65124)
    elseif ($nid == 65124) {
      $stations = $this->getStations(1);

      // Adding the Broadcast Schedule date field for sorting
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->condition('type', 'archive_descriptions')
        ->condition('status', 1, '=')
        ->sort('field_archive_broadcast_timedate.value', 'DESC')
        ->range(0, 20);
      $query->accessCheck(FALSE);

      if ($stations) {
        $query->condition('field_archive_includes.target_id', $stations, 'IN');
      }
      else {
        $query->condition('field_archive_includes.target_id', $nid, '=');
      }

      $nids = $query->execute();
    }

    // Check if the node id belong to Station 2 (nid = 65186)
    elseif ($nid == 65186) {
      $stations = $this->getStations(2);

      // Adding the Broadcast Schedule date field for sorting
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->condition('type', 'archive_descriptions')
        ->condition('status', 1, '=')
        ->sort('field_archive_broadcast_timedate.value', 'DESC')
        ->range(0, 20);
      $query->accessCheck(FALSE);

      if ($stations) {
        $query->condition('field_archive_includes.target_id', $stations, 'IN');
      }
      else {
        $query->condition('field_archive_includes.target_id', $nid, '=');
      }

      $nids = $query->execute();
    }

    // Check if the node id belong to Faith Stream Network (nid = 273434)
    elseif ($nid == 273434) {
      $stations = $this->getStations(3);

      // Adding the Broadcast Schedule date field for sorting
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->condition('type', 'archive_descriptions')
        ->condition('status', 1, '=')
        ->sort('field_archive_broadcast_timedate.value', 'DESC')
        ->range(0, 20);
      $query->accessCheck(FALSE);

      if ($stations) {
        $query->condition('field_archive_includes.target_id', $stations, 'IN');
      }
      else {
        $query->condition('field_archive_includes.target_id', $nid, '=');
      }

      $nids = $query->execute();
    }

    // Check if the node id belong to Station 1 and Station 2 (nid = 67046)
    elseif ($nid == 67046) {
      $stations = $this->getStations(0);

      // Adding the Broadcast Schedule date field for sorting
      $query = $this->entityTypeManager->getStorage('node')->getQuery();
      $query->condition('type', 'archive_descriptions')
        ->condition('status', 1, '=')
        ->sort('field_archive_broadcast_timedate.value', 'DESC')
        ->range(0, 20);
      $query->accessCheck(FALSE);

      if ($stations) {
        $query->condition('field_archive_includes.target_id', $stations, 'IN');
      }
      else {
        $query->condition('field_archive_includes.target_id', $nid, '=');
      }

      $nids = $query->execute();
    }

    if (empty($nids)) {
      return [];
    }

    // Site base url
    $site_url = \Drupal::request()->getSchemeAndHttpHost();

    $archive_data = [];
    $last_modified = 0;
    foreach ($nids as $ar_nid) {
      $node = Node::load($ar_nid);
      $changed = $node->getChangedTime();
      if ($changed > $last_modified) {
        $last_modified = $changed;
      }

      $row = [];
      $skip_node = false;

      // Archive title
      if (!empty($node->label())) {
        $row['title'] = $node->label();
        $row['itunes_title'] = Html::escape($node->label());
      }
      else {
        $row['title'] = 'RSS Archive';
        $row['itunes_title'] = 'RSS Archive';
      }

      // Archive Desc
      if ($node->hasField('field_arc_feed_des1') && !$node->get('field_arc_feed_des1')->isEmpty()) {
        $archive_desc = $node->get('field_arc_feed_des1')->value;
        $row['desc'] = $this->baseHelper->xmlEntityReplace($node->get('field_arc_feed_des1')->value);

        $itunes_archive_desc = substr($node->get('field_arc_feed_des1')->value, 0, 4000);
        $row['itunes_summary'] = $this->baseHelper->xmlEntityReplace($itunes_archive_desc, 4);
      }
      else {
        $archive_desc = 'RSS Feed';
        $row['desc'] = 'RSS Feed';
        $row['itunes_summary'] = 'RSS Feed';
      }

      $broadcast_timedate = $node->hasField('field_archive_broadcast_timedate') && !$node->get('field_archive_broadcast_timedate')->isEmpty() ?
        strtotime($node->get('field_archive_broadcast_timedate')->value) : 0;
      if ($broadcast_timedate) {
        $node_broadcast_timedate = date('Y-m-d', $broadcast_timedate);
      }
      else {
        $node_broadcast_timedate = '0000-00-00';
      }

      if ($broadcast_timedate && $node_broadcast_timedate < date('Y-m-d')) {
        $row['pub_date'] = date('D, d M Y H:i:s T', $broadcast_timedate);
        $row['publish_date'] = date('c', $broadcast_timedate);
        $row['up_date'] = date('D, d M Y H:i:s T', $broadcast_timedate);
        $row['update_date'] = date('c', $broadcast_timedate);
      }
      else {
        $row['pub_date'] = '';
        $row['publish_date'] = '';
        $row['up_date'] = '';
        $row['update_date'] = '';
        $skip_node = true;
      }

      $row['link'] = $site_url . \Drupal::service('path_alias.manager')->getAliasByPath("/node/" . $node->id());

      // Copyright
      $row['copyright'] = '©2005-' . date('Y') . ' BBS Network, Inc.';

      // Author
      if ($node->hasField('field_authorized_by') && !$node->get('field_authorized_by')->isEmpty()) {
        $author = $node->get('field_authorized_by')->value;
        $pos1 = stripos($author, '(');
        $pos2 = stripos($author, ')');
        if ($pos1 === false || $pos2 === false) {
          $author = $author . ' (Author)';
        }
        $row['author'] = $this->baseHelper->xmlEntityReplace($author);
      }
      else {
        $row['author'] = 'doug@bbsradio.com (BBS Radio, BBS Network Inc.)';
      }

      // Sub-Title
      if ($node->hasField('field_archive_show_sub_headline') && !$node->get('field_archive_show_sub_headline')->isEmpty()) {
        $subtitle = strip_tags($node->get('field_archive_show_sub_headline')->value);
        $row['subtitle'] = $this->baseHelper->xmlEntityReplace($subtitle);
        $row['itunes_subtitle'] = $this->baseHelper->xmlEntityReplace($subtitle, 4);
      }

      // Show Category and Parent Categories. Only two level of categories are counting
      $categories = [];
      $category_list = '';

      if ($node->hasField('field_archive_category') && !$node->get('field_archive_category')->isEmpty() && $node->get('field_archive_category')->entity) {
        $terms = $node->get('field_archive_category')->referencedEntities();
        if ($terms && count($terms)>0) {
          foreach ($terms as $term) {
            $parent_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadParents($term->id());
            if ($parent_terms) {
              foreach ($parent_terms as $parent_term) {
                $categories[$parent_term->id()]['tid'] = $parent_term->id();
                $categories[$parent_term->id()]['name'] = $this->baseHelper->xmlEntityReplace($parent_term->getName(), 2);
                $categories[$parent_term->id()]['children'][] = [
                  'tid' => $term->id(),
                  'name' => $this->baseHelper->xmlEntityReplace($term->getName(), 2),
                ];
              }
            }
            else {
              $categories[$term->id()]['tid'] = $term->id();
              $categories[$term->id()]['name'] = $this->baseHelper->xmlEntityReplace($term->getName(), 2);
              $categories[$term->id()]['children'] = [];
            }
          }
        }

        if ($categories) {
          foreach ($categories as $category) {
            if (!empty($category_list)) {
              $category_list .= ', ';
            }
            $category_list .= $category['name'];

            if (isset($category['children']) && count($category['children'])) {
              foreach ($category['children'] as $child) {
                if (!empty($category_list)) {
                  $category_list .= ', ';
                }
                $category_list .= $child['name'];
              }
            }
          }
        }

        $row['categories'] = $categories;
        $row['category_list'] = $category_list;
      }
      else {
        $categories[0]['tid'] = 0;
        $categories[0]['name'] = $this->baseHelper->xmlEntityReplace('News & Politics', 2);
        $categories[0]['children'] = [];
        $category_list = $this->baseHelper->xmlEntityReplace('News & Politics', 2);

        $row['categories'] = $categories;
        $row['category_list'] = $category_list;
      }

      // iTunes Categories. Only two level of categories are counting
      $itunes_categories = [];
      $itunes_category_list = '';
      if ($node->hasField('field_archive_itunes_category') && !$node->get('field_archive_itunes_category')->isEmpty() && $node->get('field_archive_itunes_category')->entity) {
        $terms = $node->get('field_archive_itunes_category')->referencedEntities();
        if ($terms && count($terms) > 0) {
          foreach ($terms as $term) {
            $parent_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadParents($term->id());
            if ($parent_terms) {
              foreach ($parent_terms as $parent_term) {
                if ($this->baseHelper->checkItunesCategory($parent_term->getName())) {
                  $itunes_categories[$parent_term->id()]['tid'] = $parent_term->id();
                  $itunes_categories[$parent_term->id()]['name'] = $this->baseHelper->xmlEntityReplace($parent_term->getName(), 2);

                  if ($this->baseHelper->checkItunesCategory($term->getName())) {
                    $itunes_categories[$parent_term->id()]['children'][] = [
                      'tid' => $term->id(),
                      'name' => $this->baseHelper->xmlEntityReplace($term->getName(), 2),
                    ];
                  }
                }
              }
            }
            else {
              if ($this->baseHelper->checkItunesCategory($term->getName())) {
                $itunes_categories[$term->id()]['tid'] = $term->id();
                $itunes_categories[$term->id()]['name'] = $this->baseHelper->xmlEntityReplace($term->getName(), 2);
                $itunes_categories[$term->id()]['children'] = [];
              }
            }
          }
        }

        if (empty($itunes_categories)) {
          $itunes_categories[0]['tid'] = 0;
          $itunes_categories[0]['name'] = $this->baseHelper->xmlEntityReplace('News & Politics', 2);
          $itunes_categories[0]['children'] = [];
        }

        if ($itunes_categories) {
          foreach ($itunes_categories as $itune_category) {
            if (!empty($itunes_category_list)) {
              $itunes_category_list .= ', ';
            }
            $itunes_category_list .= $itune_category['name'];

            if (isset($itune_category['children']) && count($itune_category['children'])) {
              foreach ($itune_category['children'] as $child) {
                if (!empty($itunes_category_list)) {
                  $itunes_category_list .= ', ';
                }
                $itunes_category_list .= $child['name'];
              }
            }
          }
        }

        $row['itunes_categories'] = $itunes_categories;
        $row['itunes_category_list'] = $itunes_category_list;
      }
      else {
        $itunes_categories[0]['tid'] = 0;
        $itunes_categories[0]['name'] = $this->baseHelper->xmlEntityReplace('News & Politics', 2);
        $itunes_categories[0]['children'] = [];
        $itunes_category_list = $this->baseHelper->xmlEntityReplace('News & Politics', 2);

        $row['itunes_categories'] = $itunes_categories;
        $row['itunes_category_list'] = $itunes_category_list;
      }

      // Tags, Keywords
      $tags = [];
      $tag_list = '';
      if ($node->hasField('field_archive_tags') && !$node->get('field_archive_tags')->isEmpty()) {
        $tag_terms = $node->get('field_archive_tags')->referencedEntities();

        foreach ($tag_terms as $tag_term) {
          // Limit tag length to max 255
          $tag_length = strlen($tag_list) + strlen($tag_term->getName());
          if ($tag_length > 253) {
            break;
          }

          if (!empty($tag_list)) {
            $tag_list .= ', ';
          }
          $tag_name = $tag_term->getName();
          $tag_name = strtolower($tag_name);
          $tag_name = str_ireplace('&', '', $tag_name);
          $tag_name = str_ireplace(' ', '-', $tag_name);
          $tag_list .= $tag_name;
        }

        $row['tag_list'] = $tag_list;
        $row['tags'] = $tags;

        if ($node->hasField('field_archive_show_duration') && !$node->get('field_archive_show_duration')->isEmpty()) {
          $row['duration'] = $node->get('field_archive_show_duration')->value;
        }
      }
      else {
        $row['tags'][] = 'Keywords';
        $row['tag_list'] = 'Keywords';
      }

      // Archive image
      $row['archive_image'] = $this->getArchiveImageUrl($node);

      if ($node->hasField('field_archive_show_duration') && !$node->get('field_archive_show_duration')->isEmpty()) {
        $row['video_duration'] = $node->get('field_archive_show_duration')->value;
      }
      else {
        $row['video_duration'] = '00:58:55';
      }

      $row['audios_videos'] = [];
      $count = 0;

      $video_url = '';
      $file = false;
      $archive_delivered_file_up = false;

      if ($node->hasField('field_archive_delivered_file_up_') && !$node->get('field_archive_delivered_file_up_')->isEmpty() && $node->get('field_archive_delivered_file_up_')->entity) {
        // $video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_delivered_file_up_')->entity->getFileUri());

        /** @var \Drupal\file\FileInterface $file */
        $file = $node->get('field_archive_delivered_file_up_')->first()->entity;
        $filename = $file->getFilename();

        $video_url = Url::fromRoute('custom_example.file_proxy', [
          'node' => $node->id(),
          'type' => 1,
          'file_type' => 2,
          'filename' => $filename,
        ], ['absolute' => TRUE])->toString();

        $row['audios_videos'][$count]['video_size'] = $node->get('field_archive_delivered_file_up_')->entity->getSize();
        $row['audios_videos'][$count]['video_mime'] = $node->get('field_archive_delivered_file_up_')->entity->getMimeType();
        $archive_delivered_file_up = true;
      }
      elseif ($node->hasField('field_archive_delivered_file_up') && !$node->get('field_archive_delivered_file_up')->isEmpty() && $node->get('field_archive_delivered_file_up')->entity) {
        // $video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_delivered_file_up')->entity->getFileUri());

        /** @var \Drupal\file\FileInterface $file */
        $file = $node->get('field_archive_delivered_file_up')->first()->entity;
        $filename = $file->getFilename();

        $video_url = Url::fromRoute('custom_example.file_proxy', [
          'node' => $node->id(),
          'type' => 1,
          'file_type' => 1,
          'filename' => $filename,
        ], ['absolute' => TRUE])->toString();

        $row['audios_videos'][$count]['video_size'] = $node->get('field_archive_delivered_file_up')->entity->getSize();
        $row['audios_videos'][$count]['video_mime'] = $node->get('field_archive_delivered_file_up')->entity->getMimeType();
        $archive_delivered_file_up = true;
      }

      if (!empty($video_url) && $file && $archive_delivered_file_up) {
        $video_player_url = $site_url . '/archive-description/audio/listen/' . $node->id();

        $row['audios_videos'][$count]['video_title'] = Html::escape($node->label());
        $row['audios_videos'][$count]['video_desc'] = $this->baseHelper->xmlEntityReplace($archive_desc, 3);

        $row['audios_videos'][$count]['video_url'] = $video_url;
        $row['audios_videos'][$count]['video_player_url'] = $video_player_url;

        $row['audios_videos'][$count]['video_duration'] = $this->getVideoDuration($file);
        $row['audios_videos'][$count]['video_player_width'] = '400';
        $row['audios_videos'][$count]['video_player_height'] = '40';
        $row['audios_videos'][$count]['media_type'] = 'audio';

        ++$count;
      }

      if (!$skip_node) {
        $archive_data[] = $row;
      }
    }

    return ['data' => $archive_data, 'last_modified' => $last_modified];
  }

  /**
   * Load the Archive Descriptions nodes for a Talkshow node
   */
  public function getMrssArchives(int $nid) {
    $j = 0;

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $nids = $query->condition('type', 'archive_descriptions')
      ->condition('field_archive_includes.target_id', $nid, '=')
      ->condition('status', 1, '=')
      ->sort('field_archive_broadcast_timedate.value', 'DESC')
      ->range(0, 20)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      return [];
    }

    // Site base url
    $site_url = \Drupal::request()->getSchemeAndHttpHost();

    $archive_data = [];
    foreach ($nids as $ar_nid) {
      $node = Node::load($ar_nid);

      $row = [];
      $skip_node = false;

      // Archive title
      if (!empty($node->label())) {
        $row['title'] = Html::escape($node->label());
      }
      else {
        $row['title'] = 'MRSS Archive';
      }

      // Archive Desc
      if ($node->hasField('field_arc_feed_des1') && !$node->get('field_arc_feed_des1')->isEmpty()) {
        $archive_desc = $node->get('field_arc_feed_des1')->value;
        $row['desc'] = $this->baseHelper->xmlEntityReplace($node->get('field_arc_feed_des1')->value);
        $row['itunes_summary'] = $this->baseHelper->xmlEntityReplace($node->get('field_arc_feed_des1')->value, 4);
      }
      else {
        $archive_desc = 'MRSS Feed';
        $row['desc'] = 'MRSS Feed';
        $row['itunes_summary'] = 'MRSS Feed';
      }

      $broadcast_timedate = $node->hasField('field_archive_broadcast_timedate') && !$node->get('field_archive_broadcast_timedate')->isEmpty() ?
        strtotime($node->get('field_archive_broadcast_timedate')->value) : 0;
      if ($broadcast_timedate) {
        $node_broadcast_timedate = date('Y-m-d', $broadcast_timedate);
      }
      else {
        $node_broadcast_timedate = '0000-00-00';
      }

      if ($broadcast_timedate && $node_broadcast_timedate < date('Y-m-d')) {
        $row['publish_date'] = date('D, d M Y H:i:s T', $broadcast_timedate);
      }
      else {
        $row['publish_date'] = '';
        $skip_node = true;
      }

      $row['link'] = $site_url . \Drupal::service('path_alias.manager')->getAliasByPath("/node/" . $node->id());

      if ($node->hasField('field_authorized_by') && !$node->get('field_authorized_by')->isEmpty()) {
        $author = $node->get('field_authorized_by')->value;
        $pos1 = stripos($author, '(');
        $pos2 = stripos($author, ')');
        if ($pos1 === false || $pos2 === false) {
          $author = $author . ' (Author)';
        }

        $row['author'] = $this->baseHelper->xmlEntityReplace($author);
      }

      if ($node->hasField('field_archive_show_sub_headline') && !$node->get('field_archive_show_sub_headline')->isEmpty()) {
        $subtitle = strip_tags($node->get('field_archive_show_sub_headline')->value);
        $subtitle = str_ireplace('&', 'and', $subtitle);
        $subtitle = Html::escape($subtitle);
        $row['subtitle'] = $this->baseHelper->xmlEntityReplace($subtitle);
      }

      if ($node->hasField('field_archive_tags') && !$node->get('field_archive_tags')->isEmpty()) {
        $terms = $node->get('field_archive_tags')->referencedEntities();

        $term_name = '';
        foreach ($terms as $term) {
          // Limit tag length to max 255
          $tag_length = strlen($term_name) + strlen($term->getName());
          if ($tag_length > 253) {
            break;
          }

          if (!empty($term_name)) {
            $term_name .= ', ';
          }
          $term_name .= $term->getName();
        }
        $row['tags'] = $term_name;

        if ($node->hasField('field_archive_show_duration') && !$node->get('field_archive_show_duration')->isEmpty()) {
          $row['duration'] = $node->get('field_archive_show_duration')->value;
        }
      }

      $row['audios_videos'] = [];
      $count = 0;

      $video_url = '';
      $file = false;
      $archive_delivered_file_up = false;

      if ($node->hasField('field_archive_delivered_file_up_') && !$node->get('field_archive_delivered_file_up_')->isEmpty() && $node->get('field_archive_delivered_file_up_')->entity) {
        // $video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_delivered_file_up_')->entity->getFileUri());

        /** @var \Drupal\file\FileInterface $file */
        $file = $node->get('field_archive_delivered_file_up_')->first()->entity;
        $filename = $file->getFilename();

        $video_url = Url::fromRoute('custom_example.file_proxy', [
          'node' => $node->id(),
          'type' => 1,
          'file_type' => 2,
          'filename' => $filename,
        ], ['absolute' => TRUE])->toString();

        $row['audios_videos'][$count]['video_size'] = $node->get('field_archive_delivered_file_up_')->entity->getSize();
        $row['audios_videos'][$count]['video_mime'] = $node->get('field_archive_delivered_file_up_')->entity->getMimeType();
        $archive_delivered_file_up = true;
      }
      elseif ($node->hasField('field_archive_delivered_file_up') && !$node->get('field_archive_delivered_file_up')->isEmpty() && $node->get('field_archive_delivered_file_up')->entity) {
        // $video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_delivered_file_up')->entity->getFileUri());

        /** @var \Drupal\file\FileInterface $file */
        $file = $node->get('field_archive_delivered_file_up')->first()->entity;
        $filename = $file->getFilename();

        $video_url = Url::fromRoute('custom_example.file_proxy', [
          'node' => $node->id(),
          'type' => 1,
          'file_type' => 1,
          'filename' => $filename,
        ], ['absolute' => TRUE])->toString();

        $row['audios_videos'][$count]['video_size'] = $node->get('field_archive_delivered_file_up')->entity->getSize();
        $row['audios_videos'][$count]['video_mime'] = $node->get('field_archive_delivered_file_up')->entity->getMimeType();
        $archive_delivered_file_up = true;
      }

      if (!empty($video_url) && $file && $archive_delivered_file_up) {
        $video_player_url = $site_url . '/archive-description/audio/listen/' . $node->id();

        $row['audios_videos'][$count]['video_title'] = Html::escape($node->label());
        $row['audios_videos'][$count]['video_desc'] = $this->baseHelper->xmlEntityReplace($archive_desc, 3);

        $row['audios_videos'][$count]['video_url'] = $video_url;
        $row['audios_videos'][$count]['video_player_url'] = $video_player_url;

        $row['audios_videos'][$count]['video_duration'] = $this->getVideoDuration($file);
        $row['audios_videos'][$count]['video_player_width'] = '400';
        $row['audios_videos'][$count]['video_player_height'] = '40';
        $row['audios_videos'][$count]['media_type'] = 'audio';

        ++$count;
      }

      $archive_uploaded_video = false;
      if ($node->hasField('field_archive_uploaded_video_') && !$node->get('field_archive_uploaded_video_')->isEmpty() && $node->get('field_archive_uploaded_video_')->entity) {
        // $archive_video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_uploaded_video_')->entity->getFileUri());

        /** @var \Drupal\file\FileInterface $archive_file */
        $archive_file = $node->get('field_archive_uploaded_video_')->first()->entity;
        $filename = $archive_file->getFilename();

        $archive_video_url = Url::fromRoute('custom_example.file_proxy', [
          'node' => $node->id(),
          'type' => 2,
          'file_type' => 4,
          'filename' => $filename,
        ], ['absolute' => TRUE])->toString();

        $row['audios_videos'][$count]['video_size'] = $node->get('field_archive_uploaded_video_')->entity->getSize();
        $row['audios_videos'][$count]['video_mime'] = $node->get('field_archive_uploaded_video_')->entity->getMimeType();
        $archive_uploaded_video = true;
      }
      elseif ($node->hasField('field_archive_uploaded_video') && !$node->get('field_archive_uploaded_video')->isEmpty() && $node->get('field_archive_uploaded_video')->entity) {
        // $archive_video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_uploaded_video')->entity->getFileUri());

        /** @var \Drupal\file\FileInterface $archive_file */
        $archive_file = $node->get('field_archive_uploaded_video')->first()->entity;
        $filename = $archive_file->getFilename();

        $archive_video_url = Url::fromRoute('custom_example.file_proxy', [
          'node' => $node->id(),
          'type' => 2,
          'file_type' => 3,
          'filename' => $filename,
        ], ['absolute' => TRUE])->toString();

        $row['audios_videos'][$count]['video_mime'] = $node->get('field_archive_uploaded_video')->entity->getMimeType();
        $row['audios_videos'][$count]['video_size'] = $node->get('field_archive_uploaded_video')->entity->getSize();

        $archive_uploaded_video = true;
      }

      if (!empty($archive_video_url) && !empty($archive_file) && !empty($archive_uploaded_video)) {
        $video_player_url = $site_url . '/archive-description/video/upld/watch/' . $node->id();

        $video_image_url = '';
        $video_image = '';
        $video_image_link = '';
        if ($node->hasField('field_archive_add_picture') && !$node->get('field_archive_add_picture')->isEmpty() && $node->get('field_archive_add_picture')->entity) {
          $video_image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_add_picture')->entity->getFileUri());
          $video_image = '<img src="' . $video_image_url . '" alt="' . Html::escape($node->label()) . '" />';
          $video_image_link = '<a href="' . $video_url . '">' . $video_image . '</a>';
        }
        elseif ($node->hasField('field_archive_opt_pic_for_body') && !$node->get('field_archive_opt_pic_for_body')->isEmpty() && $node->get('field_archive_opt_pic_for_body')->entity) {
          $video_image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_opt_pic_for_body')->entity->getFileUri());
          $video_image = '<img src="' . $video_image_url . '" alt="' . Html::escape($node->label()) . '" />';
          $video_image_link = '<a href="' . $video_url . '">' . $video_image . '</a>';
        }

        $row['audios_videos'][$count]['video_title'] = Html::escape($node->label());
        $row['audios_videos'][$count]['video_desc'] = $this->baseHelper->xmlEntityReplace($archive_desc, 3);

        $row['audios_videos'][$count]['video_url'] = $video_url;
        $row['audios_videos'][$count]['video_player_url'] = $video_player_url;

        $row['audios_videos'][$count]['video_player_width'] = '640';
        $row['audios_videos'][$count]['video_player_height'] = '360';
        $row['audios_videos'][$count]['media_type'] = 'video';

        if (!empty($video_image_url)) {
          $row['audios_videos'][$count]['video_thumb'] = $video_image_url;
        }

        ++$count;
      }

      $produced_or_delivered_pro = false;
      if ($node->hasField('field_produced_or_delivered_pro_') && !$node->get('field_produced_or_delivered_pro_')->isEmpty() && $node->get('field_produced_or_delivered_pro_')->entity) {
        // $produced_video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_produced_or_delivered_pro_')->entity->getFileUri());

        /** @var \Drupal\file\FileInterface $produced_file */
        $produced_file = $node->get('field_produced_or_delivered_pro_')->first()->entity;
        $filename = $produced_file->getFilename();

        $produced_video_url = Url::fromRoute('custom_example.file_proxy', [
          'node' => $node->id(),
          'type' => 2,
          'file_type' => 6,
          'filename' => $filename,
        ], ['absolute' => TRUE])->toString();

        $row['audios_videos'][$count]['video_size'] = $node->get('field_produced_or_delivered_pro_')->entity->getSize();
        $row['audios_videos'][$count]['video_mime'] = $node->get('field_produced_or_delivered_pro_')->entity->getMimeType();
        $produced_or_delivered_pro = true;
      }
      elseif ($node->hasField('field_produced_or_delivered_pro2') && !$node->get('field_produced_or_delivered_pro2')->isEmpty() && $node->get('field_produced_or_delivered_pro2')->entity) {
        // $produced_video_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_produced_or_delivered_pro2')->entity->getFileUri());

        /** @var \Drupal\file\FileInterface $produced_file */
        $produced_file = $node->get('field_produced_or_delivered_pro2')->first()->entity;
        $filename = $produced_file->getFilename();

        $produced_video_url = Url::fromRoute('custom_example.file_proxy', [
          'node' => $node->id(),
          'type' => 2,
          'file_type' => 5,
          'filename' => $filename,
        ], ['absolute' => TRUE])->toString();

        $row['audios_videos'][$count]['videoSize'] = $node->get('field_produced_or_delivered_pro2')->entity->getSize(0);
        $row['audios_videos'][$count]['videoMime'] = $node->get('field_produced_or_delivered_pro2')->entity->getMimeType();
        $produced_or_delivered_pro = true;
      }

      if (!empty($produced_video_url) && !empty($produced_file) && !empty($produced_or_delivered_pro)) {
        $video_player_url = $site_url . '/archive-description/video/prod/watch/' . $node->id();

        $video_image_url = '';
        $video_image = '';
        $video_image_link = '';
        if ($node->hasField('field_archive_add_picture') && !$node->get('field_archive_add_picture')->isEmpty() && $node->get('field_archive_add_picture')->entity) {
          $video_image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_add_picture')->entity->getFileUri());
          $video_image = '<img src="' . $video_image_url . '" alt="' . Html::escape($node->label()) . '" />';
          $video_image_link = '<a href="' . $video_url . '">' . $video_image . '</a>';
        }
        elseif ($node->hasField('field_archive_opt_pic_for_body') && !$node->get('field_archive_opt_pic_for_body')->isEmpty() && $node->get('field_archive_opt_pic_for_body')->entity) {
          $video_image_url = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_archive_opt_pic_for_body')->entity->getFileUri());
          $video_image = '<img src="' . $video_image_url . '" alt="' . Html::escape($node->label()) . '" />';
          $video_image_link = '<a href="' . $video_url . '">' . $video_image . '</a>';
        }

        $row['audios_videos'][$count]['video_title'] = Html::escape($node->label());
        $row['audios_videos'][$count]['video_desc'] = $this->baseHelper->xmlEntityReplace($archive_desc, 3);

        $row['audios_videos'][$count]['video_url'] = $video_url;
        $row['audios_videos'][$count]['video_player_url'] = $video_player_url;

        $row['audios_videos'][$count]['video_player_width'] = '640';
        $row['audios_videos'][$count]['video_player_height'] = '360';
        $row['audios_videos'][$count]['media_type'] = 'video';

        if (!empty($video_image_url)) {
          $row['audios_videos'][$count]['video_thumb'] = $video_image_url;
        }

        ++$count;
      }

      if (!$skip_node) {
        $archive_data[] = $row;
      }
    }

    return $archive_data;
  }

  /**
   * {@inheritdoc}
   */
  public function getRSSContent(NodeInterface $node, Request $request) {
    $site_url = \Drupal::request()->getSchemeAndHttpHost();
    $theme = \Drupal::theme()->getActiveTheme();
    $skip_node = false;

    $talk_show_data = [];
    $talk_show_data['nid'] = $node->id();
    $url_alias = \Drupal::service('path_alias.manager')->getAliasByPath('/node/' . $node->id());
    $talk_show_data['link'] = $site_url . $url_alias;

    $talk_show_data['atom_link'] = $site_url . '/customshow/' . $node->id();

    // Talk show title
    if ($node->hasField('field_feed_talkshow_title') && !$node->get('field_feed_talkshow_title')->isEmpty()) {
      $talk_show_data['title'] = $this->baseHelper->xmlEntityReplace(Html::escape($node->get('field_feed_talkshow_title')->value));
    }
    else {
      $talk_show_data['title'] = $this->baseHelper->xmlEntityReplace($node->label());
    }

    // Description
    if ($node->hasField('field_feed_description') && !$node->get('field_feed_description')->isEmpty()) {
      $talk_show_data['desc'] = $this->baseHelper->xmlEntityReplace($node->get('field_feed_description')->value, 3);
      $talk_show_data['itunes_summary'] = $this->baseHelper->xmlEntityReplace($node->get('field_feed_description')->value, 4);
    }
    else {
      $talk_show_data['desc'] = 'Talkshow RSS';
      $talk_show_data['itunes_summary'] = 'Talkshow RSS';
    }

    // Talk show Banner
    if ($node->hasField('field_include_banner') && !$node->get('field_include_banner')->isEmpty() && $node->get('field_include_banner')->entity) {
      $talk_show_data['image']['url'] = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_include_banner')->entity->getFileUri());
      $talk_show_data['image']['title'] = $this->baseHelper->xmlEntityReplace($node->label());
      $talk_show_data['image']['link'] = $site_url . '/node/' . $node->id();
    }

    // Copyright
    if ($node->hasField('field_include_copyright') && !$node->get('field_include_copyright')->isEmpty()) {
      $talk_show_data['copyright'] = $this->baseHelper->xmlEntityReplace($node->get('field_include_copyright')->value);
    }
    else {
      $talk_show_data['copyright'] = '©2005-' . date('Y') . ' BBS Network, Inc.';
    }

    // Webmaster and Editor and Language
    $talk_show_data['webmaster'] = 'doug@bbsradio.com (Douglas Newsom)';
    $talk_show_data['editor'] = 'doug@bbsradio.com (Douglas Newsom)';
    $talk_show_data['language'] = 'en-us';

    $broadcast_timedate = $node->hasField('field_archive_broadcast_timedate') && !$node->get('field_archive_broadcast_timedate')->isEmpty() ?
      strtotime($node->get('field_archive_broadcast_timedate')->value) : 0;
    if ($broadcast_timedate) {
      $node_broadcast_timedate = date('Y-m-d', $broadcast_timedate);
    }
    else {
      $node_broadcast_timedate = '0000-00-00';
    }

    if (!$broadcast_timedate || $node_broadcast_timedate >= date('Y-m-d')) {
      $broadcast_timedate = $node->getCreatedTime();
    }

    $talk_show_data['pub_date'] = date('D, d M Y H:i:s T', $broadcast_timedate);
    $talk_show_data['publish_date'] = date('c', $broadcast_timedate);
    $talk_show_data['up_date'] = date('D, d M Y H:i:s T', $broadcast_timedate);
    $talk_show_data['update_date'] = date('c', $broadcast_timedate);

    // Show Category and Parent Categories. Only two level of categories are counting
    $categories = [];
    $category_list = '';

    if ($node->hasField('field_include_show_categories') && !$node->get('field_include_show_categories')->isEmpty() && $node->get('field_include_itunes_categories')->entity) {
      $terms = $node->get('field_include_show_categories')->referencedEntities();
      if ($terms && count($terms)>0) {
        foreach ($terms as $term) {
          $parent_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadParents($term->id());
          if ($parent_terms) {
            foreach ($parent_terms as $parent_term) {
              $categories[$parent_term->id()]['tid'] = $parent_term->id();
              $categories[$parent_term->id()]['name'] = $this->baseHelper->xmlEntityReplace($parent_term->getName(), 2);
              $categories[$parent_term->id()]['children'][] = [
                'tid' => $term->id(),
                'name' => $this->baseHelper->xmlEntityReplace($term->getName(), 2),
              ];
            }
          }
          else {
            $categories[$term->id()]['tid'] = $term->id();
            $categories[$term->id()]['name'] = $this->baseHelper->xmlEntityReplace($term->getName(), 2);
            $categories[$term->id()]['children'] = [];
          }
        }
      }

      if ($categories) {
        foreach ($categories as $category) {
          if (!empty($category_list)) {
            $category_list .= ', ';
          }
          $category_list .= $category['name'];

          if (isset($category['children']) && count($category['children'])) {
            foreach ($category['children'] as $child) {
              if (!empty($category_list)) {
                $category_list .= ', ';
              }
              $category_list .= $child['name'];
            }
          }
        }
      }

      $talk_show_data['categories'] = $categories;
      $talk_show_data['category_list'] = $category_list;
    }
    else {
      $categories[0]['tid'] = 0;
      $categories[0]['name'] = $this->baseHelper->xmlEntityReplace('News & Politics', 2);
      $categories[0]['children'] = [];
      $category_list = $this->baseHelper->xmlEntityReplace('News & Politics', 2);

      $talk_show_data['categories'] = $categories;
      $talk_show_data['category_list'] = $category_list;
    }

    // iTunes Categories. Only two level of categories are counting
    $itunes_categories = [];
    $itunes_category_list = '';
    if ($node->hasField('field_include_itunes_categories') && !$node->get('field_include_itunes_categories')->isEmpty() && $node->get('field_include_itunes_categories')->entity) {
      $terms = $node->get('field_include_itunes_categories')->referencedEntities();
      if ($terms && count($terms)>0) {
        foreach ($terms as $term) {
          $parent_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadParents($term->id());
          if ($parent_terms) {
            foreach ($parent_terms as $parent_term) {
              if ($this->baseHelper->checkItunesCategory($parent_term->getName())) {
                $itunes_categories[$parent_term->id()]['tid'] = $parent_term->id();
                $itunes_categories[$parent_term->id()]['name'] = $this->baseHelper->xmlEntityReplace($parent_term->getName(), 2);

                if ($this->baseHelper->checkItunesCategory($term->getName())) {
                  $itunes_categories[$parent_term->id()]['children'][] = [
                    'tid' => $term->id(),
                    'name' => $this->baseHelper->xmlEntityReplace($term->getName(), 2),
                  ];
                }
              }
            }
          }
          else {
            if ($this->baseHelper->checkItunesCategory($term->getName())) {
              $itunes_categories[$term->id()]['tid'] = $term->id();
              $itunes_categories[$term->id()]['name'] = $this->baseHelper->xmlEntityReplace($term->getName(), 2);
              $itunes_categories[$term->id()]['children'] = [];
            }
          }
        }
      }

      if (empty($itunes_categories)) {
        $itunes_categories[0]['tid'] = 0;
        $itunes_categories[0]['name'] = $this->baseHelper->xmlEntityReplace('News & Politics', 2);
        $itunes_categories[0]['children'] = [];
      }

      if ($itunes_categories) {
        foreach ($itunes_categories as $itune_category) {
          if (!empty($itunes_category_list)) {
            $itunes_category_list .= ', ';
          }
          $itunes_category_list .= $itune_category['name'];

          if (isset($itune_category['children']) && count($itune_category['children'])) {
            foreach ($itune_category['children'] as $child) {
              if (!empty($itunes_category_list)) {
                $itunes_category_list .= ', ';
              }
              $itunes_category_list .= $child['name'];
            }
          }
        }
      }

      $talk_show_data['itunes_categories'] = $itunes_categories;
      $talk_show_data['itunes_category_list'] = $itunes_category_list;
    }
    else {
      $itunes_categories[0]['tid'] = 0;
      $itunes_categories[0]['name'] = $this->baseHelper->xmlEntityReplace('News & Politics', 2);
      $itunes_categories[0]['children'] = [];
      $itunes_category_list = $this->baseHelper->xmlEntityReplace('News & Politics', 2);

      $talk_show_data['itunes_categories'] = $itunes_categories;
      $talk_show_data['itunes_category_list'] = $itunes_category_list;
    }

    // Feed Image
    if ($node->hasField('field_include_feed_picture') && !$node->get('field_include_feed_picture')->isEmpty() && $node->get('field_include_feed_picture')->entity) {
      $talk_show_data['feed_image'] = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_include_feed_picture')->entity->getFileUri());
    }
    else {
      $talk_show_data['feed_image'] = $site_url . '/'. $theme->getPath() . '/images/a-fireside-chat-pod-pic.jpg';
    }

    // Sub-Title
    if ($node->hasField('field_include_feed_subtitle') && !$node->get('field_include_feed_subtitle')->isEmpty()) {
      $subtitle = strip_tags($node->get('field_include_feed_subtitle')->value);
      $subtitle = str_ireplace('&', 'and', $subtitle);
      $subtitle = Html::escape($subtitle);
      $talk_show_data['itunes_subtitle'] = $this->baseHelper->xmlEntityReplace($subtitle);
    }
    else {
      $talk_show_data['itunes_subtitle'] = $this->baseHelper->xmlEntityReplace($node->label());
    }

    // Tags, Keywords
    $tags = [];
    $tag_list = '';
    if ($node->hasField('field_include_feed_tags_keywords') && !$node->get('field_include_feed_tags_keywords')->isEmpty()) {
      $tag_terms = $node->get('field_include_feed_tags_keywords')->referencedEntities();

      foreach ($tag_terms as $tag_term) {
        // Limit tag length to max 255
        $tag_length = strlen($tag_list) + strlen($tag_term->getName());
        if ($tag_length > 253) {
          break;
        }

        if (!empty($tag_list)) {
          $tag_list .= ', ';
        }
        $tag_name = $tag_term->getName();
        $tag_name = strtolower($tag_name);
        $tag_name = str_ireplace('&', '', $tag_name);
        $tag_name = str_ireplace(' ', '-', $tag_name);
        $tag_list .= $tag_name;
        $tags[] = $tag_name;
      }

      $talk_show_data['tag_list'] = $tag_list;
      $talk_show_data['tags'] = $tags;
    }
    else {
      $talk_show_data['tags'][] = 'Keywords';
      $talk_show_data['tag_list'] = 'Keywords';
    }

    // Explicit Materials
    if ($node->hasField('field_include_explicit_materials') && !$node->get('field_include_explicit_materials')->isEmpty()) {
      $talk_show_data['explicit'] = strtolower($node->get('field_include_explicit_materials')->value);
    }
    else {
      $talk_show_data['explicit'] = 'clean';
    }

    $talk_show_data['episodic'] = 'episodic';
    $talk_show_data['podcast_guid'] = $node->uuid();

    // Get the Archives
    $archives_data_arr = $this->getRssArchives($node->id());
    $archives_data = !empty($archives_data_arr['data']) ? $archives_data_arr['data'] : [];
    $last_modified = isset($archives_data_arr['last_modified']) ? $archives_data_arr['last_modified'] : 0;
    if ($node->getChangedTime() > $last_modified) {
      $last_modified = $node->getChangedTime();
    }

    return [
      'talkshow' => !$skip_node ? $talk_show_data : [],
      'archives' => $archives_data,
      'last_modified' => $last_modified
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRecentFeedInfo(Request $request) {
    $site_url = \Drupal::request()->getSchemeAndHttpHost();
    $theme = \Drupal::theme()->getActiveTheme();

    $feed_data = [];
    $feed_data['link'] = $site_url . '/';
    $feed_data['atom_link'] = $site_url . '/customshow/rss';

    $feed_data['title'] = 'Talkshow Feed';
    $feed_data['desc'] = 'Talkshow RSS';
    $feed_data['copyright'] = '©2005-' . date('Y') . ' BBS Network, Inc.';
    $feed_data['webmaster'] = 'doug@bbsradio.com (Douglas Newsom)';
    $feed_data['editor'] = 'doug@bbsradio.com (Douglas Newsom)';
    $feed_data['language'] = 'en-us';
    $feed_data['pub_date'] = date('D, d M Y H:i:s T');
    $feed_data['publish_date'] = date('c');
    $feed_data['up_date'] = date('D, d M Y H:i:s T');
    $feed_data['update_date'] = date('c');
    $feed_data['feed_image'] = $site_url . '/'. $theme->getPath() . '/images/a-fireside-chat-pod-pic.jpg';

    return $feed_data;
  }

  /**
   * {@inheritdoc}
   */
  public function getRecentTalkshowData(Request $request) {
    $site_url = \Drupal::request()->getSchemeAndHttpHost();
    $theme = \Drupal::theme()->getActiveTheme();

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $nids = $query->condition('type', 'talk_show_include')
      ->condition('status', 1, '=')
      ->sort('changed', 'DESC')
      ->range(0, 20)
      ->accessCheck(FALSE)
      ->execute();

    $data = [];
    if ($nids && count($nids)>0) {
      foreach ($nids as $nid) {
        $node = $this->entityTypeManager->getStorage('node')->load($nid);

        $talk_show_data = [];
        $talk_show_data['nid'] = $node->id();
        $talk_show_data['link'] = $site_url . '/';
        $talk_show_data['atom_link'] = $site_url . '/customshow/' . $node->id();

        // Talk show title
        if ($node->hasField('field_feed_talkshow_title') && !$node->get('field_feed_talkshow_title')->isEmpty()) {
          $talk_show_data['title'] = Html::escape($node->get('field_feed_talkshow_title')->value);
        }
        else {
          $talk_show_data['title'] = $node->label();
        }

        // Description
        if ($node->hasField('field_feed_description') && !$node->get('field_feed_description')->isEmpty()) {
          $talk_show_data['desc'] = $this->baseHelper->xmlEntityReplace($node->get('field_feed_description')->value);
          $talk_show_data['itunes_summary'] = $this->baseHelper->xmlEntityReplace($node->get('field_feed_description')->value, 4);
        }
        else {
          $talk_show_data['desc'] = 'Talkshow RSS';
          $talk_show_data['itunes_summary'] = 'Talkshow RSS';
        }

        // Talk show Banner
        if ($node->hasField('field_include_banner') && !$node->get('field_include_banner')->isEmpty() && $node->get('field_include_banner')->entity) {
          $talk_show_data['image']['url'] = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_include_banner')->entity->getFileUri());
          $talk_show_data['image']['title'] = $node->label();
          $talk_show_data['image']['link'] = $site_url . '/node/' . $node->id();
        }

        // Copyright
        if ($node->hasField('field_include_copyright') && !$node->get('field_include_copyright')->isEmpty()) {
          $talk_show_data['copyright'] = $node->get('field_include_copyright')->value;
        }
        else {
          $talk_show_data['copyright'] = '©2005-' . date('Y') . ' BBS Network, Inc.';
        }

        // Webmaster and Editor and Language
        $talk_show_data['webmaster'] = 'doug@bbsradio.com (Douglas Newsom)';
        $talk_show_data['editor'] = 'doug@bbsradio.com (Douglas Newsom)';
        $talk_show_data['language'] = 'en-us';

        $talk_show_data['pub_date'] = date('D, d M Y H:i:s T', $node->getCreatedTime());
        $talk_show_data['publish_date'] = date('c', $node->getCreatedTime());
        $talk_show_data['up_date'] = date('D, d M Y H:i:s T', $node->getChangedTime());
        $talk_show_data['update_date'] = date('c', $node->getChangedTime());

        // Category and Parent Categories. Only two level of categories are counting
        $categories = [];
        $category_list = '';
        if ($node->hasField('field_include_itunes_categories') && !$node->get('field_include_itunes_categories')->isEmpty() && $node->get('field_include_itunes_categories')->entity) {
          $terms = $node->get('field_include_itunes_categories')->referencedEntities();
          if ($terms && count($terms) > 0) {
            foreach ($terms as $term) {
              $parent_terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadParents($term->id());
              if ($parent_terms) {
                foreach ($parent_terms as $parent_term) {
                  // Skipping obsolete category
                  if (in_array($parent_term->getName(), ['News'])) {
                    continue;
                  }

                  $categories[$parent_term->id()]['tid'] = $parent_term->id();
                  $categories[$parent_term->id()]['name'] = $parent_term->getName();
                  $categories[$parent_term->id()]['children'][] = [
                    'tid' => $term->id(),
                    'name' => $term->getName(),
                  ];
                }
              }
              else {
                // Skipping obsolete category
                if (in_array($term->getName(), ['News'])) {
                  continue;
                }

                $categories[$term->id()]['tid'] = $term->id();
                $categories[$term->id()]['name'] = $term->getName();
                $categories[$term->id()]['children'] = [];
              }
            }
          }

          if ($categories) {
            foreach ($categories as $category) {
              if (!empty($category_list)) {
                $category_list .= ', ';
              }
              $category_list .= $category['name'];

              if (isset($category['children']) && count($category['children'])) {
                foreach ($category['children'] as $child) {
                  if (!empty($category_list)) {
                    $category_list .= ', ';
                  }
                  $category_list .= $child['name'];
                }
              }
            }
          }

          $talk_show_data['categories'] = $categories;
          $talk_show_data['category_list'] = $this->baseHelper->xmlEntityReplace($category_list);
        }

        // Feed Image
        if ($node->hasField('field_include_feed_picture') && !$node->get('field_include_feed_picture')->isEmpty() && $node->get('field_include_feed_picture')->entity) {
          $talk_show_data['feed_image'] = \Drupal::service('file_url_generator')->generateAbsoluteString($node->get('field_include_feed_picture')->entity->getFileUri());
        }
        else {
          $talk_show_data['feed_image'] = $site_url . '/' . $theme->getPath() . '/images/a-fireside-chat-pod-pic.jpg';
        }

        // Sub-Title
        if ($node->hasField('field_include_feed_subtitle') && !$node->get('field_include_feed_subtitle')->isEmpty()) {
          $subtitle = strip_tags($node->get('field_include_feed_subtitle')->value);
          $subtitle = str_ireplace('&', 'and', $subtitle);
          $subtitle = Html::escape($subtitle);
          $talk_show_data['itunes_subtitle'] = $subtitle;
        }
        else {
          $talk_show_data['itunes_subtitle'] = $node->label();
        }

        // Tags, Keywords
        $tags = [];
        $tag_list = '';
        if ($node->hasField('field_include_feed_tags_keywords') && !$node->get('field_include_feed_tags_keywords')->isEmpty()) {
          $tag_terms = $node->get('field_include_feed_tags_keywords')->referencedEntities();

          foreach ($tag_terms as $tag_term) {
            // Limit tag length to max 255
            $tag_length = strlen($tag_list) + strlen($tag_term->getName());
            if ($tag_length > 253) {
              break;
            }

            if (!empty($tag_list)) {
              $tag_list .= ', ';
            }
            $tag_name = $tag_term->getName();
            $tag_name = strtolower($tag_name);
            $tag_name = str_ireplace('&', '', $tag_name);
            $tag_name = str_ireplace(' ', '-', $tag_name);
            $tag_list .= $tag_name;
            $tags[] = $tag_name;
          }

          $talk_show_data['tag_list'] = $tag_list;
          $talk_show_data['tags'] = $tags;
        }
        else {
          $talk_show_data['tags'][] = 'Keywords';
          $talk_show_data['tag_list'] = 'Keywords';
        }

        // Explicit Materials
        if ($node->hasField('field_include_explicit_materials') && !$node->get('field_include_explicit_materials')->isEmpty()) {
            $talk_show_data['explicit'] = strtolower($node->get('field_include_explicit_materials')->value);
        }
        else {
          $talk_show_data['explicit'] = 'clean';
        }

        $talk_show_data['episodic'] = 'episodic';
        $data[] = $talk_show_data;
      }
    }

    return [ 'feed_info' => $this->getRecentFeedInfo($request), 'talkshow' => $data];
  }

  /**
   * Gets the image URL from an archive_descriptions node for RSS feed use.
   *
   * Priority order:
   * 1. field_archive_add_picture (on archive_descriptions)
   * 2. field_archive_opt_pic_for_body (on archive_descriptions)
   * 3. field_archive_use_show_banner → field_include_banner (on talk_show_include)
   * 4. Placeholder: gavias_remito theme's images/blank-talkshow.jpg
   *
   * @param \Drupal\node\NodeInterface $node
   *   The archive_descriptions node.
   *
   * @return string
   *   The absolute image URL, or the placeholder image URL as final fallback.
   */
  public function getArchiveImageUrl(\Drupal\node\NodeInterface $node): string {
    // --- Helper closure: build placeholder URL from theme ---
    $get_placeholder_url = function (): string {
      $theme_path = \Drupal::service('extension.list.theme')->getPath('gavias_remito');
      return \Drupal::request()->getSchemeAndHttpHost()
        . '/'
        . $theme_path
        . '/images/blank-talkshow.jpg';
    };

    // Safety check: ensure we have the right bundle.
    if ($node->bundle() !== 'archive_descriptions') {
      return $get_placeholder_url();
    }

    // --- Helper closure: extract absolute URL from an image field ---
    $get_image_url = function (\Drupal\node\NodeInterface $n, string $field_name): ?string {
      if (
        $n->hasField($field_name) &&
        !$n->get($field_name)->isEmpty()
      ) {
        /** @var \Drupal\file\FileInterface|null $file */
        $file = $n->get($field_name)->entity;
        if ($file instanceof \Drupal\file\FileInterface) {
          $uri = $file->getFileUri();
          return \Drupal::service('file_url_generator')->generateAbsoluteString($uri);
        }
      }
      return NULL;
    };

    // Priority 1: field_archive_add_picture (on archive_descriptions).
    $url = $get_image_url($node, 'field_archive_add_picture');
    if ($url !== NULL) {
      return $url;
    }

    // Priority 2: field_archive_opt_pic_for_body (on archive_descriptions).
    $url = $get_image_url($node, 'field_archive_opt_pic_for_body');
    if ($url !== NULL) {
      return $url;
    }

    // Priority 3 (last resort): field_archive_use_show_banner →
    // field_include_banner (on talk_show_include).
    if (
      $node->hasField('field_archive_use_show_banner') &&
      !$node->get('field_archive_use_show_banner')->isEmpty()
    ) {
      $referenced = $node->get('field_archive_use_show_banner')->entity;
      if (
        $referenced instanceof \Drupal\node\NodeInterface &&
        $referenced->bundle() === 'talk_show_include' &&
        $referenced->isPublished()
      ) {
        $url = $get_image_url($referenced, 'field_include_banner');
        if ($url !== NULL) {
          return $url;
        }
      }
    }

    // Final fallback: placeholder image from gavias_remito theme.
    return $get_placeholder_url();
  }

}
