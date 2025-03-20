<?php

namespace Drupal\std\Entity;

use Drupal\Core\Url;
use Drupal\rep\Vocabulary\REPGUI;
use Drupal\rep\Utils;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Html;

class Study {

  public static function generateHeader() {

    return $header = [
      'element_uri' => t('URI'),
      'element_short_name' => t('Short Name'),
      'element_name' => t('Name'),
      'element_n_roles' => t('# Roles'),
      'element_n_vcs' => t('# Virt.Columns'),
      'element_n_socs' => t('# SOCs'),
    ];

  }

  public static function generateOutput($list) {

    // ROOT URL
    $root_url = \Drupal::request()->getBaseUrl();

    $output = array();
    if ($list == NULL) {
      return $output;
    }
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
      $title = ' ';
      if ($element->title != NULL) {
        $title = $element->title;
      }
      $root_url = \Drupal::request()->getBaseUrl();
      $encodedUri = rawurlencode(rawurlencode($element->uri));
      $output[$element->uri] = [
        'element_uri' => t('<a href="'.$root_url.REPGUI::DESCRIBE_PAGE.base64_encode($uri).'">'.$uri.'</a>'),
        'element_short_name' => t($label),
        'element_name' => t($title),
        'element_n_roles' => $element->totalStudyRoles,
        'element_n_vcs' => $element->totalVirtualColumns,
        'element_n_socs' => $element->totalStudyObjectCollections,
        ];
    }
    return $output;
  }

  // public static function generateOutputAsCard($list) {
  //   $output = [];

  //   // ROOT URL
  //   $root_url = \Drupal::request()->getBaseUrl();

  //   if ($list == NULL) {
  //       return $output;
  //   }

  //   //foreach ($output as $uri => $card) {
  //   //    $form['element_' . $index] = $card;
  //   //}

  //   $index = 0;
  //   foreach ($list as $element) {
  //     $index++;
  //     $uri = $element->uri ?? '';
  //     $label = $element->label ?? '';
  //     $title = $element->title ?? '';

  //     $urlComponents = parse_url($uri);

  //     if (isset($urlComponents['scheme']) && isset($urlComponents['host'])) {
  //       $url = Url::fromUri($uri);
  //     } else {
  //       $url = '';
  //     }

  //     if ($element->uri != NULL && $element->uri != "") {
  //       $previousUrl = base64_encode(\Drupal::request()->getRequestUri());

  //       $manage_elements_str = base64_encode(Url::fromRoute('std.manage_study_elements', ['studyuri' => base64_encode($element->uri)])->toString());
  //       $manage_elements = Url::fromRoute('rep.back_url', [
  //         'previousurl' => $previousUrl,
  //         'currenturl' => $manage_elements_str,
  //         'currentroute' => 'std.manage_study_elements'
  //       ]);

  //       $view_study_str = base64_encode(Url::fromRoute('rep.describe_element', ['elementuri' => base64_encode($element->uri)])->toString());
  //       $view_study_route = 'rep.describe_element';
  //       $view_study = Url::fromRoute('rep.back_url', [
  //         'previousurl' => $previousUrl,
  //         'currenturl' => $view_study_str,
  //         'currentroute' => 'rep.describe_element'
  //       ]);

  //       $edit_study_str = base64_encode(Url::fromRoute('std.edit_study', ['studyuri' => base64_encode($element->uri)])->toString());
  //       $edit_study = Url::fromRoute('rep.back_url', [
  //         'previousurl' => $previousUrl,
  //         'currenturl' => $edit_study_str,
  //         'currentroute' => 'std.edit_study'
  //       ]);

  //       $delete_study = Url::fromRoute('rep.delete_element', [
  //         'elementtype' => 'study',
  //         'elementuri' => base64_encode($element->uri),
  //         'currenturl' => $previousUrl,
  //       ]);
  //     }

  //     $output[$index] = [
  //       '#type' => 'container', // Use container instead of html_tag for better semantics
  //       '#attributes' => [
  //           'class' => ['card', 'mb-3'],
  //       ],
  //       '#prefix' => '<div class="col-md-6">',
  //       '#suffix' => '</div>',
  //       'card_body_'.$index => [
  //           '#type' => 'container', // Use container for the card body
  //           '#attributes' => [
  //               'class' => ['card-body'],
  //           ],
  //           'title' => [
  //               '#markup' => '<h5 class="card-title">' . $label . '</h5>',
  //           ],
  //           'text' => [
  //               '#markup' => '<p class="card-text">'. $title . '<br>' . $uri . '</p>',
  //           ],
  //           'link1_'.$index   => [
  //             '#type' => 'link',
  //             '#title' => Markup::create('<i class="fa-solid fa-folder-tree"></i> Manage Elements'),
  //             '#url' => $manage_elements,
  //             '#attributes' => [
  //                 'class' => ['btn', 'btn-sm', 'btn-secondary'],
  //                 'style' => 'margin-right: 10px;',
  //             ],
  //           ],
  //           'link2_'.$index => [
  //             '#type' => 'link',
  //             '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
  //             '#url' => $view_study,
  //             '#attributes' => [
  //                 'class' => ['btn', 'btn-sm', 'btn-secondary'],
  //                 'style' => 'margin-right: 10px;',
  //             ],
  //           ],
  //           'link3_'.$index => [
  //             '#type' => 'link',
  //             '#title' => Markup::create('<i class="fa-solid fa-pen-to-square"></i> Edit'),
  //             '#url' => $edit_study,
  //             '#attributes' => [
  //                 'class' => ['btn', 'btn-sm', 'btn-secondary'],
  //                 'style' => 'margin-right: 10px;',
  //             ],
  //           ],
  //           'link4_'.$index => [
  //             '#type' => 'link',
  //             '#title' => Markup::create('<i class="fa-solid fa-trash-can"></i> Delete'),
  //             '#url' => $delete_study,
  //             '#attributes' => [
  //               'onclick' => 'if(!confirm("Really Delete?")){return false;}',
  //               'class' => ['btn', 'btn-sm', 'btn-secondary', 'btn-danger'],
  //             ],
  //           ],
  //       ],
  //     ];

  //   }

  //   return $output;
  // }

  public static function generateOutputAsCard($list, $useremail) {

    if (isset($useremail) && empty($useremail))
      $useremail = \Drupal::currentUser()->getEmail();

    $cards = [];

    // Return an empty array if no items are provided.
    if (empty($list)) {
      return [];
    }

    // Process each element to build a card.
    foreach ($list as $index => $element) {
      $manage_elements = NULL;
      $edit_study = NULL;
      $delete_study = NULL;

      // Ensure values are set and provide defaults.
      $uri = is_string($element->uri) ? $element->uri : '';
      $label = $element->label ?? '';
      $title = $element->title ?? '';
      $pi = is_object($element->pi) ? $element->pi->name : ($element->pi ?? '');
      $ins = is_object($element->institution) ? $element->institution->name : ($element->institution ?? '');
      $desc = $element->comment ?? '';

      // Build the outer container for the card.
      $card = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['col-md-4'],
          'id' => 'card-item-' . md5($uri),
        ],
      ];

      // Card wrapper container.
      $card['card'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['card', 'mb-3']],
      ];

      // Card Header: Display the short name (label).
      $card['card']['header'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['card-header', 'mb-0'],
          'style' => 'margin-bottom:0!important;',
        ],
        '#markup' => '<h5>' . $label . '</h5>',
      ];

      // Get image URI: use provided image or fallback to a placeholder.
      if (!empty($element->image)) {
        $image_uri = $element->image;
      }
      else {
        $image_uri = base_path() . \Drupal::service('extension.list.module')->getPath('rep') . '/images/std_placeholder.png';
      }

      // Process the URI using the namespace utility if not empty.
      if (!empty($uri)) {
        $uri = \Drupal\rep\Utils::namespaceUri($uri);
      }

      // Card Body:
      // Limit the description text (e.g. to 120 characters) and escape it.
      $short_desc = Html::escape(mb_strimwidth($desc, 0, 120, ''));
      // Generate a unique modal ID for the "read more" modal.
      $modal_id = Html::getId($title . '-description-modal');

      $card['card']['body'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['card-body', 'mb-0'],
          'style' => 'margin-bottom:0!important;',
        ],
        'row' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['row'],
            'style' => 'margin-bottom:0!important;',
          ],
          // Left column: image.
          'image_column' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['col-md-5', 'text-center', 'mb-0', 'align-middle'],
              'style' => 'margin-bottom:0!important;',
            ],
            'image' => [
              '#theme' => 'image',
              '#uri' => $image_uri,
              '#alt' => t('Image for @name', ['@name' => $title]),
              '#attributes' => [
                'class' => ['img-fluid', 'mb-0'],
                'style' => 'width: 70%',
              ],
            ],
          ],
          // Right column: text details.
          'text_column' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['col-md-7'],
              'style' => 'margin-bottom:0!important;',
            ],
            'text' => [
              '#markup' => '<p class="card-text">
                <strong>Name:</strong> ' . $title . '
                <br><strong>URI:</strong> ' . (is_string($uri) && !empty($uri) ? \Drupal\Core\Link::fromTextAndUrl($uri, Url::fromUserInput(REPGUI::DESCRIBE_PAGE . base64_encode($uri)))->toString() : '') . '
                <br><strong>PI:</strong> ' . $pi . '
                <br><strong>Institution:</strong> ' . $ins . '
                <br><strong>Description:</strong> ' . $short_desc . '...
                <a href="#" data-bs-toggle="modal" data-bs-target="#' . $modal_id . '">read more</a>
              </p>',
            ],
          ],
        ],
      ];

      // Add modal for full description.
      $card['card']['body']['modal'] = [
        '#markup' => '
          <div class="modal fade" id="' . $modal_id . '" tabindex="-1" aria-labelledby="' . $modal_id . '-label" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header">
                  <h5 class="modal-title" id="' . $modal_id . '-label">Full Description</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <strong>Description:</strong> ' . Html::escape($desc) . '
                </div>
                <div class="modal-footer">
                  <a href="#" class="btn btn-secondary" data-bs-dismiss="modal">Close</a>
                </div>
              </div>
            </div>
          </div>',
      ];

      // Build action links if the element has a valid URI.

      if (!empty($element->uri)) {
        $studyUriEncoded = rtrim(strtr(base64_encode($element->uri), '+/', '-_'), '=');

        // Use URL-safe encoding for the previous URL.
        $safe_previousUrl = rtrim(strtr(base64_encode(\Drupal::request()->getRequestUri()), '+/', '-_'), '=');

        // Management link.
        if ($element->hasSIRManagerEmail === $useremail) {
          $manage_elements_str = rtrim(strtr(base64_encode(Url::fromRoute('std.manage_study_elements', ['studyuri' => $studyUriEncoded])->toString()), '+/', '-_'), '=');
          $manage_elements = Url::fromRoute('rep.back_url', [
            'previousurl' => $safe_previousUrl,
            'currenturl' => $manage_elements_str,
            'currentroute' => 'std.manage_study_elements',
          ]);
        }

        // View link.
        $view_study_str = rtrim(strtr(base64_encode(Url::fromRoute('rep.describe_element', ['elementuri' => $studyUriEncoded])->toString()), '+/', '-_'), '=');
        $view_study = Url::fromRoute('rep.back_url', [
          'previousurl' => $safe_previousUrl,
          'currenturl' => $view_study_str,
          'currentroute' => 'rep.describe_element',
        ]);

        // Edit link.
        if ($element->hasSIRManagerEmail === $useremail) {
          $edit_study_str = rtrim(strtr(base64_encode(Url::fromRoute('std.edit_study', ['studyuri' => $studyUriEncoded])->toString()), '+/', '-_'), '=');
          $edit_study = Url::fromRoute('rep.back_url', [
            'previousurl' => $safe_previousUrl,
            'currenturl' => $edit_study_str,
            'currentroute' => 'std.edit_study',
          ]);
        }

        // Delete link.
        if ($element->hasSIRManagerEmail === $useremail) {
          $delete_study = Url::fromRoute('rep.delete_element', [
            'elementtype' => 'study',
            'elementuri' => $studyUriEncoded,
            'currenturl' => $safe_previousUrl,
          ]);
        }
      }

      // Card Footer: action buttons.
      $card['card']['footer'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['card-footer', 'text-right', 'd-flex', 'justify-content-end'],
          'style' => 'margin-bottom:0!important;',
        ],
        'actions' => [
          'link1' => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fa-solid fa-folder-tree"></i> Manage Elements'),
            '#url' => $manage_elements,
            '#access' => !is_null($manage_elements), // Hide if not set
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-secondary', 'mx-1'],
            ],
          ],
          'link2' => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fa-solid fa-eye"></i> View'),
            '#url' => $view_study,
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-secondary', 'mx-1'],
            ],
          ],
          'link3' => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fa-solid fa-pen-to-square"></i> Edit'),
            '#url' => $edit_study,
            '#access' => !is_null($edit_study), // Hide if not set
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-secondary', 'mx-1'],
            ],
          ],
          'link4' => [
            '#type' => 'link',
            '#title' => Markup::create('<i class="fa-solid fa-trash-can"></i> Delete'),
            '#url' => $delete_study,
            '#access' => !is_null($delete_study), // Hide if not set
            '#attributes' => [
              'class' => ['btn', 'btn-sm', 'btn-secondary', 'btn-danger', 'mx-1'],
              'onclick' => 'if(!confirm("Really Delete?")){return false;}',
            ],
          ],
        ],
      ];

      // Add the card to the cards array.
      $cards[] = $card;
    }

    // Group cards into rows (3 cards per row).
    $output = [];
    $row_index = 0;
    foreach (array_chunk($cards, 3) as $row) {
      $row_index++;
      $output[] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['row', 'mb-0'],
        ],
        'cards' => $row,
      ];
    }

    return $output;
  }
}
