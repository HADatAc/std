<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\std\Controller\JsonDataController;
use Drupal\Core\Render\Markup;

class ManageStudyForm extends FormBase
{

  protected $studyUri;

  protected $study;

  public function getStudyUri()
  {
    return $this->studyUri;
  }

  public function setStudyUri($uri)
  {
    return $this->studyUri = $uri;
  }

  public function getStudy()
  {
    return $this->study;
  }

  public function setStudy($sem)
  {
    return $this->study = $sem;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'manage_study_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL)
  {

    //if ($studyuri == NULL || $studyuri == "") {
    //  \Drupal::messenger()->addMessage(t("A STUDY URI is required to manage a study."));
    //  $form_state->setRedirectUrl(Utils::selectBackUrl('study'));
    //}

    $uri_decode = base64_decode($studyuri);
    $this->setStudyUri($uri_decode);
    $api = \Drupal::service('rep.api_connector');
    $study = $api->parseObjectResponse($api->getUri($uri_decode), 'getUri');

    if ($study == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve Study."));
      self::backUrl();
    } else {
      $this->setStudy($study);
    }

    //dpr($this->getStudy()->uri);

    // get totals for current study
    $totalDAs = self::extractValue($api->parseObjectResponse($api->getTotalStudyDAs($this->getStudy()->uri), 'getTotalStudyDAs'));
    //$totalPUBs = self::extractValue($api->parseObjectResponse($api->getTotalStudyPUBs($this->getStudy()->uri), 'getTotalStudyPUBs'));    
    $totalSTREAMs = self::extractValue($api->parseObjectResponse($api->getTotalStudySTRs($this->getStudy()->uri), 'getTotalStudySTRs'));
    $totalSTRs = self::extractValue($api->parseObjectResponse($api->listSizeByManagerEmailByStudy($this->getStudy()->uri, 'str', $this->getStudy()->hasSIRManagerEmail), 'getTotalStudySTRRs'));
    $totalRoles = self::extractValue($api->parseObjectResponse($api->getTotalStudyRoles($this->getStudy()->uri), 'getTotalStudyRoles'));
    $totalVCs = self::extractValue($api->parseObjectResponse($api->getTotalStudyVCs($this->getStudy()->uri), 'getTotalStudyVCs'));
    $totalSOCs = self::extractValue($api->parseObjectResponse($api->getTotalStudySOCs($this->getStudy()->uri), 'getTotalStudySOCs'));
    $totalSOs = self::extractValue($api->parseObjectResponse($api->getTotalStudySOs($this->getStudy()->uri), 'getTotalStudySOs'));

    // Example data for cards
    $cards = array(
      1 => array(
        'value' => 'Study Content (0)',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'da')
      ),
      2 => array('value' => 'Data Files (' . $totalDAs . ')'),
      3 => array('value' => 'Publications (0)'),
      4 => array('value' => 'Media (0)'),
      5 => array('value' => '<h3>Other Content (0)</h3>'),
      6 => array(
        'value' => '<h1>' . $totalSTREAMs . '</h1><h3>Streams<br>&nbsp;</h3>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'stream',),
      ),
      7 => array(
        'value' => '<h1>' . $totalSTRs . '</h1><h3>STR<br>&nbsp;</h3>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'str',),
      ),
      8 => array(
        'value' => '<h1>' . $totalRoles . '</h1><h3>Roles<br>&nbsp;</h3>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'studyrole')
      ),
      9 => array(
        'value' => '<h1>' . $totalVCs . '</h1><h3>Virtual Columns</h3><h4>(Entities)</h4>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'virtualcolumn')
      ),
      10 => array(
        'value' => '<h1>' . $totalSOCs . '</h1><h3>Object Collections</h3><h4>(' . $totalSOs . ' Objects)</h4>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'studyobjectcollection')
      ),
      //10 => array('value' => '<h1>'.$totalSOs.'</h1><h3>Objects<br>&nbsp;</h3>',
      //           'link' => self::urlSelectByStudy($this->getStudy()->uri,'studyobject')),
    );

    // First row with 1 filler and 1 card
    $form['row1'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row')),
    );

    // Define each card individually
    //$form['row1']['filler'] = array(
    //  '#type' => 'container',
    //  '#attributes' => array('class' => array('col-md-1')),
    //);

    $piName = ' ';
    if (
      isset($this->getStudy()->pi) &&
      $this->getStudy()->pi != NULL &&
      $this->getStudy()->pi->name != NULL
    ) {
      $piName = $this->getStudy()->pi->name;
    }

    $institutionName = ' ';
    if (
      isset($this->getStudy()->institution) &&
      $this->getStudy()->institution != NULL &&
      $this->getStudy()->institution->name != NULL
    ) {
      $institutionName = $this->getStudy()->institution->name;
    }

    $title = ' ';
    if (
      isset($this->getStudy()->title) &&
      $this->getStudy()->title != NULL
    ) {
      $title = $this->getStudy()->title;
    }

    //Libraries
    $form['#attached']['library'][] = 'core/drupal.autocomplete';
    $form['#attached']['library'][] = 'std/pdfjs';

    //MODAL
    $form['row0']['modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div id="modal-container" class="modal-media hidden">
          <div class="modal-content">
            <button class="close-btn" type="button">&times;</button>
            <div id="pdf-scroll-container"></div>
            <div id="modal-content"></div>
          </div>
          <div class="modal-backdrop"></div>
        </div>
      '),
    ];

    // First row with a single card
    $form['row1']['card0'] = array(
      //'#type' => 'container',
      //'#attributes' => array('class' => array('row')),
      //'card1' => array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col-md-12')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<br><div class="card"><div class="card-body">' .
          $this->t('<h3>') . ' ' . $this->getStudy()->label . '</h3><br>' .
          $this->t('<b>URI</b>: ') . ' ' . $this->getStudy()->uri . '<br>' .
          $this->t('<b>Name</b>: ') . ' ' . $title . '<br>' .
          $this->t('<b>PI</b>: ') . ' ' . $piName . '<br>' .
          $this->t('<b>Institution</b>: ') . ' ' . $institutionName . '<br>' .
          $this->t('<b>Description</b>: ') . ' ' . $this->getStudy()->comment . '<br>' .
          '</div></div>',
      ),
      //),
    );

    // Obtenha o valor da sessão para fallback
    $session = \Drupal::service('session');
    $da_page_from_session = $session->get('da_current_page', 1);
    $pub_page_from_session = $session->get('pub_current_page', 1);
    $media_page_from_session = $session->get('media_current_page', 1);

    // Second row with 1 outter card (card 1)
    $form['row2'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row')),
    );

    // Inner row of second row with 4 cards (cards 2 to 5)
    $form['row2']['card1']['inner_row2'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row', 'm-3')),
    );

    //DA TABLE JQUERY
    $form['row2']['card1']['inner_row2']['card2'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col-md-6')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card">
          <div class="card-header text-center"><h3 id="data_files_count">' . $cards[2]['value'] . '</h3></div>' .
          '<div class="card-body">' .
          '<div id="json-table-container">Loading...</div>' .
          '</div>' .
          '<div class="card-footer">' .
          '<div id="json-table-pager" class="pagination"></div>' .
          '</div>
          </div>',
      ),
    );

    // Row 2, Card 3, Publication content
    $form['row2']['card1']['inner_row2']['card3'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col-md-3')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card">' .
          '<div class="card-header text-center"><h3 id="publication_files_count">' . $cards[3]['value'] . '</h3></div>' .
          '<div class="card-body">
             <div id="publication-table-container"></div>
           </div>
           <div class="card-footer">
             <div id="publication-table-pager" class="pagination"></div>
           </div>' .
          '</div>',
      ),
    );

    // Row 2, Card 4, Media content
    $form['row2']['card1']['inner_row2']['card4'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col-md-3')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card">' .
          '<div class="card-header text-center"><h3 id="media_files_count">' . $cards[4]['value'] . '</h3></div>' .
          '<div class="card-body">
             <div id="media-table-container"></div>
           </div>
           <div class="card-footer">
             <div id="media-table-pager" class="pagination"></div>
           </div>' .
          '</div>',
      ),
    );

    // Row 2, Card 5, Other contents
    //$form['row2']['card1']['inner_row2']['card5'] = array(
    //  '#type' => 'container',
    //  '#attributes' => array('class' => array('col-md-3')),
    //  'card' => array(
    //    '#type' => 'markup',
    //    '#markup' => '<div class="card">' . 
    //      '<div class="card-header text-center">' . $cards[5]['value'] . '</div>' .
    //      '<div class="card-body">' . 'Foo' . '</div>' .
    //      '</div>',
    //  ),
    //);

    $uid = \Drupal::currentUser()->id();

    $previousUrl = Url::fromRoute('std.manage_study_elements', [
      'studyuri' => base64_encode($this->getStudy()->uri),
    ])->toString();
    Utils::trackingStoreUrls($uid, $previousUrl, 'std.manage_study_elements');

    $url = Url::fromRoute('rep.add_mt', [
      'elementtype' => 'da',
      'studyuri' => base64_encode($this->getStudy()->uri),
      'fixstd' => 'T',
    ])->toString();

    //Toas Message
    $form['row1']['toast'] = array(
      '#type' => 'markup',
      '#attributes' => array('style="position: fixed; top: 10px; right: 10px; z-index: 1050;"'),
      '#markup' => '<div id="toast-container"></div>',
    );

    //Row 2, Outter card 1
    $form['row2']['card1'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col-md-12')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card">' .
          '<div class="card drop-area" id="drop-card">' .
          '<div class="card-header text-center"><h3 id="total_elements_count">' . $cards[1]['value'] . '</h3>' .
          '<div class="info-card">You can drag&drop files directly into this card</div>' .
          '</div>' .
          \Drupal::service('renderer')->render($form['row2']['card1']['inner_row2']) .
          //'<div class="card-footer text-center"><a href="' . $url . '" class="btn btn-secondary"><i class="fa-solid fa-list-check"></i>Add new content</a></div>' . 
          '</div>' .
          '</div>',
      ),
      '#attached' => [
        'library' => [
          'std/json_table',
          'core/drupal.autocomplete',
        ],
        'drupalSettings' => [
          'std' => [
            'studyuri' => base64_encode($this->getStudy()->uri),
            'elementtype' => 'da',
            'mode' => 'compact',
            'page' => $da_page_from_session,
            'pagesize' => 5,
          ],
          'pub' => [
            'studyuri' => base64_encode($this->getStudy()->uri),
            'elementtype' => 'publications',
            'page' => $pub_page_from_session,
            'pagesize' => 5,
          ],
          'media' => [
            'studyuri' => base64_encode($this->getStudy()->uri),
            'elementtype' => 'media',
            'page' => $media_page_from_session,
            'pagesize' => 5,
          ],
          'addNewDA' => [
            'url' => Url::fromRoute('std.render_add_da_form', [
              'elementtype' => 'da',
              'studyuri' => base64_encode($this->getStudy()->uri),
            ])->toString(),
          ],
        ],
      ],
    );

    // Third row with 5 cards (card 6 to card 10)
    $form['row3'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row row-cols-5')),
    );

    // Row 3, Card 6
    $form['row3']['card6'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card"><div class="card-body text-center">' . $cards[6]['value'] . '</div>' .
          '<div class="card-footer text-center">' .
          '<a href="' . $cards[6]['link'] . '" class="btn btn-secondary me-2"><i class="fa-solid fa-list-check"></i> Manage Streams</a>' .
          '</div></div>',
      ),
    );

    // Row 3, Card 7
    $form['row3']['card7'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card"><div class="card-body text-center">' . $cards[7]['value'] . '</div>' .
          '<div class="card-footer text-center">' .
          '<a href="' . $cards[7]['link'] . '" class="btn btn-secondary"><i class="fa-solid fa-list-check"></i> Manage STRs</a>' .
          '</div></div>',
      ),
    );

    // Row 3, Card 8
    $form['row3']['card8'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card"><div class="card-body text-center">' . $cards[8]['value'] . '</div>' .
          '<div class="card-footer text-center"><a href="' . $cards[8]['link'] . '" class="btn btn-secondary disabled"><i class="fa-solid fa-list-check"></i> Manage Roles</a></div></div>',
      ),
    );

    // Row 3, Card 9
    $form['row3']['card9'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card"><div class="card-body text-center">' . $cards[9]['value'] . '</div>' .
          '<div class="card-footer text-center"><a href="' . $cards[9]['link'] . '" class="btn btn-secondary"><i class="fa-solid fa-list-check"></i> Manage Virtual Columns</a></div></div>',
      ),
    );

    // Row 3, Card 10
    $form['row3']['card10'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('col')),
      'card' => array(
        '#type' => 'markup',
        '#markup' => '<div class="card"><div class="card-body text-center">' . $cards[10]['value'] . '</div>' .
          '<div class="card-footer text-center"><a href="' . $cards[10]['link'] . '" class="btn btn-secondary"><i class="fa-solid fa-list-check"></i> Manage Object Collections</a></div></div>',
      ),
    );

    // Bottom part of the form
    $form['row4'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row')),
      '#type' => 'markup',
      '#markup' => '<p><b>Note</b>: Data Dictionaires (DD) and Semantic Data Dictionaires (SDD) are added' .
        ' to studies through their corresponding data files.</p><br>',
    );

    $form['row5'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row')),
    );

    $form['row6']['back_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back to Manage Studies'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['col-md-2', 'btn', 'btn-primary', 'back-button'],
      ],
    ];

    $form['row7']['space'] = [
      '#type' => 'item',
      '#value' => $this->t('<br><br><br><br>'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {}

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];

    if ($button_name === 'back') {
      self::backUrl();
    }
  }

  public function extractValue($jsonString)
  {
    $data = json_decode($jsonString, true); // Decodes JSON string into associative array
    if (isset($data['total'])) {
      return $data['total'];
    }
    return -1;
  }

  /**
   * {@inheritdoc}
   */
  public static function urlSelectByStudy($studyuri, $elementType)
  {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = \Drupal::request()->getRequestUri();
    Utils::trackingStoreUrls($uid, $previousUrl, 'std.select_element_bystudy');
    $url = Url::fromRoute('std.select_element_bystudy');
    if ($elementType == 'da') {
      $url->setRouteParameter('mode', 'card');
    } else {
      $url->setRouteParameter('mode', 'table');
    }
    $url->setRouteParameter('studyuri', base64_encode($studyuri));
    $url->setRouteParameter('elementtype', $elementType);
    $url->setRouteParameter('page', 1);
    $url->setRouteParameter('pagesize', 12);
    return $url->toString();
  }

  function backUrl()
  {
    $uid = \Drupal::currentUser()->id();
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.manage_study_elements');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }
}
