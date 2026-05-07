<?php
/**
 * @file
 * contains \Drupal\custom_example\Controller\UserResourceController.
 */

namespace Drupal\custom_example\Controller;

use Drupal\bbsradio_base\Service\BaseHelper;
use Drupal\bbsradio_subscription\Service\SubscriptionAccessManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\custom_example\Service\QueryManager;
use Drupal\custom_example\Service\ResourceManager;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\path_alias\AliasManager;
use Drupal\Component\Serialization\Json;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class UserResourceController extends ControllerBase {

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
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

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
   * The stream wrapper manager.
   */
  protected StreamWrapperManagerInterface $streamWrapperManager;

  /**
   * Resource manager service.
   *
   * @var \Drupal\custom_example\Service\ResourceManager
   */
  protected $resourceManager;

  /**
   * The base helper service
   *
   * @var \Drupal\bbsradio_base\Service\BaseHelper $baseHelper
   */
  protected $baseHelper;

  /**
   * Query manager service.
   *
   * @var \Drupal\custom_example\Service\QueryManager $queryManager
   */
  protected $queryManager;

  /**
   * The subscription access manager service
   *
   * @var \Drupal\bbsradio_subscription\Service\SubscriptionAccessManager $subscriptionAccessManager
   */
  protected $subscriptionAccessManager;

  /**
   * Constructs class.
   *
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_account,
    Connection $database,
    LoggerChannelFactoryInterface $logger,
    MessengerInterface $messenger,
    ConfigFactoryInterface $config_factory,
    FileUrlGeneratorInterface $file_url_generator,
    AliasManager $alias_manager,
    StreamWrapperManagerInterface $stream_wrapper_manager,
    ResourceManager $resource_manager,
    BaseHelper $base_helper,
    QueryManager $query_manager,
    SubscriptionAccessManager $subscription_access_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $current_account;
    $this->database = $database;
    $this->logger = $logger->get('resource');
    $this->messenger = $messenger;
    $this->configFactory = $config_factory;
    $this->fileUrlGenerator = $file_url_generator;
    $this->aliasManager = $alias_manager;
    $this->streamWrapperManager = $stream_wrapper_manager;
    $this->resourceManager = $resource_manager;
    $this->baseHelper = $base_helper;
    $this->queryManager = $query_manager;
    $this->subscriptionAccessManager = $subscription_access_manager;
  }

  /**
   * Creates a new Controller.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   A new Controller object.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('database'),
      $container->get('logger.factory'),
      $container->get('messenger'),
      $container->get('config.factory'),
      $container->get('file_url_generator'),
      $container->get('path_alias.manager'),
      $container->get('stream_wrapper_manager'),
      $container->get('custom_example.resource_manager'),
      $container->get('base.helper'),
      $container->get('custom_example.query_manager'),
      $container->get('subscription.access_manager'),
    );
  }

  /**
   * Verify email with verification link
   *
   * @param string $hash_code
   * @return string[]
   */
  public function verifyMail(string $hash_code) {
    $success_message = $this->configFactory->get('custom_example.email_verification_settings')->get('verification_success_message');
    if (empty($success_message)) {
      $success_message = 'Email has been verified successfully!';
    }

    $fail_message = $this->configFactory->get('custom_example.email_verification_settings')->get('verification_fail_message');
    if (empty($fail_message)) {
      $fail_message = 'Sorry! Email verification is failed!';
    }

    // If hash code is empty, no verification needed
    if (empty($hash_code)) {
      return [
        '#markup' => $fail_message,
      ];
    }

    $status = $this->resourceManager->verifyEmail($this->account, $hash_code, 1);

    if ($status) {
      $data = $this->resourceManager->getLogByHashCode($hash_code);
      if (!empty($data['uid'])) {
        $user = \Drupal\user\Entity\User::load($data['uid']);
        $user->set('field_mail_verify_status', 1);
        $user->save();
      }

      $message = $success_message;
    }
    else {
      $message = $fail_message;
    }

    return [
      '#markup' => $message,
    ];
  }

  /**
   * Station live talk show
   */
  public function getStationLiveTalkshows(Request $request) {
    $response = [
      'status' => 0,
      'data' => [],
    ];

    $station_names = '';
    $json_station_names = $request->getContent();
    if (!empty($json_station_names)) {
      $station_names_arr = Json::decode($json_station_names);
      $station_names = !empty($station_names_arr['station_names']) ? $station_names_arr['station_names'] : '';
    }

    if (empty($station_names)) {
      return new JsonResponse($response);
    }

    $station_names_arr = explode(',', $station_names);
    $list_data = $this->queryManager->getStationLiveTalkshow($station_names_arr);

    // if there is no live talk show, return empty
    if (empty($list_data)) {
      return new JsonResponse($response);
    }

    $response['status'] = 1;
    $response['data'] = $list_data;

    return new JsonResponse($response);
  }

  /**
   * Get affiliate talk show links
   */
  public function getAffiliateTalkshowLinks(Request $request) {
    $response = [
      'status' => 0,
      'data' => [],
    ];

    $talkshow_nid = 0;
    $json_data = $request->getContent();
    if (!empty($json_data)) {
      $json_data_arr = Json::decode($json_data);
      $talkshow_nid = !empty($json_data_arr['talkshow_nid']) ? $json_data_arr['talkshow_nid'] : 0;
    }

    if (empty($talkshow_nid)) {
      return new JsonResponse($response);
    }

    $list_data = $this->queryManager->getAffiliateTalkShowInfo($talkshow_nid);

    // if there is no live talk show, return empty
    if (empty($list_data)) {
      return new JsonResponse($response);
    }

    $response['status'] = 1;
    $response['data'] = $list_data;

    return new JsonResponse($response);
  }

  public function affiliateDownload($talkshow_filename) {
    if (empty($talkshow_filename)) {
      throw new AccessDeniedHttpException();
    }

    $talkshow_name = str_replace('.mp3', '', $talkshow_filename);
    $talkshow_arr = explode('--', $talkshow_name);
    $talkshow_nid = !empty($talkshow_arr[0]) ? $talkshow_arr[0] : 0;

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
      throw new AccessDeniedHttpException();
    }

    $archive_nid = $result->nid;

    // Load the node
    /** @var \Drupal\node\NodeInterface $node */
    $node = $this->entityTypeManager->getStorage('node')->load($archive_nid);

    if ($node == false) {
      throw new AccessDeniedHttpException();
    }

    $has_perm = $this->subscriptionAccessManager->hasArchivePermission(PERM_DELIVERED_FILE_MP3, $archive_nid, $this->account->id());

    $fid = $node->hasField('field_archive_delivered_file_up') && !$node->get('field_archive_delivered_file_up')->isEmpty() ?
      $node->get('field_archive_delivered_file_up')->target_id : 0;

    // If don't have permission, return
    if (!$has_perm) {
      throw new AccessDeniedHttpException();
    }

    // If no file reference to found, return
    if (!$fid) {
      throw new NotFoundHttpException("The media item requested has no file referenced/uploaded in the field.");
    }

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    if (!$file) {
      throw new \Exception("File id {$fid} could not be loaded.");
    }

    $uri = $file->getFileUri();
    $scheme = $this->streamWrapperManager->getScheme($uri);
    $file_path_uri = $this->baseHelper->getAbsolutePath($uri);

    // Or item does not exist on disk.
    if (!$this->streamWrapperManager->isValidScheme($scheme) || !file_exists($file_path_uri)) {
      throw new NotFoundHttpException("The file {$uri} does not exist.");
    }

    // Let other modules provide headers and controls access to the file.
    $headers = $this->moduleHandler()->invokeAll('file_download', [$uri]);

    foreach ($headers as $result) {
      if ($result == -1) {
        throw new AccessDeniedHttpException();
      }
    }

    $this->database->insert('shows_main_links')
      ->fields([
        'nid' => $node->id(),
        'link_name' => 'archives_download',
        'show_name' => 'herewestand',
        'timestamp' => time(),
        'ip_address' => $_SERVER['REMOTE_ADDR'],
      ])
      ->execute();

    if (count($headers)) {
      // \Drupal\Core\EventSubscriber\FinishResponseSubscriber::onRespond()
      // sets response as not cacheable if the Cache-Control header is not
      // already modified. We pass in FALSE for non-private schemes for the
      // $public parameter to make sure we don't change the headers.
      $response = new BinaryFileResponse($file_path_uri, Response::HTTP_OK, $headers, $scheme !== 'private');
      if (empty($headers['Content-Disposition'])) {
        $disposition = ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $talkshow_filename);
      }

      return $response;
    }

    throw new AccessDeniedHttpException();
  }

}
