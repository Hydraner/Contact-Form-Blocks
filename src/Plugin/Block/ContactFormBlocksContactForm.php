<?php

/**
 * @file
 * Contains \Drupal\contact_form_blocks\Plugin\Block\ContactFormBlocksContactForm.
 */

namespace Drupal\contact_form_blocks\Plugin\Block;

use Drupal\Component\Utility\String;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a block for executing PHP code.
 *
 * @Block(
 *   id = "contact_form_blocks_contact_form",
 *   admin_label = @Translation("Contact form"),
 *   category = @Translation("Forms")
 * )
 */
class ContactFormBlocksContactForm extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * Stores the configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatter
   */
  protected $dateFormatter;

  /**
   * The entity form builder.
   *
   * @var \Drupal\Core\Entity\EntityFormBuilderInterface
   */
  protected $entityFormBuilder;

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * Creates a ContactFormBlocksContactForm instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Datetime\DateFormatter $date_formatter
   *   The date service.
   * @param \Drupal\Core\Entity\EntityFormBuilderInterface $entity_form_builder
   *   The entity form builder.
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, AccountInterface $current_user, DateFormatter $date_formatter, EntityFormBuilderInterface $entity_form_builder, EntityManagerInterface $entity_manager, FloodInterface $flood) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->dateFormatter = $date_formatter;
    $this->entityFormBuilder = $entity_form_builder;
    $this->entityManager = $entity_manager;
    $this->flood = $flood;
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('entity.form_builder'),
      $container->get('entity.manager'),
      $container->get('flood')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account) {
    // It would be nicer to have permissions per contact form.
    return $this->currentUser->hasPermission('access site-wide contact form');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Check if flood control has been activated for sending emails.
    if (!$this->currentUser->hasPermission('administer contact forms')) {
      $this->contactFloodControl();
    }

    // Get the configured form.
    $contact_form = $this->entityManager
      ->getStorage('contact_form')
      ->load($this->configuration['contact_form']);

    $message = $this->entityManager
      ->getStorage('contact_message')
      ->create(array(
        'contact_form' => $contact_form->id(),
      ));

    $form = $this->entityFormBuilder->getForm($message);
    $form['#title'] = String::checkPlain($contact_form->label());

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array(
      'contact_form' => $this->configFactory->get('contact.settings')->get('default_form'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $contact_forms = $this->entityManager->getStorage('contact_form')->loadMultiple();
    foreach ($contact_forms as $contact_form_id => $contact_form) {
      $contact_form_options[$contact_form_id] = $contact_form->label();
    }

    $form['contact_form_blocks'] = array(
      '#type' => 'details',
      '#title' => $this->t('Contact form configuration'),
      '#open' => TRUE,
      '#parents' => array(),
    );
    $form['contact_form_blocks']['contact_form'] = array(
      '#type' => 'select',
      '#title' => $this->t('Contact form'),
      '#description' => $this->t('Select the contact form you\'d like to display.'),
      '#default_value' => $this->configuration['contact_form'],
      '#options' => $contact_form_options,
    );

    return $form;
  }

  /**
   * Throws an exception if the current user triggers flood control.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   */
  protected function contactFloodControl() {
    $limit = $this->configFactory->get('contact.settings')->get('flood.limit');
    $interval = $this->configFactory->get('contact.settings')->get('flood.interval');
    if (!$this->flood->isAllowed('contact', $limit, $interval)) {
      drupal_set_message($this->t('You cannot send more than %limit messages in @interval. Try again later.', array(
        '%limit' => $limit,
        '@interval' => $this->dateFormatter->formatInterval($interval),
      )), 'error');
      throw new AccessDeniedHttpException();
    }
  }

}
