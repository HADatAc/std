<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Entity\Stream;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\std\Controller\JsonDataController;
use Drupal\Core\Render\Markup;

use function Termwind\style;

class ManageStudyForm extends FormBase
{

  protected $studyUri;

  protected $study;

  protected $streamList;

  public function getStreamList()
  {
    return $this->streamList;
  }
  public function setStreamList($list)
  {
    return $this->streamList = $list;
  }

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


    // Owner of the record
    $useremail = \Drupal::currentUser()->getEmail();

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

    // get totals for current study
    //Dá erro 404, $totalDAs = self::extractValue($api->parseObjectResponse($api->getTotalStudyDAs($this->getStudy()->uri), 'getTotalStudyDAs'));
    // $totalPUBs = self::extractValue($api->parseObjectResponse($api->getTotalStudyPUBs($this->getStudy()->uri), 'getTotalStudyPUBs'));
    $totalSTREAMs = self::extractValue($api->parseObjectResponse($api->streamSizeByStudyState($this->getStudy()->uri, HASCO::ACTIVE), 'streamSizeByStudyState'));
    $totalSTRs = self::extractValue($api->parseObjectResponse($api->listSizeByManagerEmailByStudy($this->getStudy()->uri, 'str', $this->getStudy()->hasSIRManagerEmail), 'getTotalStudySTRRs'));
    $totalRoles = self::extractValue($api->parseObjectResponse($api->getTotalStudyRoles($this->getStudy()->uri), 'getTotalStudyRoles'));
    $totalVCs = self::extractValue($api->parseObjectResponse($api->getTotalStudyVCs($this->getStudy()->uri), 'getTotalStudyVCs'));
    $totalSOCs = self::extractValue($api->parseObjectResponse($api->getTotalStudySOCs($this->getStudy()->uri), 'getTotalStudySOCs'));
    $totalSOs = self::extractValue($api->parseObjectResponse($api->getTotalStudySOs($this->getStudy()->uri), 'getTotalStudySOs'));

    // DEBBUG
    // kint([
    //   'studyUri' => $this->getStudy()->uri,
    //   'stateUri' => HASCO::ALL_STATUSES,
    //   'resultAPI' => $api->parseObjectResponse($api->streamByStudyState($this->getStudy()->uri,HASCO::ALL_STATUSES,99,0), 'streamByStudyState'),
    //   'totalSTREAMs' => $totalSTREAMs,
    // ]);
    $this->setStreamList($api->parseObjectResponse($api->streamByStudyState($this->getStudy()->uri,HASCO::ACTIVE,9999,0), 'streamByStudyState'));

    // Example data for cards
    $cards = array(
      1 => array(
        'value' => 'Study Content',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'da')
      ),
      2 => array('value' => 'Stream Data Files (' . ($totalDAs ?? 0) . ')'),
      3 => array('value' => 'Publications'),
      4 => array('value' => 'Media'),
      5 => array('value' => '<h3>Other Content (0)</h3>'),
      6 => array(
        'head' => 'Streams (' . $totalSTREAMs . ')',
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
      11 => array('value' => 'Message Stream'),
      12 => array('value' => 'Unassociated Data Files'),
      //10 => array('value' => '<h1>'.$totalSOs.'</h1><h3>Objects<br>&nbsp;</h3>',
      //           'link' => self::urlSelectByStudy($this->getStudy()->uri,'studyobject')),
    );

    // First row with 1 filler and 1 card
    $form['row1'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row')),
    );

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
    // $form['#attached']['library'][] = 'core/drupal.autocomplete';
    $form['#attached']['library'][] = 'rep/pdfjs';
    $form['#attached']['library'][] = 'rep/webdoc_modal';
    $base_url = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['drupalSettings']['webdoc_modal'] = [
      'baseUrl' => $base_url,
    ];


    // Attach our JS behavior + settings.
    $form['#attached']['library'][] = 'dpl/stream_selection';
    $form['#attached']['drupalSettings']['dpl'] = [
      'studyUri' => base64_encode($this->studyUri),
      'streamDataUrl' => Url::fromRoute('std.stream_data_ajax')->toString(),
      'ajaxUrl'  => Url::fromRoute('std.stream_data_ajax')->toString(),
    ];
    $form['#attached']['drupalSettings']['dpl']['fileIngestUrl']   = Url::fromRoute('dpl.file_ingest_ajax')->toString();
    $form['#attached']['drupalSettings']['dpl']['fileUningestUrl'] = Url::fromRoute('dpl.file_uningest_ajax')->toString();


    //MODAL
    $form['modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div id="modal-container" class="modal-media hidden" style="position:absolute; top:50px; left:0; width:100%; height:100%;">
          <div class="modal-content" style="z-index:99999 !important;">
            <button class="close-btn" type="button">&times;</button>
            <div id="pdf-scroll-container"></div>
            <div id="modal-content" style="height:100vh;"></div>
          </div>
          <div class="modal-backdrop"></div>
        </div>
      '),
      '#weight' => '-99999',
    ];

    // First row with a single card
    $form['row1']['card0']['card'] = [
      '#type'       => 'markup',
      '#markup'     => Markup::create('
        <div class="card"><div class="card-body" style="justify-content:normal!important;">
          <h3 class="mb-5 mt-3">' . $this->getStudy()->label . '</h3>
          <dl class="row">
            <dt class="col-sm-1">' . $this->t('URI')        . ':</dt><dd class="col-sm-11">' . $this->getStudy()->uri     . '</dd>
            <dt class="col-sm-1">' . $this->t('Name')       . ':</dt><dd class="col-sm-11">' . $title                       . '</dd>
            <dt class="col-sm-1">' . $this->t('PI')         . ':</dt><dd class="col-sm-11">' . $piName                      . '</dd>
            <dt class="col-sm-1">' . $this->t('Institution'). ':</dt><dd class="col-sm-11">' . $institutionName             . '</dd>
            <dt class="col-sm-1">' . $this->t('Description'). ':</dt><dd class="col-sm-11">' . $this->getStudy()->comment   . '</dd>
          </dl>
        </div></div>
      '),
    ];

    // Obtenha o valor da sessão para fallback
    $session = \Drupal::service('session');
    $da_page_from_session = $session->get('da_current_page', 1);
    $pub_page_from_session = $session->get('pub_current_page', 1);
    $media_page_from_session = $session->get('media_current_page', 1);

    $uid = \Drupal::currentUser()->id();

    $previousUrl = Url::fromRoute('std.manage_study_elements', [
      'studyuri' => base64_encode($this->getStudy()->uri),
    ])->toString();
    Utils::trackingStoreUrls($uid, $previousUrl, 'std.manage_study_elements');

    // Check if the current user is the owner (hasSIRManagerEmail is assumed to be defined previously).
    if ($this->getStudy()->hasSIRManagerEmail === $useremail) {
      // User is the owner: enable drag & drop functionality.
      // $markup = '<div class="card drop-area" id="drop-card">' .
      //           ' <div class="card-header text-center"><h3 id="total_elements_count">' . $cards[1]['value'] . '</h3>' .
      //           '   <div class="info-card">You can drag&drop files directly into this card</div>
      //             </div>' .
      //             \Drupal::service('renderer')->render($form['row2']['card1']['inner_row']);
      $markup = '<div class="card drop-area" id="drop-card" style="position: relative;">'
        . '  <div class="card-header text-center" style="position: relative;">'
        . '    <h3 id="total_elements_count">' . $cards[1]['value'] . '</h3>'
        . '    <div class="info-card">You can drag&drop files directly into this card</div>'
        . '    <div id="toast-container" '
        . '         style="position: absolute; top: 0.5rem; right: 1rem; z-index: 1050;">'
        . '    </div>'
        .     \Drupal::service('renderer')->render($form['row2']['card1']['inner_row'])
        . '</div>';
    }
    else {
      // User is not the owner: disable drag & drop functionality.
      $markup = '<div class="card" id="drop-card-disabled">' .
                '<div class="card-header text-center"><h3 id="total_elements_count">' . $cards[1]['value'] . '</h3>' .
                '<div class="info-card">Only the owner can drag&drop files</div></div>'
                . '    <div id="toast-container" '
                . '         style="position: absolute; top: 0.5rem; right: 1rem; z-index: 1050;">'
                . '    </div>'
                .     \Drupal::service('renderer')->render($form['row2']['card1']['inner_row']);
    }

    // Second row with 1 outter card (card 1)
    $form['row2'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row', 'mb-3')),
    );

    $form['row2']['card1'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-12']],
      'card' => [
        '#type' => 'markup',
        '#markup' => $markup,
      ],
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
          'user' => [
            'logged' => ($this->getStudy()->hasSIRManagerEmail === $useremail ? TRUE:FALSE),
          ],
        ],
      ],
    ];

    // Inner row of second row with 4 cards (cards 2 to 5)
    $form['row2']['card1']['inner_row'] = array(
      '#type' => 'container',
      '#attributes' => array(
        'class' => array('row', 'm-3'),
        'style' => 'margin-bottom:25px!important;',
      ),
    );

    // Row 3, Card 6

    $header = Stream::generateHeaderStudy();
    $output = Stream::generateOutputStudy($this->getStreamList());

    $form['row2']['card1']['inner_row']['card6'] = [
      '#type'       => 'container',
      '#attributes' => [
        'class' => ['col-md-12'],
      ],
      // '#prefix'     => '<div class="card">',
      // '#suffix'     => '</div>',
    ];

    $form['row2']['card1']['inner_row']['card6']['card'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['col-md-12', 'mb-4']],
      '#prefix'     => '<div class="card">',
      '#suffix'     => '</div>',
    ];

    // 3) Header
    $form['row2']['card1']['inner_row']['card6']['card']['card_header'] = [
      '#type'   => 'markup',
      '#markup' => '<div class="card-header text-center">'
        . '<h3 id="stream_files_count">' . $cards[6]['head'] . '</h3>'
        . '</div>',
    ];

    // 4) Body + tabela
    $form['row2']['card1']['inner_row']['card6']['card']['card_body'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['card-body', 'p-']],
    ];
    $form['row2']['card1']['inner_row']['card6']['card']['card_body']['element_table'] = [
      '#type'          => 'tableselect',
      '#header'        => $header,
      '#options'          => $output,
      // '#default_value' => [],
      '#empty'         => t('No stream has been found'),
      '#attributes' => [
        'id' => 'dpl-streams-table',
      ],
      // forca seleção única (radio) — ajuste se quiser usar checkbox:
      '#multiple'   => FALSE,
    ];

    // 5) Footer
    $form['row2']['card1']['inner_row']['card6']['card']['card_footer'] = [
      '#type'   => 'markup',
      '#markup' => '<div class="card-footer text-center">'
        . '<div id="json-table-pager" class="pagination"></div>'
        . '</div>',
    ];

    //DA TABLE JQUERY
    /**
      * 1) AJAX-loaded cards (Stream Data Files + Message Stream)
      *    → Full-width (col-md-12), then an inner row with two col-md-6 cards.
      */
    $form['row2']['card1']['inner_row']['ajax_cards_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-12', 'mb-4'],  // span entire width, with bottom margin
      ],
    ];
    // inner row for the two AJAX cards
    $form['row2']['card1']['inner_row']['ajax_cards_container']['ajax_row'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row'],
      ],
    ];
    // Stream Data Files card (left half)
    $form['row2']['card1']['inner_row']['ajax_cards_container']['ajax_row']['stream_data_files'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-6'],        // half width of the parent row
        'id'    => 'stream-data-files-container',
        'style' => 'display:none;',     // hidden until AJAX kicks in
      ],
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3 id="data-files-count">Stream Data Files</h3>
            </div>
            <div class="card-body">
              <div id="data-files-table">Loading…</div>
            </div>
            <div class="card-footer text-center">
              <div id="data-files-pager" class="pagination"></div>
            </div>
          </div>
        ',
      ],
    ];
    // Message Stream card (right half)
    $form['row2']['card1']['inner_row']['ajax_cards_container']['ajax_row']['message_stream'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-6'],        // half width of the parent row
        'id'    => 'message-stream-container',
        'style' => 'display:none;',
      ],
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3 id="message-stream-count">Message Stream</h3>
            </div>
            <div class="card-body">
              <div id="message-stream-table">No messages for this stream.</div>
            </div>
            <div class="card-footer text-center">
              <div id="message-stream-pager" class="pagination"></div>
            </div>
          </div>
        ',
      ],
    ];

    /**
      * 2) Fixed cards (Study Data Files, Publications, Media)
      *    → Another full-width wrapper, then an inner row with three col-md-4 cards.
      */
    $form['row2']['card1']['inner_row']['fixed_cards_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-12', 'mt-3'],
        'style' => 'border-top: 5px dashed rgb(168, 168, 168)', // spacing below
      ],
    ];
    // inner row for the three fixed cards
    $form['row2']['card1']['inner_row']['fixed_cards_container']['fixed_row'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row','align-items-start'],
      ],
    ];
    // Study Data Files (one-third width)
    $form['row2']['card1']['inner_row']['fixed_cards_container']['fixed_row']['study_data_files'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-4'],
      ],
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3>' . $cards[12]['value'] . '</h3>
            </div>
            <div class="card-body">
              <div id="json-table-container">Loading…</div>
            </div>
            <div class="card-footer text-center">
              <div id="json-table-pager" class="pagination"></div>
            </div>
          </div>
        ',
      ],
    ];
    // Publications (one-third width)
    $form['row2']['card1']['inner_row']['fixed_cards_container']['fixed_row']['publications'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-4'], // one-third + spacing above
      ],
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3>' . $cards[3]['value'] . '</h3>
            </div>
            <div class="card-body">
              <div id="publication-table-container">Loading…</div>
            </div>
            <div class="card-footer text-center">
              <div id="publication-table-pager" class="pagination"></div>
            </div>
          </div>
        ',
      ],
    ];
    // Media (one-third width)
    $form['row2']['card1']['inner_row']['fixed_cards_container']['fixed_row']['media'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-4'], // one-third + spacing above
      ],
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3>' . $cards[4]['value']. '</h3>
            </div>
            <div class="card-body">
              <div id="media-table-container">Loading…</div>
            </div>
            <div class="card-footer text-center">
              <div id="media-table-pager" class="pagination"></div>
            </div>
          </div>
        ',
      ],
    ];


    // Third row with 5 cards (card 6 to card 10)
    $form['row3']['row3_wrapper'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-12'],  // spans the same 12-col width
      ],
    ];

    // 2) Inside that wrapper we open the real Bootstrap row—now its gutters line up
    $form['row3']['row3_wrapper']['row3'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'row-cols-4', 'g-3'],
        // row-cols-4: 4 equal columns
        // g-3: standard gutter spacing
      ],
    ];

    // 3) Now each card is just one of those 4 columns:

    // Card 7: STR
    $form['row3']['row3_wrapper']['row3']['card7'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col']],     // `col` is fine inside row-cols-4
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card h-100 text-center">
            <div class="card-body">
              <h1>' . $cards[7]['value'] . '</h1>
              <p>STR</p>
            </div>
            <div class="card-footer">
              <a href="' . $cards[7]['link'] . '" class="btn btn-primary">
                <i class="fa-solid fa-list-check"></i> Manage STRs
              </a>
            </div>
          </div>
        ',
      ],
    ];

    // Card 8: Roles
    $form['row3']['row3_wrapper']['row3']['card8'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card h-100 text-center">
            <div class="card-body">
              <h1>' . $cards[8]['value'] . '</h1>
              <p>Roles</p>
            </div>
            <div class="card-footer">
              <a href="' . $cards[8]['link'] . '" class="btn btn-secondary disabled">
                <i class="fa-solid fa-list-check"></i> Manage Roles
              </a>
            </div>
          </div>
        ',
      ],
    ];

    // Card 9: Virtual Columns
    $form['row3']['row3_wrapper']['row3']['card9'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card h-100 text-center">
            <div class="card-body">
              <h1>' . $cards[9]['value'] . '</h1>
              <p>Virtual Columns<br><small>(Entities)</small></p>
            </div>
            <div class="card-footer">
              <a href="' . $cards[9]['link'] . '" class="btn btn-primary">
                <i class="fa-solid fa-list-check"></i> Manage Virtual Columns
              </a>
            </div>
          </div>
        ',
      ],
    ];

    // Card 10: Object Collections
    $form['row3']['row3_wrapper']['row3']['card10'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col']],
      'card' => [
        '#type' => 'markup',
        '#markup' => '
          <div class="card h-100 text-center">
            <div class="card-body">
              <h1>' . $cards[10]['value'] . '</h1>
              <p>Object Collections<br><small>(' . ($cards[10]['value_objects'] ?? '0') . ' Objects)</small></p>
            </div>
            <div class="card-footer">
              <a href="' . $cards[10]['link'] . '" class="btn btn-primary">
                <i class="fa-solid fa-list-check"></i> Manage Object Collections
              </a>
            </div>
          </div>
        ',
      ],
    ];

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

    $form['back_link'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back to Manage Studies'),
      '#url' => Url::fromUri('internal:/'),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['col-md-1', 'btn', 'btn-primary', 'back-button'],
        'style' => 'min-width: 220px;max-height:38px!important;',
        'onclick' => 'window.history.back(); return false;',
      ],
    ];

    $form['row7']['space'] = [
      '#type' => 'markup',
      '#attributes' => array('class' => array('col-md-1')),
      '#markup' => '<br><br><br><br>',
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
    $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.list_element');
    if ($previousUrl) {
      $response = new RedirectResponse($previousUrl);
      $response->send();
      return;
    }
  }
}
