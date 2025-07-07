<?php

namespace Drupal\std\Entity;

use Drupal\rep\Entity\Tables;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\Core\Url;

class Task {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_number' => t('Number'),
      'element_tasktype' => t('Task Type'),
      'element_temporaldependency' => t('Temporal Dependency'),
      'element_name' => t('Name'),
      // 'element_top_task' => t('Top Task'),
      // 'element_language' => t('Language'),
      'element_tot_instruments' => t('# Instruments'),
      'element_tot_detectors' => t('# Components'),
      'element_status' => t('Status'),
      'element_actions' => t('Actions'),
    ];
  }

  /**
   * Generate the renderable rows for the Sub-Tasks table, including
   * both “Edit” and AJAX-powered “Remove” buttons.
   *
   * @param array $list
   *   An array of URIs (or objects) representing each sub-task.
   * @param string $processuri
   *   The base64-encoded process URI, used to build the Edit link.
   *
   * @return array
   *   - 'output': associative array of renderable rows, keyed by raw URI
   *   - 'disabled_rows': map of row keys flagged as disabled
   *   - 'total_count': integer count of rows
   */
  public static function generateOutput(array $list, string $processuri): array {
    // 1) Early return if there are no sub-tasks.
    if (empty($list)) {
      return [
        'output'        => [],
        'disabled_rows' => [],
        'total_count'   => 0,
      ];
    }

    // 2) Normalize each item via the API so we have an array of plain arrays.
    $api    = \Drupal::service('rep.api_connector');
    $parsed = [];
    foreach ($list as $item) {
      $result = $api->parseObjectResponse($api->getUri($item), 'getUri');
      if (is_array($result)) {
        foreach ($result as $one) {
          $parsed[] = is_object($one) ? (array) $one : $one;
        }
      }
      else {
        $parsed[] = is_object($result) ? (array) $result : $result;
      }
    }

    // 3) Prepare some helpers.
    $root_url  = \Drupal::request()->getBaseUrl();
    $tables    = new Tables();
    $languages = $tables->getLanguages();

    $output        = [];
    $disabled_rows = [];

    // 4) Build each row. We use array_values() so $delta is 0,1,2…
    foreach (array_values($parsed) as $delta => $element) {
      // --- a) Extract fields with safe defaults ---
      $uri_raw       = $element['uri'] ?? '';
      $namespacedUri = Utils::namespaceUri($uri_raw);
      $label         = $element['label'] ?? '';
      $lang_code     = $element['hasLanguage'] ?? NULL;
      $taskType      = UTILS::labelFromAutocomplete($element['hasType']) ?? '';
      // $taskTemporalDependency = $element['hasTemporalDependency'] ?? NULL;
      $lang_label    = $lang_code && isset($languages[$lang_code])
                      ? $languages[$lang_code]
                      : '';
      $version       = $element['hasVersion'] ?? '';
      $topTaskRaw    = $element['hasSupertaskUri'] ?? '';
      $topTaskNs     = Utils::namespaceUri($topTaskRaw);

      // Human-readable status
      $status = '';
      if (!empty($element['hasStatus'])) {
        $statUri = $element['hasStatus'];
        $status  = parse_url($statUri, PHP_URL_FRAGMENT) ?: $statUri;
        if ($statUri === VSTOI::DRAFT && !empty($element['hasReviewNote'])) {
          $status = 'Draft (Already Reviewed)';
        }
        elseif ($statUri === VSTOI::UNDER_REVIEW) {
          $status = 'Under Review';
        }
      }

      // Counts
      $totInst = is_array($element['hasRequiredInstrumentUris'])
        ? count($element['hasRequiredInstrumentUris'])
        : 0;
      $totDet = 0;
      if (!empty($element['requiredInstrument']) && is_array($element['requiredInstrument'])) {
        foreach ($element['requiredInstrument'] as $instr) {
          if (!empty($instr['hasRequiredDetector']) && is_array($instr['hasRequiredDetector'])) {
            $totDet += count($instr['hasRequiredDetector']);
          }
          elseif (!empty($instr['detectors']) && is_array($instr['detectors'])) {
            $totDet += count($instr['detectors']);
          }
        }
      }

      // --- b) Build the “Edit” link ---
      $edit_url = Url::fromRoute('std.edit_task', [
        'processuri' => $processuri,
        'state'      => $element['hasType'] === VSTOI::ABSTRACT_TASK ? 'tasks':'basic',
        'taskuri'    => base64_encode($uri_raw),
      ])->toString();
      $edit_button_html = t(
        '<a class="btn btn-sm btn-primary edit-element-button" href=":url">Edit</a>',
        [':url' => $edit_url]
      );

      // --- c) Build the “Remove” AJAX submit button ---
      $encoded = base64_encode($uri_raw);
      $remove_button = [
        '#type'                    => 'submit',
        '#name'                    => "subtask_remove_$encoded",
        '#value'                   => t('Delete'),
        '#limit_validation_errors' => [],        // não valida o form todo
        '#ajax' => [
          'callback' => '::ajaxSubtasksCallback',
          'wrapper'  => 'subtasks-wrapper',     // o ID container inteiro
          'event'    => 'click',
        ],
        '#attributes' => [
          'class'   => ['btn','btn-sm','btn-danger','ms-2', 'delete-button'],
          'onclick' => "return confirm('Are you sure you want to delete this sub-task?');",
          'disabled' => TRUE, // TODO: falta terminal o callback no FORM
        ],
      ];

      // --- d) Combine both into a single “Actions” container ---
      $action_container = [
        '#type'       => 'container',
        '#attributes' => ['class' => ['d-flex','align-items-center']],
        'edit'        => ['#markup' => $edit_button_html],
        'remove'      => $remove_button,
      ];

      // --- e) Assemble the full row render array ---
      $rows[] = [
        'data' => [
          'element_uri'             => t(
            '<a target="_new" href=":link">:nsuri</a>',
            [
              ':link'  => $root_url . REPGUI::DESCRIBE_PAGE . base64_encode($namespacedUri),
              ':nsuri' => $namespacedUri,
            ]
          ),
          'element_number'          => $delta + 1, // 1-based index
          'element_tasktype'        => $taskType,
          'element_temporaldependency' => $taskTemporalDependency ?? '',
          'element_name'            => $label,
          // 'element_top_task'        => t(
          //   '<a target="_new" href=":link">:top</a>',
          //   [
          //     ':link' => $root_url . REPGUI::DESCRIBE_PAGE . base64_encode($topTaskRaw),
          //     ':top'  => $topTaskNs,
          //   ]
          // ),
          // 'element_language'        => $lang_label,
          'element_tot_instruments' => $totInst,
          'element_tot_detectors'   => $totDet,
          'element_status'          => $status,
          'element_actions'         => [
            'data' => $action_container,
          ],
        ],
        'id' => 'subtask-row-' . $encoded,
      ];
    }

    // 5) Return the table data structure.
    return [
      'output'        => $rows,
      'disabled_rows' => array_fill_keys($disabled_rows, TRUE),
      'total_count'   => count($parsed),
    ];
  }

  public static function generateReviewHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_name' => t('Name'),
      'element_language' => t('Language'),
      'element_version' => t('Version'),
      'element_status' => t('Status'),
    ];

  }

  public static function generateReviewOutput($list) {

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    // GET LANGUAGES
    $tables = new Tables;
    $languages = $tables->getLanguages();

    $output = array();
    foreach ($list as $element) {
      $uri = ' ';
      if ($element->uri != NULL) {
        $uri = $element->uri;
      }
      $uri = Utils::namespaceUri($uri);
      $label = ' ';
      if ($element->label != NULL) {
        $label = $element->label;
      }
      $lang = ' ';
      if ($element->hasLanguage != NULL) {
        if ($languages != NULL) {
          $lang = $languages[$element->hasLanguage];
        }
      }
      $version = ' ';
      if ($element->hasVersion != NULL) {
        $version = $element->hasVersion;
      }
      $status = ' ';
      if ($element->hasStatus != NULL) {

        // GET STATUS
        if ($element->hasStatus === VSTOI::DRAFT && $element->hasReviewNote !== NULL) {
          $status = "Draft (Already Reviewed)";
        } else if($element->hasStatus === VSTOI::UNDER_REVIEW) {
          $status = "Under Review";
        } else {
          $status = parse_url($element->hasStatus, PHP_URL_FRAGMENT);
        }

      }
      $owner = ' ';
      if ($element->hasSIRManagerEmail != NULL) {
        $owner = $element->hasSIRManagerEmail;
      }
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        'element_name' => $label,
        'element_language' => $lang,
        'element_version' => $version,
        'element_status' => $status,
        'element_owner' => $owner,
        'element_hasStatus' => parse_url($element->hasStatus, PHP_URL_FRAGMENT),
        'element_hasLanguage' => $element->hasLanguage,
        'element_hasImageUri' => $element->hasImageUri,
      ];
    }

    return $output;

  }
}
