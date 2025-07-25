<?php

namespace Drupal\std\Entity;

use Drupal\rep\Entity\Tables;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Vocabulary\VSTOI;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Component\Utility\Html;

class Task {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_name' => t('Name'),
      'element_number' => t('Number'),
      'element_tasktype' => t('Task Type'),
      'element_details' => t('Details'),
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

    // 2.b) Para cada elemento, converte o array requiredInstrument de stdClass → array
    foreach ($parsed as &$element) {
      if (isset($element['requiredInstrument']) && is_array($element['requiredInstrument'])) {
        $element['requiredInstrument'] = array_map(
          fn($instr) => is_object($instr) ? (array) $instr : $instr,
          $element['requiredInstrument']
        );
      }
    }
    unset($element);

    // 3) Prepare some helpers.
    $root_url  = \Drupal::request()->getBaseUrl();
    $tables    = new Tables();
    $languages = $tables->getLanguages();

    $output        = [];
    $disabled_rows = [];

    // 4) Build each row. We use array_values() so $delta is 0,1,2…
    foreach (array_values($parsed) as $delta => $element) {
      if (empty($element['uri'])) {
        continue; // Skip if no URI is present.
      }
      // --- a) Extract fields with safe defaults ---
      $uri_raw       = $element['uri'] ?? '';
      $namespacedUri = Utils::namespaceUri($element['uri']);
      $label         = $element['label'] ?? '';
      $lang_code     = $element['hasLanguage'] ?? NULL;
      $taskType      = $element['typeLabel'] ?? '';
      $taskTemporalDependency = $element['temporalDependencyLabel'] ?? '';
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
          if (!empty($instr['hasRequiredComponent']) && is_array($instr['hasRequiredComponent'])) {
            $totDet += count($instr['hasRequiredComponent']);
          }
          elseif (!empty($instr['components']) && is_array($instr['components'])) {
            $totDet += count($instr['components']);
          }
        }
      }

      $details = '';

      if ($element['typeUri'] === VSTOI::ABSTRACT_TASK) {
        $details .= 'Temporal Dependency: '.$taskTemporalDependency.'<br />';
      } else {
        $details .= '#Instruments: '.$totInst.'<br />';
        $details .= '#Components: '.$totDet;
      }

      // --- b) Build the “Edit” link ---
      $edit_url = Url::fromRoute('std.edit_task', [
        'processuri' => $processuri,
        'state'      => $element['typeUri'] === VSTOI::ABSTRACT_TASK ? 'tasks':'init',
        'taskuri'    => base64_encode($uri_raw),
      ])->toString();
      $edit_button_html = t(
        '<a class="btn btn-sm btn-primary edit-element-button" href=":url">Edit Task</a>',
        [':url' => $edit_url]
      );

      $encoded = base64_encode($uri_raw);
      $delete_url = Url::fromRoute('std.delete_subtask', [
          'processuri' => $processuri,
          'state'      => $element['typeUri'] === VSTOI::ABSTRACT_TASK ? 'tasks':'init',
          'parenttaskuri' => base64_encode($element['hasSupertaskUri']),
          'taskuri'    => base64_encode($uri_raw)
      ]);

      $remove_button = [
        '#type' => 'link',
        '#title' => t('Delete'),
        '#url' => $delete_url,
        '#attributes' => [
          'class' => ['use-ajax', 'btn','btn-sm','btn-danger','ms-2', 'delete-element-button'],
          'onclick' => "if (!confirm('Are you sure you want to delete this sub-task?')) { return false; }",
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
            '<a target="_new" href=":link">'.UTILS::namespaceUri($element['uri']) ?? ''.'</a>',
            [
              ':link'  => $root_url . REPGUI::DESCRIBE_PAGE . base64_encode($namespacedUri)
            ]
          ),
          'element_name'            => $label,
          'element_number'          => $delta + 1,
          'element_tasktype'        => $taskType,
          'element_details'         => t($details),
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
