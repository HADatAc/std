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
use Drupal\Component\Utility\Html;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;

use function Termwind\style;

class ManageStudyForm extends FormBase
{
  private const ANY_PROCESS_URI = 'http://hadatac.org/ont/hasco/AnyProcess';


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

    $form['#attributes']['class'][] = 'manage-study-form';
    $form['#attached']['library'][] = 'std/manage_study_fix';

    $config = $this->config(static::CONFIGNAME);
    $preferred_study = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';
    $preferredStudyLabel = ucfirst(trim((string) $preferred_study));
    if ($preferredStudyLabel === '') {
      $preferredStudyLabel = 'Study';
    }
    
    // Determine back button label and URL based on where user came from
    // Use non-destructive peek to avoid deleting the tracking record
    $uid = \Drupal::currentUser()->id();
    $refererUrl = Utils::trackingPeekPreviousUrl($uid, 'std.manage_study_elements');
    $backButtonLabel = 'Back to Manage Studies'; // Default
    if ($refererUrl && strpos($refererUrl, '/std/search/studies') !== false) {
      $backButtonLabel = 'Back to ' . $preferredStudyLabel . ' Search';
    }

    //Libraries
    $form['#attached']['library'][] = 'std/json_table';
    $form['#attached']['library'][] = 'core/drupal.autocomplete';
    $form['#attached']['library'][] = 'rep/pdfjs';
    $form['#attached']['library'][] = 'rep/webdoc_modal';
    $form['#attached']['library'][] = 'std/stream_selection';
    $form['#attached']['library'][] = 'dpl/stream_recorder';
    $base_url = (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://':'http://'). \Drupal::request()->getHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['drupalSettings']['webdoc_modal'] = [
      'baseUrl' => $base_url,
    ];

    // Owner of the record
    $useremail = \Drupal::currentUser()->getEmail();

    if ($studyuri == NULL || $studyuri == "") {
     \Drupal::messenger()->addMessage(t("A URI is required to manage a ".$preferred_study."."));
     $form_state->setRedirectUrl(Utils::selectBackUrl('study'));
    }

    $uri_decode = base64_decode($studyuri);
    $this->setStudyUri($uri_decode);
    $api = \Drupal::service('rep.api_connector');
    $study = $api->parseObjectResponse($api->getUri($uri_decode), 'getUri');

    if ($study == NULL) {
      \Drupal::messenger()->addMessage(t("Failed to retrieve ".ucfirst($preferred_study)."."));
      self::backUrl();
    } else {
      $this->setStudy($study);
    }

    $isProcessBasedStudyType = isset($this->getStudy()->hascoTypeUri)
      && $this->getStudy()->hascoTypeUri === 'http://hadatac.org/ont/hasco/ProcessBasedStudy';

    $piMbox = '';
    if (isset($this->getStudy()->pi) && is_object($this->getStudy()->pi)) {
      $piMbox = strtolower(trim((string) ($this->getStudy()->pi->mbox ?? '')));
    }

    $ownerCandidates = [
      strtolower(trim((string) ($this->getStudy()->hasSIRManagerEmail ?? ''))),
      strtolower(trim((string) ($this->getStudy()->contactEmail ?? ''))),
      $piMbox,
    ];
    $isOwner = in_array(strtolower(trim((string) $useremail)), $ownerCandidates, TRUE) && trim((string) $useremail) !== '';
    $canAccessCttEditor = \Drupal::currentUser()->hasPermission('access ctt editor');
    $canSubmitCttWorkflow = \Drupal::currentUser()->hasPermission('submit ctt workflow');

	// ROW CONTENT
    $session = \Drupal::service('session');
    $da_page_from_session = $session->get('da_current_page', 1);
    $pub_page_from_session = $session->get('pub_current_page', 1);
    $media_page_from_session = $session->get('media_current_page', 1);
    $medical_page_from_session = $session->get('medical_current_page', 1);

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
    $form['#attached']['drupalSettings']['medical'] = [
      'studyuri'   => rawurlencode($this->studyUri),
      'elementtype'=> 'medical',
      'page'       => $medical_page_from_session,
      'pagesize'   => 5,
    ];
    $form['#attached']['drupalSettings']['std'] = [
      // -- stream/topic selection --
      'studyuri'        => base64_encode($this->studyUri),
      'elementtype'=> 'da',
      'mode'       => 'compact',
      'page'       => $da_page_from_session,
      'pagesize'   => 5,
      'ajaxUrl'         => Url::fromRoute('std.stream_data_ajax')->toString(),
      'streamDataUrl'   => Url::fromRoute('std.stream_data_ajax')->toString(),
      'latestUrl'       => (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://':'http://'). \Drupal::request()->getHost() . \Drupal::request()->getBaseUrl()
                . '/dpl/streamtopic/latest_message/',
      'fileIngestUrl'   => Url::fromRoute('dpl.file_ingest_ajax')->toString(),
      'fileUningestUrl' => Url::fromRoute('dpl.file_uningest_ajax')->toString(),
      'isOwner'         => $isOwner,
      'canDeleteFiles'  => $isOwner,

    ];

    $form['#attached']['drupalSettings']['stdManageStudy'] = [
      'isOwner' => $isOwner,
      'modeLabel' => $isOwner ? 'Edit Mode' : 'View Mode',
      'abortEndpoint' => Url::fromRoute('ctt.api.r_analysis.abort')->toString(),
      'csrfToken' => \Drupal::csrfToken()->get('rest'),
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
    // Workflows associated with this study (used in Simulation Process Executions).
    $totalPRCs = 0;
    $associatedWorkflows = [];
    try {
      $associatedWorkflows = $this->getAssociatedWorkflowsForStudy($api, $this->getStudy()->uri, $useremail);
      $totalPRCs = count($associatedWorkflows);
    }
    catch (\Throwable $e) {
      // Keep zero if the backend is unavailable.
      $associatedWorkflows = [];
    }

    $associatedWorkflowUris = [];
    foreach ($associatedWorkflows as $workflow) {
      $workflowUri = trim((string) ($workflow->uri ?? ''));
      if ($workflowUri !== '') {
        $associatedWorkflowUris[$workflowUri] = TRUE;
      }
    }

    $workflowAssociationOptions = [];
    if ($isOwner && $canSubmitCttWorkflow) {
      $associationCandidates = $this->getWorkflowAssociationCandidates($api, $useremail);
      foreach ($associationCandidates as $workflow) {
        $workflowUri = trim((string) ($workflow->uri ?? ''));
        if ($workflowUri === '' || isset($associatedWorkflowUris[$workflowUri])) {
          continue;
        }

        $workflowLabel = trim((string) ($workflow->label ?? $workflow->title ?? ''));
        if ($workflowLabel === '') {
          $workflowLabel = $workflowUri;
        }

        $workflowAssociationOptions[$workflowUri] = $this->formatWorkflowOptionLabel($workflowLabel, $workflowUri);
      }
    }

    $availableWorkflowCount = count($workflowAssociationOptions);

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
      2 => array('value' => 'Stream Files (' . ($totalDAs ?? 0) . ')'),
      3 => array('value' => 'Publications'),
      4 => array('value' => 'Media'),
      15 => array('value' => 'Medical Images'),
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
        'value' => '<h1>' . $totalPRCs . '</h1><h3>Workflow<br>&nbsp;</h3>',
        'link' => Url::fromRoute('ctt.execution_simulate', [
          'studyuri' => base64_encode($this->getStudy()->uri),
        ])->toString(),
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
      $this->getStudy()->pi != NULL
    ) {
      if (is_object($this->getStudy()->pi)) {
        $piName = (string) ($this->getStudy()->pi->name ?? ($this->getStudy()->pi->label ?? ' '));
      }
      elseif (is_string($this->getStudy()->pi) && trim($this->getStudy()->pi) !== '') {
        $piName = trim((string) $this->getStudy()->pi);
      }
    }
    if ((trim($piName) === '' || $piName === ' ') && isset($this->getStudy()->principalInvestigator)) {
      $piFallback = trim((string) $this->getStudy()->principalInvestigator);
      if ($piFallback !== '') {
        $piName = $piFallback;
      }
    }
    if ((trim($piName) === '' || $piName === ' ') && isset($this->getStudy()->hasSIRManagerEmail)) {
      $managerEmailFallback = trim((string) $this->getStudy()->hasSIRManagerEmail);
      if ($managerEmailFallback !== '') {
        $piName = $managerEmailFallback;
      }
    }

    $piUri = '';
    if (isset($this->getStudy()->piUri) && is_string($this->getStudy()->piUri)) {
      $piUri = trim((string) $this->getStudy()->piUri);
    }
    if ($piUri === '' && isset($this->getStudy()->pi) && is_object($this->getStudy()->pi) && isset($this->getStudy()->pi->uri)) {
      $piUri = trim((string) $this->getStudy()->pi->uri);
    }

    $institutionName = ' ';
    $institutionUri = '';
    if (isset($this->getStudy()->institution) && $this->getStudy()->institution != NULL) {
      if (is_object($this->getStudy()->institution)) {
        $institutionName = (string) ($this->getStudy()->institution->name ?? ($this->getStudy()->institution->label ?? ' '));
        $institutionUri = (string) ($this->getStudy()->institution->uri ?? ($this->getStudy()->institutionUri ?? ''));
      }
      elseif (is_string($this->getStudy()->institution)) {
        $institutionUri = trim((string) $this->getStudy()->institution);
      }
    }

    if ($institutionUri === '' && isset($this->getStudy()->institutionUri) && $this->getStudy()->institutionUri != NULL) {
      $institutionUri = trim((string) $this->getStudy()->institutionUri);
    }

    if ((trim($institutionName) === '' || $institutionName === ' ') && isset($this->getStudy()->institutionName)) {
      $institutionNameFallback = trim((string) $this->getStudy()->institutionName);
      if ($institutionNameFallback !== '') {
        $institutionName = $institutionNameFallback;
      }
    }

    if ((trim($institutionName) === '' || $institutionName === ' ') && $institutionUri !== '') {
      $normalizedInstitutionUri = strtolower(trim($institutionUri));
      $isResolvableInstitutionUri = preg_match('/^https?:\/\//i', $institutionUri) === 1
        && !in_array($normalizedInstitutionUri, ['unknown', 'none', 'null', 'n/a'], TRUE);

      if ($isResolvableInstitutionUri) {
        try {
          $institutionObj = $api->parseObjectResponse($api->getUri($institutionUri), 'getUri');
          if (is_object($institutionObj)) {
            $institutionName = (string) ($institutionObj->name ?? ($institutionObj->label ?? $institutionUri));
          }
          else {
            $institutionName = $institutionUri;
          }
        }
        catch (\Throwable $e) {
          $institutionName = $institutionUri;
        }
      }
      else {
        $institutionName = $institutionUri;
      }
    }

    $piDisplayHtml = Html::escape(trim((string) $piName));
    if ($piDisplayHtml === '') {
      $piDisplayHtml = '&nbsp;';
    }
    if ($piUri !== '' && preg_match('/^https?:\/\//i', $piUri) === 1) {
      $piHref = Url::fromRoute('rep.describe_element', ['elementuri' => base64_encode($piUri)])->toString();
      $piLinkText = trim((string) $piName) !== '' ? trim((string) $piName) : $piUri;
      $piDisplayHtml = '<a href="' . Html::escape($piHref) . '">' . Html::escape($piLinkText) . '</a>';
    }

    $institutionDisplayHtml = Html::escape(trim((string) $institutionName));
    if ($institutionDisplayHtml === '') {
      $institutionDisplayHtml = '&nbsp;';
    }
    if ($institutionUri !== '' && preg_match('/^https?:\/\//i', $institutionUri) === 1) {
      $institutionHref = Url::fromRoute('rep.describe_element', ['elementuri' => base64_encode($institutionUri)])->toString();
      $institutionLinkText = trim((string) $institutionName) !== '' ? trim((string) $institutionName) : $institutionUri;
      $institutionDisplayHtml = '<a href="' . Html::escape($institutionHref) . '">' . Html::escape($institutionLinkText) . '</a>';
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
    $base_url = (\Drupal::request()->headers->get('x-forwarded-proto') === 'https' ? 'https://':'http://'). \Drupal::request()->getHost() . \Drupal::request()->getBaseUrl();
    $form['#attached']['drupalSettings']['webdoc_modal'] = [
      'baseUrl' => $base_url,
    ];

    //MODAL
    $form['modal'] = [
      '#type' => 'markup',
      '#markup' => Markup::create('
        <div id="modal-container" class="modal-media hidden std-study-modal" aria-hidden="true">
          <div class="modal-content" role="dialog" aria-modal="true" aria-label="File viewer modal">
            <button class="close-btn" type="button" aria-label="Close viewer">&times;</button>
            <div id="pdf-scroll-container"></div>
            <div id="modal-content"></div>
          </div>
          <div class="modal-backdrop"></div>
        </div>
      '),
      '#weight' => '-99999',
    ];

    $form['workflow_canvas_svg'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];

    $form['workflow_canvas_png'] = [
      '#type' => 'hidden',
      '#default_value' => '',
    ];

    $form['workflow_canvas_source'] = [
      '#type' => 'hidden',
      '#default_value' => 'none',
    ];

    $currentStudyUri = trim((string) ($this->getStudy()->uri ?? ''));
    $currentProcessUri = '';
    if ($isProcessBasedStudyType) {
      $currentProcessUri = (string) ($this->getStudy()->processUri ?? $this->getStudy()->hasProcess ?? $this->getStudy()->hasProcessUri ?? '');
      if (is_object($currentProcessUri)) {
        $currentProcessUri = (string) ($currentProcessUri->uri ?? '');
      }
      $currentProcessUri = trim($currentProcessUri);
    }

    $criticalWarningInlineHtml = '';
    if ($isProcessBasedStudyType && $currentProcessUri !== '') {
      $criticalWarningData = $this->getRequiredInstrumentWarningData($currentProcessUri);
      if (is_array($criticalWarningData)) {
        $warningDialogId = 'std-critical-warning-dialog';
        $warningOpenBtnId = 'std-critical-warning-open';
        $warningCloseBtnId = 'std-critical-warning-close';

        $missingList = $criticalWarningData['missingUris'] ?? [];
        $affectedTasks = $criticalWarningData['affectedTasks'] ?? [];
        $detailItemsHtml = '';
        foreach ($missingList as $missingUri) {
          $detailItemsHtml .= '<li>' . Html::escape((string) $missingUri) . '</li>';
        }

        $taskRowsHtml = '';
        foreach ($affectedTasks as $taskInfo) {
          if (!is_array($taskInfo)) {
            continue;
          }

          $taskUri = trim((string) ($taskInfo['uri'] ?? ''));
          $taskLabel = trim((string) ($taskInfo['label'] ?? ''));
          if ($taskUri === '' && $taskLabel === '') {
            continue;
          }

          $taskRowsHtml .= ''
            . '<tr>'
            . '  <td style="vertical-align:top;word-break:break-all;">' . Html::escape($taskUri !== '' ? $taskUri : '-') . '</td>'
            . '  <td style="vertical-align:top;">' . Html::escape($taskLabel !== '' ? $taskLabel : '-') . '</td>'
            . '</tr>';
        }

        $criticalWarningInlineHtml = ''
          . '<div class="std-critical-warning-inline mt-2 mb-3" role="alert"'
          . ' style="position:static!important;display:inline-flex;align-items:center;gap:.75rem;padding:.5rem .75rem;width:fit-content;max-width:100%;border:2px solid #dc3545;border-radius:.375rem;background:#f8d7da;color:#842029;">'
          . '  <strong style="margin:0;">Critical Warning: Required Instruments Unavailable</strong>'
          . '  <button type="button" class="btn btn-danger btn-sm" id="' . Html::escape($warningOpenBtnId) . '">See more</button>'
          . '</div>'
          . '<div id="' . Html::escape($warningDialogId) . '" class="d-none" aria-hidden="true"'
          . ' style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1060;display:flex;align-items:center;justify-content:center;padding:1rem;">'
          . '  <div role="dialog" aria-modal="true" aria-labelledby="std-critical-warning-title"'
          . '    style="background:#fff;border-radius:.5rem;max-width:760px;width:100%;padding:1rem 1rem 1.25rem;box-shadow:0 8px 24px rgba(0,0,0,.2);max-height:80vh;overflow:auto;">'
          . '    <h4 id="std-critical-warning-title" style="margin:0 0 .75rem 0;">Critical Warning: Required Instruments Unavailable</h4>'
          . '    <p style="margin:0 0 .75rem 0;">This scenario currently references required instruments that are unavailable or unresolved. The scenario execution can be invalid until this is fixed.</p>'
          . '    <p style="margin:0 0 .75rem 0;"><strong>Affected tasks:</strong> ' . Html::escape((string) ($criticalWarningData['affectedTaskCount'] ?? 0)) . ' | '
          . '      <strong>Unavailable required instruments:</strong> ' . Html::escape((string) count($missingList)) . '</p>'
          . '    <p style="margin:0 0 .5rem 0;"><strong>Affected tasks (URI - Label):</strong></p>'
          . '    <div style="margin:0 0 .75rem 0;overflow:auto;max-height:240px;border:1px solid #dee2e6;border-radius:.25rem;">'
          . '      <table class="table table-sm table-striped mb-0">'
          . '        <thead style="position:sticky;top:0;background:#f8f9fa;z-index:1;">'
          . '          <tr><th style="white-space:nowrap;">Task URI</th><th>Task Label</th></tr>'
          . '        </thead>'
          . '        <tbody>' . ($taskRowsHtml !== '' ? $taskRowsHtml : '<tr><td colspan="2">No task details available.</td></tr>') . '</tbody>'
          . '      </table>'
          . '    </div>'
          . '    <p style="margin:0 0 .5rem 0;"><strong>Unavailable required instruments:</strong></p>'
          . '    <ul style="margin:0 0 .75rem 1rem;">' . $detailItemsHtml . '</ul>'
          . '    <p style="margin:0 0 1rem 0;">Review workflow task instrument assignments and verify the related objects in Objects of Interest.</p>'
          . '    <div style="display:flex;justify-content:flex-end;gap:.5rem;">'
          . '      <button type="button" id="' . Html::escape($warningCloseBtnId) . '" class="btn btn-outline-secondary">Close</button>'
          . '    </div>'
          . '  </div>'
          . '</div>';
      }
    }

    // First row with a single card
    $form['row1']['card0']['card'] = [
      '#type'       => 'markup',
      '#markup'     => Markup::create('
        <div class="card"><div class="card-body" style="justify-content:normal!important;">
          <h3 class="mb-3 mt-3">' . $this->getStudy()->label . '</h3>
          <dl class="row">
            <dt class="col-sm-1">' . $this->t('URI')        . ':</dt><dd class="col-sm-11">' . $this->getStudy()->uri     . '</dd>
            <dt class="col-sm-1">' . $this->t('Name')       . ':</dt><dd class="col-sm-11">' . $title                       . '</dd>
            <dt class="col-sm-1">' . $this->t('PI')         . ':</dt><dd class="col-sm-11">' . $piDisplayHtml               . '</dd>
            <dt class="col-sm-1">' . $this->t('Institution'). ':</dt><dd class="col-sm-11">' . $institutionDisplayHtml      . '</dd>
            <dt class="col-sm-1">' . $this->t('Description'). ':</dt><dd class="col-sm-11">' . $this->getStudy()->comment   . '</dd>
          </dl>' . $criticalWarningInlineHtml . '
        </div></div>
      '),
    ];

    if ($isProcessBasedStudyType || $isOwner) {
      $form['row1_actions'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['d-flex', 'gap-2', 'mb-3', 'flex-wrap']],
      ];

      if ($isProcessBasedStudyType) {
        $form['row1_actions']['generate_pdf'] = [
          '#type' => 'submit',
          '#value' => $this->t('Generate PDF'),
          '#name' => 'generate_pdf',
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['btn', 'btn-outline-secondary'],
            'id' => 'std-generate-pdf-button',
          ],
        ];

        $form['row1_actions']['wkf_generation_scope'] = [
          '#type' => 'hidden',
          '#default_value' => 'full',
        ];

        $form['row1_actions']['generate_wkf'] = [
          '#type' => 'submit',
          '#value' => $this->t('Generate WKF'),
          '#name' => 'generate_wkf',
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['btn', 'btn-outline-success'],
            'id' => 'std-generate-wkf-button',
          ],
        ];

        $createQuery = [];
        if ($currentProcessUri !== '') {
          $createQuery['process_uri'] = $currentProcessUri;
        }
        $createStudyUrl = Url::fromRoute('std.add_processbasedstudy', [], [
          'query' => $createQuery,
        ]);
        $form['row1_actions']['create_study_with_process'] = [
          '#type' => 'link',
          '#title' => $this->t('Create @study with this Process', ['@study' => $preferredStudyLabel]),
          '#url' => $createStudyUrl,
          '#attributes' => ['class' => ['btn', 'btn-outline-primary']],
        ];

        if ($isOwner) {
          $form['row1_actions']['students_count'] = [
            '#type' => 'hidden',
            '#default_value' => '',
            '#attributes' => [
              'id' => 'std-students-count-hidden',
            ],
          ];

          $form['row1_actions']['add_students'] = [
            '#type' => 'submit',
            '#value' => $this->t('Add Students'),
            '#name' => 'add_students',
            '#limit_validation_errors' => [
              ['row1_actions', 'students_count'],
              ['students_count'],
            ],
            '#attributes' => [
              'class' => ['btn', 'btn-outline-info'],
              'id' => 'std-add-students-button',
            ],
          ];

          $form['row1_actions']['add_students_modal'] = [
            '#type' => 'markup',
            '#markup' => Markup::create(''
              . '<div id="std-add-students-modal" class="d-none" aria-hidden="true"'
              . ' style="position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1055;display:flex;align-items:center;justify-content:center;padding:1rem;">'
              . '  <div role="dialog" aria-modal="true" aria-labelledby="std-add-students-title"'
              . '    style="background:#fff;border-radius:.5rem;max-width:460px;width:100%;padding:1rem 1rem 1.25rem;box-shadow:0 8px 24px rgba(0,0,0,.2);">'
              . '    <h5 id="std-add-students-title" style="margin:0 0 .75rem 0;">Add Students</h5>'
              . '    <p style="margin:0 0 .75rem 0;">How many students should be included in SOC-STUDENT? (1 to 100)</p>'
              . '    <input id="std-add-students-count-input" type="number" min="1" max="100" step="1" value="1" class="form-control" />'
              . '    <div id="std-add-students-error" class="text-danger" style="display:none;margin-top:.5rem;">Please enter a number between 1 and 100.</div>'
              . '    <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem;">'
              . '      <button type="button" id="std-add-students-cancel" class="btn btn-outline-secondary">Cancel</button>'
              . '      <button type="button" id="std-add-students-confirm" class="btn btn-primary">Confirm</button>'
              . '    </div>'
              . '  </div>'
              . '</div>'
            ),
          ];
        }
      }

      if ($isOwner && $currentStudyUri !== '') {
        $encodedStudyUri = base64_encode($currentStudyUri);
        $editRoute = $isProcessBasedStudyType ? 'std.edit_processbasedstudy' : 'std.edit_study';
        $currentPath = \Drupal::request()->getPathInfo();
        $safePreviousUrl = rtrim(strtr(base64_encode($currentPath), '+/', '-_'), '=');
        $safePreviousUrlStr = base64_encode($safePreviousUrl);
        $editStudyUrlStr = base64_encode(Url::fromRoute($editRoute, ['studyuri' => $encodedStudyUri])->toString());
        $editStudyUrl = Url::fromRoute('rep.back_url', [
          'previousurl' => $safePreviousUrlStr,
          'currenturl' => $editStudyUrlStr,
          'currentroute' => $editRoute,
        ]);
        $form['row1_actions']['edit_study'] = [
          '#type' => 'link',
          '#title' => $this->t('Edit @study', ['@study' => $preferredStudyLabel]),
          '#url' => $editStudyUrl,
          '#attributes' => ['class' => ['btn', 'btn-primary']],
        ];

        if ($canSubmitCttWorkflow) {
          $manageToolsProcessUri = $currentProcessUri;
          if ($manageToolsProcessUri === '' && !empty($associatedWorkflows)) {
            foreach ($associatedWorkflows as $workflowCandidate) {
              $candidateUri = trim((string) ($workflowCandidate->uri ?? ''));
              if (preg_match('/^https?:\/\//i', $candidateUri) === 1) {
                $manageToolsProcessUri = $candidateUri;
                break;
              }
            }
          }

          $returnToManageScenarioUrl = Url::fromRoute('std.manage_study_elements', [
            'studyuri' => base64_encode($currentStudyUri),
          ])->toString();

          $manageToolsUrl = Url::fromRoute('ctt.tools_repository', [], [
            'query' => [
              'studyUri' => $currentStudyUri,
              'scenarioUri' => $currentStudyUri,
              'processUri' => $manageToolsProcessUri,
              'returnTo' => $returnToManageScenarioUrl,
            ],
          ]);

          $form['row1_actions']['manage_tools'] = [
            '#type' => 'link',
            '#title' => $this->t('Manage Tools'),
            '#url' => $manageToolsUrl,
            '#attributes' => [
              'class' => ['btn', 'btn-outline-secondary'],
            ],
          ];
        }
      }
    }

    // ROW AREA INTERESTS

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
        'class' => ['accordion-collapse','collapse'],
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

    // Note: Do NOT call trackingStoreUrls here - it would overwrite the tracking
    // set by rep.back_url when navigating to this page from Study Search

    // ROW 2A - DESCRIPTION SECTION (Study properties and ProcessBasedStudy properties if applicable)
    $form['row2a'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['accordion', 'mt-3'], 'id' => 'accordionDescription'],
    ];

    $form['row2a']['item'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['accordion-item', 'card'],
        'id'    => 'description-card',
      ],
    ];

    $form['row2a']['item']['header'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['accordion-header', 'justify-content-between', 'align-items-center'],
        'id'    => 'headingDescription',
      ],
      'button' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('<h3 class="mb-0">Description</h3>'),
        '#attributes' => [
          'class' => ['accordion-button', 'collapsed'],
          'type' => 'button',
          'data-bs-toggle' => 'collapse',
          'data-bs-target' => '#collapseDescription',
          'aria-expanded' => 'false',
          'aria-controls' => 'collapseDescription',
        ],
      ],
    ];

    $form['row2a']['item']['collapse'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'collapseDescription',
        'class' => ['accordion-collapse','collapse'],
        'aria-labelledby' => 'headingDescription',
        'data-bs-parent' => '#accordionDescription',
      ],
    ];

    $form['row2a']['item']['collapse']['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['accordion-body','p-3']],
    ];

    // Build description content with study properties
    $descriptionContent = '<div class="study-description">';
    $descriptionContent .= '<section class="description-subsection description-subsection--properties">';
    $descriptionContent .= '<h4 class="description-subsection-title">Study Properties</h4>';
    $descriptionContent .= '<dl class="row">';
    
    // Common study properties
    if (isset($this->getStudy()->uri)) {
      $descriptionContent .= '<dt class="col-sm-3">URI:</dt><dd class="col-sm-9">' . htmlspecialchars($this->getStudy()->uri) . '</dd>';
    }
    if (isset($this->getStudy()->label)) {
      $descriptionContent .= '<dt class="col-sm-3">Label:</dt><dd class="col-sm-9">' . htmlspecialchars($this->getStudy()->label) . '</dd>';
    }
    if (isset($this->getStudy()->title)) {
      $descriptionContent .= '<dt class="col-sm-3">Title:</dt><dd class="col-sm-9">' . htmlspecialchars($this->getStudy()->title) . '</dd>';
    }
    if (isset($this->getStudy()->comment)) {
      $descriptionContent .= '<dt class="col-sm-3">Comment:</dt><dd class="col-sm-9">' . htmlspecialchars($this->getStudy()->comment) . '</dd>';
    }
    if (isset($this->getStudy()->externalSource)) {
      $descriptionContent .= '<dt class="col-sm-3">External Source:</dt><dd class="col-sm-9">' . htmlspecialchars($this->getStudy()->externalSource) . '</dd>';
    }
    if (isset($this->getStudy()->studyDesignTypeLabel)) {
      $descriptionContent .= '<dt class="col-sm-3">Study Design Type:</dt><dd class="col-sm-9">' . htmlspecialchars($this->getStudy()->studyDesignTypeLabel) . '</dd>';
    }
    if (isset($this->getStudy()->hasSIRManagerEmail)) {
      $descriptionContent .= '<dt class="col-sm-3">Manager Email:</dt><dd class="col-sm-9">' . htmlspecialchars($this->getStudy()->hasSIRManagerEmail) . '</dd>';
    }
    
    $descriptionContent .= '</dl>';
    $descriptionContent .= '</section>';
    
    // Check if this is a ProcessBasedStudy and add its specific properties
    $isProcessBasedStudy = $isProcessBasedStudyType;
    $processUri = '';
    $processTypeLabel = '';
    $processTypeUri = '';
    $processTypeBrowseHref = '';
    $processTypeUriResolved = false;
    if ($isProcessBasedStudy) {
      $descriptionContent .= '<section class="description-subsection description-subsection--process">';
      $descriptionContent .= '<h4 class="description-subsection-title">Process-Based Study Properties</h4>';
      $descriptionContent .= '<dl class="row">';
      
      if (isset($this->getStudy()->hasProcess) || isset($this->getStudy()->hasProcessUri) || isset($this->getStudy()->processUri)) {
        $processUri = $this->getStudy()->processUri ?? $this->getStudy()->hasProcess ?? $this->getStudy()->hasProcessUri ?? '';
        if (is_object($processUri)) {
          $processUri = $processUri->uri ?? '';
        }
        $processUri = (string) $processUri;

        // Process Type = associated WorkflowStem value (from Process.wasDerivedFrom).
        if ($processUri !== '') {
          try {
            $processObj = $api->parseObjectResponse($api->getUri($processUri), 'getUri');
            if (is_object($processObj)) {
              $stemRef = $processObj->wasDerivedFrom ?? '';
              if (is_object($stemRef)) {
                $processTypeUri = (string) ($stemRef->uri ?? '');
                $processTypeLabel = (string) ($stemRef->label ?? '');
              }
              elseif (is_string($stemRef)) {
                $processTypeUri = trim($stemRef);
              }

              if ($processTypeUri !== '') {
                $stemObj = $api->parseObjectResponse($api->getUri($processTypeUri), 'getUri');
                if (is_object($stemObj)) {
                  $processTypeUriResolved = true;
                  $processTypeLabel = trim((string) ($stemObj->label ?? ($stemObj->title ?? ($stemObj->name ?? ''))));
                }
              }
            }
          }
          catch (\Throwable $e) {
            // Keep graceful fallback if process/process-stem lookup fails.
          }
        }
      }
      if ($processTypeLabel !== '' || $processTypeUri !== '') {
        $processTypeDisplay = $processTypeLabel !== '' ? $processTypeLabel : $processTypeUri;
        $browseQuery = [];
        if ($processTypeUri !== '' && $processTypeUriResolved) {
          $browseQuery['search_value'] = $processTypeUri;
        }
        $processTypeBrowseHref = Url::fromRoute('rep.browse_tree', [
          'mode' => 'browse',
          'elementtype' => 'workflowstem',
        ], [
          'query' => $browseQuery,
        ])->toString();

        $processTypeMarkup = htmlspecialchars($processTypeDisplay);
        if ($processTypeBrowseHref !== '') {
          $processTypeMarkup .= ' <a href="' . Html::escape($processTypeBrowseHref) . '" class="btn btn-sm btn-outline-primary ms-2 rep-nav-guard">Browse</a>';
        }

        $descriptionContent .= '<dt class="col-sm-3">Process Type:</dt><dd class="col-sm-9">' . $processTypeMarkup . '</dd>';
      }
      else {
        $descriptionContent .= '<dt class="col-sm-3">Process Type:</dt><dd class="col-sm-9"><span class="text-muted"><em>Not provided</em></span></dd>';
      }

      if ($processUri !== '') {
        $descriptionContent .= '<dt class="col-sm-3">Process:</dt><dd class="col-sm-9">' . htmlspecialchars($processUri) . '</dd>';
      }

      if (isset($this->getStudy()->processLabel)) {
        $descriptionContent .= '<dt class="col-sm-3">Process Label:</dt><dd class="col-sm-9">' . htmlspecialchars($this->getStudy()->processLabel) . '</dd>';
      }

      $learningObjectives = trim((string) ($this->getStudy()->hasLearningObjectives ?? ''));
      $learningObjectivesDisplay = $learningObjectives !== ''
        ? htmlspecialchars($learningObjectives)
        : '<span class="text-muted"><em>Not provided</em></span>';
      $descriptionContent .= '<dt class="col-sm-3">Learning Objectives:</dt><dd class="col-sm-9">' . $learningObjectivesDisplay . '</dd>';

      $criticalActions = trim((string) ($this->getStudy()->hasCriticalActions ?? ''));
      $criticalActionsDisplay = $criticalActions !== ''
        ? htmlspecialchars($criticalActions)
        : '<span class="text-muted"><em>Not provided</em></span>';
      $descriptionContent .= '<dt class="col-sm-3">Critical Actions:</dt><dd class="col-sm-9">' . $criticalActionsDisplay . '</dd>';

      $debriefingFocus = trim((string) ($this->getStudy()->hasDebriefingFocus ?? ''));
      $debriefingFocusDisplay = $debriefingFocus !== ''
        ? htmlspecialchars($debriefingFocus)
        : '<span class="text-muted"><em>Not provided</em></span>';
      $descriptionContent .= '<dt class="col-sm-3">Debriefing Focus:</dt><dd class="col-sm-9">' . $debriefingFocusDisplay . '</dd>';
      
      $descriptionContent .= '</dl>';
      $descriptionContent .= '</section>';
    }
    
    $descriptionContent .= '</div>';

    $form['row2a']['item']['collapse']['body']['content'] = [
      '#type'   => 'markup',
      '#markup' => Markup::create($descriptionContent),
    ];

    // If this is a ProcessBasedStudy, add Workflow Canvas section
    if ($isProcessBasedStudy) {
      if (!empty($processUri)) {
        // Check if the workflow exists in HASCOAPI
        $workflowExists = true;
        $workflowProbeError = '';
        if (\Drupal::hasService('ctt.hasco_client')) {
          try {
            $probe = \Drupal::service('ctt.hasco_client')->getByUri($processUri);
            if (!is_array($probe) || !empty($probe['error'])) {
              $workflowExists = false;
              $workflowProbeError = is_array($probe) ? (string) ($probe['error'] ?? '') : '';
            }
          }
          catch (\Throwable $e) {
            $workflowExists = false;
            $workflowProbeError = $e->getMessage();
          }
        }

      // Attach workflow preview library
      $form['#attached']['library'][] = 'rep/workflow_preview';

      // Create inner collapsible for Workflow Canvas
      $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['mt-4']],
      ];

      $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['workflow-canvas-block'],
        ],
      ];

      if ($workflowExists) {
        $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['#attributes']['data-workflow-preview-block'] = '1';
      }

      $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_header'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['workflow-canvas-header'],
        ],
      ];

      $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_header']['title'] = [
        '#type' => 'html_tag',
        '#tag' => 'h4',
        '#value' => $this->t('Workflow Canvas'),
        '#attributes' => [
          'class' => ['workflow-canvas-title'],
        ],
      ];

      $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_header']['actions'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['workflow-canvas-actions'],
        ],
      ];

      if ($workflowExists) {
        $collapseTitle = (string) $this->t('Collapse workflow canvas');
        $fullscreenTitle = (string) $this->t('Enter fullscreen');

        $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_header']['actions']['collapse'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => Markup::create('<i class="fa fa-chevron-up" aria-hidden="true"></i><span class="workflow-preview-sr">' . $this->t('Collapse workflow canvas') . '</span>'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['workflow-preview-collapse-btn', 'workflow-preview-icon-btn'],
            'data-workflow-preview-collapse' => '1',
            'aria-expanded' => 'true',
            'aria-controls' => 'ctt-workflow-app',
            'title' => $collapseTitle,
          ],
        ];

        $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_header']['actions']['fullscreen'] = [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => Markup::create('<i class="fa fa-expand" aria-hidden="true"></i><span class="workflow-preview-sr">' . $this->t('Enter fullscreen') . '</span>'),
          '#attributes' => [
            'type' => 'button',
            'class' => ['workflow-preview-fullscreen-btn', 'workflow-preview-icon-btn'],
            'data-workflow-preview-fullscreen' => '1',
            'aria-pressed' => 'false',
            'title' => $fullscreenTitle,
          ],
        ];
      }

      $editorPreviewUrl = Url::fromUserInput('/ctt/editor', [
        'query' => [
          'processUri' => $processUri,
          'execution' => '1',
        ],
      ])->toString();

      $openStableLabel = (string) $this->t('Open stable editor');
      $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_header']['actions']['open_stable_editor'] = [
        '#type' => 'link',
        '#title' => Markup::create('<i class="fa fa-external-link" aria-hidden="true"></i><span class="workflow-preview-sr">' . $this->t('Open stable editor') . '</span>'),
        '#url' => Url::fromUserInput('/ctt/editor', [
          'query' => [
            'processUri' => $processUri,
            'execution' => '1',
          ],
        ]),
        '#attributes' => [
          'class' => ['workflow-preview-open-editor-btn', 'workflow-preview-icon-btn'],
          'title' => $openStableLabel,
        ],
      ];

      if (!$workflowExists) {
        $warningMessage = (string) $this->t('This workflow URI is not available in HASCOAPI right now, so embedded canvas cannot be rendered.');
        if ($workflowProbeError !== '') {
          $warningMessage .= ' ' . (string) $this->t('Backend detail: @detail', ['@detail' => $workflowProbeError]);
        }

        $createModeUrl = Url::fromUserInput('/ctt/editor', [
          'query' => [
            'processUri' => $processUri,
            'execution' => '0',
          ],
        ])->toString();

        $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_unavailable'] = [
          '#type' => 'markup',
          '#markup' => '<div class="alert alert-warning workflow-preview-unavailable" role="alert">'
            . '<h4 class="alert-heading" style="margin-top:0;">' . $this->t('Workflow canvas unavailable') . '</h4>'
            . '<p>' . $warningMessage . '</p>'
            . '<p><small>URI: ' . Html::escape($processUri) . '</small></p>'
            . '<div class="workflow-preview-unavailable-actions">'
            . '<a class="workflow-preview-open-editor-btn" href="' . Html::escape($editorPreviewUrl) . '">' . $this->t('Open stable editor') . '</a> '
            . '<a class="workflow-preview-open-editor-btn workflow-preview-open-editor-btn-secondary" href="' . Html::escape($createModeUrl) . '">' . $this->t('Open editor in create mode') . '</a>'
            . '</div>'
            . '</div>',
        ];
      }
      else {
        // Attach CTT editor library
        $form['#attached']['library'][] = 'ctt/ctt-editor-init';

        $currentUser = \Drupal::currentUser();
        $drupalBaseUrl = \Drupal::request()->getSchemeAndHttpHost() . \Drupal::request()->getBasePath() . '/';

        $existingCttSettings = $form['#attached']['drupalSettings']['ctt'] ?? [];
        $form['#attached']['drupalSettings']['ctt'] = array_replace_recursive($existingCttSettings, [
          'drupalBaseUrl' => $drupalBaseUrl,
          'apiBaseUrl' => $drupalBaseUrl . 'workflow/api',
          'hascoApiUrl' => $drupalBaseUrl . 'workflow',
          'csrfToken' => \Drupal::csrfToken()->get('rest'),
          'processUri' => $processUri,
          'currentUser' => [
            'id' => (string) $currentUser->id(),
            'name' => $currentUser->getDisplayName(),
            'email' => (string) $currentUser->getEmail(),
          ],
          'execution' => [
            'mode' => 'execution',
            'daUri' => NULL,
            'dataFileUri' => NULL,
            'studyUri' => $this->getStudy()->uri,
            'processUri' => $processUri,
            'readOnlyPreview' => true,
          ],
          'readOnlyPreview' => true,
        ]);

        $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_body'] = [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['workflow-canvas-body'],
          ],
        ];

        $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_body']['workflow_canvas'] = [
          '#type' => 'container',
          '#attributes' => [
            'id' => 'ctt-workflow-app',
            'class' => ['ctt-workflow-preview-app'],
            'data-ctt-min-height' => '520',
            'data-workflow-preview-editor-url' => $editorPreviewUrl,
          ],
        ];

        $form['row2a']['item']['collapse']['body']['workflow_canvas_wrapper']['workflow_canvas_block']['workflow_canvas_body']['workflow_canvas']['loading'] = [
          '#type' => 'markup',
          '#markup' => '<div class="ctt-loading-indicator"><div class="ctt-loading-content"><div class="ajax-progress ajax-progress-throbber"><div class="throbber">&nbsp;</div></div><p class="ctt-loading-text">' . $this->t('Loading workflow canvas...') . '</p></div></div>',
        ];
      }
      }
    }

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
        '#value'      => '<h3 id="total_elements_count" class="mb-0">Data Contents</h3>' .
          ($isOwner ?
            '&nbsp;<div class="info-card text-center w-80">(You can drag and drop files directly onto this card)</div>' :
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
        'class'           => ['accordion-collapse', 'collapse'], // `show` = start expanded
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
      '#empty'    => t('No streams were found'),
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
              <div class="info-card">Card data refreshes every 15 seconds</div>
            </div>
            <div class="card-body">
              <div id="topic-list-table">Loading...</div>
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
              <div id="data-files-table">Loading...</div>
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
      '#empty'    => t('No streams were found'),
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
      '#attributes' => ['class' => ['row', 'align-items-start', 'g-3', 'std-fixed-files-row']],
    ];

    // Study Data Files (one-third width)
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container']['fixed_row']['study_data_files'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-xl-3', 'col-lg-6', 'col-md-6', 'col-12', 'std-fixed-files-col']],
      'card' => [
        '#type'   => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3>' . $cards[12]['value'] . '</h3>
            </div>
            <div class="card-body">
              <div id="json-table-container">Loading...</div>
            </div>
            <div class="card-footer text-center">
              <div id="json-table-pager" class="std-pager"></div>
            </div>
          </div>
        ',
      ],
    ];

    // Medical Images
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container']['fixed_row']['medical_images'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-xl-3', 'col-lg-6', 'col-md-6', 'col-12', 'std-fixed-files-col']],
      'card' => [
        '#type'   => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3>' . $cards[15]['value'] . '</h3>
            </div>
            <div class="card-body">
              <div id="medical-images-table-container">Loading...</div>
            </div>
            <div class="card-footer text-center">
              <div id="medical-images-table-pager" class="std-pager"></div>
            </div>
          </div>
        ',
      ],
    ];

    // Publications
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container']['fixed_row']['publications'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-xl-3', 'col-lg-6', 'col-md-6', 'col-12', 'std-fixed-files-col']],
      'card' => [
        '#type'   => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3>' . $cards[3]['value'] . '</h3>
            </div>
            <div class="card-body">
              <div id="publication-table-container">Loading...</div>
            </div>
            <div class="card-footer text-center">
              <div id="publication-table-pager" class="std-pager"></div>
            </div>
          </div>
        ',
      ],
    ];

    // Media
    $form['row2']['accordion']['item']['collapse']['body']['inner_row']['fixed_cards_container']['fixed_row']['media'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['col-xl-3', 'col-lg-6', 'col-md-6', 'col-12', 'std-fixed-files-col']],
      'card' => [
        '#type'   => 'markup',
        '#markup' => '
          <div class="card">
            <div class="card-header text-center">
              <h3>' . $cards[4]['value'] . '</h3>
            </div>
            <div class="card-body">
              <div id="media-table-container">Loading...</div>
            </div>
            <div class="card-footer text-center">
              <div id="media-table-pager" class="std-pager"></div>
            </div>
          </div>
        ',
      ],
    ];

    // WORKFLOW EXECUTIONS
    $preferredProcessLabel = $config->get('preferred_process') ?: 'Workflow';
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
        '#value' => $this->t('<h3 class="mb-0">Process Executions</h3>'),
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
        'class' => ['accordion-collapse','collapse'],
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
      '#attributes' => ['class' => ['row','g-0','p-3','pt-0']],
    ];

    $processExecutionsReturnTo = Url::fromRoute('std.manage_study_elements', [
      'studyuri' => base64_encode((string) $this->getStudy()->uri),
    ], [
      'fragment' => 'process-executions',
    ])->toString();

    $effectiveProcessUri = trim($currentProcessUri);
    if ($effectiveProcessUri === '' && !empty($associatedWorkflows)) {
      foreach ($associatedWorkflows as $workflowCandidate) {
        $candidateUri = trim((string) ($workflowCandidate->uri ?? ''));
        if ($candidateUri !== '') {
          $effectiveProcessUri = $candidateUri;
          break;
        }
      }
    }

    $toolExecutionRows = '';
    $toolExecutions = $this->getAnalyticalToolExecutionsForStudy((string) $this->getStudy()->uri, $effectiveProcessUri);
    foreach ($toolExecutions as $execution) {
      $toolLabel = Html::escape((string) ($execution['toolLabel'] ?? $execution['toolUri'] ?? 'Unknown tool'));
      $startedAt = Html::escape((string) ($execution['startedAt'] ?? '-'));
      $endedAt = Html::escape((string) ($execution['endedAt'] ?? '-'));
      $statusLabel = strtolower(trim((string) ($execution['status'] ?? '')));
      $resultsLink = '-';
      $resultUri = trim((string) ($execution['resultUri'] ?? ''));
      if ($resultUri !== '' && (str_starts_with($resultUri, 'http://') || str_starts_with($resultUri, 'https://'))) {
        $resultsLink = '<a href="' . Html::escape($resultUri) . '" target="_blank" rel="noopener noreferrer">Results</a>';
      }
      elseif (!empty($execution['runId'])) {
        $fallbackUrl = Url::fromRoute('ctt.r_analysis', [], [
          'query' => [
            'studyUri' => (string) $this->getStudy()->uri,
            'processUri' => $effectiveProcessUri,
          ],
        ])->toString();
        $resultsLink = '<a href="' . Html::escape($fallbackUrl) . '" target="_blank" rel="noopener noreferrer">View Run ' . Html::escape((string) $execution['runId']) . '</a>';
      }

      if ($statusLabel !== '') {
        $resultsLink .= ' <span class="badge bg-light text-dark border ms-1">' . Html::escape($statusLabel) . '</span>';
      }

      $toolExecutionRows .= '<tr>'
        . '<td class="text-break">' . $toolLabel . '</td>'
        . '<td>' . $startedAt . '</td>'
        . '<td>' . $endedAt . '</td>'
        . '<td>' . $resultsLink . '</td>'
        . '</tr>';
    }

    if ($toolExecutionRows === '') {
      $toolExecutionRows = '<tr><td colspan="4" class="text-center text-muted">No tool executions found for this scenario/process.</td></tr>';
    }

    $availableToolRows = '';
    $availableTools = $this->getAvailableAnalyticalToolsForProcess($api, $effectiveProcessUri);
    foreach ($availableTools as $toolEntry) {
      $toolName = trim((string) ($toolEntry['name'] ?? $toolEntry['label'] ?? $toolEntry['toolUri'] ?? ''));
      if ($toolName === '') {
        $toolName = (string) $this->t('Unnamed tool');
      }

      $toolUri = trim((string) ($toolEntry['toolUri'] ?? $toolEntry['uri'] ?? ''));
      $isGenericSimulator = in_array(strtolower($toolName), [
        'individual ctt simulator',
        'cohort ctt simulator',
      ], TRUE);

      $inputContent = $isGenericSimulator
        ? (string) $this->t('Task model')
        : trim((string) ($toolEntry['datasetUri'] ?? ''));
      if ($inputContent === '') {
        $inputContent = $isGenericSimulator
          ? (string) $this->t('Task model')
          : (string) $this->t('Study dataset (as configured)');
      }

      $runUrl = '';
      if ($isGenericSimulator) {
        $runUrl = Url::fromRoute('ctt.submission_entry', [
          'studyuri' => base64_encode((string) $this->getStudy()->uri),
        ], [
          'query' => [
            'processUri' => $effectiveProcessUri,
            'autoExecute' => '1',
            'executionPanel' => 'top',
            'returnTo' => $processExecutionsReturnTo,
          ],
        ])->toString();
      }
      else {
        $runUrl = Url::fromRoute('ctt.r_analysis', [], [
          'query' => [
            'studyUri' => (string) $this->getStudy()->uri,
            'processUri' => $effectiveProcessUri,
            'toolUri' => $toolUri,
          ],
        ])->toString();
      }

      $actions = '<a href="' . Html::escape($runUrl) . '" class="btn btn-sm btn-primary me-1" target="_blank" rel="noopener noreferrer">Run</a>'
        . '<button type="button"'
        . ' class="btn btn-sm btn-outline-danger ctt-tool-abort-button"'
        . ' data-study-uri="' . Html::escape((string) $this->getStudy()->uri) . '"'
        . ' data-process-uri="' . Html::escape($effectiveProcessUri) . '"'
        . ' data-tool-uri="' . Html::escape($toolUri) . '"'
        . '>Abort</button>';

      $availableToolRows .= '<tr>'
        . '<td class="text-break">' . Html::escape($toolName) . '</td>'
        . '<td class="text-break">' . Html::escape($inputContent) . '</td>'
        . '<td style="white-space: nowrap; text-align: center;">' . $actions . '</td>'
        . '</tr>';
    }

    if ($availableToolRows === '') {
      $availableToolRows = '<tr><td colspan="3" class="text-center text-muted">No tools available for the current process.</td></tr>';
    }

    $form['row6']['item']['collapse']['body']['cards_row']['tool_executions_table'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<div class="col-12 mt-3">'
          . '<div class="card">'
          . '<div class="card-header text-center" id="process-executions"><h3 class="mb-0">Tool Executions</h3></div>'
          . '<div class="card-body">'
          . '<table class="table table-striped table-bordered mb-0">'
          . '<thead><tr><th>Tool</th><th>Start Date</th><th>End Date</th><th>Results</th></tr></thead>'
          . '<tbody>' . $toolExecutionRows . '</tbody>'
          . '</table>'
          . '</div>'
          . '</div>'
          . '</div>'
      ),
    ];

    $form['row6']['item']['collapse']['body']['cards_row']['available_tools_table'] = [
      '#type' => 'markup',
      '#markup' => Markup::create(
        '<div class="col-12 mt-3">'
          . '<div class="card">'
          . '<div class="card-header text-center"><h3 class="mb-0">Available Tools</h3></div>'
          . '<div class="card-body">'
          . '<table class="table table-striped table-bordered mb-0">'
          . '<thead><tr><th>Tool</th><th>Input Content</th><th style="width: 1%; white-space: nowrap; text-align: center;">Actions</th></tr></thead>'
          . '<tbody>' . $availableToolRows . '</tbody>'
          . '</table>'
          . '</div>'
          . '</div>'
          . '</div>'
      ),
    ];

    // Bottom part of the form
    $form['row4'] = array(
      '#type' => 'container',
      '#attributes' => ['class' => ['row']],
      '#type' => 'markup',
      '#markup' => '<p><br /><b>Note</b>: Data Dictionaries (DD) and Semantic Data Dictionaries (SDD) are added' .
        ' to studies through their corresponding data files.</p><br>',
    );

    $form['row5'] = array(
      '#type' => 'container',
      '#attributes' => array('class' => array('row')),
    );

    $form['back_link'] = [
      '#type' => 'submit',
      '#value' => $this->t($backButtonLabel),
      '#name' => 'back',
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'back-button'],
        'style' => 'min-width: 260px; white-space: nowrap;',
      ],
    ];

    $form['row7']['space'] = [
      '#type' => 'markup',
      '#attributes' => array('class' => array('col-md-1')),
      '#markup' => '<br><br><br><br>',
    ];


    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? '';

    if ($button_name === 'associate_workflow') {
      $workflowUri = trim((string) $form_state->getValue('associate_workflow_uri', ''));
      if ($workflowUri === '') {
        $form_state->setErrorByName('associate_workflow_uri', $this->t('Select a workflow to associate.'));
      }
    }

    if ($button_name === 'add_students') {
      $countRaw = (string) $this->getStudentsCountRawFromFormState($form_state);
      if ($countRaw === '' || !ctype_digit($countRaw)) {
        $form_state->setErrorByName('students_count', $this->t('Please provide a valid number of students between 1 and 100.'));
        return;
      }

      $count = (int) $countRaw;
      if ($count < 1 || $count > 100) {
        $form_state->setErrorByName('students_count', $this->t('Please provide a valid number of students between 1 and 100.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'] ?? '';

    if ($button_name === 'generate_pdf') {
      $encodedStudyUri = (string) (\Drupal::routeMatch()->getParameter('studyuri') ?? '');
      $decodedStudyUri = base64_decode($encodedStudyUri, TRUE);
      if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
        \Drupal::messenger()->addError($this->t('Unable to generate PDF: invalid study URI.'));
        return;
      }

      $svgMarkup = trim((string) $form_state->getValue('workflow_canvas_svg', ''));
      $pngDataUrl = trim((string) $form_state->getValue('workflow_canvas_png', ''));
      $captureSource = trim((string) $form_state->getValue('workflow_canvas_source', 'none'));
      $svgKey = sha1($decodedStudyUri . '|' . microtime(TRUE));
      \Drupal::service('tempstore.private')->get('std')->set('study_report_svg_' . $svgKey, [
        'svg' => $svgMarkup,
        'png' => $pngDataUrl,
        'source' => $captureSource !== '' ? $captureSource : 'none',
      ]);

      $routeParams = ['studyuri' => $encodedStudyUri];
      $routeOptions = ['query' => ['svg_key' => $svgKey]];

      $form_state->setRedirect('std.download_study_report_pdf', $routeParams, $routeOptions);
      return;
    }

    if ($button_name === 'generate_wkf') {
      $encodedStudyUri = (string) (\Drupal::routeMatch()->getParameter('studyuri') ?? '');
      $decodedStudyUri = base64_decode($encodedStudyUri, TRUE);
      if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
        \Drupal::messenger()->addError($this->t('Unable to generate WKF: invalid study URI.'));
        return;
      }

      $scope = trim((string) $form_state->getValue('wkf_generation_scope', 'full'));
      if ($scope !== 'scenario') {
        $scope = 'full';
      }

      $this->generateWkfForStudy($decodedStudyUri, $scope, $form_state);
      return;
    }

    if ($button_name === 'associate_workflow') {
      $encodedStudyUri = (string) (\Drupal::routeMatch()->getParameter('studyuri') ?? '');
      $decodedStudyUri = base64_decode($encodedStudyUri, TRUE);
      if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
        \Drupal::messenger()->addError($this->t('Unable to associate workflow: invalid study URI.'));
        return;
      }

      $workflowUri = trim((string) $form_state->getValue('associate_workflow_uri', ''));
      if ($workflowUri === '') {
        \Drupal::messenger()->addError($this->t('Select a workflow to associate.'));
        return;
      }

      $this->persistStudyWorkflowAssociation($decodedStudyUri, $workflowUri);
      \Drupal::messenger()->addStatus($this->t('Workflow successfully associated with this study.'));

      $form_state->setRedirect('std.manage_study_elements', [
        'studyuri' => $encodedStudyUri,
      ]);
      return;
    }

    if ($button_name === 'add_students') {
      $encodedStudyUri = (string) (\Drupal::routeMatch()->getParameter('studyuri') ?? '');
      $decodedStudyUri = base64_decode($encodedStudyUri, TRUE);
      if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
        \Drupal::messenger()->addError($this->t('Unable to add students: invalid study URI.'));
        return;
      }

      $count = (int) $this->getStudentsCountRawFromFormState($form_state);
      if ($count < 1 || $count > 100) {
        \Drupal::messenger()->addError($this->t('Unable to add students: number must be between 1 and 100.'));
        return;
      }

      $this->includeStudentsInSoc((string) $decodedStudyUri, $count);
      $form_state->setRedirect('std.manage_study_elements', [
        'studyuri' => $encodedStudyUri,
      ]);
      return;
    }

    if ($button_name === 'back') {
      // Get tracked previous URL
      $uid = \Drupal::currentUser()->id();
      $previousUrl = Utils::trackingGetPreviousUrl($uid, 'std.manage_study_elements');
      
      if ($previousUrl) {
        // Use RedirectResponse and exit to ensure redirect happens
        $response = new RedirectResponse($previousUrl);
        $response->send();
        exit(); // Critical: exit to prevent further processing
      }
      
      // Default fallback: redirect to studies list
      $form_state->setRedirect('std.select_study', [
        'elementtype' => 'study',
        'page' => '1',
        'pagesize' => '12',
      ]);
      return;
    }
  }

  private function generateWkfForStudy(string $studyUri, string $scope, FormStateInterface $form_state): void {
    $studyUri = trim($studyUri);
    if ($studyUri === '') {
      \Drupal::messenger()->addError($this->t('Unable to generate WKF: invalid study URI.'));
      return;
    }

    $api = \Drupal::service('rep.api_connector');
    $originMgr = \Drupal::service('rep.file_origin_manager');

    $study = $this->getStudy();
    if (!is_object($study) || trim((string) ($study->uri ?? '')) !== $studyUri) {
      $study = $api->parseObjectResponse($api->getUri($studyUri), 'getUri');
    }

    if (!is_object($study)) {
      \Drupal::messenger()->addError($this->t('Unable to generate WKF: study could not be loaded from API.'));
      return;
    }

    $processUri = trim((string) ($study->processUri ?? $study->hasProcess ?? $study->hasProcessUri ?? ''));
    if (is_object($study->processUri ?? NULL)) {
      $processUri = trim((string) (($study->processUri->uri ?? '')));
    }

    if ($scope === 'full' && $processUri === '') {
      \Drupal::messenger()->addError($this->t('Unable to generate full WKF: this scenario has no associated process.'));
      return;
    }

    $studyLabel = trim((string) ($study->label ?? $study->title ?? 'scenario'));
    $slug = preg_replace('/[^A-Za-z0-9\-]+/', '-', strtoupper($studyLabel));
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
      $slug = 'SCENARIO';
    }

    $suffix = $scope === 'scenario' ? 'SCENARIO-ONLY' : 'FULL';
    $filename = 'WKF-' . $slug . '-' . $suffix . '-' . gmdate('Ymd-His') . '.xlsx';

    $raw = $api->generateMTPerElement(
      'wkf',
      'none',
      $studyUri,
      $filename,
      'none',
      $studyUri,
      $scope !== 'scenario'
    );
    $generated = $api->parseObjectResponse($raw, 'generateMTPerElement');
    $generatedFilename = basename(trim((string) $generated));

    if ($generatedFilename === '') {
      \Drupal::messenger()->addError($this->t('WKF generation failed: API did not return a generated filename.'));
      return;
    }

    $file = File::create([
      'uri' => 'private://generated_mt/' . $generatedFilename,
      'filename' => $generatedFilename,
      'status' => FileInterface::STATUS_PERMANENT,
      'uid' => \Drupal::currentUser()->id(),
    ]);
    $file->save();

    $originMgr->markApi((int) $file->id(), [
      'study_uri' => $studyUri,
      'process_uri' => $processUri,
      'scope' => $scope,
      'generator' => 'wkf',
    ]);

    $scopeLabel = $scope === 'scenario' ? 'Scenario only' : 'Scenario + Process + Task Model';
    \Drupal::messenger()->addStatus($this->t('WKF generation started (@scope). Downloading generated file...', [
      '@scope' => $scopeLabel,
    ]));

    $form_state->setRedirect('rep.file_download', [
      'fid' => (string) $file->id(),
    ]);
  }

  private function includeStudentsInSoc(string $studyUri, int $requestedCount): void
  {
    $studyUri = trim($studyUri);
    if ($studyUri === '' || $requestedCount < 1) {
      \Drupal::messenger()->addError($this->t('Unable to add students: invalid request.'));
      return;
    }

    $api = \Drupal::service('rep.api_connector');
    $useremail = (string) \Drupal::currentUser()->getEmail();

    $socsRaw = $api->studyObjectCollectionsByStudy($studyUri);
    $socs = $api->parseObjectResponse($socsRaw, 'studyObjectCollectionsByStudy');
    if (is_object($socs)) {
      $socs = [$socs];
    }
    if (!is_array($socs) || empty($socs)) {
      \Drupal::messenger()->addError($this->t('No object collections were found for this scenario.'));
      return;
    }

    $studentSoc = NULL;
    foreach ($socs as $soc) {
      if (!is_object($soc)) {
        continue;
      }
      $candidateUri = strtoupper(trim((string) ($soc->uri ?? '')));
      $candidateLabel = strtoupper(trim((string) ($soc->label ?? '')));
      if (strpos($candidateUri, 'SOC-STUDENT') !== FALSE || strpos($candidateLabel, 'SOC-STUDENT') !== FALSE) {
        $studentSoc = $soc;
        break;
      }
    }

    if (!is_object($studentSoc) || trim((string) ($studentSoc->uri ?? '')) === '') {
      \Drupal::messenger()->addError($this->t('SOC-STUDENT was not found for this scenario.'));
      return;
    }

    $studentSocUri = trim((string) $studentSoc->uri);
    $existingRaw = $api->studyObjectsBySOCwithPage($studentSocUri, 10000, 0);
    $existing = $api->parseObjectResponse($existingRaw, 'studyObjectsBySOCwithPage');
    if (is_object($existing)) {
      $existing = [$existing];
    }
    if (!is_array($existing)) {
      $existing = [];
    }

    $existingUris = [];
    foreach ($existing as $item) {
      if (is_object($item)) {
        $uri = trim((string) ($item->uri ?? ''));
        if ($uri !== '') {
          $existingUris[$uri] = TRUE;
        }
      }
    }

    $created = 0;
    for ($i = 1; $i <= $requestedCount; $i++) {
      $studentUri = $studentSocUri . $i;
      if (isset($existingUris[$studentUri])) {
        continue;
      }

      $payload = [
        'uri' => $studentUri,
        'typeUri' => HASCO::STUDY_OBJECT,
        'hascoTypeUri' => HASCO::STUDY_OBJECT,
        'isMemberOfUri' => $studentSocUri,
        'label' => 'Student ' . $i,
        'originalId' => (string) $i,
        'comment' => 'Auto-created student ' . $i . ' for scenario participant collection.',
        'hasSIRManagerEmail' => $useremail,
      ];

      $result = $api->parseObjectResponse(
        $api->elementAdd('studyobject', json_encode($payload, JSON_UNESCAPED_SLASHES)),
        'elementAdd'
      );

      if ($result !== NULL) {
        $created++;
      }
    }

    $existingCount = count($existingUris);
    $totalAfter = $existingCount + $created;
    if ($created > 0) {
      \Drupal::messenger()->addStatus($this->t('Students updated in SOC-STUDENT: @created created, @total now available. Verify in Objects of Interest under this SOC.', [
        '@created' => (string) $created,
        '@total' => (string) $totalAfter,
      ]));
      return;
    }

    \Drupal::messenger()->addStatus($this->t('No new students were created. SOC-STUDENT already contains the requested sequential student URIs up to @count. Verify in Objects of Interest.', [
      '@count' => (string) $requestedCount,
    ]));
  }

  private function getStudentsCountRawFromFormState(FormStateInterface $form_state): string
  {
    $direct = trim((string) $form_state->getValue('students_count', ''));
    if ($direct !== '') {
      return $direct;
    }

    $row1 = $form_state->getValue('row1_actions', NULL);
    if (is_array($row1) && isset($row1['students_count'])) {
      $nested = trim((string) $row1['students_count']);
      if ($nested !== '') {
        return $nested;
      }
    }

    $userInput = $form_state->getUserInput();
    if (is_array($userInput)) {
      if (isset($userInput['students_count'])) {
        $raw = trim((string) $userInput['students_count']);
        if ($raw !== '') {
          return $raw;
        }
      }

      if (isset($userInput['row1_actions']) && is_array($userInput['row1_actions']) && isset($userInput['row1_actions']['students_count'])) {
        $rawNested = trim((string) $userInput['row1_actions']['students_count']);
        if ($rawNested !== '') {
          return $rawNested;
        }
      }

      $rawDeep = $this->findStudentsCountInInput($userInput);
      if ($rawDeep !== '') {
        return $rawDeep;
      }
    }

    return '';
  }

  private function findStudentsCountInInput(array $input): string
  {
    foreach ($input as $key => $value) {
      if (is_array($value)) {
        $found = $this->findStudentsCountInInput($value);
        if ($found !== '') {
          return $found;
        }
        continue;
      }

      if (!is_scalar($value)) {
        continue;
      }

      $normalizedKey = strtolower(trim((string) $key));
      if ($normalizedKey === 'students_count' || str_ends_with($normalizedKey, '[students_count]')) {
        $candidate = trim((string) $value);
        if ($candidate !== '') {
          return $candidate;
        }
      }
    }

    return '';
  }

  private function getRequiredInstrumentWarningData(string $processUri): ?array
  {
    $processUri = trim($processUri);
    if ($processUri === '') {
      return NULL;
    }

    if (!\Drupal::hasService('ctt.hasco_client')) {
      return NULL;
    }

    try {
      $cttClient = \Drupal::service('ctt.hasco_client');
    }
    catch (\Throwable $e) {
      return NULL;
    }

    if (!is_object($cttClient) || !method_exists($cttClient, 'getTasksByProcess')) {
      return NULL;
    }

    try {
      $tasks = $cttClient->getTasksByProcess($processUri);
    }
    catch (\Throwable $e) {
      return NULL;
    }

    if (!is_array($tasks) || empty($tasks)) {
      return NULL;
    }

    $missingUris = [];
    $affectedTasks = [];
    $checkedUris = [];
    $availability = [];

    foreach ($tasks as $taskItem) {
      $task = is_object($taskItem) ? get_object_vars($taskItem) : $taskItem;
      if (!is_array($task)) {
        continue;
      }

      $taskUri = trim((string) ($task['uri'] ?? $task['hasURI'] ?? ''));
      $taskLabel = trim((string) ($task['label'] ?? $task['title'] ?? ''));
      $taskKey = $taskUri !== '' ? $taskUri : sha1(json_encode($task));

      $requiredInstrumentUris = $this->extractRequiredInstrumentUrisFromTask($task);
      foreach ($requiredInstrumentUris as $candidateUri) {
        if ($candidateUri === '') {
          continue;
        }

        if ($this->looksLikeRequiredInstrumentReferenceUri($candidateUri)) {
          $missingUris[$candidateUri] = TRUE;
          $affectedTasks[$taskKey] = [
            'uri' => $taskUri,
            'label' => $taskLabel,
          ];
          continue;
        }

        if (!isset($checkedUris[$candidateUri])) {
          $checkedUris[$candidateUri] = TRUE;
          $isAvailable = FALSE;

          try {
            if (method_exists($cttClient, 'getByUri')) {
              $instrumentEntity = $cttClient->getByUri($candidateUri);
              $isAvailable = is_array($instrumentEntity)
                && empty($instrumentEntity['error'])
                && trim((string) ($instrumentEntity['uri'] ?? '')) !== '';
            }
          }
          catch (\Throwable $e) {
            $isAvailable = FALSE;
          }

          $availability[$candidateUri] = $isAvailable;
        }

        if (($availability[$candidateUri] ?? FALSE) === FALSE) {
          $missingUris[$candidateUri] = TRUE;
          $affectedTasks[$taskKey] = [
            'uri' => $taskUri,
            'label' => $taskLabel,
          ];
        }
      }
    }

    if (empty($missingUris)) {
      return NULL;
    }

    $missingList = array_keys($missingUris);
    sort($missingList, SORT_STRING);

    $affectedTaskList = array_values($affectedTasks);
    usort($affectedTaskList, static function (array $left, array $right): int {
      $leftKey = strtolower(trim((string) ($left['uri'] ?? $left['label'] ?? '')));
      $rightKey = strtolower(trim((string) ($right['uri'] ?? $right['label'] ?? '')));
      return strcmp($leftKey, $rightKey);
    });

    return [
      'processUri' => $processUri,
      'missingUris' => $missingList,
      'affectedTaskCount' => count($affectedTaskList),
      'affectedTasks' => $affectedTaskList,
    ];
  }

  private function extractRequiredInstrumentUrisFromTask(array $task): array
  {
    $uris = [];

    $requiredInstrumentEntries = $task['requiredInstrument'] ?? NULL;
    if (is_array($requiredInstrumentEntries)) {
      foreach ($requiredInstrumentEntries as $entry) {
        if (is_array($entry)) {
          $candidate = trim((string) ($entry['instrumentUri'] ?? $entry['usesInstrument'] ?? $entry['hasInstrument'] ?? ''));
          if ($candidate !== '') {
            $uris[$candidate] = TRUE;
          }
        }
        elseif (is_string($entry) && trim($entry) !== '') {
          $uris[trim($entry)] = TRUE;
        }
      }
    }

    $refs = $task['hasRequiredInstrumentUris'] ?? $task['hasRequiredInstrument'] ?? NULL;
    if (is_string($refs) && trim($refs) !== '') {
      $refs = preg_split('/\s*[|;]\s*/', trim($refs));
    }

    if (is_array($refs)) {
      foreach ($refs as $ref) {
        if (is_array($ref)) {
          $candidate = trim((string) ($ref['uri'] ?? $ref['instrumentUri'] ?? ''));
          if ($candidate !== '') {
            $uris[$candidate] = TRUE;
          }
        }
        elseif (is_string($ref) && trim($ref) !== '') {
          $uris[trim($ref)] = TRUE;
        }
      }
    }

    return array_keys($uris);
  }

  private function looksLikeRequiredInstrumentReferenceUri(string $uri): bool
  {
    $normalized = strtolower(trim($uri));
    if ($normalized === '') {
      return FALSE;
    }

    return strpos($normalized, '/rin/') !== FALSE
      || strpos($normalized, ':/rin/') !== FALSE
      || strpos($normalized, 'requiredinstrument') !== FALSE;
  }

  private function persistStudyWorkflowAssociation(string $studyUri, string $workflowUri): void
  {
    $studyUri = trim($studyUri);
    $workflowUri = trim($workflowUri);
    if ($studyUri === '' || $workflowUri === '') {
      return;
    }

    $state = \Drupal::state();
    $studyHash = sha1($studyUri);

    $state->set('ctt.study_process.' . $studyHash, $workflowUri);

    $existing = $state->get('ctt.study_processes.' . $studyHash, []);
    if (is_string($existing) && trim($existing) !== '') {
      $decoded = json_decode($existing, TRUE);
      if (is_array($decoded)) {
        $existing = $decoded;
      }
      else {
        $existing = array_map('trim', explode(',', $existing));
      }
    }

    if (!is_array($existing)) {
      $existing = [];
    }

    $normalized = [];
    foreach ($existing as $candidate) {
      if (!is_scalar($candidate)) {
        continue;
      }

      $candidate = trim((string) $candidate);
      if ($candidate !== '') {
        $normalized[$candidate] = TRUE;
      }
    }

    $normalized[$workflowUri] = TRUE;
    $state->set('ctt.study_processes.' . $studyHash, array_keys($normalized));
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
   * @return array<int, object>
   */
  private function getAssociatedWorkflowsForStudy($api, string $studyUri, string $userEmail): array
  {
    $associated = [];
    $seenUris = [];

    $appendWorkflow = function ($workflow) use (&$associated, &$seenUris): void {
      if (!is_object($workflow)) {
        return;
      }

      $workflowUri = trim((string) ($workflow->uri ?? ''));
      if ($workflowUri === '' || isset($seenUris[$workflowUri])) {
        return;
      }

      $seenUris[$workflowUri] = TRUE;
      $associated[] = $workflow;
    };

    // Preserve explicitly selected study-process associations.
    $studyHash = sha1($studyUri);
    $storedProcessUris = [];

    $storedProcessUri = (string) \Drupal::state()->get('ctt.study_process.' . $studyHash, '');
    if ($storedProcessUri !== '') {
      $storedProcessUris[$storedProcessUri] = TRUE;
    }

    $storedProcessList = \Drupal::state()->get('ctt.study_processes.' . $studyHash, []);
    if (is_string($storedProcessList) && trim($storedProcessList) !== '') {
      $decoded = json_decode($storedProcessList, TRUE);
      if (is_array($decoded)) {
        $storedProcessList = $decoded;
      }
      else {
        $storedProcessList = array_map('trim', explode(',', $storedProcessList));
      }
    }

    if (is_array($storedProcessList)) {
      foreach ($storedProcessList as $candidateUri) {
        if (!is_scalar($candidateUri)) {
          continue;
        }

        $candidateUri = trim((string) $candidateUri);
        if ($candidateUri !== '') {
          $storedProcessUris[$candidateUri] = TRUE;
        }
      }
    }

    foreach (array_keys($storedProcessUris) as $candidateUri) {
      try {
        $storedProcess = $api->parseObjectResponse($api->getUri($candidateUri), 'getUri');
        $appendWorkflow($storedProcess);
      }
      catch (\Throwable $e) {
        // Keep collecting from list endpoints.
      }
    }

    $workflows = [];
    $workflowsAreStudyScoped = FALSE;

    try {
      $studyScoped = $api->parseObjectResponse(
        $api->listByManagerEmailByStudy($studyUri, 'workflow', $userEmail, 9999, 0),
        'listByManagerEmail'
      );

      if (is_array($studyScoped) && !empty($studyScoped)) {
        $workflows = $studyScoped;
        $workflowsAreStudyScoped = TRUE;
      }
    }
    catch (\Throwable $e) {
      // Fallback handled below.
    }

    if (empty($workflows)) {
      try {
        $workflows = $api->parseObjectResponse($api->listByManagerEmail('workflow', $userEmail, 9999, 0), 'listByManagerEmail');
      }
      catch (\Throwable $e) {
        $workflows = [];
      }
    }

    if (!is_array($workflows) || empty($workflows)) {
      try {
        $workflows = $api->parseObjectResponse($api->listByKeyword('workflow', '_', 9999, 0), 'listByKeyword');
      }
      catch (\Throwable $e) {
        $workflows = [];
      }
    }

    if (is_array($workflows)) {
      foreach ($workflows as $workflow) {
        if (!is_object($workflow)) {
          continue;
        }

        if ($workflowsAreStudyScoped || $this->isWorkflowAssociatedWithStudy($workflow, $studyUri)) {
          $appendWorkflow($workflow);
        }
      }
    }

    usort($associated, function ($left, $right) {
      $leftLabel = strtolower((string) ($left->label ?? $left->title ?? $left->uri ?? ''));
      $rightLabel = strtolower((string) ($right->label ?? $right->title ?? $right->uri ?? ''));
      return strcmp($leftLabel, $rightLabel);
    });

    return $associated;
  }

  /**
   * Resolve available analytical tools for one process URI.
   *
   * Includes tools explicitly linked to the process and wildcard tools ('*').
   *
   * @return array<int, array<string, mixed>>
   */
  private function getAvailableAnalyticalToolsForProcess($api, string $processUri): array
  {
    $normalizedProcessUri = trim($processUri);

    $allTools = [];
    $allToolsRaw = [];
    try {
      if ($normalizedProcessUri !== '') {
        $allToolsRaw = $api->listAnalyticalToolsByProcess($normalizedProcessUri);
      }
      else {
        // If no process is selected yet, ask for wildcard scope directly.
        $allToolsRaw = $api->listAnalyticalToolsByProcess(self::ANY_PROCESS_URI);
      }
    }
    catch (\Throwable $e) {
      $allToolsRaw = [];
    }

    if (is_string($allToolsRaw) && trim($allToolsRaw) !== '') {
      $decoded = json_decode($allToolsRaw, TRUE);
      if (is_array($decoded)) {
        if (isset($decoded['body']) && is_array($decoded['body'])) {
          $allTools = $decoded['body'];
        }
        elseif (isset($decoded[0])) {
          $allTools = $decoded;
        }
      }
    }
    elseif (is_object($allToolsRaw)) {
      $asArray = get_object_vars($allToolsRaw);
      if (isset($asArray['body']) && is_array($asArray['body'])) {
        $allTools = $asArray['body'];
      }
    }
    elseif (is_array($allToolsRaw)) {
      $allTools = $allToolsRaw;
    }

    $filtered = [];
    foreach ($allTools as $tool) {
      if (is_object($tool)) {
        $tool = get_object_vars($tool);
      }

      if (!is_array($tool)) {
        continue;
      }

      $toolUri = trim((string) ($tool['toolUri'] ?? $tool['uri'] ?? ''));
      if ($toolUri === '') {
        continue;
      }

      $processCandidate = trim((string) ($tool['hasProcessUri'] ?? $tool['processUri'] ?? ''));
      if ($processCandidate !== ''
        && $processCandidate !== '*'
        && $processCandidate !== self::ANY_PROCESS_URI
        && $processCandidate !== $normalizedProcessUri
      ) {
        continue;
      }

      $tool['toolUri'] = $toolUri;
      if ($processCandidate !== '') {
        $tool['processUri'] = $processCandidate;
      }
      $filtered[$toolUri] = $tool;
    }

    // Fallback: include wildcard tools from local CTT catalog if API response is empty
    // or does not include those entries yet.
    $catalog = \Drupal::state()->get('ctt.analytical_tools.catalog.v1', []);
    if (is_array($catalog)) {
      foreach ($catalog as $toolUri => $tool) {
        if (is_object($tool)) {
          $tool = get_object_vars($tool);
        }
        if (!is_array($tool)) {
          continue;
        }

        $candidateToolUri = trim((string) ($tool['toolUri'] ?? (is_string($toolUri) ? $toolUri : '')));
        if ($candidateToolUri === '') {
          continue;
        }

        $processCandidate = trim((string) ($tool['hasProcessUri'] ?? $tool['processUri'] ?? ''));
        $isWildcard = $processCandidate === '*' || $processCandidate === self::ANY_PROCESS_URI;
        $matchesProcess = $normalizedProcessUri !== '' && $processCandidate === $normalizedProcessUri;

        if (!$isWildcard && !$matchesProcess) {
          continue;
        }

        $tool['toolUri'] = $candidateToolUri;
        if ($processCandidate !== '') {
          $tool['processUri'] = $processCandidate;
        }
        $filtered[$candidateToolUri] = $tool;
      }
    }

    return array_values($filtered);
  }

  /**
   * Resolve tool execution history for one study/process pair.
   *
   * @return array<int, array<string, string>>
   */
  private function getAnalyticalToolExecutionsForStudy(string $studyUri, string $processUri): array
  {
    $studyUri = trim($studyUri);
    $processUri = trim($processUri);
    if ($studyUri === '') {
      return [];
    }

    $historyKey = 'ctt.r_analysis_runs.' . sha1($studyUri);
    $history = \Drupal::state()->get($historyKey, []);
    if (!is_array($history) || empty($history)) {
      return [];
    }

    $catalog = \Drupal::state()->get('ctt.analytical_tools.catalog.v1', []);
    if (!is_array($catalog)) {
      $catalog = [];
    }

    $executions = [];
    foreach ($history as $entry) {
      if (!is_array($entry)) {
        continue;
      }

      $entryProcess = trim((string) ($entry['processUri'] ?? ''));
      if ($processUri !== '' && $entryProcess !== '' && $entryProcess !== $processUri) {
        continue;
      }

      $toolUri = trim((string) ($entry['toolUri'] ?? ''));
      $toolLabel = $toolUri;
      if ($toolUri !== '' && isset($catalog[$toolUri]) && is_array($catalog[$toolUri])) {
        $catalogEntry = $catalog[$toolUri];
        $toolLabel = trim((string) ($catalogEntry['name'] ?? $catalogEntry['label'] ?? $toolUri));
      }

      $startedAt = trim((string) ($entry['requestedAt'] ?? ''));
      $startedAtCandidate = trim((string) ($entry['startedAt'] ?? ''));
      if ($startedAtCandidate !== '') {
        $startedAt = $startedAtCandidate;
      }
      if ($startedAt === '') {
        $startedAt = '-';
      }

      $finishedAt = trim((string) ($entry['finishedAt'] ?? ''));
      if ($finishedAt === '') {
        $finishedAt = '-';
      }

      $status = strtolower(trim((string) ($entry['status'] ?? '')));
      if ($status === '') {
        $status = 'completed';
      }

      $executions[] = [
        'runId' => trim((string) ($entry['runId'] ?? '')),
        'toolUri' => $toolUri,
        'toolLabel' => $toolLabel !== '' ? $toolLabel : 'Unknown tool',
        'startedAt' => $startedAt,
        'endedAt' => $finishedAt,
        'status' => $status,
        'resultUri' => trim((string) ($entry['resultUri'] ?? '')),
      ];
    }

    return $executions;
  }

  /**
   * @return array<int, object>
   */
  private function getWorkflowAssociationCandidates($api, string $userEmail): array
  {
    $candidates = [];
    $seenUris = [];

    $appendWorkflow = function ($workflow) use (&$candidates, &$seenUris): void {
      if (!is_object($workflow)) {
        return;
      }

      $workflowUri = trim((string) ($workflow->uri ?? ''));
      if ($workflowUri === '' || isset($seenUris[$workflowUri])) {
        return;
      }

      $seenUris[$workflowUri] = TRUE;
      $candidates[] = $workflow;
    };

    $workflowsByOwner = [];
    try {
      $workflowsByOwner = $api->parseObjectResponse($api->listByManagerEmail('workflow', $userEmail, 9999, 0), 'listByManagerEmail');
    }
    catch (\Throwable $e) {
      $workflowsByOwner = [];
    }

    if (is_array($workflowsByOwner)) {
      foreach ($workflowsByOwner as $workflow) {
        $appendWorkflow($workflow);
      }
    }

    $allWorkflows = [];
    try {
      $allWorkflows = $api->parseObjectResponse($api->listByKeyword('workflow', '_', 9999, 0), 'listByKeyword');
    }
    catch (\Throwable $e) {
      $allWorkflows = [];
    }

    if (is_array($allWorkflows)) {
      foreach ($allWorkflows as $workflow) {
        $appendWorkflow($workflow);
      }
    }

    usort($candidates, function ($left, $right) {
      $leftLabel = strtolower((string) ($left->label ?? $left->title ?? $left->uri ?? ''));
      $rightLabel = strtolower((string) ($right->label ?? $right->title ?? $right->uri ?? ''));
      return strcmp($leftLabel, $rightLabel);
    });

    return $candidates;
  }

  private function formatWorkflowOptionLabel(string $label, string $workflowUri): string
  {
    $label = trim($label);
    $workflowUri = trim($workflowUri);

    if ($label === '') {
      $label = $workflowUri;
    }

    if (strlen($workflowUri) <= 70) {
      return $label . ' [' . $workflowUri . ']';
    }

    $shortUri = substr($workflowUri, 0, 35) . '...' . substr($workflowUri, -25);
    return $label . ' [' . $shortUri . ']';
  }

  private function isWorkflowAssociatedWithStudy(object $workflow, string $studyUri): bool
  {
    $fields = [
      'studyUri',
      'study',
      'hasStudy',
      'hasStudyUri',
      'hasAssociatedStudy',
    ];

    foreach ($fields as $field) {
      if (!property_exists($workflow, $field)) {
        continue;
      }

      if ($this->workflowValueMatchesStudy($workflow->{$field}, $studyUri)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function workflowValueMatchesStudy($value, string $studyUri): bool
  {
    if (is_string($value)) {
      $candidate = trim($value);
      if ($candidate === '') {
        return FALSE;
      }

      if ($candidate === $studyUri) {
        return TRUE;
      }

      $decoded = base64_decode($candidate, TRUE);
      if (is_string($decoded) && $decoded !== '' && trim($decoded) === $studyUri) {
        return TRUE;
      }

      if (str_contains($candidate, ',')) {
        $parts = array_map('trim', explode(',', $candidate));
        return in_array($studyUri, $parts, TRUE);
      }

      return FALSE;
    }

    if (is_object($value)) {
      if (property_exists($value, 'uri') && is_string($value->uri) && trim($value->uri) === $studyUri) {
        return TRUE;
      }

      foreach (get_object_vars($value) as $innerValue) {
        if ($this->workflowValueMatchesStudy($innerValue, $studyUri)) {
          return TRUE;
        }
      }

      return FALSE;
    }

    if (is_array($value)) {
      foreach ($value as $innerValue) {
        if ($this->workflowValueMatchesStudy($innerValue, $studyUri)) {
          return TRUE;
        }
      }
    }

    return FALSE;
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
