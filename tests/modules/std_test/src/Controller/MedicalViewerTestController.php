<?php

declare(strict_types=1);

namespace Drupal\std_test\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Test-only wrapper pages for FunctionalJavascript medical viewer checks.
 */
final class MedicalViewerTestController extends ControllerBase {

  public function wrapper(string $filename, string $studyuri, string $token): array {
    $viewerUrl = Url::fromRoute('std.medical_viewer', [
      'filename' => $filename,
      'studyuri' => $studyuri,
      'token' => $token,
    ])->toString();

    $markup = '<section id="std-medical-viewer-wrapper">'
      . '<h2>Medical Viewer Wrapper</h2>'
      . '<iframe id="std-medical-viewer-frame" name="std-medical-viewer-frame" src="' . Html::escape($viewerUrl) . '" style="width:100%;height:80vh;border:1px solid #ced4da;"></iframe>'
      . '</section>';

    return [
      '#type' => 'markup',
      '#markup' => $markup,
      '#allowed_tags' => ['section', 'h2', 'iframe'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
