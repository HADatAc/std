<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\std\Service\StudyVariableSearchService;

/**
 * Form for manually clearing the persistent study search cache.
 * 
 * This form provides a way to explicitly clear the study search cache that
 * normally persists across 'drush cr' operations. Use this after major data
 * imports, structural changes, or when troubleshooting cache-related issues.
 */
class ClearPersistentCacheForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'std_clear_persistent_cache_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to clear the study search persistent cache?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('<p><strong>Warning:</strong> This will completely clear all cached study search data.</p><p>The study search cache is designed to persist across normal cache clears (drush cr) for performance reasons. After clearing this cache, the first search for each study will be slower as the cache rebuilds.</p><p><strong>When to use this:</strong></p><ul><li>After bulk data imports or migrations</li><li>After structural changes to study/SOC/workflow data models</li><li>When troubleshooting cache-related display issues</li><li>When you need to force a complete cache rebuild</li></ul><p><strong>Alternatives:</strong> For most cases, use selective cache invalidation instead (automatically happens when editing studies/SOCs).</p>');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('system.performance_settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Clear Persistent Cache');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Clear the persistent cache
    StudyVariableSearchService::clearAllCache();
    
    $this->messenger()->addStatus(
      $this->t('The study search persistent cache has been cleared. The cache will rebuild automatically on the next search.')
    );
    
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
