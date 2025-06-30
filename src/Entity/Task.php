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
      'element_top_task' => t('Top Task'),
      'element_language' => t('Language'),
      'element_tot_instruments' => t('# Instruments'),
      'element_tot_detectors' => t('# Components'),
      'element_status' => t('Status'),
      'element_actions' => t('Actions'),
    ];
  }

  // public static function generateOutput($list, $processuri) {

  //   if (empty($list)) {
  //     return [];
  //   }

  //   $api = \Drupal::service('rep.api_connector');
  //   $list = $api->parseObjectResponse($list, 'getUri');


  //   // ROOT URL
  //   $root_url = \Drupal::request()->getBaseUrl();

  //   // GET LANGUAGES
  //   $tables = new Tables;
  //   $languages = $tables->getLanguages();

  //   $output = array();
  //   $disabled_rows = [];
  //   foreach ($list as $element) {
  //     $uri = ' ';
  //     if ($element['uri'] != NULL) {
  //       $uri = $element['uri'];
  //     }
  //     $tasktype = ' ';
  //     if ($element['tasktype'] != NULL) {
  //       $type = $element['tasktype'];
  //     }
  //     $typeLabel = ' ';
  //     if ($element['typeLabel'] != NULL) {
  //       $typeLabel = $element['typeLabel'];
  //     }
  //     $uri = Utils::namespaceUri($uri);
  //     $label = ' ';
  //     if ($element['label'] != NULL) {
  //       $label = $element['label'];
  //     }
  //     $lang = ' ';
  //     if ($element['hasLanguage'] != NULL) {
  //       if ($languages != NULL) {
  //         $lang = $languages[$element['hasLanguage']];
  //       }
  //     }
  //     $version = ' ';
  //     if ($element['hasVersion'] != NULL) {
  //       $version = $element['hasVersion'];
  //     }
  //     $status = ' ';
  //     if ($element['hasStatus'] != NULL) {
  //       // GET STATUS
  //       if ($element['hasStatus'] === VSTOI::DRAFT && $element['hasReviewNote'] !== NULL) {
  //         $status = "Draft (Already Reviewed)";
  //       } else if($element['hasStatus'] === VSTOI::UNDER_REVIEW) {
  //         $status = "Under Review";
  //       } else {
  //         $status = parse_url($element['hasStatus'], PHP_URL_FRAGMENT);
  //       }
  //     }
  //     $totInst = 0;
  //     if ($element['hasRequiredInstrumentationUris'] !== null && is_array($element['hasRequiredInstrumentationUris'])) {
  //       $totInst = count($element['hasRequiredInstrumentationUris']);
  //     }
  //     $totDet = 0;

  //     if (isset($element['requiredInstrumentation']) && is_array($element['requiredInstrumentation'])) {
  //         foreach ($element['requiredInstrumentation'] as $instrument) {
  //             if (isset($instrument['hasRequiredDetector']) && is_array($instrument['hasRequiredDetector'])) {
  //                 $totDet += count($instrument['hasRequiredDetector']);
  //             } elseif (isset($instrument['detectors']) && is_array($instrument['detectors'])) {
  //                 $totDet += count($instrument['detectors']);
  //             }
  //         }
  //     }

  //     // 1) Prepare the edit-link URL, re‐using the same route your form uses.
  //     $edit_url = Url::fromRoute('std.edit_task', [
  //       'processuri' => $processuri,
  //       'state'      => 'tasks',
  //       'taskuri'    => base64_encode($element['uri']),
  //     ])->toString();

  //     // 2) Render the edit button HTML
  //     $edit_button = t('<a class="btn btn-sm btn-primary edit-element-button" href=":url">Edit</a>', [
  //       ':url' => $edit_url,
  //     ]);

  //     $output[$element['uri']] = [
  //       'element_uri' => t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
  //       // 'element_tasktype' => t('<a target="_new" href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($element->typeUri).'">'.$typeLabel.'</a>'),
  //       'element_tasktype' => $tasktype,
  //       'element_name' => $label,
  //       'element_language' => $lang,
  //       // 'element_version' => $version,
  //       'element_tot_instruments' => $totInst,
  //       'element_tot_detectors' => $totDet,
  //       'element_status' => $status,
  //       // 'element_hasStatus' => parse_url($element['hasStatus'], PHP_URL_FRAGMENT),
  //       // 'element_hasLanguage' => $element['hasLanguage'],
  //       // 'element_hasImageUri' => $element['hasImageUri'],
  //       // 3) Finally, inject our new “actions” column
  //       'element_actions' => [
  //         'data' => [
  //           '#markup' => $edit_button,
  //         ],
  //       ],
  //     ];
  //   }

  //   // Ensure disabled_rows is an associative array
  //   $normalized_disabled_rows = array_fill_keys($disabled_rows, TRUE);

  //   return [
  //     'output'        => $output,
  //     'disabled_rows' => $normalized_disabled_rows,
  //     'total_count'   => count($list),
  //   ];
  // }
  public static function generateOutput($list, $processuri) {
    // 1) If input is empty, immediately return an empty table structure.
    if (empty($list)) {
        return [
            'output'        => [],
            'disabled_rows' => [],
            'total_count'   => 0,
        ];
    }

    // 2) Fetch your API connector.
    $api = \Drupal::service('rep.api_connector');

    // 3) Parse each stdClass (or array) in $list:
    //    parseObjectResponse may return a single item or an array;
    //    we normalize everything into one flat array of associative arrays.
    $parsed = [];
    foreach ($list as $item) {
        // parseObjectResponse(…, 'getUri') returns one element or an array of elements
        $result = $api->parseObjectResponse($api->getUri($item), 'getUri');
        if (is_array($result)) {
            // If it’s an array of items, cast each to array and merge
            foreach ($result as $one) {
                $parsed[] = is_object($one) ? (array) $one : $one;
            }
        }
        else {
            // Single item case — cast and push
            $parsed[] = is_object($result) ? (array) $result : $result;
        }
    }
    // Now $parsed is an array of associative‐arrays for every elementUri
    $list = $parsed;

    // 4) Prepare helpers: base URL and language lookup.
    $root_url  = \Drupal::request()->getBaseUrl();
    $tables    = new Tables();
    $languages = $tables->getLanguages();

    $output        = [];
    $disabled_rows = [];

    // 5) Main loop: build one render-row per parsed element.
    foreach ($list as $element) {
        // a) URI and namespacing
        $uri_raw       = !empty($element['uri']) ? $element['uri'] : ' ';
        $namespacedUri = Utils::namespaceUri($uri_raw);

        // b) Other fields with safe fallbacks
        $label      = !empty($element['label']) ? $element['label'] : ' ';
        $lang_code  = !empty($element['hasLanguage']) ? $element['hasLanguage'] : null;
        $lang_label = ($lang_code && isset($languages[$lang_code]))
            ? $languages[$lang_code]
            : ' ';
        $version = !empty($element['hasVersion']) ? $element['hasVersion'] : ' ';
        $topTask = !empty($element['hasSupertaskUri']) ? Utils::namespaceUri($element['hasSupertaskUri']) : ' ';

        // c) Human-readable status
        $status = ' ';
        if (!empty($element['hasStatus'])) {
            if ($element['hasStatus'] === VSTOI::DRAFT && !empty($element['hasReviewNote'])) {
                $status = "Draft (Already Reviewed)";
            }
            elseif ($element['hasStatus'] === VSTOI::UNDER_REVIEW) {
                $status = "Under Review";
            }
            else {
                $status = parse_url($element['hasStatus'], PHP_URL_FRAGMENT);
            }
        }

        // d) Count instruments & detectors
        $totInst = is_array($element['hasRequiredInstrumentationUris'])
            ? count($element['hasRequiredInstrumentationUris'])
            : 0;

        $totDet = 0;
        if (!empty($element['requiredInstrumentation']) && is_array($element['requiredInstrumentation'])) {
            foreach ($element['requiredInstrumentation'] as $instr) {
                if (!empty($instr['hasRequiredDetector']) && is_array($instr['hasRequiredDetector'])) {
                    $totDet += count($instr['hasRequiredDetector']);
                }
                elseif (!empty($instr['detectors']) && is_array($instr['detectors'])) {
                    $totDet += count($instr['detectors']);
                }
            }
        }

        // e) Build Edit link
        $edit_url = Url::fromRoute('std.edit_task', [
            'processuri' => $processuri,
            'state'      => 'tasks',
            'taskuri'    => base64_encode($uri_raw),
        ])->toString();
        $edit_button = t(
            '<a class="btn btn-sm btn-primary edit-element-button" href=":url">Edit</a>',
            [':url' => $edit_url]
        );

        // f) Assemble this row’s render array
        $output[$uri_raw] = [
            'element_uri'             => t(
                '<a target="_new" href="'
                . $root_url
                . REPGUI::DESCRIBE_PAGE
                . base64_encode($namespacedUri)
                . '">'
                . $namespacedUri
                . '</a>'
            ),
            'element_tasktype'        => ' ',  // populate if needed
            'element_name'            => $label,
            'element_top_task'        => t(
              '<a target="_new" href="'
              . $root_url
              . REPGUI::DESCRIBE_PAGE
              . base64_encode($topTask)
              . '">'
              . $topTask
              . '</a>'
            ),
            'element_language'        => $lang_label,
            'element_tot_instruments' => $totInst,
            'element_tot_detectors'   => $totDet,
            'element_status'          => $status,
            'element_actions'         => [
                'data' => ['#markup' => $edit_button],
            ],
        ];
    }

    // 6) Normalize disabled rows and return full table structure.
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
