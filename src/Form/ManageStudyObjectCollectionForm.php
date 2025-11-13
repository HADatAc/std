<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormBuilder;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\Component\Serialization\Json;

class ManageStudyObjectCollectionForm extends FormBase {

  protected $study;

  public function getStudy() {
    return $this->study;
  }

  public function setStudy($study) {
    return $this->study = $study;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'manage_study_object_collection_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL) {

    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';

    # SET CONTEXT
    $uri=base64_decode($studyuri);

    $uemail = \Drupal::currentUser()->getEmail();
    $uid = \Drupal::currentUser()->id();
    $user = \Drupal\user\Entity\User::load($uid);
    $username = $user->name->value;

    // RETRIEVE CONTAINER BY URI
    $api = \Drupal::service('rep.api_connector');
    $study = $api->parseObjectResponse($api->getUri($uri),'getUri');
    $this->setStudy($study);

    // RETRIEVE SLOT_ELEMENTS BY CONTAINER
    $socs = $api->parseObjectResponse($api->studyObjectCollectionsByStudy($this->getStudy()->uri),'studyObjectCollectionsByStudy');

    #if (sizeof($containerslots) <= 0) {
    #  return new RedirectResponse(Url::fromRoute('sir.add_containerslots', ['containeruri' => base64_encode($this->getContainerUri())])->toString());
    #}

    //dpm($container);
    //dpm($slotElements);

    # ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    # BUILD HEADER
    $header = [
      'soc_uri' => t('URI'),
      'soc_reference' => t('Reference'),
      'soc_label' => t('Label'),
      'soc_grounding_label' => t('Grounding Label'),
      'soc_operations' => t('Operations'),
    ];

    # POPULATE DATA
    $output = array();
    $uriType = array();
    if ($socs != NULL) {
      foreach ($socs as $soc) {
        $link = $root_url.REPGUI::MANAGE_STUDY_OBJECTS.base64_encode($soc->uri);
        $button = '<a href="' . $link . '" class="btn btn-primary btn-sm" '.
               ' role="button">Mng. Objects</a>';
        $output[$soc->uri] = [
          'soc_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($soc->uri).'">'.Utils::namespaceUri($soc->uri).'</a>'),
          'soc_reference' => $soc->virtualColumn->socreference,
          'soc_label' => $soc->label,
          'soc_grounding_label' => $soc->virtualColumn->groundingLabel,
          'soc_operations' => t($button),
        ];
      }
    }

    # PUT FORM TOGETHER
    $form['scope'] = [
      '#type' => 'item',
      '#title' => t('<h3>'.ucfirst($preferred_study).' Object Collections (SOCs) of '.ucfirst($preferred_study).' <font color="DarkGreen">' . $this->getStudy()->label . '</font></h3>'),
    ];
    $form['subtitle'] = [
      '#type' => 'item',
      '#title' => t('<h4>SOCs maintained by <font color="DarkGreen">' . $username . ' (' . $uemail . ')</font></h4>'),
    ];
    if ($this->getStudy() != NULL) {
      $form['add_soc'] = [
        '#type' => 'submit',
        '#value' => $this->t("Add SOC"),
        '#name' => 'add_soc',
        '#attributes' => [
          'class' => ['btn', 'btn-primary', 'add-element-button'],
        ],
      ];
    }
    $form['edit_soc'] = [
      '#type' => 'submit',
      '#value' => $this->t("Edit SOC"),
      '#name' => 'edit_soc',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'edit-element-button'],
      ],
    ];
    $form['delete_soc'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete Selected'),
      '#name' => 'delete_socs',
      '#attributes' => [
        'onclick' => 'if(!confirm("Really Delete?")){return false;}',
        'class' => ['btn', 'btn-primary', 'delete-button'],
      ],
    ];
    $form['soc_table'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $output,
      '#js_select' => FALSE,
      '#empty' => t('No response options found'),
    ];
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back to Studies'),
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

    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';

    // RETRIEVE TRIGGERING BUTTON
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    // RETRIEVE SELECTED ROWS, IF ANY
    $selected_rows = $form_state->getValue('soc_table');
    $rows = [];
    foreach ($selected_rows as $index => $selected) {
      if ($selected) {
        $rows[$index] = $index;
      }
    }

    // ADD SOC
    if ($button_name === 'add_soc') {
      $url = Url::fromRoute('std.add_studyobjectcollection');
      $url->setRouteParameter('studyuri', base64_encode($this->getStudy()->uri));
      $form_state->setRedirectUrl($url);
    }

    // EDIT SOC
    if ($button_name === 'edit_soc') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addWarning(t("Select a SOC to be edited."));
      } else if (sizeof($rows) > 1) {
        \Drupal::messenger()->addWarning(t("Select one SOC to be edited. No more than one SOC can be edited at once."));
      } else {
        $first = array_shift($rows);
        $url = Url::fromRoute('std.edit_studyobjectcollection');
        $url->setRouteParameter('studyobjectcollectionuri', base64_encode($first));
        $form_state->setRedirectUrl($url);
      }
      return;
    }

    // DELETE SOC
    if ($button_name === 'delete_soc') {
      if (sizeof($rows) < 1) {
        \Drupal::messenger()->addMessage(t("Select SOCs to be deleted."));
        return;
      } else {
        $api = \Drupal::service('rep.api_connector');
        //dpm($rows);
        foreach($rows as $shortUri) {
          $uri = Utils::plainUri($shortUri);
          try {
            $api->studyObjectCollectionDel($uri);
          } catch(\Exception $e) {
            \Drupal::messenger()->addError(t("An error occurred while deleting a ".ucfirst($preferred_study)." Object Collection: ".$e->getMessage()));
            $url = Url::fromRoute('std.manage_studyobjectcollection', ['studyuri' => base64_encode($this->getStudy()->uri)]);
            $form_state->setRedirectUrl($url);
            return;
          }
        }
        \Drupal::messenger()->addMessage(t("SOC(s) has been deleted successfully."));
        $url = Url::fromRoute('std.manage_studyobjectcollection');
        $url->setRouteParameter('studyuri', base64_encode($this->getStudy()->uri));
        $form_state->setRedirectUrl($url);
        return;
      }
    }

    // BACK TO MAIN PAGE
    if ($button_name === 'back') {
      $form_state->setRedirectUrl(Utils::selectBackUrl('study'));
    }
  }

}
