<?php

declare(strict_types=1);

namespace Drupal\std_test;

/**
 * Deterministic API stub for Study Search functional tests.
 */
final class StudySearchApiConnectorStub {

  public function listByKeyword($elementType, $keyword, $pageSize, $offset): array {
    if ($elementType === 'study') {
      return $this->getStudies();
    }

    if ($elementType === 'workflow') {
      return $this->getWorkflows();
    }

    return [];
  }

  public function parseObjectResponse($response, $methodCalled) {
    return $response;
  }

  public function virtualColumnsByStudy(string $studyUri): array {
    $columns = [
      'http://example.org/study/alpha' => [
        (object) ['label' => 'Heart Rate'],
        (object) ['label' => 'Blood Pressure'],
        (object) ['label' => 'Left Ventricle UBERON:0002084'],
        (object) ['label' => 'Cardiac MRI NCIT:C16809'],
      ],
      'http://example.org/study/beta' => [
        (object) ['label' => 'Heart Rate'],
        (object) ['label' => 'Exercise Capacity NCIT:C25347'],
        (object) ['label' => 'Respiratory Flow UBERON:0000178'],
      ],
      'http://example.org/study/gamma' => [
        (object) ['label' => 'Heart Rate'],
        (object) ['label' => 'Body Temperature'],
        (object) ['label' => 'Lung Volume UBERON:0002048'],
      ],
      'http://example.org/study/delta' => [
        (object) ['label' => 'Blood Pressure'],
        (object) ['label' => 'Oxygen Saturation'],
        (object) ['label' => 'Electrocardiogram NCIT:C16403'],
      ],
      'http://example.org/study/hidden' => [
        (object) ['label' => 'Secret Variable NCIT:C99999'],
      ],
    ];

    return $columns[$studyUri] ?? [];
  }

  private function getStudies(): array {
    return [
      (object) [
        'uri' => 'http://example.org/study/alpha',
        'label' => 'Study Alpha',
        'comment' => 'Alpha test study',
        'hasStatus' => 'https://hadatac.org/ont/vstoi#current',
        'hasSIRManagerEmail' => 'owner1@example.com',
      ],
      (object) [
        'uri' => 'http://example.org/study/beta',
        'label' => 'Study Beta',
        'comment' => 'Beta draft from owner1',
        'hasStatus' => 'https://hadatac.org/ont/vstoi#draft',
        'hasSIRManagerEmail' => 'owner1@example.com',
      ],
      (object) [
        'uri' => 'http://example.org/study/gamma',
        'label' => 'Study Gamma',
        'comment' => 'Gamma public from another owner',
        'hasStatus' => 'https://hadatac.org/ont/vstoi#current',
        'hasSIRManagerEmail' => 'other@example.com',
      ],
      (object) [
        'uri' => 'http://example.org/study/delta',
        'label' => 'Study Delta',
        'comment' => 'Delta population baseline cohort',
        'hasStatus' => 'https://hadatac.org/ont/vstoi#current',
        'hasSIRManagerEmail' => 'other@example.com',
      ],
      (object) [
        'uri' => 'http://example.org/study/hidden',
        'label' => 'Study Hidden',
        'comment' => 'Hidden draft from another owner',
        'hasStatus' => 'https://hadatac.org/ont/vstoi#draft',
        'hasSIRManagerEmail' => 'other@example.com',
      ],
    ];
  }

  private function getWorkflows(): array {
    return [
      (object) [
        'uri' => 'http://example.org/workflow/alpha',
        'studyuri' => 'http://example.org/study/alpha',
        'simulatorName' => 'Cardio Simulator',
        'instrumentName' => '12-Lead ECG Monitor',
        'componentName' => 'Pressure Sensor Actuator',
        'questionnaireName' => 'Baseline Intake Questionnaire',
      ],
      (object) [
        'uri' => 'http://example.org/workflow/beta',
        'studyuri' => 'http://example.org/study/beta',
        'simulatorPlatform' => 'Respiratory Simulation Platform',
        'instrumentName' => 'Spirometer',
        'componentActuator' => 'Valve Controller',
        'codebookName' => 'Pulmonary Follow-up Questionnaire',
      ],
    ];
  }

}
