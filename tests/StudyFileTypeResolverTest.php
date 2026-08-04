<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/StudyFileTypeResolver.php';

use Drupal\std\Support\StudyFileTypeResolver;

function assertSameValue($expected, $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

assertSameValue('da', StudyFileTypeResolver::resolveStorageFolderFromFilename('table.csv'), 'csv should map to da');
assertSameValue('Publications', StudyFileTypeResolver::resolveStorageFolderFromFilename('report.docx'), 'docx should map to Publications');
assertSameValue('media', StudyFileTypeResolver::resolveStorageFolderFromFilename('clip.mp4'), 'mp4 should map to media');
assertSameValue('OHIF', StudyFileTypeResolver::resolveStorageFolderFromFilename('slice.dcm'), 'dcm should map to OHIF');
assertSameValue('OHIF', StudyFileTypeResolver::resolveStorageFolderFromFilename('volume.nii.gz'), 'nii.gz should map to OHIF');
assertSameValue('da', StudyFileTypeResolver::resolveStorageFolderFromFilename('session.ssx'), 'ssx should map to da');
assertSameValue('da', StudyFileTypeResolver::resolveStorageFolderFromFilename('scenario.scx'), 'scx should map to da');
assertSameValue('da', StudyFileTypeResolver::resolveStorageFolderFromFilename('sim-result.sim'), 'sim should map to da');
assertSameValue('da', StudyFileTypeResolver::resolveStorageFolderFromFilename('procedure.score'), 'score should map to da');
assertSameValue(true, StudyFileTypeResolver::isProprietarySimulationResultFilename('session.ssx'), 'ssx should be recognized as proprietary simulation suffix');
assertSameValue(false, StudyFileTypeResolver::isProprietarySimulationResultFilename('table.csv'), 'csv should not be recognized as proprietary simulation suffix');
assertSameValue('nii.gz', StudyFileTypeResolver::normalizeExtension('volume.nii.gz'), 'normalizeExtension should preserve nii.gz');
assertSameValue(null, StudyFileTypeResolver::resolveStorageFolderFromFilename('bundle.zip'), 'zip must not be accepted');

fwrite(STDOUT, "PASS: StudyFileTypeResolverTest\n");
