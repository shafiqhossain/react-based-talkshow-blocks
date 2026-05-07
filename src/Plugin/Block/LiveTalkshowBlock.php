<?php

namespace Drupal\custom_example\Plugin\Block;

use Drupal\bbsradio_base\Service\BaseHelper;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Block\Annotation\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Live Talk Show Block.
 *
 * @Block(
 *   id = "live_talkshow",
 *   admin_label = @Translation("Live talk show block"),
 *   category = @Translation("Talk show"),
 * )
 */
class LiveTalkshowBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The Entity Manager.
   *
   * @var EntityTypeManagerInterface $entityTypeManager
   */
  protected $entityTypeManager;

  /**
   * @var AccountInterface $account
   */
  protected $account;

  /**
   * The Database Connection.
   *
   * @var Connection $database
   */
  protected $database;

  /**
   * The form builder Service.
   *
   * @var FormBuilderInterface $formBuilder
   */
  protected $formBuilder;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   */
  protected $configFactory;

  /**
   * The base helper.
   *
   * @var \Drupal\bbsradio_base\Service\BaseHelper $baseHelper
   */
  protected $baseHelper;

  /**
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition,
                              EntityTypeManagerInterface $entityTypeManager,
                              AccountInterface $current_account,
                              Connection $database,
                              FormBuilderInterface $formBuilder,
                              ConfigFactoryInterface $config_factory,
                              BaseHelper $baseHelper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entityTypeManager;
    $this->account = $current_account;
    $this->database = $database;
    $this->formBuilder = $formBuilder;
    $this->configFactory = $config_factory;
    $this->baseHelper = $baseHelper;
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('database'),
      $container->get('form_builder'),
      $container->get('config.factory'),
      $container->get('base.helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    return AccessResult::allowedIfHasPermission($account, 'access content');
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'station_names' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $options = $this->baseHelper->getStations();
    $form['station_names'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Stations'),
      '#options' => $options,
      '#description' => $this->t('Please select a Station for which live talk show will display'),
      '#default_value' => !empty($this->configuration['station_names']) ? explode(',', $this->configuration['station_names']) : [],
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];

    return $form;
  }


  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state) : void {
  }


  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) : void {
    parent::blockSubmit($form, $form_state);

    $station_names = array_filter($form_state->getValue('station_names'));
    $this->configuration['station_names'] = implode(',', $station_names);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $host = str_ireplace('http:', '', $host);
    $host = str_ireplace('https:', '', $host);

    return [
      '#markup' => '<div id="station-live-talkshow-root"></div>',
      '#attached' => [
        'library' => [
          'custom_example/live-talkshow-lib'
        ],
        'drupalSettings' => [
          'reactApp' => [
            'station_names' => $this->configuration['station_names'],
            'site_url' => $host,
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return 0;
  }

}
