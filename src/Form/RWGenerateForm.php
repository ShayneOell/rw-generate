<?php

namespace Drupal\rw_generate\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rw_generate\Service\AIContentGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Drupal\Core\Extension\ModuleHandlerInterface;

class RWGenerateForm extends FormBase
{

  protected $aiGenerator;
  protected $entityTypeManager;
  protected $entityFieldManager;
  protected $fileSystem;
  protected $moduleHandler;

  public function __construct(AIContentGenerator $aiGenerator, EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, FileSystemInterface $fileSystem, ModuleHandlerInterface $module_handler)
  {
    $this->aiGenerator = $aiGenerator;
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->fileSystem = $fileSystem;
    $this->moduleHandler = $module_handler;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('rw_generate.ai_generator'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('file_system'),
      $container->get('module_handler')
    );
  }

  public function getFormId()
  {
    return 'rw_generate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
    ];

    $form['generation_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Generation'),
      '#group' => 'tabs',
    ];

    $form['generation_tab']['count'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of nodes to generate'),
      '#default_value' => 1,
      '#min' => 1,
      '#required' => TRUE,
    ];

    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $content_type_options = [];
    foreach ($content_types as $id => $content_type) {
      $content_type_options[$id] = $content_type->label();
    }

    $form['generation_tab']['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#options' => $content_type_options,
      '#required' => TRUE,
      '#default_value' => key($content_type_options),
    ];

    $form['generation_tab']['generate_images'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate Images'),
      '#default_value' => FALSE,
    ];

    
    $form['context_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Site Context'),
      '#group' => 'tabs',
    ];

    $module_path = $this->moduleHandler->getModule('rw_generate')->getPath();
    $site_context_path = $module_path . '/config/site.context.yml';
    $site_context_content = '';
    if (is_file($site_context_path)) {
      $site_context_content = file_get_contents($site_context_path);
    }

    $form['context_tab']['site_context'] = [
      '#type' => 'textarea',
      '#title' => $this->t('site.context.yml'),
      '#default_value' => $site_context_content,
      '#rows' => 20,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate AI Nodes'),
    ];

    $form['actions']['save_context'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Context and Clear Cache'),
      '#submit' => ['::saveContextSubmit'],
    ];



    return $form;
  }



  public function saveContextSubmit(array &$form, FormStateInterface $form_state) {
    $site_context = $form_state->getValue('site_context');

    $module_path = $this->moduleHandler->getModule('rw_generate')->getPath();
    $site_context_path = $module_path . '/config/site.context.yml';
    file_put_contents($site_context_path, $site_context);

    
    \Drupal::service('cache.render')->invalidateAll();

    $this->messenger()->addStatus($this->t('Site context saved and render cache cleared.'));
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $count = $form_state->getValue('count');
    $generateImages = $form_state->getValue('generate_images');
    $content_type_id = $form_state->getValue('content_type');

    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $content_type_id);
    $fields = array_keys($field_definitions);

    $created = $this->aiGenerator->generateNodes($count, $content_type_id, $generateImages, $fields);
    $this->messenger()->addStatus($this->t('@count @type nodes generated successfully.', ['@count' => $created, '@type' => $content_type_id]));
  }


}
