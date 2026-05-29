<?php

declare(strict_types=1);

namespace Drupal\Tests\std\FunctionalJavascript;

use Drupal\Core\File\FileSystemInterface;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Validates medical viewer runtime fallback inside an iframe wrapper.
 *
 * @group std
 */
final class MedicalViewerFallbackJavascriptTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'std', 'std_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'hasco_barrio';

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  protected function setUp(): void {
    parent::setUp();

    $account = $this->createUser(['access content']);
    $this->drupalLogin($account);
  }

  public function testRuntimeFallbackInIframe(): void {
    $studyUri = 'http://example.org/study/runtime-fallback';
    $studyKey = 'runtime-fallback';
    $filename = 'broken-scan.dcm';

    // Keep DICOM extension to trigger can_visualize=true while forcing parser failure.
    $this->createMedicalFile($studyKey, $filename, 'not-a-valid-dicom');

    $wrapperPath = '/std-test/medical-viewer-wrapper/'
      . rawurlencode($filename)
      . '/'
      . $this->encodeStudyUri($studyUri)
      . '/'
      . $this->buildToken($filename);

    $this->drupalGet($wrapperPath);
    $this->assertSession()->pageTextContains('Medical Viewer Wrapper');
    $this->assertSession()->elementExists('css', '#std-medical-viewer-frame');

    $this->getSession()->switchToIFrame('std-medical-viewer-frame');

    $this->assertSession()->elementExists('css', '#std-medical-viewer');

    $statusCondition = "document.querySelector('#std-medical-viewer-status') && document.querySelector('#std-medical-viewer-status').textContent.indexOf('Unable to render DICOM preview.') !== -1";
    $fallbackCondition = "document.querySelector('#std-medical-viewer-fallback') && !document.querySelector('#std-medical-viewer-fallback').classList.contains('d-none')";

    $this->assertTrue((bool) $this->getSession()->wait(8000, $statusCondition), 'Timed out waiting for runtime render-error status in viewer iframe.');
    $this->assertTrue((bool) $this->getSession()->wait(8000, $fallbackCondition), 'Timed out waiting for fallback panel to become visible in viewer iframe.');

    $this->assertSession()->pageTextContains('If rendering fails, open the original file or download it.');
    $this->assertSession()->pageTextContains('Open Original File');
    $this->assertSession()->pageTextContains('Download');

    $this->getSession()->switchToIFrame();
  }

  private function createMedicalFile(string $studyKey, string $filename, string $contents): void {
    $directory = 'private://std/' . $studyKey . '/OHIF';

    /** @var \Drupal\Core\File\FileSystemInterface $fileSystem */
    $fileSystem = $this->container->get('file_system');
    $fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $realDirectory = $fileSystem->realpath($directory);
    $this->assertNotFalse($realDirectory);

    file_put_contents($realDirectory . DIRECTORY_SEPARATOR . $filename, $contents);
  }

  private function encodeStudyUri(string $studyUri): string {
    return rawurlencode(base64_encode($studyUri));
  }

  private function buildToken(string $filename): string {
    return hash_hmac('sha256', $filename, '1357924680');
  }

}
