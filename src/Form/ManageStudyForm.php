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
use Drupal\dpl\Controller\StreamController;
use Drupal\Core\Render\Markup;

use function Termwind\style;

class ManageStudyForm extends FormBase
{

  Const CONFIGNAME = "rep.settings";

  protected $studyUri;

  protected $study;

  protected $streamList;

  protected $outStreamList;

  public function getStreamList()
  {
    return $this->streamList;
  }
  public function setStreamList($list)
  {
    return $this->streamList = $list;
  }

  public function getOutStreamList()
  {
    return $this->outStreamList;
  }
  public function setSOutStreamList($list)
  {
    return $this->outStreamList = $list;
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

  protected function getEditableConfigNames() {
        return [
            static::CONFIGNAME,
        ];
    }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL)
  {

    $config = $this->config(static::CONFIGNAME);

    //Libraries
    $form['#attached']['library'][] = 'std/json_table';
    $form['#attached']['library'][] = 'core/drupal.autocomplete';
    $form['#attached']['library'][] = 'rep/pdfjs';
    $form['#attached']['library'][] = 'rep/webdoc_modal';
    $form['#attached']['library'][] = 'std/stream_selection';
    $form['#attached']['library'][] = 'dpl/stream_recorder';
    $base_url = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['drupalSettings']['webdoc_modal'] = [
      'baseUrl' => $base_url,
    ];

    // Owner of the record
    $useremail = \Drupal::currentUser()->getEmail();

    if ($studyuri == NULL || $studyuri == "") {
     \Drupal::messenger()->addMessage(t("A STUDY URI is required to manage a study."));
     $form_state->setRedirectUrl(Utils::selectBackUrl('study'));
    }

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

	// ROW CONTENT
    $session = \Drupal::service('session');
    $da_page_from_session = $session->get('da_current_page', 1);
    $pub_page_from_session = $session->get('pub_current_page', 1);
    $media_page_from_session = $session->get('media_current_page', 1);

    // Settings para AJAX das tabelas
    $form['#attached']['drupalSettings']['pub'] = [
      'studyuri'   => rawurlencode($this->studyUri),
      'elementtype'=> 'publications',
      'page'       => $pub_page_from_session,
      'pagesize'   => 5,
    ];
    $form['#attached']['drupalSettings']['media'] = [
      'studyuri'   => rawurlencode($this->studyUri),
      'elementtype'=> 'media',
      'page'       => $media_page_from_session,
      'pagesize'   => 5,
    ];
    $form['#attached']['drupalSettings']['std'] = [
      // —— stream/topic selection ——
      'studyuri'        => base64_encode($this->studyUri),
      'ajaxUrl'         => Url::fromRoute('std.stream_data_ajax')->toString(),
      'streamDataUrl'   => Url::fromRoute('std.stream_data_ajax')->toString(),
      'latestUrl'       => \Drupal::request()->getSchemeAndHttpHost()
                . \Drupal::request()->getBaseUrl()
                . '/dpl/streamtopic/latest_message/',
      'fileIngestUrl'   => Url::fromRoute('dpl.file_ingest_ajax')->toString(),
      'fileUningestUrl' => Url::fromRoute('dpl.file_uningest_ajax')->toString(),
      'elementtype'=> 'da',
      'mode'       => 'compact',
      'page'       => $da_page_from_session,
      'pagesize'   => 5,
    ];

    // get totals for current study
    //Dá erro 404, $totalDAs = self::extractValue($api->parseObjectResponse($api->getTotalStudyDAs($this->getStudy()->uri), 'getTotalStudyDAs'));
    // $totalPUBs = self::extractValue($api->parseObjectResponse($api->getTotalStudyPUBs($this->getStudy()->uri), 'getTotalStudyPUBs'));
    $totalSTREAMs = self::extractValue($api->parseObjectResponse($api->streamSizeByStudyState($this->getStudy()->uri, HASCO::ACTIVE), 'streamSizeByStudyState'));
    $totalOutSTREAMs = 0; // self::extractValue($api->parseObjectResponse($api->streamSizeByStudyState($this->getStudy()->uri, HASCO::ACTIVE), 'streamSizeByStudyState'));
    $totalSTRs = self::extractValue($api->parseObjectResponse($api->listSizeByManagerEmailByStudy($this->getStudy()->uri, 'str', $this->getStudy()->hasSIRManagerEmail), 'getTotalStudySTRRs'));
    $totalRoles = self::extractValue($api->parseObjectResponse($api->getTotalStudyRoles($this->getStudy()->uri), 'getTotalStudyRoles'));
    $totalVCs = self::extractValue($api->parseObjectResponse($api->getTotalStudyVCs($this->getStudy()->uri), 'getTotalStudyVCs'));
    $totalSOCs = self::extractValue($api->parseObjectResponse($api->getTotalStudySOCs($this->getStudy()->uri), 'getTotalStudySOCs'));
    $totalSOs = self::extractValue($api->parseObjectResponse($api->getTotalStudySOs($this->getStudy()->uri), 'getTotalStudySOs'));
    $totalPRCs = 0; // TODO

    // SET STREAM LIST
    $this->setStreamList($api->parseObjectResponse($api->streamByStudyState($this->getStudy()->uri,HASCO::ACTIVE,9999,0), 'streamByStudyState'));

    // TODO - SET OUT STREAM LIST
    $this->setSOutStreamList([]);

    // Example data for cards
    $cards = array(
      1 => array(
        'value' => 'Contents',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'da')
      ),
      2 => array('value' => 'Stream Data Files (' . ($totalDAs ?? 0) . ')'),
      3 => array('value' => 'Publications'),
      4 => array('value' => 'Media'),
      5 => array('value' => '<h3>Other Content (0)</h3>'),
      6 => array(
        'head' => 'Original Streams (' . $totalSTREAMs . ')',
        'value' => '<h1>' . $totalSTREAMs . '</h1><h3>Streams<br>&nbsp;</h3>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'stream',),
      ),
      7 => array(
        'value' => '<h1>' . $totalSTRs . '</h1><h3>STR<br>&nbsp;</h3>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'str',),
      ),
      10 => array(
        'value' => '<h1>' . $totalRoles . '</h1><h3>Roles<br>&nbsp;</h3>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'studyrole')
      ),
      9 => array(
        'value' => '<h1>' . $totalVCs . '</h1><h3>Entities</h3><h4>(Virtual Columns)</h4>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'virtualcolumn')
      ),
      8 => array(
        'value' => '<h1>' . $totalSOCs . '</h1><h3>Object Collections</h3><h4>(' . $totalSOs . ' Objects)</h4>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'studyobjectcollection')
      ),
      11 => array('value' => 'Message Stream'),
      12 => array('value' => 'Unassociated Data Files'),
      13 => array(
        'head' => 'Annotated Streams (' . $totalOutSTREAMs . ')',
        'value' => '<h1>' . $totalOutSTREAMs . '</h1><h3>Streams<br>&nbsp;</h3>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'stream',),
      ),
      14 => array(
        'value' => '<h1>' . $totalPRCs . '</h1><h3>Process<br>&nbsp;</h3>',
        'link' => self::urlSelectByStudy($this->getStudy()->uri, 'prc',),
      ),
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

    // ROW AREA INTERESTS
    $form['#attached']['library'][] = 'core/drupal.collapse';

    $form['row3'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['accordion', 'mt-3'], 'id' => 'accordionAreas'],
    ];

    $form['row3']['item'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['accordion-item', 'card', 'drop-area'],
        'id'    => 'areas-card',
      ],
    ];

    $form['row3']['item']['header'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['accordion-header', 'justify-content-between', 'align-items-center'],
        'id'    => 'headingAreas',
      ],
      'button' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('<h3 class="mb-0">Objects of Interest</h3>'),
        '#attributes' => [
          'class' => ['accordion-button', 'collapsed'],
          'type' => 'button',
          'data-bs-toggle' => 'collapse',
          'data-bs-target' => '#collapseAreas',
          'aria-expanded' => 'false',
          'aria-controls' => 'collapseAreas',
        ],
      ],
    ];

    $form['row3']['item']['collapse'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'collapseAreas',
        'class' => ['accordion-collapse','collapse', 'hide'],
        'aria-labelledby' => 'headingAreas',
        'data-bs-parent' => '#accordionAreas',
      ],
    ];

    $form['row3']['item']['collapse']['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['accordion-body','p-0']],
    ];

    $form['row3']['item']['collapse']['body']['cards_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row','row-cols-3','g-0','p-3','pt-0']],
    ];

    foreach ([8, 9, 10] as $key) {
      switch ($key) {
        case 8:
          $title = t('Manage Object Collections');
          $btn_classes = ['btn', 'btn-secondary',];
          break;
        case 9:
          $title = t('Manage Virtual Columns');
          $btn_classes = ['btn', 'btn-primary'];
          break;
        case 10:
          $title = t('Manage Roles');
          $btn_classes = ['btn', 'btn-primary', 'disabled'];
          break;
      }

      $link = $cards[$key]['link'];
      if (strpos($link, base_path()) === 0) {
        $link = substr($link, strlen(base_path()) - 1);
      }

      $form['row3']['item']['collapse']['body']['cards_row']["card{$key}"] = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['col', 'p-2']],
        'card' => [
          '#type'       => 'container',
          '#attributes' => ['class' => ['card', 'h-100', 'text-center']],
          'body'   => [
            '#type'       => 'container',
            '#attributes' => ['class' => ['card-body']],
            'value' => [
              '#type'  => 'html_tag',
              '#tag'   => 'h1',
              '#value' => $cards[$key]['value'],
            ],
          ],
          'footer' => [
            '#type'       => 'container',
            '#attributes' => ['class' => ['card-footer']],
            'link' => [
              '#type'       => 'link',
              '#title'      => $title,
              '#url'        => Url::fromUserInput($link),
              '#attributes' => ['class' => $btn_classes],
            ],
          ],
        ],
      ];
    }

    $uid = \Drupal::currentUser()->id();

    $previousUrl = Url::fromRoute('std.manage_study_elements', [
      'studyuri' => base64_encode($this->getStudy()->uri),
    ])->toString();
    Utils::trackingStoreUrls($uid, $previousUrl, 'std.manage_study_elements');

    // Check if the current user is the owner (hasSIRManagerEmail is assumed to be defined previously).
    $isOwner = $this->getStudy()->hasSIRManagerEmail === $useremail;

    // ROW 2 as a Bootstrap 5 Accordion, preserving your AJAX logic
    $form['row2'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'mb-3'],
      ],
    ];

    // Accordion wrapper for the entire row
    $form['row2']['accordion'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['accordion', 'w-100'],
        'id'    => 'accordionRow2',
      ],
    ];

    // Single accordion item (you could duplicate this if you ever need more)
    $form['row2']['accordion']['item'] = [
	  '#type' => 'container',
	  '#attributes' => [
		'class' => ['accordion-item', 'card', 'drop-area'],
		'id'    => 'drop-card',
	  ],
	];

    // Accordion header: button that toggles the collapse
    $form['row2']['accordion']['item']['header'] = [
	  '#type' => 'container',
	  '#attributes' => [
		'class' => ['accordion-header', 'd-flex', 'justify-content-between', 'align-items-center'],
		'id'    => 'headingDropCard',
	  ],
      'button' => [
        '#type'       => 'html_tag',
        '#tag'        => 'button',
        '#value'      => '<h3 id="total_elements_count" class="mb-0">' . $cards[1]['value'] . '</h3>' .
          ($isOwner ?
            '&nbsp;<div class="info-card text-center w-80">(You can drag&amp;drop files directly into this card)</div>' :
            '') .
            '<div id="toast-container" style="position:absolute; top:0.5rem; right:1rem; z-index:1050;"></div>',
        '#attributes' => [
          'class'          => ['accordion-button', 'collapsed'],
          'type'           => 'button',
          'data-bs-toggle' => 'collapse',
          'data-bs-target'=> '#collapseDropCard',
          'aria-expanded'  => 'false',
          'aria-controls'  => 'collapseDropCard',
        ],
      ],
    ];

    // The collapsible pane containing all your cards and AJAX tables
    $form['row2']['accordion']['item']['collapse'] = [
      '#type' => 'container',
      '#attributes' => [
        'class'           => ['accordion-collapse', 'collapse', 'hide'], // `show` = start expanded
        'id'              => 'collapseDropCard',
        'aria-labelledby' => 'headingDropCard',
        'data-bs-parent'  => '#accordionRow2',
      ],
    ];

    // Accordion body: wrap your original card-body here
    $form['row2']['accordion']['item']['collapse']['body'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['accordion-body', 'p-0'],
      ],
    ];

    // --- Begin inner_row: your original contentRow ---
    $form['row2']['accordion']['item']['collapse']['body']['inner_row'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'm-3'],
        'style' => 'margin-bottom:25px!important;',
      ],
    ];

    // Card 6: Streams IN
    $header   = Stream::generateHeaderStudy();
    $output   = Stream::generateOutputStudy($this->getStreamList());
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card6'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['col-md-12']],
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card6']['card'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['col-md-12', 'mb-4']],
      '#prefix'     => '<div class="card">',
      '#suffix'     => '</div>',
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card6']['card']['card_header'] = [
      '#type'   => 'markup',
      '#markup' => '<div class="card-header text-center">'
        . '<h3 id="stream_files_count">' . $cards[6]['head'] . '</h3>'
        . '</div>',
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card6']['card']['card_body'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['card-body', 'p-']],
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card6']['card']['card_body']['element_table'] = [
      '#type'     => 'tableselect',
      '#header'   => $header,
      '#options'  => $output,
      '#empty'    => t('No stream has been found'),
      '#attributes' => ['id' => 'dpl-streams-table'],
      '#multiple' => FALSE,
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card6']['card']['card_footer'] = [
      '#type'   => 'markup',
      '#markup' => '<div class="card-footer text-center">'
        . '<div id="json-table-stream-pager" class="pagination"></div>'
        . '</div>',
    ];

    // AJAX-loaded cards: Stream Topic List, Stream Data Files, Message Stream
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['ajax_cards_container'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-12', 'mb-4']],
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['ajax_cards_container']['ajax_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
    ];

    // Stream Topic List (full width)
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['ajax_cards_container']['ajax_row']['stream_topic_list'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-12'],
        'id'    => 'stream-topic-list-container',
      ],
      'card' => [
        '#type'   => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3 id="topic-list-count">Stream Topic List</h3>
              <div class="info-card">Cards data are refreshed every 15 seconds</div>
            </div>
            <div class="card-body">
              <div id="topic-list-table">Loading…</div>
            </div>
            <div class="card-footer text-center">
              <div id="topic-list-pager" class="pagination"></div>
            </div>
          </div>
        ',
      ],
    ];

    // Stream Data Files (left half, hidden until a stream is selected)
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['ajax_cards_container']['ajax_row']['stream_data_files'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-7', 'mt-3'],
        'id'    => 'stream-data-files-container',
        'style' => 'display:none;',
      ],
      'card' => [
        '#type'   => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3 id="data-files-count">Stream Data Files</h3>
            </div>
            <div class="card-body">
              <div id="data-files-table">Loading…</div>
            </div>
            <div class="card-footer text-center">
              <div id="data-files-pager" class="pagination stream-only-pager"></div>
              <div id="topic-files-pager" class="pagination topic-only-pager" style="display:none;"></div>
            </div>
          </div>
        ',
      ],
    ];

    // Message Stream (right half, hidden until a stream is selected)
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['ajax_cards_container']['ajax_row']['message_stream'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-5', 'mt-3'],
        'id'    => 'message-stream-container',
        'style' => 'display:none!important;',
      ],
      'card' => [
        '#type'   => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3 id="message-stream-count">Message Stream</h3>
            </div>
            <div class="card-body">
              <div id="message-stream-table">
                <p class="text-muted">Select a stream to view messages.</p>
              </div>
            </div>
            <div class="card-footer text-center">
              <div id="message-stream-pager" class="pagination"></div>
            </div>
          </div>
        ',
      ],
    ];

    // Card 13: Streams OUT
    $headerOut = Stream::generateHeaderOutStream();
    $outputOut = Stream::generateOutputStream($this->getOutStreamList());
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card13'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['col-md-12', 'mt-4']],
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card13']['card'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['col-md-12', 'mb-4']],
      '#prefix'     => '<div class="card">',
      '#suffix'     => '</div>',
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card13']['card']['card_header'] = [
      '#type'   => 'markup',
      '#markup' => '<div class="card-header text-center">'
        . '<h3 id="stream_files_count">' . $cards[13]['head'] . '</h3>'
        . '</div>',
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card13']['card']['card_body'] = [
      '#type'       => 'container',
      '#attributes' => ['class' => ['card-body', 'p-2']],
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card13']['card']['card_body']['element_table'] = [
      '#type'     => 'tableselect',
      '#header'   => $headerOut,
      '#options'  => $outputOut,
      '#empty'    => t('No stream has been found'),
      '#attributes' => ['id' => 'dpl-streamsout-table'],
      '#multiple' => FALSE,
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['card13']['card']['card_footer'] = [
      '#type'   => 'markup',
      '#markup' => '<div class="card-footer text-center">'
        . '<div id="json-table-stream-pager" class="pagination"></div>'
        . '</div>',
    ];

    // Fixed cards container for Study Data Files, Publications, Media
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-md-12', 'mt-3'],
        'style' => 'border-top: 5px dashed rgb(168, 168, 168)',
      ],
    ];
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container']['fixed_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row', 'align-items-start']],
    ];

    // Study Data Files (one-third width)
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container']['fixed_row']['study_data_files'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-4']],
      'card' => [
        '#type'   => 'markup',
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
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container']['fixed_row']['publications'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-4']],
      'card' => [
        '#type'   => 'markup',
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
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container']['fixed_row']['media'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-md-4']],
      'card' => [
        '#type'   => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3>' . $cards[4]['value'] . '</h3>
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

    // WORKFLOW EXECUTIONS
    $form['row6'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['accordion', 'mt-3'], 'id' => 'accordionWorkflow'],
    ];

    $form['row6']['item'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['accordion-item', 'card', 'drop-area'],
        'id'    => 'workflow-card',
      ],
    ];

    $form['row6']['item']['header'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['accordion-header', 'justify-content-between', 'align-items-center'],
        'id'    => 'headingWorkflow',
      ],
      'button' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('<h3 class="mb-0">'.$config->get("preferred_process").' Executions' ?? 'Process Executions'.'</h3>'),
        '#attributes' => [
          'class' => ['accordion-button', 'collapsed'],
          'type' => 'button',
          'data-bs-toggle' => 'collapse',
          'data-bs-target' => '#collapseWorkflow',
          'aria-expanded' => 'false',
          'aria-controls' => 'collapseWorkflow',
        ],
      ],
    ];

    $form['row6']['item']['collapse'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'collapseWorkflow',
        'class' => ['accordion-collapse','collapse', 'hide'],
        'aria-labelledby' => 'headingWorkflow',
        'data-bs-parent' => '#accordionWorkflow',
      ],
    ];

    $form['row6']['item']['collapse']['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['accordion-body','p-0']],
    ];

    $form['row6']['item']['collapse']['body']['cards_row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['row','row-cols-3','g-0','p-3','pt-0']],
    ];

    foreach ([14] as $key) {
      switch ($key) {
        // case 7:
        //   $title = t('Manage STRs');
        //   $btn_classes = ['btn', 'btn-primary'];
        //   break;
        case 14:
          $title = t('Create Execution');
          $btn_classes = ['btn', 'btn-primary',];
          break;
      }

      $form['row6']['item']['collapse']['body']['cards_row']["card{$key}"] = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['col', 'p-2']],
        'card' => [
          '#type'       => 'container',
          '#attributes' => ['class' => ['card', 'h-100', 'text-center']],
          'body'   => [
            '#type'       => 'container',
            '#attributes' => ['class' => ['card-body']],
            'value' => [
              '#type'  => 'html_tag',
              '#tag'   => 'h1',
              '#value' => $cards[$key]['value'],
            ],
          ],
          'footer' => [
            '#type'       => 'container',
            '#attributes' => ['class' => ['card-footer']],
            'link' => [
              '#type'       => 'link',
              '#title'      => $title,
              '#url'        => Url::fromUserInput($cards[$key]['link']),
              '#attributes' => ['class' => $btn_classes],
            ],
          ],
        ],
      ];
    }

    // Bottom part of the form
    $form['row4'] = array(
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
      '#type' => 'markup',
      '#markup' => '<p><br /><b>Note</b>: Data Dictionaires (DD) and Semantic Data Dictionaires (SDD) are added' .
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
