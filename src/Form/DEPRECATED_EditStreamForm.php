<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;

class EditStreamForm extends FormBase {

  protected $stream;

  public function getStream() {
    return $this->stream;
  }

  public function setStream($stream) {
    return $this->stream = $stream;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_stream_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $streamuri = NULL, $fixstd = NULL) {
    $streamuri_decode=base64_decode($streamuri);

    $api = \Drupal::service('rep.api_connector');
    $stream = $api->parseObjectResponse($api->getUri($streamuri_decode),'getUri');
    if ($stream == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Stream."));
      self::backUrl();
      return;
    } else {
      $this->setStream($stream);
      //dpm($stream);
    }

    $study = ' ';
    if ($this->getStream()->study != NULL &&
        $this->getStream()->study->uri != NULL &&
        $this->getStream()->study->label != NULL) {
      $study = Utils::fieldToAutocomplete($this->getStream()->study->uri,$this->getStream()->study->label);
    }

    $semanticdatadictionary = ' ';
    if ($this->getStream()->semanticDataDictionary != NULL &&
        $this->getStream()->semanticDataDictionary->uri != NULL &&
        $this->getStream()->semanticDataDictionary->label != NULL) {
      $semanticdatadictionary = Utils::fieldToAutocomplete($this->getStream()->semanticDataDictionary->uri,$this->getStream()->semanticDataDictionary->label);
    }

    $deployment = ' ';
    if ($this->getStream()->deployment != NULL &&
        $this->getStream()->deployment->uri != NULL &&
        $this->getStream()->deployment->label != NULL) {
      $deployment = Utils::fieldToAutocomplete($this->getStream()->deployment->uri,$this->getStream()->deployment->label);
    }

    if ($fixstd == 'T') {
      $form['stream_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#disabled' => TRUE,
      ];
    } else {
      $form['stream_study'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Study'),
        '#default_value' => $study,
        '#autocomplete_route_name' => 'std.study_autocomplete',
      ];
    }

    $form['stream_semanticdatadictionary'] = [
      '#type' => 'textfield',
      '#title' => $this->t("Semantic Data Dictionary"),
      '#default_value' => $semanticdatadictionary,
      '#autocomplete_route_name' => 'std.semanticdatadictionary_autocomplete',
  ];

    $form['stream_deployment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deployment'),
      '#default_value' => $deployment,
      '#autocomplete_route_name' => 'std.deployment_autocomplete',
  ];

    $form['stream_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#default_value' => $this->getStream()->label,
    ];

    $method_options = [
      ' ',
      'message',
      'file',
    ];

    $form['stream_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Method'),
      '#default_value' => $this->getStream()->method,
      '#options' => $method_options,
    ];

    $form['stream_dataset_pattern'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dataset Pattern'),
      '#default_value' => $this->getStream()->datasetPattern,
    ];

    $form['stream_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#default_value' => $this->getStream()->hasVersion,
    ];
    $form['update_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
      '#name' => 'save',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'save-button'],
      ],
    ];
    $form['cancel_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'cancel-button'],
      ],
    ];
    $form['bottom_space'] = [
      '#type' => 'item',
      '#title' => t('<br><br>'),
    ];


    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'save') {
      if(strlen($form_state->getValue('stream_study')) < 1) {
        $form_state->setErrorByName('stream_study', $this->t('Please enter a valid study for the Stream'));
      }
      if(strlen($form_state->getValue('stream_semanticdatadictionary')) < 1) {
        $form_state->setErrorByName('stream_semanticdatadictionary', $this->t('Please enter a valid semanticdatadictionary for the Stream'));
      }
      if(strlen($form_state->getValue('stream_deployment')) < 1) {
        $form_state->setErrorByName('stream_deployment', $this->t('Please enter a valid Deployment for the Stream'));
      }
      if($form_state->getValue('stream_method') == ' ') {
        $form_state->setErrorByName('stream_method', $this->t('Please enter a valid method for the Stream'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      self::backUrl();
      return;
    }

    $useremail = \Drupal::currentUser()->getEmail();

    $studyUri = NULL;
    if ($form_state->getValue('stream_study') != NULL && $form_state->getValue('stream_study') != '') {
      $studyUri = Utils::uriFromAutocomplete($form_state->getValue('stream_study'));
    }

    $semanticDataDictionaryUri = NULL;
    if ($form_state->getValue('stream_semanticdatadictionary') != NULL && $form_state->getValue('stream_semanticdatadictionary') != '') {
      $semanticDataDictionaryUri = Utils::uriFromAutocomplete($form_state->getValue('stream_semanticdatadictionary'));
    }

    $deploymentUri = NULL;
    if ($form_state->getValue('stream_deployment') != NULL && $form_state->getValue('stream_deployment') != '') {
      $deploymentUri = Utils::uriFromAutocomplete($form_state->getValue('stream_deployment'));
    }

    $streamJSON = '{"uri":"'. $this->getStream()->uri .'",'.
        '"typeUri":"'.HASCO::STREAM.'",'.
        '"hascoTypeUri":"'.HASCO::STREAM.'",'.
        '"label":"'.$form_state->getValue('stream_name').'",'.
        '"studyUri":"' . $studyUri . '",' .
        '"semanticDataDictionaryUri":"'.$semanticDataDictionaryUri.'",'.
        '"deploymentUri":"'.$deploymentUri.'",'.
        '"method":"'.$form_state->getValue('stream_method').'",'.
        '"datasetPattern":"'.$form_state->getValue('stream_dataset_pattern').'",'.
        '"hasVersion":"'.$form_state->getValue('stream_version').'",'.
        '"permissionUri":"'.$form_state->getValue('stream_permission').'",' .
        '"hasStreamStatus":"'.$this->getStream()->hasStreamStatus.'",'.
        '"hasSIRManagerEmail":"'.$useremail.'"}';

    try {
      // UPDATE BY DELETING AND CREATING
      $api = \Drupal::service('rep.api_connector');
      $api->elementDel('stream',$this->getStream()->uri);
      $api->elementAdd('stream',$streamJSON);

      \Drupal::messenger()->addMessage(t("Stream has been updated successfully."));
      self::backUrl();
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addMessage(t("An error occurred while updating Stream: ".$e->getMessage()));
      self::backUrl();
      return;
    }

  }

  function backUrl() {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.edit_stream');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }

}
