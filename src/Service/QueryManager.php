<?php

namespace Drupal\custom_example\Service;

use Drupal\bbsradio_base\Service\BaseHelper;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\path_alias\AliasManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * Query Manager Class.
 *
 * @package Drupal\custom_example
 */
class QueryManager {
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
   * File Url Generator service
   *
   * @var FileUrlGeneratorInterface $fileUrlGenerator
   */
  protected $fileUrlGenerator;

  /**
   * Alias manager service
   *
   * @var AliasManager $aliasManager
   */
  protected $aliasManager;

  /**
   * Base helper service.
   *
   * @var \Drupal\bbsradio_base\Service\BaseHelper
   */
  protected $baseHelper;

  /**
   * Constructs a new class.
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
    FileUrlGeneratorInterface $fileUrlGenerator,
    AliasManager $aliasManager,
    BaseHelper $base_helper
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('bbs_query');
    $this->configFactory = $config_factory;
    $this->fileUrlGenerator = $fileUrlGenerator;
    $this->aliasManager = $aliasManager;
    $this->baseHelper = $base_helper;
  }

  /**
   * Get the live talk show list.
   *   We will receive the station term ids. Based on that, we need to get the station names.
   *   Station names need to match with Archives to return. If archive not found, if fall back
   *   node is set, return that.
   *
   * @param array $station_ids
   * @return bool|array
   */
  public function fetchStationLiveTalkshowData(array $station_ids) {
    $data = [];

    // If no station id provided, return empty
    if (empty($station_ids)) {
      return $data;
    }

    $normal_weeks = [
      'Daily Show',
      'Weekly Show',
      'Everyday',
      'Weekly or Biweekly Slot',
      'Biweekly Slot Only'
    ];

    foreach($station_ids as $station_id) {
      /** @var \Drupal\taxonomy\TermInterface $term */
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($station_id);
      if ($term) {
        $station_name = $term->getName();
        $station_description = $term->getDescription();
        $fallback_talkshow_nid = $term->hasField('field_fallback_talkshow') ? $term->get('field_fallback_talkshow')->target_id : 0;

        $data[$station_id]['station_id'] = $station_id;
        $data[$station_id]['station_name'] = $station_name;
        $data[$station_id]['station_description'] = $station_description;
        $data[$station_id]['talkshow'] = 0; // initialize

        $query = $this->database->select('node_field_data', 'n');
        $query->innerJoin('node__field_show_broadcast_schedule', 'nfsbs', 'n.nid = nfsbs.entity_id');
        $query->innerJoin('paragraphs_item_field_data', 'pifd', "nfsbs.field_show_broadcast_schedule_target_id = pifd.id AND pifd.parent_type = 'node' AND pifd.parent_field_name='field_show_broadcast_schedule' ");
        $query->innerJoin('paragraph__field_schedule_station', 'pfss', "pifd.id = pfss.entity_id AND pfss.bundle= 'show_broadcast_schedule' AND pfss.deleted = 0");
        $query->innerJoin('paragraph__field_featured_talk_show', 'pffts', "pifd.id = pffts.entity_id AND pffts.bundle= 'show_broadcast_schedule' AND pffts.deleted = 0");
        $query->innerJoin('paragraph__field_schedule_broadcast_day', 'pfsbd', "pifd.id = pfsbd.entity_id AND pfsbd.bundle= 'show_broadcast_schedule' AND pfsbd.deleted = 0");
        $query->leftJoin('paragraph__field_schedule_starts', 'pfschs', "pifd.id = pfschs.entity_id AND pfschs.bundle= 'show_broadcast_schedule' AND pfschs.deleted = 0");
        $query->leftJoin('paragraph__field_schedule_ends', 'pfsche', "pifd.id = pfsche.entity_id AND pfsche.bundle= 'show_broadcast_schedule' AND pfsche.deleted = 0");
        $query->leftJoin('paragraph__field_schedule_weekly_or_biweekl', 'pfswb', "pifd.id = pfswb.entity_id AND pfswb.bundle= 'show_broadcast_schedule' AND pfswb.deleted = 0");

        $xcond = $query->orConditionGroup()
          ->condition('pffts.field_featured_talk_show_value', 'yes', '=')
          ->condition('pfsbd.field_schedule_broadcast_day_value', [date('l'), 'Everyday'], 'IN');

        $query->condition('n.type', 'talk_show_include', '=')
          ->condition('n.status', 1, '=')
          ->condition('pifd.type', 'show_broadcast_schedule', '=')
          ->condition('pfss.field_schedule_station_value', $station_name, '=')
          ->condition($xcond)
          ->fields('n', ['nid', 'title']);
        $query->addField('pfsbd', 'field_schedule_broadcast_day_value', 'schedule_broadcast_day');
        $query->addField('pfschs', 'field_schedule_starts_value', 'schedule_starts');
        $query->addField('pfsche', 'field_schedule_ends_value', 'schedule_ends');
        $query->addField('pffts', 'field_featured_talk_show_value', 'featured_talk_show');
        $query->addField('pfswb', 'field_schedule_weekly_or_biweekl_value', 'schedule_weekly_or_biweekl');

        $results = $query->execute()->fetchAll();
        if ($results) {
          foreach ($results as $result) {
            $schedule_weekly_or_biweekly = $result->schedule_weekly_or_biweekl;
            $week_of_month = $this->baseHelper->weekOfMonth(time());
            if ($week_of_month % 2 == 0) {
              $week_of_month_type =  'Even';
            }
            else {
              $week_of_month_type =  'Odd';
            }

            $schedule_broadcast_day = $result->schedule_broadcast_day;
            $featured_talk_show = $result->featured_talk_show;
            $schedule_starts = $result->schedule_starts;
            $schedule_ends = $result->schedule_ends;

            $schedule_start_time_arr = $this->baseHelper->getCTPTTime($schedule_starts);
            $schedule_start_time = $schedule_start_time_arr['broadcast_time_c'];
            $schedule_start_time_type = $schedule_start_time_arr['type'];
            $current_time = $schedule_start_time_arr['current_time_c'];

            $schedule_end_time_arr = $this->baseHelper->getCTPTTime($schedule_ends);
            $schedule_end_time = $schedule_end_time_arr['broadcast_time_c'];
            $schedule_end_time_type = $schedule_end_time_arr['type'];

            if ($current_time >= $schedule_start_time && $current_time <= $schedule_end_time) {
              if ($featured_talk_show == 'yes') {
                $data[$station_id]['talkshow'] = $result->nid;
                $data[$station_id]['is_fallback'] = 0;
                $data[$station_id]['broadcast_day'] = $schedule_broadcast_day;
                $data[$station_id]['weekly_or_biweekl'] = $schedule_weekly_or_biweekly;
                break;
              }
              elseif ($schedule_weekly_or_biweekly == 'Bi-Weekly Show (Odd Week)' && $week_of_month_type == 'Odd') {
                $data[$station_id]['talkshow'] = $result->nid;
                $data[$station_id]['is_fallback'] = 0;
                $data[$station_id]['broadcast_day'] = $schedule_broadcast_day;
                $data[$station_id]['weekly_or_biweekl'] = $schedule_weekly_or_biweekly;
                break;
              }
              elseif ($schedule_weekly_or_biweekly == 'Bi-Weekly Show (Even Week)' && $week_of_month_type == 'Even') {
                $data[$station_id]['talkshow'] = $result->nid;
                $data[$station_id]['is_fallback'] = 0;
                $data[$station_id]['broadcast_day'] = $schedule_broadcast_day;
                $data[$station_id]['weekly_or_biweekl'] = $schedule_weekly_or_biweekly;
                break;
              }
              elseif (($featured_talk_show == 'no' || empty($featured_talk_show)) && in_array($schedule_weekly_or_biweekly, $normal_weeks) ) {
                $data[$station_id]['talkshow'] = $result->nid;
                $data[$station_id]['is_fallback'] = 0;
                $data[$station_id]['broadcast_day'] = $schedule_broadcast_day;
                $data[$station_id]['weekly_or_biweekl'] = $schedule_weekly_or_biweekly;
                break;
              }
            }
          }

          // If No time matched, use fallback
          if (empty($data[$station_id]['talkshow'])) {
            $data[$station_id]['talkshow'] = $fallback_talkshow_nid;
            $data[$station_id]['is_fallback'] = 1;
            $data[$station_id]['broadcast_day'] = '';
            $data[$station_id]['weekly_or_biweekl'] = '';
          }
        }
        else {
          $data[$station_id]['talkshow'] = $fallback_talkshow_nid;
          $data[$station_id]['is_fallback'] = 1;
          $data[$station_id]['broadcast_day'] = '';
          $data[$station_id]['weekly_or_biweekl'] = '';
        }
      }
    }

    return $data;
  }

  public function getStationLiveTalkshow(array $station_ids) {
    $live_talk_show_list = $this->fetchStationLiveTalkshowData($station_ids);

    // if there is no live talk show, return empty
    if (empty($live_talk_show_list)) {
      return [];
    }

    // initialize the response
    $response = [];

    foreach ($live_talk_show_list as $key => $row) {
      if (!empty($row['talkshow'])) {
        /** @var \Drupal\node\NodeInterface $node */
        $node = $this->entityTypeManager->getStorage('node')->load($row['talkshow']);
        if ($node) {
          $banner_image = '';
          if ($node->hasField('field_include_banner') && !$node->get('field_include_banner')->isEmpty()) {
            $banner_title = $node->get('field_include_banner')->title;
            $banner_alt = $node->get('field_include_banner')->alt;

            /** @var \Drupal\file\FileInterface $banner_file */
            $banner_file = $node->get('field_include_banner')->entity;
            if ($banner_file) {
              $banner_url = $this->fileUrlGenerator->generateAbsoluteString($banner_file->getFileUri());
              $banner_image = '<img alt="' . $banner_alt . '" title="' . $banner_title . '" src="' . $banner_url . '" loading="lazy" />';
            }
          }

          $show_page_link = '';
          if ($node->hasField('field_include_show_page') && !$node->get('field_include_show_page')->isEmpty()) {
            $link_url = $node->get('field_include_show_page')->first()->getUrl()->toString();
            $link_title = $node->get('field_include_show_page')->first()->title;
            $show_page_link = '<a href="' . $link_url . '">' . $link_title . '</a>';
          }

          $host_name_link = '';
          if ($node->hasField('field_include_host_name') && !$node->get('field_include_host_name')->isEmpty()) {
            $host_entities = $node->get('field_include_host_name')->referencedEntities();
            /** @var \Drupal\user\UserInterface $host_entity */
            foreach ($host_entities as $host_entity) {
              $current_host_path = '/user/' . $host_entity->id();
              $alias_url = $this->aliasManager->getAliasByPath($current_host_path);
              if (!empty($host_name_link)) {
                $host_name_link .= ', ';
              }
              $host_name_link .= '<a href="' . $alias_url . '" hreflang="en">' . $host_entity->getAccountName() . '</a>';
            }
          }

          $host_picture = '';
          if ($node->hasField('field_include_host_picture') && !$node->get('field_include_host_picture')->isEmpty()) {
            $host_picture_title = $node->get('field_include_host_picture')->title;
            $host_picture_alt = $node->get('field_include_host_picture')->alt;

            $host_picture_url = $this->fileUrlGenerator->generateAbsoluteString($node->get('field_include_host_picture')->entity->getFileUri());
            $host_picture = '<img alt="' . $host_picture_alt . '" title="' . $host_picture_title . '" src="' . $host_picture_url . '" loading="lazy" />';
          }

          $schedule_broadcast_day = '';
          $schedule_weekly_or_biweekly = '';
          if ($row['is_fallback'] == 1) {
            $paragraphs = $node->get('field_show_broadcast_schedule')->referencedEntities();
            foreach ($paragraphs as $paragraph) {
              // Get info only from first record
              $schedule_broadcast_day = $paragraph->get('field_schedule_broadcast_day')->value;
              $schedule_weekly_or_biweekly = $paragraph->get('field_schedule_weekly_or_biweekl')->value;
              break;
            }
          }
          else {
            $schedule_broadcast_day = $row['broadcast_day'];
            $schedule_weekly_or_biweekly = $row['weekly_or_biweekl'];
          }

          $program_archives_link = '';
          if ($node->hasField('field_include_program_archives') && !$node->get('field_include_program_archives')->isEmpty()) {
            $link_url = $node->get('field_include_program_archives')->first()->getUrl()->toString();
            $link_title = $this->t('Archives');
            $program_archives_link = '<a href="' . $link_url . '">' . $link_title . '</a>';
          }

          $coming_up_soon_link = '';
          if ($node->hasField('field_include_coming_up_soon') && !$node->get('field_include_coming_up_soon')->isEmpty()) {
            $link_url = $node->get('field_include_coming_up_soon')->first()->getUrl()->toString();
            $link_title = $this->t('Headlined info');
            $coming_up_soon_link = '<a href="' . $link_url . '">' . $link_title . '</a>';
          }

          $upcoming_show_link = '';
          if ($node->hasField('field_include_upcoming_show') && !$node->get('field_include_upcoming_show')->isEmpty()) {
            $link_url = $node->get('field_include_upcoming_show')->first()->getUrl()->toString();
            $link_title = $this->t('Featured guests');
            $upcoming_show_link = '<a href="' . $link_url . '">' . $link_title . '</a>';
          }

          $rss_feed_link = '';
          if ($node->hasField('field_include_show_feed') && !$node->get('field_include_show_feed')->isEmpty()) {
            $link_url = $node->get('field_include_show_feed')->first()->getUrl()->toString();
            $link_title = $this->t('RSS feed');
            $rss_feed_link = '<a href="' . $link_url . '">' . $link_title . '</a>';
          }

          $mrss_feed_link = '';
          if ($node->hasField('field_include_mrss_feed') && !$node->get('field_include_mrss_feed')->isEmpty()) {
            $link_url = $node->get('field_include_mrss_feed')->first()->getUrl()->toString();
            $link_title = $this->t('MRSS feed');
            $mrss_feed_link = '<a href="' . $link_url . '">' . $link_title . '</a>';
          }

          $categories = '';
          $terms = $node->get('field_include_show_categories')->referencedEntities();
          /** @var \Drupal\taxonomy\TermInterface $term */
          foreach ($terms as $term) {
            if (!empty($categories)) {
              $categories .= ', ';
            }
            $categories .= $term->getName();
          }

          $field_include_audio_or_video = $node->hasField('field_include_audio_or_video') && !$node->get('field_include_audio_or_video')->isEmpty() ? $node->get('field_include_audio_or_video')->value : '';
          if ($field_include_audio_or_video == 'Video') {
            $audio_or_video = '<svg role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><title>Video</title><path fill="#e7ad47" d="M0 128C0 92.7 28.7 64 64 64H320c35.3 0 64 28.7 64 64V384c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V128zM559.1 99.8c10.4 5.6 16.9 16.4 16.9 28.2V384c0 11.8-6.5 22.6-16.9 28.2s-23 5-32.9-1.6l-96-64L416 337.1V320 192 174.9l14.2-9.5 96-64c9.8-6.5 22.4-7.2 32.9-1.6z"/></svg>';
          }
          else {
            $audio_or_video = '<svg role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><title>Audio</title><path fill="#e7ad47" d="M533.6 32.5C598.5 85.2 640 165.8 640 256s-41.5 170.7-106.4 223.5c-10.3 8.4-25.4 6.8-33.8-3.5s-6.8-25.4 3.5-33.8C557.5 398.2 592 331.2 592 256s-34.5-142.2-88.7-186.3c-10.3-8.4-11.8-23.5-3.5-33.8s23.5-11.8 33.8-3.5zM473.1 107c43.2 35.2 70.9 88.9 70.9 149s-27.7 113.8-70.9 149c-10.3 8.4-25.4 6.8-33.8-3.5s-6.8-25.4 3.5-33.8C475.3 341.3 496 301.1 496 256s-20.7-85.3-53.2-111.8c-10.3-8.4-11.8-23.5-3.5-33.8s23.5-11.8 33.8-3.5zm-60.5 74.5C434.1 199.1 448 225.9 448 256s-13.9 56.9-35.4 74.5c-10.3 8.4-25.4 6.8-33.8-3.5s-6.8-25.4 3.5-33.8C393.1 284.4 400 271 400 256s-6.9-28.4-17.7-37.3c-10.3-8.4-11.8-23.5-3.5-33.8s23.5-11.8 33.8-3.5zM301.1 34.8C312.6 40 320 51.4 320 64V448c0 12.6-7.4 24-18.9 29.2s-25 3.1-34.4-5.3L131.8 352H64c-35.3 0-64-28.7-64-64V224c0-35.3 28.7-64 64-64h67.8L266.7 40.1c9.4-8.4 22.9-10.4 34.4-5.3z"/></svg>';
          }

          $response[$row['station_id']] = [
            'nid' => $node->id(),
            'station_id' => $row['station_id'],
            'station_name' => $row['station_name'],
            'station_description' => !empty($row['station_description']) ? $row['station_description'] : '',
            'field_include_audio_or_video' => $audio_or_video,
            'field_include_banner' => !empty($banner_image) ? $banner_image : '',
            'field_include_show_page' => !empty($show_page_link) ? $show_page_link : '',
            'field_include_host_name' => !empty($host_name_link) ? $host_name_link : '',
            'field_include_host_picture' => !empty($host_picture) ? $host_picture : '',
            'field_talk_show_detail' => $node->hasField('field_talk_show_detail') && !$node->get('field_talk_show_detail')->isEmpty() ? $node->get('field_talk_show_detail')->value : '',
            'field_schedule_weekly_or_biweekl' => !empty($schedule_weekly_or_biweekly) ? $schedule_weekly_or_biweekly : '',
            'field_schedule_broadcast_day' => !empty($schedule_broadcast_day) ? $schedule_broadcast_day : '',
            'field_include_program_archives' => !empty($program_archives_link) ? $program_archives_link : '',
            'field_include_coming_up_soon' => !empty($coming_up_soon_link) ? $coming_up_soon_link : '',
            'field_include_upcoming_show' => !empty($upcoming_show_link) ? $upcoming_show_link : '',
            'field_include_show_feed' => !empty($rss_feed_link) ? $rss_feed_link : '',
            'field_include_mrss_feed' => !empty($mrss_feed_link) ? $mrss_feed_link : '',
            'field_include_show_categories' => !empty($categories) ? $categories : '',
          ];
        }
      }
    }

    return $response;
  }

  /**
   * Get matched talk show by conditions
   *
   * @param int $station_id
   * @param string $week_day
   * @param string $schedule_time
   * @param string $schedule_time_ampm
   * @param string $schedule_time_timezone
   * @return array
   */
  public function filterStationLiveTalkShow(int $station_id, string $week_day, int $show_all_talkshows, string $schedule_time, string $schedule_time_ampm, string $schedule_time_timezone) {
    $data = [];
    $station_data = [];

    // If no station id provided, return empty
    if (empty($station_id) || empty($week_day)) {
      return [
        'station' => $station_data,
        'talkshow' => $data,
      ];
    }

    $talk_show_schedule_time = $schedule_time . ' '. $schedule_time_ampm . ' '. $schedule_time_timezone;
    $current_time_arr = $this->baseHelper->getCTPTTime($talk_show_schedule_time);
    $current_time = $current_time_arr['broadcast_time_c'];
    $current_time_type = $current_time_arr['type'];

    /** @var \Drupal\taxonomy\TermInterface $term */
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($station_id);
    if ($term) {
      $station_name = $term->getName();
      $station_description = $term->getDescription();

      $station_data['station_id'] = $station_id;
      $station_data['station_name'] = $station_name;
      $station_data['station_description'] = $station_description;

      $query = $this->database->select('node_field_data', 'n');
      $query->innerJoin('node__field_show_broadcast_schedule', 'nfsbs', 'n.nid = nfsbs.entity_id');
      $query->innerJoin('paragraphs_item_field_data', 'pifd', "nfsbs.field_show_broadcast_schedule_target_id = pifd.id AND pifd.parent_type = 'node' AND pifd.parent_field_name='field_show_broadcast_schedule' ");
      $query->innerJoin('paragraph__field_schedule_station', 'pfss', "pifd.id = pfss.entity_id AND pfss.bundle= 'show_broadcast_schedule' AND pfss.deleted = 0");
      $query->innerJoin('paragraph__field_featured_talk_show', 'pffts', "pifd.id = pffts.entity_id AND pffts.bundle= 'show_broadcast_schedule' AND pffts.deleted = 0");
      $query->innerJoin('paragraph__field_schedule_broadcast_day', 'pfsbd', "pifd.id = pfsbd.entity_id AND pfsbd.bundle= 'show_broadcast_schedule' AND pfsbd.deleted = 0");
      $query->leftJoin('paragraph__field_schedule_starts', 'pfschs', "pifd.id = pfschs.entity_id AND pfschs.bundle= 'show_broadcast_schedule' AND pfschs.deleted = 0");
      $query->leftJoin('paragraph__field_schedule_ends', 'pfsche', "pifd.id = pfsche.entity_id AND pfsche.bundle= 'show_broadcast_schedule' AND pfsche.deleted = 0");
      $query->leftJoin('paragraph__field_schedule_weekly_or_biweekl', 'pfswb', "pifd.id = pfswb.entity_id AND pfswb.bundle= 'show_broadcast_schedule' AND pfswb.deleted = 0");

      $xcond = $query->orConditionGroup()
        ->condition('pffts.field_featured_talk_show_value', 'yes', '=')
        ->condition('pfsbd.field_schedule_broadcast_day_value', [$week_day, 'Everyday'], 'IN');

      $query->condition('n.type', 'talk_show_include', '=')
        ->condition('n.status', 1, '=')
        ->condition('pifd.type', 'show_broadcast_schedule', '=')
        ->condition('pfss.field_schedule_station_value', $station_name, '=')
        ->condition($xcond)
        ->fields('n', ['nid', 'title']);
      $query->addField('pfsbd', 'field_schedule_broadcast_day_value', 'schedule_broadcast_day');
      $query->addField('pfschs', 'field_schedule_starts_value', 'schedule_starts');
      $query->addField('pfsche', 'field_schedule_ends_value', 'schedule_ends');
      $query->addField('pffts', 'field_featured_talk_show_value', 'featured_talk_show');
      $query->addField('pfswb', 'field_schedule_weekly_or_biweekl_value', 'schedule_weekly_or_biweekl');

      $results = $query->execute()->fetchAll();
      $count = 0;
      if ($results) {
        foreach ($results as $result) {
          $schedule_weekly_or_biweekly = $result->schedule_weekly_or_biweekl;
          $week_of_month = $this->baseHelper->weekOfMonth(strtotime(time()));
          if ($week_of_month % 2 == 0) {
            $week_of_month_type =  'Even';
          }
          else {
            $week_of_month_type =  'Odd';
          }

          $schedule_broadcast_day = $result->schedule_broadcast_day;
          $featured_talk_show = $result->featured_talk_show;
          $schedule_starts = $result->schedule_starts;
          $schedule_ends = $result->schedule_ends;

          $schedule_start_time_arr = $this->baseHelper->getCTPTTime($schedule_starts);
          $schedule_start_time = $schedule_start_time_arr['broadcast_time_c'];
          $schedule_start_time_type = $schedule_start_time_arr['type'];

          $schedule_end_time_arr = $this->baseHelper->getCTPTTime($schedule_ends);
          $schedule_end_time = $schedule_end_time_arr['broadcast_time_c'];
          $schedule_end_time_type = $schedule_end_time_arr['type'];

          if (
            $show_all_talkshows == 0 &&
            $current_time >= $schedule_start_time &&
            $current_time <= $schedule_end_time &&
            $current_time_type == $schedule_start_time_type &&
            $current_time_type == $schedule_end_time_type
          ) {
            if ($featured_talk_show == 'yes') {
              $data[$count]['talkshow_nid'] = $result->nid;
              $data[$count]['talkshow_title'] = $result->title;
              $data[$count]['featured'] = 'Yes';
              $data[$count]['schedule_starts'] = $schedule_starts;
              $data[$count]['schedule_ends'] = $schedule_ends;
              $data[$count]['broadcast_day'] = $schedule_broadcast_day;
              $data[$count]['weekly_or_biweekly'] = $schedule_weekly_or_biweekly;
            }
            elseif ($schedule_weekly_or_biweekly == 'Bi-Weekly Show (Odd Week)' && $week_of_month_type == 'Odd') {
              $data[$count]['talkshow_nid'] = $result->nid;
              $data[$count]['talkshow_title'] = $result->title;
              $data[$count]['featured'] = 'No';
              $data[$count]['schedule_starts'] = $schedule_starts;
              $data[$count]['schedule_ends'] = $schedule_ends;
              $data[$count]['broadcast_day'] = $schedule_broadcast_day;
              $data[$count]['weekly_or_biweekly'] = $schedule_weekly_or_biweekly;
            }
            elseif ($schedule_weekly_or_biweekly == 'Bi-Weekly Show (Even Week)' && $week_of_month_type == 'Even') {
              $data[$count]['talkshow_nid'] = $result->nid;
              $data[$count]['talkshow_title'] = $result->title;
              $data[$count]['featured'] = 'No';
              $data[$count]['schedule_starts'] = $schedule_starts;
              $data[$count]['schedule_ends'] = $schedule_ends;
              $data[$count]['broadcast_day'] = $schedule_broadcast_day;
              $data[$count]['weekly_or_biweekly'] = $schedule_weekly_or_biweekly;
            }
            else {
              $data[$count]['talkshow_nid'] = $result->nid;
              $data[$count]['talkshow_title'] = $result->title;
              $data[$count]['featured'] = 'No';
              $data[$count]['schedule_starts'] = $schedule_starts;
              $data[$count]['schedule_ends'] = $schedule_ends;
              $data[$count]['broadcast_day'] = $schedule_broadcast_day;
              $data[$count]['weekly_or_biweekly'] = $schedule_weekly_or_biweekly;
            }
            ++$count;
          }
          elseif ($show_all_talkshows == 1) {
            $data[$count]['talkshow_nid'] = $result->nid;
            $data[$count]['talkshow_title'] = $result->title;
            $data[$count]['featured'] = 'No';
            $data[$count]['schedule_starts'] = $schedule_starts;
            $data[$count]['schedule_ends'] = $schedule_ends;
            $data[$count]['broadcast_day'] = $schedule_broadcast_day;
            $data[$count]['weekly_or_biweekly'] = $schedule_weekly_or_biweekly;
            ++$count;
          }
        }
      }
    }

    return [
      'station' => $station_data,
      'talkshow' => $data,
    ];
  }

  public function isLiveNow(int $talkshow_nid) {
    $data = [];

    // If no talk show id provided, return empty
    if (empty($talkshow_nid)) {
      return $data;
    }

    $normal_weeks = [
      'Daily Show',
      'Weekly Show',
      'Everyday',
      'Weekly or Biweekly Slot',
      'Biweekly Slot Only'
    ];

    $video_svg = '<svg role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><title>Video</title><path fill="#e7ad47" d="M0 128C0 92.7 28.7 64 64 64H320c35.3 0 64 28.7 64 64V384c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V128zM559.1 99.8c10.4 5.6 16.9 16.4 16.9 28.2V384c0 11.8-6.5 22.6-16.9 28.2s-23 5-32.9-1.6l-96-64L416 337.1V320 192 174.9l14.2-9.5 96-64c9.8-6.5 22.4-7.2 32.9-1.6z"/></svg>';
    $audio_svg = '<svg role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><title>Audio</title><path fill="#e7ad47" d="M533.6 32.5C598.5 85.2 640 165.8 640 256s-41.5 170.7-106.4 223.5c-10.3 8.4-25.4 6.8-33.8-3.5s-6.8-25.4 3.5-33.8C557.5 398.2 592 331.2 592 256s-34.5-142.2-88.7-186.3c-10.3-8.4-11.8-23.5-3.5-33.8s23.5-11.8 33.8-3.5zM473.1 107c43.2 35.2 70.9 88.9 70.9 149s-27.7 113.8-70.9 149c-10.3 8.4-25.4 6.8-33.8-3.5s-6.8-25.4 3.5-33.8C475.3 341.3 496 301.1 496 256s-20.7-85.3-53.2-111.8c-10.3-8.4-11.8-23.5-3.5-33.8s23.5-11.8 33.8-3.5zm-60.5 74.5C434.1 199.1 448 225.9 448 256s-13.9 56.9-35.4 74.5c-10.3 8.4-25.4 6.8-33.8-3.5s-6.8-25.4 3.5-33.8C393.1 284.4 400 271 400 256s-6.9-28.4-17.7-37.3c-10.3-8.4-11.8-23.5-3.5-33.8s23.5-11.8 33.8-3.5zM301.1 34.8C312.6 40 320 51.4 320 64V448c0 12.6-7.4 24-18.9 29.2s-25 3.1-34.4-5.3L131.8 352H64c-35.3 0-64-28.7-64-64V224c0-35.3 28.7-64 64-64h67.8L266.7 40.1c9.4-8.4 22.9-10.4 34.4-5.3z"/></svg>';

    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_show_broadcast_schedule', 'nfsbs', 'n.nid = nfsbs.entity_id');
    $query->innerJoin('paragraphs_item_field_data', 'pifd', "nfsbs.field_show_broadcast_schedule_target_id = pifd.id AND pifd.parent_type = 'node' AND pifd.parent_field_name='field_show_broadcast_schedule' ");
    $query->innerJoin('paragraph__field_schedule_station', 'pfss', "pifd.id = pfss.entity_id AND pfss.bundle= 'show_broadcast_schedule' AND pfss.deleted = 0");
    $query->innerJoin('paragraph__field_featured_talk_show', 'pffts', "pifd.id = pffts.entity_id AND pffts.bundle= 'show_broadcast_schedule' AND pffts.deleted = 0");
    $query->innerJoin('paragraph__field_schedule_broadcast_day', 'pfsbd', "pifd.id = pfsbd.entity_id AND pfsbd.bundle= 'show_broadcast_schedule' AND pfsbd.deleted = 0");
    $query->leftJoin('paragraph__field_schedule_starts', 'pfschs', "pifd.id = pfschs.entity_id AND pfschs.bundle= 'show_broadcast_schedule' AND pfschs.deleted = 0");
    $query->leftJoin('paragraph__field_schedule_ends', 'pfsche', "pifd.id = pfsche.entity_id AND pfsche.bundle= 'show_broadcast_schedule' AND pfsche.deleted = 0");
    $query->leftJoin('paragraph__field_schedule_weekly_or_biweekl', 'pfswb', "pifd.id = pfswb.entity_id AND pfswb.bundle= 'show_broadcast_schedule' AND pfswb.deleted = 0");

    $xcond = $query->orConditionGroup()
      ->condition('pffts.field_featured_talk_show_value', 'yes', '=')
      ->condition('pfsbd.field_schedule_broadcast_day_value', [date('l'), 'Everyday'], 'IN');

    $query->condition('n.type', 'talk_show_include', '=')
      ->condition('n.status', 1, '=')
      ->condition('n.nid', $talkshow_nid, '=')
      ->condition('pifd.type', 'show_broadcast_schedule', '=')
      ->condition($xcond)
      ->fields('n', ['nid', 'title']);
    $query->addField('pfsbd', 'field_schedule_broadcast_day_value', 'schedule_broadcast_day');
    $query->addField('pfschs', 'field_schedule_starts_value', 'schedule_starts');
    $query->addField('pfsche', 'field_schedule_ends_value', 'schedule_ends');
    $query->addField('pffts', 'field_featured_talk_show_value', 'featured_talk_show');
    $query->addField('pfswb', 'field_schedule_weekly_or_biweekl_value', 'schedule_weekly_or_biweekl');
    $query->addField('pfss', 'field_schedule_station_value', 'station_name');

    $results = $query->execute()->fetchAll();

    if ($results) {
      foreach ($results as $result) {
        $schedule_weekly_or_biweekly = $result->schedule_weekly_or_biweekl;
        $week_of_month = $this->baseHelper->weekOfMonth(time());
        if ($week_of_month % 2 == 0) {
          $week_of_month_type = 'Even';
        }
        else {
          $week_of_month_type = 'Odd';
        }

        $schedule_broadcast_day = $result->schedule_broadcast_day;
        $featured_talk_show = $result->featured_talk_show;
        $schedule_starts = $result->schedule_starts;
        $schedule_ends = $result->schedule_ends;

        $station_name = $result->station_name;
        $station_id = $this->baseHelper->getStationByName($station_name);
        $station_info = $this->baseHelper->getStationInfoByName($station_name);
        $station_audio_image = !empty($station_info['station_audio_image']) ? $station_info['station_audio_image'] : '';
        $station_video_image = !empty($station_info['station_video_image']) ? $station_info['station_video_image'] : '';
        $station_audio_player = !empty($station_info['station_audio_player']) ? $station_info['station_audio_player'] : '';
        $station_video_player = !empty($station_info['station_video_player']) ? $station_info['station_video_player'] : '';

        $schedule_start_time_arr = $this->baseHelper->getCTPTTime($schedule_starts);
        $schedule_start_time = $schedule_start_time_arr['broadcast_time_c'];
        $schedule_start_time_type = $schedule_start_time_arr['type'];
        $current_time = $schedule_start_time_arr['current_time_c'];

        $schedule_end_time_arr = $this->baseHelper->getCTPTTime($schedule_ends);
        $schedule_end_time = $schedule_end_time_arr['broadcast_time_c'];
        $schedule_end_time_type = $schedule_end_time_arr['type'];

        if ($current_time >= $schedule_start_time && $current_time <= $schedule_end_time) {
          if ($featured_talk_show == 'yes') {
            $data[$station_id]['station_id'] = $station_id;
            $data[$station_id]['station_name'] = $station_name;
            $data[$station_id]['station_audio_image'] = $station_audio_image;
            $data[$station_id]['station_video_image'] = $station_video_image;
            $data[$station_id]['audio_image'] = $audio_svg;
            $data[$station_id]['video_image'] = $video_svg;
            $data[$station_id]['station_audio_player'] = $station_audio_player;
            $data[$station_id]['station_video_player'] = $station_video_player;
            $data[$station_id]['talkshow'] = $result->nid;
            $data[$station_id]['broadcast_day'] = $schedule_broadcast_day;
            $data[$station_id]['weekly_or_biweekl'] = $schedule_weekly_or_biweekly;
            break;
          }
          elseif ($schedule_weekly_or_biweekly == 'Bi-Weekly Show (Odd Week)' && $week_of_month_type == 'Odd') {
            $data[$station_id]['station_id'] = $station_id;
            $data[$station_id]['station_name'] = $station_name;
            $data[$station_id]['station_audio_image'] = $station_audio_image;
            $data[$station_id]['station_video_image'] = $station_video_image;
            $data[$station_id]['audio_image'] = $audio_svg;
            $data[$station_id]['video_image'] = $video_svg;
            $data[$station_id]['station_audio_player'] = $station_audio_player;
            $data[$station_id]['station_video_player'] = $station_video_player;
            $data[$station_id]['talkshow'] = $result->nid;
            $data[$station_id]['broadcast_day'] = $schedule_broadcast_day;
            $data[$station_id]['weekly_or_biweekl'] = $schedule_weekly_or_biweekly;
            break;
          }
          elseif ($schedule_weekly_or_biweekly == 'Bi-Weekly Show (Even Week)' && $week_of_month_type == 'Even') {
            $data[$station_id]['station_id'] = $station_id;
            $data[$station_id]['station_name'] = $station_name;
            $data[$station_id]['station_audio_image'] = $station_audio_image;
            $data[$station_id]['station_video_image'] = $station_video_image;
            $data[$station_id]['audio_image'] = $audio_svg;
            $data[$station_id]['video_image'] = $video_svg;
            $data[$station_id]['station_audio_player'] = $station_audio_player;
            $data[$station_id]['station_video_player'] = $station_video_player;
            $data[$station_id]['talkshow'] = $result->nid;
            $data[$station_id]['broadcast_day'] = $schedule_broadcast_day;
            $data[$station_id]['weekly_or_biweekl'] = $schedule_weekly_or_biweekly;
            break;
          }
          elseif (($featured_talk_show == 'no' || empty($featured_talk_show)) && in_array($schedule_weekly_or_biweekly, $normal_weeks)) {
            $data[$station_id]['station_id'] = $station_id;
            $data[$station_id]['station_name'] = $station_name;
            $data[$station_id]['station_audio_image'] = $station_audio_image;
            $data[$station_id]['station_video_image'] = $station_video_image;
            $data[$station_id]['audio_image'] = $audio_svg;
            $data[$station_id]['video_image'] = $video_svg;
            $data[$station_id]['station_audio_player'] = $station_audio_player;
            $data[$station_id]['station_video_player'] = $station_video_player;
            $data[$station_id]['talkshow'] = $result->nid;
            $data[$station_id]['broadcast_day'] = $schedule_broadcast_day;
            $data[$station_id]['weekly_or_biweekl'] = $schedule_weekly_or_biweekly;
            break;
          }
        }
      }
    }

    return $data;
  }

  public function getAffiliateTalkShowInfo(int $talkshow_nid) {
    $data = [
      'status' => 0,
    ];

    /** @var \Drupal\node\NodeInterface $talkshow_node */
    $talkshow_node = $this->entityTypeManager->getStorage('node')->load($talkshow_nid);
    if (empty($talkshow_node) || $talkshow_node->bundle() != 'talk_show_include') {
      return $data;
    }

    // Get site timezone
    $site_timezone = \Drupal::config('system.date')->get('timezone.default');

    // Get the latest archive description
    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_archive_includes', 'ai', 'n.nid = ai.entity_id');
    $query->condition('n.type', 'archive_descriptions', '=')
      ->condition('n.status', 1, '=')
      ->condition('ai.field_archive_includes_target_id', $talkshow_nid, '=')
      ->fields('n', ['nid', 'title'])
      ->orderBy('n.created', 'DESC')
      ->range(0, 1);

    $result = $query->execute()->fetchObject();
    if (empty($result)) {
      return $data;
    }

    /** @var \Drupal\node\NodeInterface $archive_node */
    $archive_node = $this->entityTypeManager->getStorage('node')->load($result->nid);

    $headline = $archive_node->hasField('field_archive_show_headline') && !$archive_node->get('field_archive_show_headline')->isEmpty() ?
      $archive_node->get('field_archive_show_headline')->value : '';
    $sub_headline = $archive_node->hasField('field_archive_show_sub_headline') && !$archive_node->get('field_archive_show_sub_headline')->isEmpty() ?
      $archive_node->get('field_archive_show_sub_headline')->value : '';

    $delivered_file_type = $archive_node->hasField('field_delivered_file_type') && !$archive_node->get('field_delivered_file_type')->isEmpty() ?
      $archive_node->get('field_delivered_file_type')->value : '';
    if ($delivered_file_type != 1) {
      $delivered_file_type = 0;
    }
    /** @var \Drupal\file\FileInterface $archive_delivered_file_up */
    $archive_delivered_file_up = $archive_node->hasField('field_archive_delivered_file_up') && !$archive_node->get('field_archive_delivered_file_up')->isEmpty() ?
      $archive_node->get('field_archive_delivered_file_up')->entity : '';
    $archive_delivered_file_up_url = '';
    if (!empty($archive_delivered_file_up)) {
      $archive_delivered_file_up_uri = $archive_delivered_file_up->getFileUri();
      $archive_delivered_file_up_url = $this->fileUrlGenerator->generateString($archive_delivered_file_up_uri);
    }

    $produced_or_delivered_type = $archive_node->hasField('field_produced_or_delivered_type') && !$archive_node->get('field_produced_or_delivered_type')->isEmpty() ?
      $archive_node->get('field_produced_or_delivered_type')->value : '';
    if ($produced_or_delivered_type != 1) {
      $produced_or_delivered_type = 0;
    }
    /** @var \Drupal\file\FileInterface $archive_uploaded_video */
    $archive_uploaded_video = $archive_node->hasField('field_archive_uploaded_video') && !$archive_node->get('field_archive_uploaded_video')->isEmpty() ?
      $archive_node->get('field_archive_uploaded_video')->entity : '';
    $archive_uploaded_video_url = '';
    if (!empty($archive_uploaded_video)) {
      $archive_uploaded_video_uri = $archive_uploaded_video->getFileUri();
      $archive_uploaded_video_url = $this->fileUrlGenerator->generateString($archive_uploaded_video_uri);
    }

    $broadcast_timedate = '';
    $broadcast_date = '';
    $broadcast_time = '';
    if (
      $archive_node->hasField('field_archive_broadcast_timedate') &&
      !$archive_node->get('field_archive_broadcast_timedate')->isEmpty()
    ) {
      // Value is stored in UTC
      $date = new DrupalDateTime(
        $archive_node->get('field_archive_broadcast_timedate')->value,
        new \DateTimeZone('UTC')
      );

      // Convert to site timezone
      $date->setTimezone(new \DateTimeZone($site_timezone));

      $broadcast_timedate = $date->format('D, F j, Y - H:i');
      $broadcast_iso = $date->format(DATE_ATOM);
      $broadcast_date = $date->format('Y-m-d');
      $broadcast_time = $date->format('H:i');
    }

    // Use Show Banner for picture
    $archive_use_show_banner = $archive_node->hasField('field_archive_use_show_banner') && !$archive_node->get('field_archive_use_show_banner')->isEmpty() ?
      $archive_node->get('field_archive_use_show_banner')->target_id : '';
    $banner_url = '';
    $banner_image = '';
    if (empty($archive_use_show_banner)) {
      /** @var \Drupal\file\FileInterface $archive_add_picture */
      $archive_add_picture = $archive_node->hasField('field_archive_add_picture') && !$archive_node->get('field_archive_add_picture')->isEmpty() ?
        $archive_node->get('field_archive_add_picture')->entity : '';
      if (!empty($archive_add_picture)) {
        $banner_uri = $archive_add_picture->getFileUri();
        $banner_url = $this->fileUrlGenerator->generateString($banner_uri);
        $banner_image = '<img alt="' . $talkshow_node->getTitle() . '" title="' . $talkshow_node->getTitle() . '" src="' . $banner_url . '" loading="lazy" />';
      }
    }
    else {
      /** @var \Drupal\node\NodeInterface $banner_node */
      $banner_node = $this->entityTypeManager->getStorage('node')->load($archive_use_show_banner);
      if (!empty($banner_node)) {
        /** @var \Drupal\file\FileInterface $include_banner */
        $include_banner = $banner_node->hasField('field_include_banner') && !$banner_node->get('field_include_banner')->isEmpty() ?
          $banner_node->get('field_include_banner')->entity : '';
        if (!empty($include_banner)) {
          $banner_uri = $include_banner->getFileUri();
          $banner_url = $this->fileUrlGenerator->generateString($banner_uri);
          $banner_image = '<img alt="' . $talkshow_node->getTitle() . '" title="' . $talkshow_node->getTitle() . '" src="' . $banner_url . '" loading="lazy" />';
        }
      }
    }

    /** @var \Drupal\file\FileInterface $include_host_picture */
    $include_host_picture = $talkshow_node->hasField('field_include_host_picture') && !$talkshow_node->get('field_include_host_picture')->isEmpty() ?
      $talkshow_node->get('field_include_host_picture')->entity : '';
    $include_host_picture_url = '';
    if (!empty($include_host_picture)) {
      $include_host_picture_uri = $include_host_picture->getFileUri();
      $include_host_picture_url = $this->fileUrlGenerator->generateString($include_host_picture_uri);
    }
    $include_host_picture_title = $talkshow_node->hasField('field_include_host_picture') && !$talkshow_node->get('field_include_host_picture')->isEmpty() ?
      $talkshow_node->get('field_include_host_picture')->title : $talkshow_node->getTitle();

    $live_data = $this->isLiveNow($talkshow_nid);

    /** @var \Drupal\file\FileInterface $affiliate_stations_info */
    $affiliate_stations_info = $talkshow_node->hasField('field_affiliate_stations_info') && !$talkshow_node->get('field_affiliate_stations_info')->isEmpty() ?
      $talkshow_node->get('field_affiliate_stations_info')->entity : '';
    $affiliate_stations_info_url = '';
    $affiliate_stations_info_mime = '';
    if (!empty($affiliate_stations_info)) {
      $affiliate_stations_info_uri = $affiliate_stations_info->getFileUri();
      $affiliate_stations_info_mime = $affiliate_stations_info->getMimeType();
      $affiliate_stations_info_url = $this->fileUrlGenerator->generateString($affiliate_stations_info_uri);
    }

    $affiliate_stations_info_file_type = '';
    if ($affiliate_stations_info_mime == 'application/msword' || $affiliate_stations_info_mime == 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
      $affiliate_stations_info_file_type = 'doc';
    }
    elseif ($affiliate_stations_info_mime == 'application/vnd.ms-excel' || $affiliate_stations_info_mime == 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
      $affiliate_stations_info_file_type = 'xls';
    }
    else {
      $affiliate_stations_info_file_type = 'pdf';
    }

    // Talk show file name
    $talkshow_file_name = $talkshow_node->getTitle();
    $talkshow_file_name = strtolower($talkshow_file_name);
    $talkshow_file_name = str_replace(' ', '-', $talkshow_file_name);
    $talkshow_file_name = str_replace('_', '-', $talkshow_file_name);
    $talkshow_file_name = str_replace("'", '', $talkshow_file_name);
    $talkshow_file_name = $talkshow_node->id() . '--' . $talkshow_file_name . '.mp3';

    $stations = $this->baseHelper->getTalkshowStations($talkshow_nid);
    foreach ($stations as $station_id => $station) {
      $station_audio_image = $station['station_audio_image'];
      $station_video_image = $station['station_video_image'];
      $station_audio_player = $station['station_audio_player'];
      $station_video_player = $station['station_video_player'];
      break;
    }

    // Initialize the response
    $response = [];

    $response[$talkshow_nid] = [
      'talkshow_nid' => $talkshow_nid,
      'talkshow_title' => $talkshow_node->getOwner(),
      'headline' => $headline,
      'sub_headline' => $sub_headline,
      'delivered_file_type' => $delivered_file_type,
      'archive_delivered_file_up_url' => $archive_delivered_file_up_url,
      'station_audio_image' => $station_audio_image,
      'station_audio_player' => $station_audio_player,
      'produced_or_delivered_type' => $produced_or_delivered_type,
      'archive_uploaded_video_url' => $archive_uploaded_video_url,
      'station_video_image' => $station_video_image,
      'station_video_player' => $station_video_player,
      'broadcast_datetime' => $broadcast_timedate,
      'broadcast_date' => $broadcast_date,
      'broadcast_time' => $broadcast_time,
      'banner_url' => $banner_url,
      'banner_image' => $banner_image,
      'include_host_picture_url' => $include_host_picture_url,
      'include_host_picture_title' => $include_host_picture_title,
      'is_live' => !empty($live_data) ? 1 : 0,
      'live_data' => $live_data,
      'affiliate_stations_info_file_type' => $affiliate_stations_info_file_type,
      'affiliate_stations_info_url' => $affiliate_stations_info_url,
      'talkshow_file_name' => '/bbsradio/affiliate-download/' . $talkshow_file_name,
    ];

    return $response;
  }

}
