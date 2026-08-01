<?php

namespace Drupal\std\Controller;

use Dompdf\Dompdf;
use Dompdf\Options;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\rep\Utils;
use Symfony\Component\HttpFoundation\Response;

class StudyReportPdfController extends ControllerBase {

  /**
   * Download Process-Based Study report as PDF.
   */
  public function download(string $studyuri): Response {
    $decodedStudyUri = base64_decode(rawurldecode($studyuri), TRUE);
    if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
      return new Response('Invalid study URI.', 400);
    }

    if (!class_exists(Dompdf::class)) {
      return new Response('PDF engine unavailable. Install dompdf/dompdf first.', 500);
    }

    $studyUri = Utils::canonicalizePmsrUri(trim($decodedStudyUri));
    $api = \Drupal::service('rep.api_connector');

    $study = $api->parseObjectResponse($api->getUri($studyUri), 'getUri');
    if (!is_object($study)) {
      return new Response('Study not found.', 404);
    }

    $host = \Drupal::request()->getSchemeAndHttpHost();
    $title = trim((string) ($study->label ?? $study->studyTitle ?? 'Process-Based Study Report'));

    $piEmail = $this->normalizeEmailValue((string) ($study->contactEmail ?? ''));
    $person = $this->resolveKgrPersonByEmail($api, $piEmail);
    $piName = $this->resolvePersonName($person);
    if ($piName === '') {
      $piName = trim((string) ($study->principalInvestigator ?? ($study->pi->name ?? $study->pi->label ?? $study->pi ?? '')));
    }
    $piUri = is_object($person)
      ? $this->resolveUriFromStudyValue($person->uri ?? NULL)
      : $this->resolveUriFromStudyValue($study->piUri ?? ($study->pi->uri ?? $study->pi ?? NULL));

    $organization = $this->resolveKgrOrganizationFromStudy($api, $study);
    $institutionUri = is_object($organization)
      ? $this->resolveUriFromStudyValue($organization->uri ?? NULL)
      : $this->resolveUriFromStudyValue($study->institutionUri ?? ($study->institution->uri ?? $study->institution ?? NULL));
    $institutionName = $this->resolveOrganizationName($organization);
    if ($institutionName === '') {
      $institutionName = trim((string) ($study->institutionName ?? ($study->institution->name ?? $study->institution->label ?? $study->institution ?? '')));
    }
    $description = trim((string) ($study->comment ?? $study->description ?? ''));

    $debugInfo = [
      'piEmail' => $piEmail !== '' ? $piEmail : 'empty',
      'piUri' => $piUri !== '' ? $piUri : 'empty',
      'piName' => $piName !== '' ? $piName : 'empty',
      'institutionUri' => $institutionUri !== '' ? $institutionUri : 'empty',
      'institutionName' => $institutionName !== '' ? $institutionName : 'empty',
    ];

    $piLink = $this->buildRepUriLink($host, $piUri, $piName !== '' ? $piName : 'Not provided');
    $institutionLink = $this->buildRepUriLink($host, $institutionUri, $institutionName !== '' ? $institutionName : 'Not provided');
    $logoDebug = [];
    $organizationLogoSrc = $this->resolveOrganizationLogoSrc($api, $study, $institutionUri, $host, $organization, $logoDebug);
    $debugInfo['logo'] = $logoDebug;

    $objectsOfInterestHtml = $this->buildObjectsOfInterestHtml($api, $studyUri);
    $descriptionHtml = $this->buildDescriptionHtml($study);
    $processUri = $study->processUri ?? $study->hasProcess ?? $study->hasProcessUri ?? '';
    if (is_object($processUri)) {
      $processUri = (string) ($processUri->uri ?? '');
    }
    $processUri = trim((string) $processUri);

    $workflowDebug = [];
    $workflowCanvasHtml = $this->buildWorkflowCanvasHtml($processUri, $workflowDebug);
    $debugInfo['workflow'] = $workflowDebug;

    $html = '<html><head><meta charset="utf-8"><style>'
      . 'body{font-family:DejaVu Sans, sans-serif;font-size:12px;color:#111;}'
      . 'h1{font-size:20px;margin:0 0 10px 0;}h2{font-size:16px;margin:18px 0 8px 0;border-bottom:1px solid #bbb;padding-bottom:4px;}'
      . 'h3{font-size:13px;margin:12px 0 6px 0;}.muted{color:#666;}'
      . '.meta dt{float:left;width:90px;font-weight:bold;}.meta dd{margin:0 0 6px 95px;word-wrap:break-word;}'
      . '.card{border:1px solid #ddd;border-radius:6px;padding:10px;margin:8px 0;}'
      . '.kv{margin:0 0 8px 0;} .kv b{display:inline-block;min-width:150px;}'
      . '.workflow-svg-wrap{border:1px solid #ccc;padding:8px;overflow:hidden;}'
      . '.report-header{display:table;width:100%;margin:0 0 12px 0;}'
      . '.report-header .logo-cell{display:table-cell;width:120px;vertical-align:top;}'
      . '.report-header .title-cell{display:table-cell;vertical-align:top;padding-left:10px;}'
      . '.org-logo{max-width:110px;max-height:110px;object-fit:contain;border:1px solid #d9d9d9;padding:4px;background:#fff;}'
      . '.debug-box{margin-top:14px;border:1px dashed #b5b5b5;padding:8px;background:#fafafa;font-size:10px;}'
      . '.debug-box h4{margin:0 0 6px 0;font-size:11px;}'
      . '.debug-box table{width:100%;border-collapse:collapse;}'
      . '.debug-box td{padding:2px 4px;vertical-align:top;border-bottom:1px solid #efefef;word-break:break-word;}'
      . '.debug-box td:first-child{width:36%;font-weight:bold;}'
      . '.footer-note{margin-top:16px;font-size:10px;color:#666;}'
      . 'a{color:#0a58ca;text-decoration:none;}'
      . '</style></head><body>'
      . '<div class="report-header">'
      . '<div class="logo-cell">' . ($organizationLogoSrc !== '' ? ('<img class="org-logo" alt="Organization logo" src="' . htmlspecialchars($organizationLogoSrc) . '" />') : '') . '</div>'
      . '<div class="title-cell"><h1>' . htmlspecialchars($title) . '</h1></div>'
      . '</div>'
      . '<dl class="meta">'
      . '<dt>URI:</dt><dd>' . htmlspecialchars($studyUri) . '</dd>'
      . '<dt>Name:</dt><dd>' . htmlspecialchars($title) . '</dd>'
      . '<dt>PI:</dt><dd>' . $piLink . '</dd>'
      . '<dt>Institution:</dt><dd>' . $institutionLink . '</dd>'
      . '<dt>Description:</dt><dd>' . htmlspecialchars($description) . '</dd>'
      . '</dl>'
      . '<h2>Objects of Interest</h2>'
      . $objectsOfInterestHtml
      . '<h2>Description</h2>'
      . $descriptionHtml
      . '<h3>Workflow Canvas</h3>'
      . $workflowCanvasHtml
      . '<div class="footer-note">Generated at ' . htmlspecialchars(date('Y-m-d H:i:s')) . '</div>'
      . '</body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', TRUE);
    $options->set('isHtml5ParserEnabled', TRUE);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $safeId = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($study->studyID ?? 'study-report'));
    if ($safeId === '' || $safeId === '_') {
      $safeId = 'study-report';
    }

    $response = new Response($dompdf->output());
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $safeId . '.pdf"');
    return $response;
  }

  private function resolveUriFromStudyValue($value): string {
    if (is_string($value)) {
      $candidate = trim($value);
      if (preg_match('/^https?:\/\//i', $candidate) === 1) {
        return Utils::canonicalizePmsrUri($candidate);
      }
      return '';
    }

    if (is_object($value)) {
      foreach (['uri', 'hasURI', 'value'] as $key) {
        if (isset($value->{$key}) && is_string($value->{$key})) {
          $candidate = trim((string) $value->{$key});
          if (preg_match('/^https?:\/\//i', $candidate) === 1) {
            return Utils::canonicalizePmsrUri($candidate);
          }
        }
      }
    }

    return '';
  }

  private function buildRepUriLink(string $host, string $uri, string $label): string {
    if ($uri === '') {
      return htmlspecialchars($label);
    }

    $encoded = rawurlencode(base64_encode($uri));
    $href = $host . Url::fromUserInput('/rep/uri/' . $encoded)->toString();
    return '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
  }

  private function resolveOrganizationLogoSrc($api, $study, string $institutionUri, string $host, $organization = NULL, array &$debugInfo = []): string {
    $placeholder = Utils::placeholderImage('organization', 'organization');
    $imageCandidate = '';
    $debugInfo = [
      'candidate' => 'empty',
      'source' => 'none',
      'details' => '',
      'length' => 0,
    ];

    if (is_object($organization)) {
      $imageCandidate = trim((string) ($organization->hasImageUri ?? $organization->image ?? $organization->logo ?? $organization->logoUri ?? ''));
    }

    if ($imageCandidate === '' && is_object($study) && isset($study->institution) && is_object($study->institution)) {
      $imageCandidate = trim((string) ($study->institution->hasImageUri ?? $study->institution->image ?? ''));
    }

    if ($imageCandidate === '' && $institutionUri !== '') {
      try {
        $organization = $api->parseObjectResponse($api->getUri($institutionUri), 'getUri');
        if (is_object($organization)) {
          $imageCandidate = trim((string) ($organization->hasImageUri ?? $organization->image ?? $organization->logo ?? $organization->logoUri ?? ''));
        }
      }
      catch (\Throwable $e) {
        $imageCandidate = '';
      }
    }

    if ($imageCandidate === '') {
      $debugInfo['details'] = 'No hasImageUri/image/logo value resolved from organization.';
      return '';
    }

    $debugInfo['candidate'] = $imageCandidate;

    $embedded = $this->buildOrganizationLogoDataUri($institutionUri, $imageCandidate);
    if ($embedded !== '') {
      $debugInfo['source'] = 'data-uri-local-file';
      $debugInfo['length'] = strlen($embedded);
      $debugInfo['details'] = 'Embedded from local file path.';
      return $embedded;
    }

    $downloaded = $this->buildLogoDataUriFromApiDownload($api, $institutionUri !== '' ? $institutionUri : (string) ($study->uri ?? ''), $imageCandidate);
    if ($downloaded !== '') {
      $debugInfo['source'] = 'data-uri-api-download';
      $debugInfo['length'] = strlen($downloaded);
      $debugInfo['details'] = 'Embedded from API file download response.';
      return $downloaded;
    }

    $resolved = Utils::getAPIImage($institutionUri !== '' ? $institutionUri : (string) ($study->uri ?? ''), $imageCandidate, $placeholder);
    $normalized = $this->normalizeImageSrcForPdf((string) $resolved, $host);
    if ($normalized === '' || $normalized === $placeholder || str_contains($normalized, 'organization_placeholder.png')) {
      $debugInfo['source'] = 'none';
      $debugInfo['details'] = 'Fallback image path did not resolve to organization logo.';
      return '';
    }

    $debugInfo['source'] = str_starts_with($normalized, 'data:image/') ? 'data-uri' : 'url';
    $debugInfo['length'] = strlen($normalized);
    $debugInfo['details'] = 'Resolved via Utils::getAPIImage fallback.';
    return $normalized;
  }

  private function normalizeEmailValue(string $value): string {
    $email = strtolower(trim($value));
    if ($email === '') {
      return '';
    }

    if (strpos($email, 'mailto:') === 0) {
      $email = trim(substr($email, 7));
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
  }

  private function resolveKgrPersonByEmail($api, string $email) {
    if ($email === '') {
      return NULL;
    }

    try {
      $people = $api->parseObjectResponse($api->listByKeyword('person', $email, 25, 0), 'listByKeyword');
      if (!is_array($people) || empty($people)) {
        return NULL;
      }

      $fallback = NULL;
      foreach ($people as $person) {
        if (!is_object($person)) {
          continue;
        }

        if ($fallback === NULL) {
          $fallback = $person;
        }

        $candidates = [
          $person->mbox ?? NULL,
          $person->userEmail ?? NULL,
          $person->hasSIRManagerEmail ?? NULL,
          $person->email ?? NULL,
        ];

        foreach ($candidates as $candidate) {
          $normalized = $this->normalizeEmailValue(is_string($candidate) ? $candidate : '');
          if ($normalized !== '' && $normalized === $email) {
            return $person;
          }
        }
      }

      return $fallback;
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  private function resolvePersonName($person): string {
    if (!is_object($person)) {
      return '';
    }

    $name = trim((string) ($person->name ?? $person->label ?? ''));
    if ($name !== '') {
      return $name;
    }

    $given = trim((string) ($person->givenName ?? ''));
    $family = trim((string) ($person->familyName ?? ''));
    $full = trim($given . ' ' . $family);
    if ($full !== '') {
      return $full;
    }

    return trim((string) ($person->userEmail ?? $person->mbox ?? ''));
  }

  private function resolveKgrOrganizationFromStudy($api, $study) {
    if (!is_object($study)) {
      return NULL;
    }

    $institutionUri = $this->resolveUriFromStudyValue($study->institutionUri ?? ($study->institution->uri ?? $study->institution ?? NULL));
    if ($institutionUri !== '') {
      try {
        $organization = $api->parseObjectResponse($api->getUri($institutionUri), 'getUri');
        if (is_object($organization)) {
          return $organization;
        }
      }
      catch (\Throwable $e) {
        // Continue with fallback by name.
      }
    }

    $institutionName = trim((string) ($study->institutionName ?? ($study->institution->name ?? $study->institution->label ?? $study->institution ?? '')));
    if ($institutionName === '') {
      return NULL;
    }

    try {
      $organizations = $api->parseObjectResponse($api->listByKeyword('organization', $institutionName, 25, 0), 'listByKeyword');
      if (!is_array($organizations) || empty($organizations)) {
        return NULL;
      }

      $normalizedTarget = strtolower($institutionName);
      $fallback = NULL;
      foreach ($organizations as $organization) {
        if (!is_object($organization)) {
          continue;
        }

        if ($fallback === NULL) {
          $fallback = $organization;
        }

        $candidateName = strtolower(trim((string) ($organization->name ?? $organization->label ?? '')));
        if ($candidateName !== '' && $candidateName === $normalizedTarget) {
          return $organization;
        }
      }

      return $fallback;
    }
    catch (\Throwable $e) {
      return NULL;
    }
  }

  private function resolveOrganizationName($organization): string {
    if (!is_object($organization)) {
      return '';
    }

    return trim((string) ($organization->name ?? $organization->label ?? ''));
  }

  private function buildOrganizationLogoDataUri(string $organizationUri, string $imageCandidate): string {
    $imageCandidate = trim($imageCandidate);
    if ($imageCandidate === '') {
      return '';
    }

    if (stripos($imageCandidate, 'data:image/') === 0) {
      return $imageCandidate;
    }

    if (preg_match('/^https?:\/\//i', $imageCandidate) === 1) {
      return '';
    }

    $filename = basename(str_replace('\\', '/', $imageCandidate));
    if ($filename === '') {
      return '';
    }

    try {
      $realPath = Utils::resolvePrivateResourcePath($organizationUri, $filename, ['image', 'images', 'logo', 'logos', 'webdocument', 'webdoc']);
      if (!is_string($realPath) || $realPath === '' || !is_file($realPath)) {
        return '';
      }

      $binary = @file_get_contents($realPath);
      if (!is_string($binary) || $binary === '') {
        return '';
      }

      $mime = @mime_content_type($realPath);
      if (!is_string($mime) || strpos($mime, 'image/') !== 0) {
        $ext = strtolower((string) pathinfo($realPath, PATHINFO_EXTENSION));
        $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : ($ext === 'gif' ? 'image/gif' : ($ext === 'webp' ? 'image/webp' : 'image/png'));
      }

      return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }
    catch (\Throwable $e) {
      return '';
    }
  }

  private function normalizeImageSrcForPdf(string $src, string $host): string {
    $src = trim($src);
    if ($src === '') {
      return '';
    }

    if (stripos($src, 'data:image/') === 0) {
      return $src;
    }

    if (preg_match('/^https?:\/\//i', $src) === 1) {
      return $src;
    }

    if (strpos($src, '//') === 0) {
      $scheme = \Drupal::request()->getScheme();
      return $scheme . ':' . $src;
    }

    if (strpos($src, '/') === 0) {
      return rtrim($host, '/') . $src;
    }

    return rtrim($host, '/') . '/' . ltrim($src, '/');
  }

  private function parseTotal($raw): int {
    if ($raw === NULL) {
      return 0;
    }

    $decoded = NULL;
    if (is_string($raw)) {
      $decoded = json_decode($raw);
    }
    elseif (is_object($raw) || is_array($raw)) {
      $decoded = json_decode(json_encode($raw));
    }

    if (!is_object($decoded) || empty($decoded->isSuccessful)) {
      return 0;
    }

    $body = $decoded->body ?? NULL;
    if (is_string($body)) {
      $body = json_decode($body);
    }

    if (is_object($body) && isset($body->total) && is_numeric($body->total)) {
      return (int) $body->total;
    }

    if (is_array($body) && isset($body['total']) && is_numeric($body['total'])) {
      return (int) $body['total'];
    }

    return 0;
  }

  private function buildObjectsOfInterestHtml($api, string $studyUri): string {
    $totalSocs = $this->parseTotal($api->getTotalStudySOCs($studyUri));
    $totalSos = $this->parseTotal($api->getTotalStudySOs($studyUri));

    $html = '<div class="kv"><b>Total Object Collections:</b> ' . (int) $totalSocs . '</div>'
      . '<div class="kv"><b>Total Objects:</b> ' . (int) $totalSos . '</div>';

    $socs = $api->parseObjectResponse($api->studyObjectCollectionsByStudy($studyUri), 'studyObjectCollectionsByStudy');
    if (!is_array($socs) || empty($socs)) {
      return $html . '<div class="muted">No object collections found.</div>';
    }

    foreach ($socs as $soc) {
      if (!is_object($soc)) {
        continue;
      }

      $socUri = trim((string) ($soc->uri ?? ''));
      $socLabel = trim((string) ($soc->label ?? $soc->studyObjectCollectionID ?? $socUri));
      $socSize = $socUri !== '' ? $this->parseTotal($api->sizeStudyObjectsBySOC($socUri)) : 0;

      $html .= '<div class="card">'
        . '<div class="kv"><b>Collection:</b> ' . htmlspecialchars($socLabel) . '</div>'
        . '<div class="kv"><b>URI:</b> ' . htmlspecialchars($socUri) . '</div>'
        . '<div class="kv"><b>Objects:</b> ' . (int) $socSize . '</div>';

      if ($socUri !== '') {
        $objects = $api->parseObjectResponse($api->studyObjectsBySOCwithPage($socUri, 15, 0), 'studyObjectsBySOCwithPage');
        if (is_array($objects) && !empty($objects)) {
          $html .= '<ul>';
          foreach ($objects as $obj) {
            if (!is_object($obj)) {
              continue;
            }
            $objLabel = trim((string) ($obj->label ?? $obj->name ?? $obj->originalID ?? $obj->uri ?? ''));
            $objUri = trim((string) ($obj->uri ?? ''));
            $html .= '<li>' . htmlspecialchars($objLabel);
            if ($objUri !== '') {
              $html .= ' <span class="muted">(' . htmlspecialchars($objUri) . ')</span>';
            }
            $html .= '</li>';
          }
          $html .= '</ul>';
        }
      }

      $html .= '</div>';
    }

    return $html;
  }

  private function buildDescriptionHtml($study): string {
    $rows = [];

    $addRow = function (string $label, string $value) use (&$rows): void {
      $rows[] = '<div class="kv"><b>' . htmlspecialchars($label) . ':</b> ' . htmlspecialchars($value !== '' ? $value : 'Not provided') . '</div>';
    };

    $addRow('Study ID', trim((string) ($study->studyID ?? '')));
    $addRow('Title', trim((string) ($study->studyTitle ?? $study->label ?? '')));
    $addRow('Specific Aims', trim((string) ($study->specificAims ?? '')));
    $addRow('Significance', trim((string) ($study->significance ?? '')));
    $addRow('Start Date', trim((string) ($study->startDate ?? '')));
    $addRow('End Date', trim((string) ($study->endDate ?? '')));
    $addRow('Learning Objectives', trim((string) ($study->hasLearningObjectives ?? '')));
    $addRow('Critical Actions', trim((string) ($study->hasCriticalActions ?? '')));
    $addRow('Debriefing Focus', trim((string) ($study->hasDebriefingFocus ?? '')));

    $processUri = $study->processUri ?? $study->hasProcess ?? $study->hasProcessUri ?? '';
    if (is_object($processUri)) {
      $processUri = (string) ($processUri->uri ?? '');
    }
    $addRow('Process URI', trim((string) $processUri));

    return implode('', $rows);
  }

  private function buildWorkflowCanvasHtml(string $processUri, array &$debugInfo = []): string {
    $svgMarkup = '';
    $pngDataUrl = '';
    $captureSource = 'none';
    $debugInfo = [
      'svgKeyProvided' => 'no',
      'captureSource' => 'none',
      'pngLength' => 0,
      'svgLength' => 0,
      'renderedFrom' => 'none',
      'notes' => '',
    ];
    $svgKey = trim((string) \Drupal::request()->query->get('svg_key', ''));
    if ($svgKey !== '') {
      $debugInfo['svgKeyProvided'] = 'yes';
      $stored = \Drupal::service('tempstore.private')->get('std')->get('study_report_svg_' . $svgKey);
      if (is_array($stored)) {
        $svgMarkup = trim((string) ($stored['svg'] ?? ''));
        $pngDataUrl = trim((string) ($stored['png'] ?? ''));
        $captureSource = trim((string) ($stored['source'] ?? 'none'));
      }
      elseif (is_string($stored)) {
        $svgMarkup = trim($stored);
      }
      else {
        $debugInfo['notes'] = 'Tempstore lookup returned empty value for svg_key.';
      }
    }

    $debugInfo['captureSource'] = $captureSource !== '' ? $captureSource : 'none';
    $debugInfo['pngLength'] = strlen($pngDataUrl);
    $debugInfo['svgLength'] = strlen($svgMarkup);

    if ($pngDataUrl !== '' && strpos($pngDataUrl, 'data:image/png;base64,') === 0) {
      $debugInfo['renderedFrom'] = 'png';
      return $this->buildWorkflowImageHtml($pngDataUrl, 'Task model graphic');
    }

    if ($svgMarkup !== '' && stripos($svgMarkup, '<svg') !== FALSE) {
      $svgMarkup = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $svgMarkup);
      $svgMarkup = preg_replace('/\son[a-z]+="[^"]*"/i', '', $svgMarkup);
      $debugInfo['renderedFrom'] = 'svg';
      $svgDataUri = $this->svgMarkupToDataUri((string) $svgMarkup);
      if ($svgDataUri !== '') {
        return $this->buildWorkflowImageHtml($svgDataUri, 'Task model graphic');
      }
      return '<div class="workflow-svg-wrap">' . $svgMarkup . '</div>';
    }

    $fallbackSvg = $this->buildWorkflowTaskModelFallbackSvg($processUri, $debugInfo);
    if ($fallbackSvg !== '') {
      $debugInfo['renderedFrom'] = (string) ($debugInfo['fallbackRenderedFrom'] ?? 'generated-svg-tree');
      if ($debugInfo['renderedFrom'] === 'wkf-export-png' && strpos($fallbackSvg, 'data:image/png;base64,') === 0) {
        return $this->buildWorkflowImageHtml($fallbackSvg, 'Task model graphic');
      }

      $svgDataUri = $this->svgMarkupToDataUri((string) $fallbackSvg);
      if ($svgDataUri !== '') {
        return $this->buildWorkflowImageHtml($svgDataUri, 'Task model graphic');
      }

      return '<div class="workflow-svg-wrap">' . $fallbackSvg . '</div>';
    }

    $debugInfo['renderedFrom'] = 'none';
    if ($debugInfo['notes'] === '') {
      $debugInfo['notes'] = 'No PNG/SVG payload was available at PDF render time.';
    }

    $editorUrl = Url::fromUserInput('/ctt/editor', [
      'query' => ['processUri' => $processUri, 'execution' => '1'],
    ])->toString();

    return '<div class="muted">Workflow canvas snapshot was not available during export. Open the editor and retry export from Manage Scenario Elements.</div>'
      . '<div class="kv"><b>Editor URL:</b> ' . htmlspecialchars($editorUrl) . '</div>';
  }

  private function buildLogoDataUriFromApiDownload($api, string $elementUri, string $imageCandidate): string {
    $elementUri = trim($elementUri);
    $imageCandidate = trim($imageCandidate);
    if ($elementUri === '' || $imageCandidate === '') {
      return '';
    }

    try {
      $response = $api->downloadFile($elementUri, $imageCandidate);
      if (!$response) {
        return '';
      }

      $content = '';
      if (is_object($response) && method_exists($response, 'getContent')) {
        $content = (string) $response->getContent();
      }
      elseif (is_object($response) && method_exists($response, 'getBody')) {
        $content = (string) $response->getBody()->getContents();
      }

      if ($content === '') {
        return '';
      }

      $contentType = '';
      if (is_object($response) && isset($response->headers) && method_exists($response->headers, 'get')) {
        $contentType = (string) $response->headers->get('Content-Type');
      }
      elseif (is_object($response) && method_exists($response, 'getHeaderLine')) {
        $contentType = (string) $response->getHeaderLine('Content-Type');
      }

      if ($contentType === '' || strpos($contentType, 'image/') !== 0) {
        $ext = strtolower((string) pathinfo($imageCandidate, PATHINFO_EXTENSION));
        $contentType = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : ($ext === 'gif' ? 'image/gif' : ($ext === 'webp' ? 'image/webp' : 'image/png'));
      }

      return 'data:' . $contentType . ';base64,' . base64_encode($content);
    }
    catch (\Throwable $e) {
      return '';
    }
  }

  private function buildWorkflowImageHtml(string $src, string $alt): string {
    $safeSrc = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    $safeAlt = htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    return '<div class="workflow-svg-wrap"><img alt="' . $safeAlt . '" style="display:block;width:100%;height:auto;" src="' . $safeSrc . '" /></div>';
  }

  private function svgMarkupToDataUri(string $svgMarkup): string {
    $svgMarkup = trim($svgMarkup);
    if ($svgMarkup === '' || stripos($svgMarkup, '<svg') === FALSE) {
      return '';
    }

    $svgMarkup = preg_replace('/<\?xml[^>]*\?>/i', '', $svgMarkup);
    $svgMarkup = is_string($svgMarkup) ? trim($svgMarkup) : '';
    if ($svgMarkup === '') {
      return '';
    }

    return 'data:image/svg+xml;base64,' . base64_encode($svgMarkup);
  }

  private function buildWorkflowTaskModelFallbackSvg(string $processUri, array &$debugInfo): string {
    $processUri = trim($processUri);
    if ($processUri === '') {
      return '';
    }

    if (\Drupal::hasService('ctt.workflow_layout_exporter')) {
      try {
        $export = \Drupal::service('ctt.workflow_layout_exporter')->exportProcessLayout($processUri);
        if (is_array($export) && !empty($export['isSuccessful'])) {
          $pngDataUrl = trim((string) ($export['pngDataUrl'] ?? ''));
          $svgMarkup = trim((string) ($export['svgMarkup'] ?? ''));

          if ($svgMarkup !== '' && stripos($svgMarkup, '<svg') !== FALSE) {
            $debugInfo['notes'] = 'Workflow fallback rendered from wkf export endpoint (SVG).';
            $debugInfo['fallbackRenderedFrom'] = 'wkf-export-svg';
            return $svgMarkup;
          }

          if ($pngDataUrl !== '' && strpos($pngDataUrl, 'data:image/png;base64,') === 0) {
            $debugInfo['notes'] = 'Workflow fallback rendered from wkf export endpoint (PNG).';
            $debugInfo['fallbackRenderedFrom'] = 'wkf-export-png';
            return $pngDataUrl;
          }
        }

        if (is_array($export) && !empty($export['message'])) {
          $debugInfo['notes'] = 'Workflow export endpoint note: ' . (string) $export['message'];
        }
      }
      catch (\Throwable $e) {
        $debugInfo['notes'] = 'Workflow export endpoint error: ' . $e->getMessage();
      }
    }

    if (!\Drupal::hasService('ctt.hasco_client')) {
      $debugInfo['notes'] = 'Workflow fallback unavailable: ctt.hasco_client service not found.';
      return '';
    }

    try {
      $client = \Drupal::service('ctt.hasco_client');
      $tasks = $client->getTasksByProcess($processUri);
      if (!is_array($tasks) || empty($tasks)) {
        $debugInfo['notes'] = 'Workflow fallback: no tasks returned for process.';
        return '';
      }

      $nodes = [];
      $children = [];
      $parents = [];

      foreach ($tasks as $task) {
        if (!is_array($task)) {
          continue;
        }

        $uri = trim((string) ($task['uri'] ?? $task['hasURI'] ?? ''));
        if ($uri === '') {
          continue;
        }

        $label = trim((string) ($task['label'] ?? $task['name'] ?? $task['comment'] ?? $uri));
        if ($label === '') {
          $label = $uri;
        }

        $nodes[$uri] = [
          'uri' => $uri,
          'label' => $label,
        ];

        $children[$uri] = $children[$uri] ?? [];

        $subtaskUris = $task['hasSubtaskUris'] ?? $task['hasSubtask'] ?? [];
        if (is_string($subtaskUris)) {
          $subtaskUris = [$subtaskUris];
        }
        if (is_array($subtaskUris)) {
          foreach ($subtaskUris as $subtaskRef) {
            $childUri = '';
            if (is_string($subtaskRef)) {
              $childUri = trim($subtaskRef);
            }
            elseif (is_array($subtaskRef)) {
              $childUri = trim((string) ($subtaskRef['uri'] ?? $subtaskRef['hasURI'] ?? ''));
            }
            elseif (is_object($subtaskRef)) {
              $childUri = trim((string) ($subtaskRef->uri ?? $subtaskRef->hasURI ?? ''));
            }

            if ($childUri !== '') {
              $children[$uri][] = $childUri;
              $parents[$childUri] = $uri;
            }
          }
        }

        $superUri = trim((string) ($task['hasSupertaskUri'] ?? ''));
        if ($superUri !== '') {
          $parents[$uri] = $superUri;
          $children[$superUri] = $children[$superUri] ?? [];
          if (!in_array($uri, $children[$superUri], TRUE)) {
            $children[$superUri][] = $uri;
          }
        }
      }

      if (empty($nodes)) {
        $debugInfo['notes'] = 'Workflow fallback: task data had no URI-bearing nodes.';
        return '';
      }

      $roots = [];
      foreach ($nodes as $uri => $_node) {
        $parentUri = trim((string) ($parents[$uri] ?? ''));
        if ($parentUri === '' || !isset($nodes[$parentUri])) {
          $roots[] = $uri;
        }
      }

      if (empty($roots)) {
        $roots = [array_key_first($nodes)];
      }

      $ordered = [];
      $visited = [];
      $maxDepth = 0;

      $walk = function (string $uri, int $depth) use (&$walk, &$ordered, &$visited, &$maxDepth, $children, $nodes): void {
        if (isset($visited[$uri]) || !isset($nodes[$uri])) {
          return;
        }
        $visited[$uri] = TRUE;
        $maxDepth = max($maxDepth, $depth);
        $ordered[] = ['uri' => $uri, 'depth' => $depth];

        foreach (($children[$uri] ?? []) as $childUri) {
          $walk((string) $childUri, $depth + 1);
        }
      };

      foreach ($roots as $rootUri) {
        $walk((string) $rootUri, 0);
      }

      foreach (array_keys($nodes) as $uri) {
        if (!isset($visited[$uri])) {
          $walk((string) $uri, 0);
        }
      }

      $count = count($ordered);
      if ($count === 0) {
        return '';
      }

      $boxW = 260;
      $boxH = 54;
      $gapX = 36;
      $gapY = 22;
      $marginX = 16;
      $marginY = 16;

      $positions = [];
      foreach ($ordered as $idx => $entry) {
        $x = $marginX + ($entry['depth'] * ($boxW + $gapX));
        $y = $marginY + ($idx * ($boxH + $gapY));
        $positions[$entry['uri']] = ['x' => $x, 'y' => $y];
      }

      $svgWidth = $marginX * 2 + (($maxDepth + 1) * $boxW) + ($maxDepth * $gapX);
      $svgHeight = $marginY * 2 + ($count * $boxH) + (max(0, $count - 1) * $gapY);

      $svg = [];
      $svg[] = '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $svgWidth . '" height="' . (int) $svgHeight . '" viewBox="0 0 ' . (int) $svgWidth . ' ' . (int) $svgHeight . '">';
      $svg[] = '<rect x="0" y="0" width="' . (int) $svgWidth . '" height="' . (int) $svgHeight . '" fill="#ffffff" />';

      foreach ($children as $parentUri => $childUris) {
        if (!isset($positions[$parentUri])) {
          continue;
        }

        foreach ($childUris as $childUri) {
          if (!isset($positions[$childUri])) {
            continue;
          }

          $from = $positions[$parentUri];
          $to = $positions[$childUri];
          $x1 = $from['x'] + $boxW;
          $y1 = $from['y'] + (int) floor($boxH / 2);
          $x2 = $to['x'];
          $y2 = $to['y'] + (int) floor($boxH / 2);
          $midX = (int) floor(($x1 + $x2) / 2);

          $svg[] = '<path d="M ' . (int) $x1 . ' ' . (int) $y1 . ' C ' . $midX . ' ' . (int) $y1 . ', ' . $midX . ' ' . (int) $y2 . ', ' . (int) $x2 . ' ' . (int) $y2 . '" stroke="#8aa1b8" stroke-width="2" fill="none" />';
        }
      }

      foreach ($ordered as $entry) {
        $uri = $entry['uri'];
        $node = $nodes[$uri];
        $pos = $positions[$uri];

        $label = preg_replace('/\s+/', ' ', $node['label']);
        $label = is_string($label) ? trim($label) : '';
        if ($label === '') {
          $label = $uri;
        }
        if (strlen($label) > 74) {
          $label = substr($label, 0, 71) . '...';
        }

        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');

        $svg[] = '<rect x="' . (int) $pos['x'] . '" y="' . (int) $pos['y'] . '" rx="8" ry="8" width="' . $boxW . '" height="' . $boxH . '" fill="#f7fbff" stroke="#2f6ea9" stroke-width="1.5" />';
        $svg[] = '<text x="' . ((int) $pos['x'] + 10) . '" y="' . ((int) $pos['y'] + 31) . '" font-family="DejaVu Sans, sans-serif" font-size="12" fill="#0f2e4d">' . $safeLabel . '</text>';
      }

      $svg[] = '</svg>';
      $debugInfo['notes'] = 'Workflow fallback rendered from process task tree.';
      return implode('', $svg);
    }
    catch (\Throwable $e) {
      $debugInfo['notes'] = 'Workflow fallback error: ' . $e->getMessage();
      return '';
    }
  }

}
