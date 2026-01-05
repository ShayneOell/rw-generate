<?php

namespace Drupal\rw_generate\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure site context settings for this site.
 */
class SiteContextForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rw_generate_site_context';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['rw_generate.site_context'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('rw_generate.site_context');
    $default_context = <<<EOT
default_site_context:
  base_prompt: 'Write a {field_label} about {topic}. {variation}.'
  topics:
    - 'a general topic'
  variations:
    - 'with interesting details'
  image_keywords:
    - 'abstract image'
EOT;


    $form['site_context'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Site Context'),
      '#description' => $this->t('Enter the site context in YAML format.'),
      '#default_value' => $config->get('context') ?? $default_context,
      '#rows' => 20,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('rw_generate.site_context')
      ->set('context', $form_state->getValue('site_context'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
