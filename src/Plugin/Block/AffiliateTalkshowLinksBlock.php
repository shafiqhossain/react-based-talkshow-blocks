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
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an affiliate talk show links Block.
 *
 * @Block(
 *   id = "affiliate_talkshow_links_block",
 *   admin_label = @Translation("Affiliate talk show links block"),
 *   category = @Translation("Talk show"),
 * )
 */
class AffiliateTalkshowLinksBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $currentRouteMatch;

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
                              CurrentRouteMatch $current_route_match,
                              BaseHelper $baseHelper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->entityTypeManager = $entityTypeManager;
    $this->account = $current_account;
    $this->database = $database;
    $this->formBuilder = $formBuilder;
    $this->configFactory = $config_factory;
    $this->currentRouteMatch = $current_route_match;
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
      $container->get('current_route_match'),
      $container->get('base.helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'talkshow_nid' => 0,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $options = $this->baseHelper->getTalkshows();
    $form['talkshow_nid'] = [
      '#type' => 'select',
      '#title' => $this->t('Talk show'),
      '#description' => $this->t('Please select a talk show'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $this->configuration['talkshow_nid'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state) {
    if (empty($form_state->getValue('talkshow_nid'))) {
      $form_state->setErrorByName('talkshow_nid', $this->t('Please select a talk show.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->configuration['talkshow_nid'] = $values['talkshow_nid'];
  }


  /**
   * {@inheritdoc}
   */
  protected function blockAccess(AccountInterface $account) {
    if (!empty($this->configuration['talkshow_nid'])) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $host = str_ireplace('http:', '', $host);
    $host = str_ireplace('https:', '', $host);

    return [
      '#markup' => '<div id="affiliate-talkshow-links-root"></div>',
      '#attached' => [
        'library' => [
          'custom_example/affiliate-talkshow-links-lib'
        ],
        'drupalSettings' => [
          'reactApp' => [
            'talkshow_nid' => $this->configuration['talkshow_nid'],
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
