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
      'element_tasktype' => t('Task Type'),
      'element_name' => t('Name'),
      'element_language' => t('Top Task'),
      'element_tot_instruments' => t('# Instruments'),
      'element_tot_detectors' => t('# Detectors'),
      'element_status' => t('Status'),
      'element_actions' => t('Actions'),
    ];
  }

  public static function generateOutput($list) {

    if (empty($list)) {
      return [];
    }

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    // GET LANGUAGES
    $tables = new Tables;
    $languages = $tables->getLanguages();

    $output = array();
    $disabled_rows = [];
    foreach ($list as $element) {
      $uri = ' ';
      if ($element['uri'] != NULL) {
        $uri = $element['uri'];
      }
      $tasktype = ' ';
      if ($element['tasktype'] != NULL) {
        $type = $element['tasktype'];
      }
      $typeLabel = ' ';
      if ($element['typeLabel'] != NULL) {
        $typeLabel = $element['typeLabel'];
      }
      $uri = Utils::namespaceUri($uri);
      $label = ' ';
      if ($element['label'] != NULL) {
        $label = $element['label'];
      }
      $lang = ' ';
      if ($element['hasLanguage'] != NULL) {
        if ($languages != NULL) {
          $lang = $languages[$element['hasLanguage']];
        }
      }
      $version = ' ';
      if ($element['hasVersion'] != NULL) {
        $version = $element['hasVersion'];
      }
      $status = ' ';
      if ($element['hasStatus'] != NULL) {
        // GET STATUS
        if ($element['hasStatus'] === VSTOI::DRAFT && $element['hasReviewNote'] !== NULL) {
          $status = "Draft (Already Reviewed)";
        } else if($element['hasStatus'] === VSTOI::UNDER_REVIEW) {
          $status = "Under Review";
        } else {
          $status = parse_url($element['hasStatus'], PHP_URL_FRAGMENT);
        }
      }
      $totInst = 0;
      if ($element['hasRequiredInstrumentationUris'] !== null && is_array($element['hasRequiredInstrumentationUris'])) {
        $totInst = count($element['hasRequiredInstrumentationUris']);
      }
      $totDet = 0;

      if (isset($element['requiredInstrumentation']) && is_array($element['requiredInstrumentation'])) {
          foreach ($element['requiredInstrumentation'] as $instrument) {
              if (isset($instrument['hasRequiredDetector']) && is_array($instrument['hasRequiredDetector'])) {
                  $totDet += count($instrument['hasRequiredDetector']);
              } elseif (isset($instrument['detectors']) && is_array($instrument['detectors'])) {
                  $totDet += count($instrument['detectors']);
              }
          }
      }

      // 1) Prepare the edit-link URL, re‐using the same route your form uses.
      $edit_url = Url::fromRoute('std.edit_task', [
        'processuri' => base64_encode(\Drupal::request()->get('processuri')),
        'state'      => 'tasks',
        'taskuri'    => base64_encode($element['uri']),
      ])->toString();

      // 2) Render the edit button HTML
      $edit_button = t('<a class="btn btn-sm btn-primary edit-element-button" href=":url">Edit</a>', [
        ':url' => $edit_url,
      ]);

      $output[$element['uri']] = [
        'element_uri' => t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        // 'element_tasktype' => t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($element->typeUri).'">'.$typeLabel.'</a>'),
        'element_tasktype' => $tasktype,
        'element_name' => $label,
        'element_language' => $lang,
        // 'element_version' => $version,
        'element_tot_instruments' => $totInst,
        'element_tot_detectors' => $totDet,
        'element_status' => $status,
        // 'element_hasStatus' => parse_url($element['hasStatus'], PHP_URL_FRAGMENT),
        // 'element_hasLanguage' => $element['hasLanguage'],
        // 'element_hasImageUri' => $element['hasImageUri'],
        // 3) Finally, inject our new “actions” column
        'element_actions' => [
          'data' => [
            '#markup' => $edit_button,
          ],
        ],
      ];
    }

    // Ensure disabled_rows is an associative array
    $normalized_disabled_rows = array_fill_keys($disabled_rows, TRUE);

    return [
      'output'        => $output,
      'disabled_rows' => $normalized_disabled_rows,
      'total_count'   => count($list),
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
