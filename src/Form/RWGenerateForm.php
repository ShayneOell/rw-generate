<?php

namespace Drupal\rw_generate\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rw_generate\Service\AIContentGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

class RWGenerateForm extends FormBase {

  protected $aiGenerator;

  public function __construct(AIContentGenerator $aiGenerator) {
    $this->aiGenerator = $aiGenerator;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('rw_generate.ai_generator')
    );
  }

  public function getFormId() {
    return 'rw_generate_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['count'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of nodes to generate'),
      '#default_value' => 1,
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
    ];

    $form['content_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Content Type'),
      '#options' => [
        'article' => $this->t('Article'),
        'page' => $this->t('Basic Page'),
      ],
      '#default_value' => 'article',
      '#required' => TRUE,
    ];

    $form['generate_images'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate Images'),
      '#default_value' => FALSE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate AI Nodes'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $count = $form_state->getValue('count');
    $contentType = $form_state->getValue('content_type');
    $generateImages = $form_state->getValue('generate_images');

    $created = $this->aiGenerator->generateNodes($count, $contentType, $generateImages);

    $this->messenger()->addStatus($this->t('@count @type nodes generated successfully.', ['@count' => $created, '@type' => $contentType]));
  }
}
