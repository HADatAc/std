<?php

namespace Drupal\std\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\rep\Utils;
use Drupal\rep\Vocabulary\HASCO;
use Drupal\rep\Vocabulary\VSTOI;

/**
 * Form for editing a Process-Based Study
 * 
 * This form allows editing ProcessBasedStudy metadata.
 * The Process URI cannot be changed after creation.
 * 
 * Related to PMSR ProcessBasedStudy migration (Phase 2)
 */
class EditProcessBasedStudyForm extends FormBase {

  protected $studyUri;
  protected $study;
  protected $processUri = '';

  /**
   * Preferred study noun from configuration (lowercase).
   */
  private function preferredStudyNoun(): string {
    $configured = \Drupal::config('rep.settings')->get('preferred_study') ?? 'study';
    $normalized = trim((string) $configured);
    return $normalized !== '' ? strtolower($normalized) : 'study';
  }

  /**
   * Preferred study label from configuration (title case).
   */
  private function preferredStudyLabel(): string {
    return ucfirst($this->preferredStudyNoun());
  }

  /**
   * Convert API values to safe scalar strings for form rendering.
   */
  private function toSafeString($value) {
    if (is_string($value) || is_numeric($value)) {
      return trim((string) $value);
    }
    if (is_object($value)) {
      foreach (['uri', 'label', 'name', 'title', 'value'] as $key) {
        if (isset($value->{$key}) && (is_string($value->{$key}) || is_numeric($value->{$key}))) {
          return trim((string) $value->{$key});
        }
      }
      return '';
    }
    if (is_array($value)) {
      foreach ($value as $item) {
        $normalized = $this->toSafeString($item);
        if ($normalized !== '') {
          return $normalized;
        }
      }
    }
    return '';
  }

  /**
   * Resolve a URI candidate from mixed API values.
   */
  private function resolveUriFromValue($value): string {
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
      return '';
    }

    return '';
  }

  /**
   * Build a small detail button pointing to the URI describe page.
   */
  private function buildDetailButtonMarkup(string $uri): string {
    $trimmed = trim($uri);
    if ($trimmed === '') {
      return '<span class="btn btn-secondary btn-sm disabled" style="margin-left:8px; display:inline-block; vertical-align:middle;" aria-disabled="true">' . $this->t('Detail') . '</span>';
    }

    $encoded = rawurlencode(base64_encode($trimmed));
    $url = Url::fromUserInput('/rep/uri/' . $encoded)->toString();

    return '<a class="btn btn-secondary btn-sm" style="margin-left:8px; display:inline-block; vertical-align:middle;" target="_blank" rel="noopener noreferrer" href="'
      . Html::escape($url) . '">' . $this->t('Detail') . '</a>';
  }

  /**
   * Resolve organization URI from a Process-Based Study object.
   */
  private function resolveOrganizationUriFromStudy($study): string {
    $institutionValue = is_object($study) ? ($study->institution ?? $study->hasInstitution ?? NULL) : NULL;

    if (is_object($institutionValue)) {
      $uri = trim((string) ($institutionValue->uri ?? $institutionValue->hasURI ?? ''));
      return Utils::canonicalizePmsrUri($uri);
    }

    if (is_string($institutionValue)) {
      $candidate = trim($institutionValue);
      if (preg_match('/^https?:\/\//i', $candidate) === 1) {
        return Utils::canonicalizePmsrUri($candidate);
      }
    }

    if (is_object($study)) {
      foreach (['hasInstitutionUri', 'institutionUri'] as $key) {
        if (isset($study->{$key}) && is_string($study->{$key})) {
          $candidate = trim((string) $study->{$key});
          if (preg_match('/^https?:\/\//i', $candidate) === 1) {
            return Utils::canonicalizePmsrUri($candidate);
          }
        }
      }
    }

    return '';
  }

  /**
   * Resolve principal investigator URI from a Process-Based Study object.
   */
  private function resolvePrincipalInvestigatorUriFromStudy($study): string {
    if (!is_object($study)) {
      return '';
    }

    foreach (['principalInvestigator', 'principalInvestigatorUri', 'hasPrincipalInvestigator', 'hasPrincipalInvestigatorUri', 'pi', 'piUri'] as $key) {
      if (!isset($study->{$key})) {
        continue;
      }
      $candidate = $this->resolveUriFromValue($study->{$key});
      if ($candidate !== '') {
        return $candidate;
      }
    }

    return '';
  }

  /**
   * Normalize candidate email values for exact comparisons.
   */
  private function normalizeEmailValue($value): string {
    if (!is_string($value)) {
      return '';
    }

    $email = strtolower(trim($value));
    if ($email === '') {
      return '';
    }

    if (strpos($email, 'mailto:') === 0) {
      $email = trim(substr($email, 7));
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
  }

  /**
   * Resolve PI URI from KGR Person by matching PI email.
   */
  private function resolvePrincipalInvestigatorUriByEmail(string $email): string {
    $targetEmail = $this->normalizeEmailValue($email);
    if ($targetEmail === '') {
      return '';
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $people = $this->parseApiListBody($api->listByKeyword('person', $targetEmail, 25, 0));
      if (empty($people)) {
        return '';
      }

      $firstUri = '';
      foreach ($people as $person) {
        if (!is_object($person)) {
          continue;
        }

        $uri = trim((string) ($person->uri ?? ''));
        if ($uri !== '' && $firstUri === '') {
          $firstUri = Utils::canonicalizePmsrUri($uri);
        }

        $emailCandidates = [
          $person->mbox ?? NULL,
          $person->userEmail ?? NULL,
          $person->hasSIRManagerEmail ?? NULL,
          $person->email ?? NULL,
        ];

        foreach ($emailCandidates as $candidateValue) {
          $candidateEmail = $this->normalizeEmailValue(is_string($candidateValue) ? $candidateValue : '');
          if ($candidateEmail !== '' && $candidateEmail === $targetEmail && $uri !== '') {
            return Utils::canonicalizePmsrUri($uri);
          }
        }
      }

      return $firstUri;
    }
    catch (\Throwable $e) {
      return '';
    }
  }

  /**
   * Recursively extract laboratory options from API payload values.
   */
  private function extractLaboratoryOptionsFromValue($value, array &$options, int $depth = 0): void {
    if ($depth > 5 || $value === NULL) {
      return;
    }

    if (is_array($value)) {
      foreach ($value as $item) {
        $this->extractLaboratoryOptionsFromValue($item, $options, $depth + 1);
      }
      return;
    }

    if (is_object($value)) {
      $label = trim((string) ($value->label ?? $value->name ?? $value->title ?? ''));
      $uri = trim((string) ($value->uri ?? $value->hasURI ?? ''));
      $candidateValue = $uri !== '' ? $uri : $label;
      if ($candidateValue !== '' && !isset($options[$candidateValue])) {
        $options[$candidateValue] = $label !== '' ? $label : $candidateValue;
      }

      foreach (get_object_vars($value) as $key => $child) {
        if (stripos((string) $key, 'lab') !== FALSE || is_array($child) || is_object($child)) {
          $this->extractLaboratoryOptionsFromValue($child, $options, $depth + 1);
        }
      }
      return;
    }

    if (is_string($value)) {
      $candidate = trim($value);
      if ($candidate === '') {
        return;
      }
      if (stripos($candidate, 'lab') !== FALSE || preg_match('/^https?:\/\//i', $candidate) === 1) {
        if (!isset($options[$candidate])) {
          $options[$candidate] = $candidate;
        }
      }
    }
  }

  /**
   * Parse list-style API responses into a plain array of objects.
   */
  private function parseApiListBody($raw): array {
    if ($raw === NULL) {
      return [];
    }

    $decoded = NULL;
    if (is_string($raw)) {
      $decoded = json_decode($raw);
      if (!is_object($decoded)) {
        return [];
      }
    }
    elseif (is_object($raw) || is_array($raw)) {
      $decoded = json_decode(json_encode($raw));
      if (!is_object($decoded)) {
        return [];
      }
    }

    if (!is_object($decoded) || empty($decoded->isSuccessful)) {
      return [];
    }

    $body = $decoded->body ?? NULL;
    if (is_string($body)) {
      $body = json_decode($body);
    }

    if (is_array($body)) {
      return $body;
    }

    if (is_object($body) && isset($body->elements) && is_array($body->elements)) {
      return $body->elements;
    }

    return [];
  }

  /**
   * Parse total-style API responses.
   */
  private function parseApiTotal($raw): int {
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

  /**
   * Extract organization URI references from an organization object.
   */
  private function extractOrganizationUriCandidates($organization): array {
    $uris = [];
    if (!is_object($organization)) {
      return $uris;
    }

    foreach (['uri', 'parentOrganizationUri', 'hasParentOrganizationUri', 'parentOrganization'] as $key) {
      if (!isset($organization->{$key})) {
        continue;
      }

      $value = $organization->{$key};
      $candidate = '';
      if (is_string($value)) {
        $candidate = trim($value);
      }
      elseif (is_object($value)) {
        $candidate = trim((string) ($value->uri ?? $value->hasURI ?? ''));
      }

      if ($candidate !== '' && preg_match('/^https?:\/\//i', $candidate) === 1) {
        $canonical = Utils::canonicalizePmsrUri($candidate);
        $uris[$canonical] = TRUE;
      }
    }

    return array_keys($uris);
  }

  /**
   * Build laboratory options using platform instances linked via hasco:partOf.
   */
  private function buildLaboratoryOptionsFromPartOf(string $organizationUri, array $fallbackOrganizationUris = []): array {
    $options = [];
    $organizationUris = [];

    $normalizedOrgUri = Utils::canonicalizePmsrUri($organizationUri);
    if ($normalizedOrgUri !== '') {
      $organizationUris[$normalizedOrgUri] = TRUE;
    }

    foreach ($fallbackOrganizationUris as $uri) {
      if (!is_string($uri)) {
        continue;
      }
      $normalized = Utils::canonicalizePmsrUri(trim($uri));
      if ($normalized !== '') {
        $organizationUris[$normalized] = TRUE;
      }
    }

    if (empty($organizationUris)) {
      return $options;
    }

    try {
      $api = \Drupal::service('rep.api_connector');
      $total = $this->parseApiTotal($api->listSizeByKeyword('platforminstance', '_'));
      if ($total <= 0) {
        return $options;
      }

      $target = min($total, 500);
      $pageSize = 100;
      $offset = 0;

      while ($offset < $target) {
        $chunk = $this->parseApiListBody($api->listByKeyword('platforminstance', '_', $pageSize, $offset));
        if (empty($chunk)) {
          break;
        }

        foreach ($chunk as $instance) {
          if (!is_object($instance) || empty($instance->uri)) {
            continue;
          }

          $partOf = '';
          if (isset($instance->partOf)) {
            if (is_string($instance->partOf)) {
              $partOf = trim($instance->partOf);
            }
            elseif (is_object($instance->partOf)) {
              $partOf = trim((string) ($instance->partOf->uri ?? $instance->partOf->hasURI ?? ''));
            }
          }

          if ($partOf === '') {
            continue;
          }

          $partOf = Utils::canonicalizePmsrUri($partOf);
          if (!isset($organizationUris[$partOf])) {
            continue;
          }

          $typeUri = trim((string) ($instance->type->uri ?? $instance->typeUri ?? ''));
          $typeLabel = trim((string) ($instance->type->label ?? ''));
          $isLaboratory = FALSE;

          if ($typeUri !== '') {
            $isLaboratory = stripos($typeUri, '#Laboratory') !== FALSE || stripos($typeUri, '/Laboratory') !== FALSE;
          }
          if (!$isLaboratory && $typeLabel !== '') {
            $isLaboratory = stripos($typeLabel, 'laboratory') !== FALSE || stripos($typeLabel, 'laborat') !== FALSE;
          }

          if (!$isLaboratory) {
            continue;
          }

          $uri = trim((string) $instance->uri);
          $label = trim((string) ($instance->label ?? ''));
          if ($uri !== '' && !isset($options[$uri])) {
            $options[$uri] = $label !== '' ? $label : $uri;
          }
        }

        if (count($chunk) < $pageSize) {
          break;
        }

        $offset += $pageSize;
      }
    }
    catch (\Throwable $e) {
      return $options;
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Build laboratory options from the selected organization (fallback: any).
   */
  private function buildLaboratoryOptions(string $organizationUri, string $organizationName = ''): array {
    $options = [
      'any' => $this->t('any'),
    ];

    if ($organizationUri === '') {
      return $options;
    }

    $organizationFallbackUris = [];

    try {
      $api = \Drupal::service('rep.api_connector');
      $organizationResponse = $api->getUri($organizationUri);
      $organization = $api->parseObjectResponse($organizationResponse, 'getUri');
      if ($organization === NULL || !is_object($organization)) {
        return $options;
      }

      $organizationFallbackUris = $this->extractOrganizationUriCandidates($organization);

      // Preferred source: platform instances linked to the organization via hasco:partOf.
      $linkedLabs = $this->buildLaboratoryOptionsFromPartOf($organizationUri, $organizationFallbackUris);
      foreach ($linkedLabs as $labUri => $labLabel) {
        $options[$labUri] = $labLabel;
      }

      $candidateKeys = [
        'hasLaboratory',
        'hasLaboratories',
        'hasLaboratoryUris',
        'hasLaboratoryUri',
        'laboratory',
        'laboratories',
      ];

      foreach ($candidateKeys as $key) {
        if (isset($organization->{$key})) {
          $this->extractLaboratoryOptionsFromValue($organization->{$key}, $options);
        }
      }

      foreach (get_object_vars($organization) as $key => $value) {
        if (stripos((string) $key, 'lab') !== FALSE) {
          $this->extractLaboratoryOptionsFromValue($value, $options);
        }
      }

      // If direct org relation yields none, try parent organization relation once.
      if (count($options) <= 1 && !empty($organizationFallbackUris)) {
        $parentOnly = [];
        foreach ($organizationFallbackUris as $candidateUri) {
          $normalizedCandidate = Utils::canonicalizePmsrUri((string) $candidateUri);
          if ($normalizedCandidate !== '' && $normalizedCandidate !== Utils::canonicalizePmsrUri($organizationUri)) {
            $parentOnly[] = $normalizedCandidate;
          }
        }
        $parentLabs = $this->buildLaboratoryOptionsFromPartOf('', $parentOnly);
        foreach ($parentLabs as $labUri => $labLabel) {
          $options[$labUri] = $labLabel;
        }
      }
    }
    catch (\Throwable $e) {
      // Keep the safe fallback option if organization laboratory lookup fails.
    }

    // Fallback for known organizations when URI-based lab discovery is unavailable.
    $normalizedOrgName = mb_strtolower(trim($organizationName));
    if ($normalizedOrgName === 'escola superior de tecnologia e gestao jean piaget - instituto politecnico jean piaget do sul'
      || $normalizedOrgName === 'escola superior de tecnologia e gestão jean piaget - instituto politécnico jean piaget do sul') {
      if (!isset($options['laboratory'])) {
        $options['laboratory'] = $this->t('Laboratory');
      }
    }

    return $options;
  }

  /**
   * Resolve the linked process URI from a Process-Based Study object.
   */
  private function resolveProcessUriFromStudy($study): string {
    $processUri = '';
    if (is_object($study) && isset($study->processUri)) {
      if (is_object($study->processUri)) {
        $processUri = trim((string) ($study->processUri->uri ?? ''));
      }
      else {
        $processUri = trim((string) $study->processUri);
      }
    }
    if ($processUri === '' && is_object($study) && isset($study->hasProcess)) {
      if (is_object($study->hasProcess)) {
        $processUri = trim((string) ($study->hasProcess->uri ?? ''));
      }
      else {
        $processUri = trim((string) $study->hasProcess);
      }
    }
    if ($processUri === '' && is_object($study) && isset($study->hasProcessUri)) {
      $processUri = trim((string) $study->hasProcessUri);
    }
    return $processUri;
  }

  /**
   * Resolve a canonical study URI from form state, object state, or route.
   */
  private function resolveStudyUriForSubmit(FormStateInterface $form_state): string {
    $candidate = trim((string) $form_state->getValue('study_uri'));
    if ($candidate === '') {
      $candidate = trim((string) ($this->studyUri ?? ''));
    }

    if ($candidate === '' && is_object($this->study) && isset($this->study->uri)) {
      $candidate = trim((string) $this->study->uri);
    }

    if ($candidate === '') {
      $routeParam = \Drupal::routeMatch()->getParameter('studyuri');
      $encoded = is_string($routeParam) ? rawurldecode($routeParam) : '';
      $decoded = $encoded !== '' ? base64_decode($encoded, TRUE) : FALSE;
      if (is_string($decoded) && trim($decoded) !== '') {
        $candidate = trim($decoded);
      }
      elseif ($encoded !== '') {
        // Support plain URI route parameters when not base64-encoded.
        $candidate = trim($encoded);
      }
    }

    if ($candidate === '') {
      $buildInfo = $form_state->getBuildInfo();
      $arg0 = $buildInfo['args'][0] ?? '';
      $encoded = is_string($arg0) ? rawurldecode($arg0) : '';
      $decoded = $encoded !== '' ? base64_decode($encoded, TRUE) : FALSE;
      if (is_string($decoded) && trim($decoded) !== '') {
        $candidate = trim($decoded);
      }
      elseif ($encoded !== '') {
        $candidate = trim($encoded);
      }
    }

    return Utils::canonicalizePmsrUri($candidate);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'edit_processbasedstudy_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $studyuri = NULL) {
    $preferredStudyNoun = $this->preferredStudyNoun();
    $preferredStudyLabel = $this->preferredStudyLabel();

    // Decode and validate the URI parameter.
    $encodedStudyUri = is_string($studyuri) ? rawurldecode($studyuri) : '';
    $decodedStudyUri = $encodedStudyUri !== '' ? base64_decode($encodedStudyUri, TRUE) : FALSE;
    if (!is_string($decodedStudyUri) || trim($decodedStudyUri) === '') {
      \Drupal::messenger()->addError($this->t('Invalid Process-Based @study URI.', ['@study' => $preferredStudyLabel]));
      $form_state->setRedirect('std.edit_study', ['studyuri' => $studyuri]);
      return [];
    }
    $this->studyUri = Utils::canonicalizePmsrUri(trim($decodedStudyUri));

    // Fetch the existing ProcessBasedStudy from the API
    $api = \Drupal::service('rep.api_connector');
    try {
      $studyResponse = $api->getUri($this->studyUri);
      $this->study = $api->parseObjectResponse($studyResponse, 'getUri');
    }
    catch (\Throwable $e) {
      \Drupal::messenger()->addError($this->t('Failed to load Process-Based @study: @message', [
        '@study' => $preferredStudyLabel,
        '@message' => $e->getMessage(),
      ]));
      $form_state->setRedirect('std.edit_study', ['studyuri' => $studyuri]);
      return [];
    }

    if ($this->study === NULL) {
      \Drupal::messenger()->addError($this->t('Failed to load ProcessBasedStudy with URI: @uri', ['@uri' => $this->studyUri]));
      $form_state->setRedirect('std.edit_study', ['studyuri' => $studyuri]);
      return [];
    }

    // Normalize process URI values that may arrive as structured objects.
    $processUri = Utils::canonicalizePmsrUri($this->resolveProcessUriFromStudy($this->study));
    $this->processUri = $processUri;
    $organizationUri = $this->resolveOrganizationUriFromStudy($this->study);
    $piEmail = $this->toSafeString($this->study->contactEmail ?? '');
    $principalInvestigatorUri = $this->resolvePrincipalInvestigatorUriByEmail($piEmail);
    if ($principalInvestigatorUri === '') {
      $principalInvestigatorUri = $this->resolvePrincipalInvestigatorUriFromStudy($this->study);
    }
    $organizationName = $this->toSafeString($this->study->institutionName ?? ($this->study->institution ?? ''));
    $laboratoryOptions = $this->buildLaboratoryOptions($organizationUri, $organizationName);
    $existingLaboratory = $this->toSafeString($this->study->laboratory ?? ($this->study->hasLaboratory ?? ''));
    if ($existingLaboratory !== '' && !isset($laboratoryOptions[$existingLaboratory])) {
      $laboratoryOptions[$existingLaboratory] = $existingLaboratory;
    }
    $defaultLaboratory = $existingLaboratory !== '' ? $existingLaboratory : 'any';

    // Hidden field to preserve the URI
    $form['study_uri'] = [
      '#type' => 'hidden',
      '#value' => $this->studyUri,
    ];

    // Study metadata layout
    $form['study_metadata'] = [
      '#type' => 'container',
    ];

    $form['study_metadata']['properties_layout'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['row', 'g-3'],
      ],
    ];

    $form['study_metadata']['properties_layout']['left_column'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-12', 'col-lg-6'],
      ],
    ];

    $form['study_metadata']['properties_layout']['right_column'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['col-12', 'col-lg-6'],
      ],
    ];

    $form['study_metadata']['properties_layout']['left_column']['predefined_properties'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Predefined Properties'),
      '#weight' => 0,
      '#attributes' => [
        'style' => 'border: 2px solid #9ca3af; border-radius: 8px; padding: 12px; background: #ffffff;',
      ],
    ];

    $form['study_metadata']['properties_layout']['left_column']['task_management'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Task Management'),
      '#weight' => 20,
      '#attributes' => [
        'class' => ['mt-3'],
        'style' => 'border: 2px solid #9ca3af; border-radius: 8px; padding: 12px; background: #ffffff;',
      ],
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Adjustable Properties'),
      '#attributes' => [
        'style' => 'border: 2px solid #9ca3af; border-radius: 8px; padding: 12px; background: #ffffff;',
      ],
    ];

    $form['study_metadata']['properties_layout']['left_column']['predefined_properties']['study_uri_display'] = [
      '#type' => 'textfield',
      '#title' => $this->t('@study URI', ['@study' => $preferredStudyLabel]),
      '#default_value' => $this->toSafeString($this->studyUri),
      '#disabled' => TRUE,
      '#field_suffix' => $this->buildDetailButtonMarkup($this->studyUri),
    ];

    $form['study_metadata']['properties_layout']['left_column']['predefined_properties']['process_uri_display'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Process URI'),
      '#default_value' => $processUri !== '' ? $this->toSafeString($processUri) : 'N/A',
      '#description' => $this->t('The associated Process cannot be changed after creation.'),
      '#disabled' => TRUE,
      '#field_suffix' => $this->buildDetailButtonMarkup($processUri),
    ];

    $form['study_metadata']['properties_layout']['left_column']['predefined_properties']['study_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('@study ID', ['@study' => $preferredStudyLabel]),
      '#default_value' => $this->toSafeString($this->study->studyID ?? ''),
      '#description' => $this->t('@study ID is read-only in edit mode because URI renaming is not supported.', ['@study' => $preferredStudyLabel]),
      '#maxlength' => 128,
      '#disabled' => TRUE,
    ];

    $form['study_metadata']['properties_layout']['left_column']['context_properties'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Context Properties'),
      '#weight' => 10,
      '#attributes' => [
        'class' => ['mt-3'],
        'style' => 'border: 2px solid #9ca3af; border-radius: 8px; padding: 12px; background: #ffffff;',
      ],
    ];

    $form['study_metadata']['properties_layout']['left_column']['context_properties']['institution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Institution'),
      '#default_value' => $this->toSafeString($this->study->institutionName ?? ($this->study->institution ?? '')),
      '#description' => $this->t('Name of the institution conducting the @study.', ['@study' => $preferredStudyNoun]),
      '#maxlength' => 256,
      '#disabled' => TRUE,
      '#field_suffix' => $this->buildDetailButtonMarkup($organizationUri),
    ];

    $form['study_metadata']['properties_layout']['left_column']['context_properties']['laboratory'] = [
      '#type' => 'select',
      '#title' => $this->t('Laboratory'),
      '#description' => $this->t('Optional. Select a laboratory available at the selected organization.'),
      '#options' => $laboratoryOptions,
      '#default_value' => $defaultLaboratory,
      '#required' => FALSE,
    ];

    $form['study_metadata']['properties_layout']['left_column']['predefined_properties']['principal_investigator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Principal Investigator'),
      '#default_value' => $this->toSafeString($this->study->principalInvestigator ?? ($this->study->pi ?? '')),
      '#description' => $this->t('Name of the PI.'),
      '#maxlength' => 256,
      '#disabled' => TRUE,
      '#field_suffix' => $this->buildDetailButtonMarkup($principalInvestigatorUri),
    ];

    $form['study_metadata']['properties_layout']['left_column']['predefined_properties']['contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Contact Email'),
      '#default_value' => $this->toSafeString($this->study->contactEmail ?? ''),
      '#description' => $this->t('Email address for @study contact.', ['@study' => $preferredStudyNoun]),
      '#maxlength' => 256,
      '#disabled' => TRUE,
    ];

    $form['study_metadata']['properties_layout']['left_column']['task_management']['validate_task_model'] = [
      '#type' => 'submit',
      '#value' => $this->t('Validate Task Model'),
      '#submit' => ['::validateTaskModel'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'me-2', 'mb-2', 'check-button', 'top-icon'],
        'style' => 'max-width: 220px;',
      ],
    ];

    $form['study_metadata']['properties_layout']['left_column']['task_management']['execute_task_model'] = [
      '#type' => 'submit',
      '#value' => $this->t('Execute Task Model'),
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'me-2', 'mb-2', 'execute-button'],
        'style' => 'max-width: 220px;',
      ],
      '#disabled' => TRUE,
    ];

    $form['study_metadata']['properties_layout']['left_column']['task_management']['edit_task'] = [
      '#type' => 'submit',
      '#value' => $this->t('Edit Task Model'),
      '#submit' => ['::openLegacyEditor'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'me-2', 'mb-2', 'edit-task-button', 'edit-element-button', 'top-icon'],
        'style' => 'max-width: 220px; border-color: #006600;',
      ],
    ];

    $form['study_metadata']['properties_layout']['left_column']['task_management']['edit_task_canvas'] = [
      '#type' => 'submit',
      '#value' => $this->t('Edit Task Model - Canvas'),
      '#submit' => ['::openCanvasEditor'],
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['btn', 'btn-primary', 'mb-2', 'edit-task-button', 'edit-element-button', 'top-icon', 'edit-task-canvas-button'],
        'style' => 'max-width: 220px; border-color: #006600;',
      ],
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties']['study_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('@study Title', ['@study' => $preferredStudyLabel]),
      '#default_value' => $this->toSafeString($this->study->studyTitle ?? ''),
      '#description' => $this->t('Full title of the @study.', ['@study' => $preferredStudyNoun]),
      '#maxlength' => 512,
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties']['specific_aims'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Specific Aims'),
      '#default_value' => $this->toSafeString($this->study->specificAims ?? ''),
      '#description' => $this->t('Research aims and objectives.'),
      '#rows' => 3,
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties']['significance'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Significance'),
      '#default_value' => $this->toSafeString($this->study->significance ?? ''),
      '#description' => $this->t('Why this @study is important.', ['@study' => $preferredStudyNoun]),
      '#rows' => 3,
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('Start Date'),
      '#default_value' => $this->toSafeString($this->study->startDate ?? ''),
      '#description' => $this->t('@study start date (ISO 8601 format: YYYY-MM-DD).', ['@study' => $preferredStudyLabel]),
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('End Date'),
      '#default_value' => $this->toSafeString($this->study->endDate ?? ''),
      '#description' => $this->t('@study end date (ISO 8601 format: YYYY-MM-DD).', ['@study' => $preferredStudyLabel]),
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties']['learning_objectives'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Learning Objectives'),
      '#default_value' => $this->toSafeString($this->study->hasLearningObjectives ?? ''),
      '#description' => $this->t('Measurable learning outcomes, semicolon-separated (INACSL Criterion 3).'),
      '#rows' => 3,
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties']['critical_actions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Critical Actions'),
      '#default_value' => $this->toSafeString($this->study->hasCriticalActions ?? ''),
      '#description' => $this->t('Essential performance criteria for assessment, semicolon-separated (INACSL Criterion 5/10).'),
      '#rows' => 3,
    ];

    $form['study_metadata']['properties_layout']['right_column']['adjustable_properties']['debriefing_focus'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Debriefing Focus'),
      '#default_value' => $this->toSafeString($this->study->hasDebriefingFocus ?? ''),
      '#description' => $this->t('Structured reflection topics/questions, semicolon-separated (INACSL Criterion 9).'),
      '#rows' => 3,
    ];

    // Submit buttons
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#name' => 'save',
    ];

    $form['actions']['delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete'),
      '#name' => 'delete',
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button', 'button--danger'],
        'onclick' => 'return confirm("Are you sure you want to delete this scenario?");',
      ],
    ];

    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Back'),
      '#name' => 'back',
      '#limit_validation_errors' => [],
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $study_id = trim((string) $form_state->getValue('study_id'));
    $contact_email = trim($form_state->getValue('contact_email'));
    $preferredStudyLabel = $this->preferredStudyLabel();

    // Auto-normalize legacy identifiers such as STD_ABC to STD-ABC.
    if ($study_id !== '') {
      $normalizedStudyId = str_replace('_', '-', $study_id);
      if ($normalizedStudyId !== $study_id) {
        $form_state->setValue('study_id', $normalizedStudyId);
      }
      $study_id = $normalizedStudyId;
    }

    // Validate Study ID format if provided
    if (!empty($study_id)) {
      if (!preg_match('/^STD-/', $study_id)) {
        $form_state->setErrorByName('study_id', $this->t('@study ID must start with "STD-".', ['@study' => $preferredStudyLabel]));
      }
    }

    // Validate email format if provided
    if (!empty($contact_email)) {
      if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $form_state->setErrorByName('contact_email', $this->t('Contact Email must be a valid email address.'));
      }
    }

    // Validate date order if both provided
    $start_date = $form_state->getValue('start_date');
    $end_date = $form_state->getValue('end_date');
    if (!empty($start_date) && !empty($end_date)) {
      if (strtotime($start_date) > strtotime($end_date)) {
        $form_state->setErrorByName('end_date', $this->t('End Date must be after Start Date.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $button_name = $triggering_element['#name'];
    $studyUriForNavigation = $this->resolveStudyUriForSubmit($form_state);

    if ($button_name === 'back') {
      $this->backUrl($studyUriForNavigation);
      return;
    }

    if ($button_name === 'delete') {
      try {
        $studyUri = $this->resolveStudyUriForSubmit($form_state);
        if ($studyUri === '') {
          throw new \RuntimeException('Missing ProcessBasedStudy URI for delete operation.');
        }
        $api = \Drupal::service('rep.api_connector');
        $deleteEndpoint = '/hascoapi/api/processbasedstudy/delete/' . rawurlencode($studyUri);
        $deleteResponse = $api->perform_http_request('POST', $api->getApiUrl() . $deleteEndpoint, $api->getHeader());
        if ($deleteResponse === NULL) {
          // Fallback for deployments where delete is exposed via GET.
          $deleteResponse = $api->elementDel('processbasedstudy', $studyUri);
        }
        $deleted = $api->parseObjectResponse($deleteResponse, 'deleteProcessBasedStudy');
        if ($deleted === NULL && $deleteResponse !== NULL) {
          // Some API builds return a success payload shape with empty body.
          $decodedDelete = json_decode((string) $deleteResponse);
          if (is_object($decodedDelete) && !empty($decodedDelete->isSuccessful)) {
            $deleted = TRUE;
          }
        }

        if ($deleted === NULL) {
          throw new \RuntimeException('API rejected ProcessBasedStudy delete request.');
        }

        \Drupal::messenger()->addMessage($this->t('Process-Based @study has been deleted successfully.', ['@study' => $this->preferredStudyLabel()]));
        \Drupal\std\Service\StudyVariableSearchService::invalidateCache($studyUri);
        $this->backUrl($studyUri);
        return;
      }
      catch (\Exception $e) {
        \Drupal::messenger()->addError($this->t('An error occurred while deleting Process-Based @study: @message', [
          '@study' => $this->preferredStudyLabel(),
          '@message' => $e->getMessage(),
        ]));
        $this->backUrl($studyUriForNavigation);
        return;
      }
    }

    try {
      $studyUri = $this->resolveStudyUriForSubmit($form_state);
      if ($studyUri === '') {
        throw new \RuntimeException('Missing ProcessBasedStudy URI for update operation.');
      }

      // Build JSON payload for ProcessBasedStudy update
      // Include all editable metadata fields
      $processUri = $this->processUri !== '' ? $this->processUri : $this->resolveProcessUriFromStudy($this->study);
      $processUri = Utils::canonicalizePmsrUri($processUri);

      $originalStudyId = trim((string) ($this->study->studyID ?? ''));
      $studyId = $originalStudyId;
      $submittedStudyId = trim((string) $form_state->getValue('study_id'));
      if ($submittedStudyId !== '') {
        $studyId = $submittedStudyId;
      }
      if ($studyId !== '') {
        $studyId = str_replace('_', '-', $studyId);
        if (str_starts_with($studyId, 'STD_')) {
          $studyId = 'STD-' . substr($studyId, 4);
        }
      }

      // Preserve read-only values: disabled fields are not submitted by browsers.
      $institutionName = trim((string) $form_state->getValue('institution'));
      if ($institutionName === '') {
        $institutionName = $this->toSafeString($this->study->institutionName ?? ($this->study->institution ?? ''));
      }

      $principalInvestigator = trim((string) $form_state->getValue('principal_investigator'));
      if ($principalInvestigator === '') {
        $principalInvestigator = $this->toSafeString($this->study->principalInvestigator ?? ($this->study->pi ?? ''));
      }

      $contactEmail = trim((string) $form_state->getValue('contact_email'));
      if ($contactEmail === '') {
        $contactEmail = $this->toSafeString($this->study->contactEmail ?? '');
      }

      $laboratory = trim((string) $form_state->getValue('laboratory'));
      if (strtolower($laboratory) === 'any') {
        $laboratory = '';
      }

      $studyData = [
        'uri' => $studyUri,
        'typeUri' => HASCO::PROCESS_BASED_STUDY,
        'hascoTypeUri' => HASCO::PROCESS_BASED_STUDY,
        'processUri' => $processUri, // Cannot be changed
        'studyID' => $studyId,
        'studyTitle' => trim($form_state->getValue('study_title')),
        'specificAims' => trim($form_state->getValue('specific_aims')),
        'significance' => trim($form_state->getValue('significance')),
        'institutionName' => $institutionName,
        'institution' => $institutionName,
        'principalInvestigator' => $principalInvestigator,
        'contactEmail' => $contactEmail,
        'laboratory' => $laboratory,
        'hasLaboratory' => $laboratory,
        'startDate' => $form_state->getValue('start_date') ?: '',
        'endDate' => $form_state->getValue('end_date') ?: '',
        'hasLearningObjectives' => trim($form_state->getValue('learning_objectives')),
        'hasCriticalActions' => trim($form_state->getValue('critical_actions')),
        'hasDebriefingFocus' => trim($form_state->getValue('debriefing_focus')),
      ];

      $studyJSON = json_encode($studyData);

      // Use dedicated API to update ProcessBasedStudy
      $api = \Drupal::service('rep.api_connector');

      $updateEndpoint = '/hascoapi/api/processbasedstudy/update/' . rawurlencode($studyUri);
      // Primary strategy: hascoapi runtime in this environment binds `json`
      // reliably from query string parameters.
      $updateResponse = $api->perform_http_request(
        'POST',
        $api->getApiUrl() . $updateEndpoint . '?json=' . rawurlencode($studyJSON),
        $api->getHeader()
      );
      $updated = $api->parseObjectResponse($updateResponse, 'updateProcessBasedStudyQuery');

      if ($updated === NULL) {
        // Fallback: form-encoded json parameter.
        $updateOptions = $api->getHeader();
        $updateOptions['form_params'] = ['json' => $studyJSON];
        $updateResponse = $api->perform_http_request('POST', $api->getApiUrl() . $updateEndpoint, $updateOptions);
        $updated = $api->parseObjectResponse($updateResponse, 'updateProcessBasedStudyForm');
      }

      if ($updated === NULL) {
        // Final fallback: raw JSON body for environments with body parser support.
        $updateOptions = $api->getHeader();
        $updateOptions['headers']['Content-Type'] = 'application/json';
        $updateOptions['body'] = $studyJSON;
        $updateResponse = $api->perform_http_request('POST', $api->getApiUrl() . $updateEndpoint, $updateOptions);
        $updated = $api->parseObjectResponse($updateResponse, 'updateProcessBasedStudyBody');
      }
      
      if ($updated === NULL) {
        throw new \RuntimeException('API rejected ProcessBasedStudy update payload.');
      }

      // Verify update
      $verify = $api->parseObjectResponse($api->getUri($studyUri), 'getUri');
      if ($verify === NULL) {
        throw new \RuntimeException('ProcessBasedStudy was not persisted after update call.');
      }

      \Drupal::messenger()->addMessage($this->t('Process-Based @study has been updated successfully.', ['@study' => $this->preferredStudyLabel()]));
      
      // Invalidate study search cache for this study
      \Drupal\std\Service\StudyVariableSearchService::invalidateCache($studyUri);
      
      $this->backUrl($studyUri);
      return;

    } catch(\Exception $e) {
      \Drupal::messenger()->addError($this->t('An error occurred while updating Process-Based @study: @message', [
        '@study' => $this->preferredStudyLabel(),
        '@message' => $e->getMessage(),
      ]));
      $this->backUrl($studyUriForNavigation);
      return;
    }
  }

  /**
   * Open the visual React canvas editor for the linked workflow process.
   */
  public function openCanvasEditor(array &$form, FormStateInterface $form_state) {
    $processUri = $this->processUri !== '' ? $this->processUri : $this->resolveProcessUriFromStudy($this->study);
    if ($processUri === '') {
      \Drupal::messenger()->addError($this->t('No Process URI is linked to this Process-Based @study.', ['@study' => $this->preferredStudyLabel()]));
      return;
    }

    $previousUrl = \Drupal::request()->getRequestUri();
    $url = NULL;

    try {
      $routeProvider = \Drupal::service('router.route_provider');
      try {
        $routeProvider->getRouteByName('hasco_workflow.editor.page');
        $url = Url::fromRoute('hasco_workflow.editor.page', [], [
          'query' => [
            'processUri' => $processUri,
            'studyUri' => $this->studyUri,
          ],
        ]);
      }
      catch (\Exception $e) {
        $routeProvider->getRouteByName('ctt.editor');
        $url = Url::fromRoute('ctt.editor', [], [
          'query' => [
            'processUri' => $processUri,
            'studyUri' => $this->studyUri,
          ],
        ]);
      }
    }
    catch (\Exception $e) {
      $url = $this->legacyEditorUrl($processUri, $previousUrl);
    }

    $form_state->setRedirectUrl($url);
  }

  /**
   * Open the legacy Drupal-forms task-model editor.
   */
  public function openLegacyEditor(array &$form, FormStateInterface $form_state) {
    $processUri = $this->processUri !== '' ? $this->processUri : $this->resolveProcessUriFromStudy($this->study);
    if ($processUri === '') {
      \Drupal::messenger()->addError($this->t('No Process URI is linked to this Process-Based @study.', ['@study' => $this->preferredStudyLabel()]));
      return;
    }

    $previousUrl = \Drupal::request()->getRequestUri();
    $url = $this->legacyEditorUrl($processUri, $previousUrl);
    $form_state->setRedirectUrl($url);
  }

  /**
   * Build the URL for the legacy task-model editor route (std.edit_task).
   */
  protected function legacyEditorUrl(string $processUri, ?string $backTo = NULL) {
    $api = \Drupal::service('rep.api_connector');
    $process = $api->parseObjectResponse($api->getUri($processUri), 'getUri');
    $topTaskUri = is_object($process) ? trim((string) ($process->hasTopTaskUri ?? '')) : '';
    if ($topTaskUri === '' && is_object($process) && isset($process->hasTopTask) && is_object($process->hasTopTask)) {
      $topTaskUri = trim((string) ($process->hasTopTask->uri ?? ''));
    }
    if ($topTaskUri === '') {
      \Drupal::messenger()->addWarning($this->t('Legacy task editor is unavailable because this workflow has no Top Task. Opening workflow editor instead.'));
      return Url::fromRoute('std.edit_workflow', [
        'workflowuri' => base64_encode($processUri),
      ]);
    }

    $topTask = $api->parseObjectResponse($api->getUri($topTaskUri), 'getUri');
    $state = ($topTask && ($topTask->typeUri ?? '') === VSTOI::ABSTRACT_TASK) ? 'tasks' : 'basic';

    $options = [];
    if (is_string($backTo) && trim($backTo) !== '') {
      $options['query'] = [
        'back_to' => base64_encode($backTo),
      ];
    }

    return Url::fromRoute('std.edit_task', [
      'workflowuri' => base64_encode($processUri),
      'state' => $state,
      'taskuri' => base64_encode($topTaskUri),
    ], $options);
  }

  /**
   * Validate the linked workflow task model.
   */
  public function validateTaskModel(array &$form, FormStateInterface $form_state) {
    $form_state->setRebuild(TRUE);
    $processUri = $this->processUri !== '' ? $this->processUri : $this->resolveProcessUriFromStudy($this->study);
    if ($processUri === '') {
      \Drupal::messenger()->addError($this->t('Validation failed: this Process-Based @study has no linked Process URI.', ['@study' => $this->preferredStudyLabel()]));
      return;
    }

    $api = \Drupal::service('rep.api_connector');
    $process = $api->parseObjectResponse($api->getUri($processUri), 'getUri');
    if (!$process) {
      \Drupal::messenger()->addError($this->t('Validation failed: linked Process <em>@uri</em> returned no object from the knowledge graph.', ['@uri' => $processUri]));
      return;
    }

    $topTaskUri = trim((string) ($process->hasTopTaskUri ?? ''));
    if ($topTaskUri === '' && isset($process->hasTopTask) && is_object($process->hasTopTask)) {
      $topTaskUri = trim((string) ($process->hasTopTask->uri ?? ''));
    }
    if ($topTaskUri === '') {
      \Drupal::messenger()->addError($this->t('Validation failed: this workflow has no Top Task (hasTopTask), so the editor cannot render a task tree.'));
      return;
    }

    $top = $api->parseObjectResponse($api->getUri($topTaskUri), 'getUri');
    if (!$top) {
      \Drupal::messenger()->addError($this->t('Validation failed: the Top Task <em>@uri</em> returned no object from the knowledge graph. The canvas shows the empty "Main Task" placeholder. Re-ingest the workflow metatemplate to restore it.', ['@uri' => $topTaskUri]));
      return;
    }

    $visited = [];
    $unresolved = [];
    $queue = [$topTaskUri];
    $count = 0;
    while (!empty($queue)) {
      $uri = trim((string) array_shift($queue));
      if ($uri === '' || isset($visited[$uri])) {
        continue;
      }
      $visited[$uri] = TRUE;
      $task = ($uri === $topTaskUri) ? $top : $api->parseObjectResponse($api->getUri($uri), 'getUri');
      if (!$task) {
        $unresolved[] = $uri;
        continue;
      }
      $count++;
      $subs = $task->hasSubtaskUris ?? [];
      if (is_string($subs)) {
        $subs = [$subs];
      }
      if (is_array($subs)) {
        foreach ($subs as $sub) {
          if (is_string($sub) && trim($sub) !== '') {
            $queue[] = trim($sub);
          }
        }
      }
    }

    if (!empty($unresolved)) {
      \Drupal::messenger()->addWarning($this->t('@n subtask reference(s) do not resolve in the knowledge graph (broken links): @list', [
        '@n' => count($unresolved),
        '@list' => implode(', ', array_slice($unresolved, 0, 5)) . (count($unresolved) > 5 ? ', ...' : ''),
      ]));
      return;
    }

    \Drupal::messenger()->addStatus($this->t('Task model is valid: @n task(s) reachable from a single Top Task "@label".', [
      '@n' => $count,
      '@label' => $top->label ?? $topTaskUri,
    ]));
  }

  /**
   * Navigate back to previous page
   */
  function backUrl(?string $studyUri = NULL) {
    $candidateStudyUri = trim((string) $studyUri);
    if ($candidateStudyUri === '') {
      $candidateStudyUri = trim((string) ($this->studyUri ?? ''));
    }
    if ($candidateStudyUri === '' && is_object($this->study) && isset($this->study->uri)) {
      $candidateStudyUri = trim((string) $this->study->uri);
    }

    if ($candidateStudyUri !== '') {
      $encodedStudyUri = base64_encode($candidateStudyUri);
      $response = new RedirectResponse(Url::fromRoute('std.manage_study_elements', [
        'studyuri' => $encodedStudyUri,
      ])->toString());
      $response->send();
      return;
    }

    $response = new RedirectResponse(Url::fromRoute('std.select_study', [
      'elementtype' => 'study',
      'page' => 1,
      'pagesize' => 9,
    ])->toString());
    $response->send();
  }

}
