<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;

class ViewStudyObjectForm extends FormBase {

  protected $studyObjectCollection;

  public function getStudyObjectCollection() {
    return $this->studyObjectCollection;
  }

  public function setStudyObjectCollection($studyObjectCollection) {
    return $this->studyObjectCollection = $studyObjectCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'view_study_object_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyobjectcollectionuri = NULL) {

    # SET CONTEXT
    $uri=base64_decode($studyobjectcollectionuri);

    # ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    // RETRIEVE STUDY OBJECT COLLECTION BY URI
    $api = \Drupal::service('rep.api_connector');
    $studyObjectCollection = $api->parseObjectResponse($api->getUri($uri),'getUri');
    $this->setStudyObjectCollection($studyObjectCollection);

    // RETRIEVE STUDY OBJECT BY STUDY OBJECT COLLECTION
    $sos = $api->parseObjectResponse($api->studyObjectsBySOCWithPage($this->getStudyObjectCollection()->uri,12,0),'studyObjectsBySOCWithPage');

    //dpm($sos);

    # BUILD HEADER

    $header = [
      'so_uri' => t('URI'),
      'so_original_id' => t('Original Id'),
      'so_entity' => t('Entity Type'),
    ];

    # POPULATE DATA

    $output = array();
    $uriType = array();
    if ($sos != NULL) {
      foreach ($sos as $so) {

        $nsUri = Utils::namespaceUri($so->uri);

        $originalId = ' ';
        if ($so->originalId != NULL) {
          $originalId = $so->originalId;
        }

        $typeLabel = ' ';
        if ($so->typeLabel != NULL) {
          $typeLabel = $so->typeLabel;
        }

        $output[$so->uri] = [
          'so_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($so->uri).'">'.$nsUri.'</a>'),
          'so_original_id' => $so->originalId,
          'so_entity' => $so->typeLabel,
        ];
      }
    }

    # PUT FORM TOGETHER

    $form['scope'] = [
      '#type' => 'item',
      '#title' => t('<h3>Study: <font color="DarkGreen">' . $this->getStudyObjectCollection()->isMemberOf->label . '</font></h3>'),
    ];
    $form['subscope'] = [
      '#type' => 'item',
      '#title' => t('<h4>Study Object Collection: <font color="DarkGreen">' . $this->getStudyObjectCollection()->label . '</font></h4>'),
    ];

    $form['so_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $output,
      '#empty' => t('No response options found'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
      ],
    ];
    $form['bottom_space'] = [
      '#type' => 'item',
      '#title' => t('<br><br><br>'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $submitted_values = $form_state->cleanValues()->getValues();
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // RETRIEVE TRIGGERING BUTTON
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    // BACK TO MAIN PAGE
    if ($button_name === 'back') {
      $url = Url::fromRoute('rep.describe_element', ['elementuri' => base64_encode($this->getStudyObjectCollection()->isMemberOf->uri)]);
      $form_state->setRedirectUrl($url);
      return;
    }
  }

}
