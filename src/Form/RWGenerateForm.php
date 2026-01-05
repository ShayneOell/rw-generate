<?php

namespace Drupal\rw_generate\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rw_generate\Service\ContentGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;

class RWGenerateForm extends FormBase
{

  protected $contentGenerator;
  protected $entityTypeManager;
  protected $entityFieldManager;
  protected $fileSystem;

  public function __construct(ContentGenerator $contentGenerator, EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, FileSystemInterface $fileSystem)
  {
    $this->contentGenerator = $contentGenerator;
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->fileSystem = $fileSystem;
  }

  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('rw_generate.content_generator'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('file_system')
    );
  }

  public function getFormId()
  {
    return 'rw_generate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['count'] = [
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

    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#options' => $content_type_options,
      '#required' => TRUE,
      '#default_value' => key($content_type_options),
    ];

    $form['generate_images'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate Images'),
      '#default_value' => FALSE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate AI Nodes'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $count = $form_state->getValue('count');
    $generateImages = $form_state->getValue('generate_images');
    $content_type_id = $form_state->getValue('content_type');

    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $content_type_id);
    $fields = array_keys($field_definitions);

    $created = $this->contentGenerator->generateNodes($count, $content_type_id, $generateImages, $fields);
    $this->messenger()->addStatus($this->t('@count @type nodes generated successfully.', ['@count' => $created, '@type' => $content_type_id]));
  }


}
