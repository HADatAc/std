<?php

declare(strict_types=1);

namespace Drupal\Tests\std\Functional;

use Drupal\Component\Serialization\Json;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Tests\BrowserTestBase;

/**
 * Covers internal medical viewer endpoint behavior.
 *
 * @group std
 */
final class MedicalImageViewerEndpointTest extends BrowserTestBase {

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

  public function testMedicalListingIncludesConditionalViewerFlag(): void {
    $studyUri = 'http://example.org/study/alpha';
    $studyKey = 'alpha';

    $this->createMedicalFile($studyKey, 'scan.dcm', str_repeat("\0", 132) . 'DICM');
    $this->createMedicalFile($studyKey, 'volume.nii.gz', 'dummy-nifti');

    $this->drupalGet('/std/get-medical-image-files/' . $this->encodeStudyUri($studyUri) . '/1/10');
    $this->assertSession()->statusCodeEquals(200);

    $payload = Json::decode($this->getSession()->getPage()->getContent());
    $this->assertIsArray($payload);
    $this->assertArrayHasKey('files', $payload);
    $this->assertCount(2, $payload['files']);

    $byFilename = [];
    foreach ($payload['files'] as $fileRow) {
      if (is_array($fileRow) && isset($fileRow['filename'])) {
        $byFilename[(string) $fileRow['filename']] = $fileRow;
      }
    }

    $this->assertArrayHasKey('scan.dcm', $byFilename);
    $this->assertArrayHasKey('volume.nii.gz', $byFilename);

    $this->assertTrue((bool) ($byFilename['scan.dcm']['can_visualize'] ?? FALSE));
    $this->assertFalse((bool) ($byFilename['volume.nii.gz']['can_visualize'] ?? TRUE));

    $this->assertNotEmpty($byFilename['scan.dcm']['viewer_url'] ?? '');
    $this->assertStringContainsString('/std/medical-viewer/', (string) ($byFilename['scan.dcm']['viewer_url'] ?? ''));

    $viewPath = (string) (parse_url((string) ($byFilename['scan.dcm']['view_url'] ?? ''), PHP_URL_PATH) ?? '');
    $viewerPath = (string) (parse_url((string) ($byFilename['scan.dcm']['viewer_url'] ?? ''), PHP_URL_PATH) ?? '');
    $this->assertNotSame('', $viewPath);
    $this->assertNotSame('', $viewerPath);

    $this->drupalGet($viewPath);
    $this->assertSession()->statusCodeEquals(200);

    $this->drupalGet($viewerPath);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '#std-medical-viewer-frame-controls');
    $this->assertSession()->elementExists('css', '#std-medical-viewer-frame-label');
  }

  public function testMedicalViewerFallbackAndTokenValidation(): void {
    $studyUri = 'http://example.org/study/beta';
    $studyKey = 'beta';
    $filename = 'volume.nii.gz';

    $this->createMedicalFile($studyKey, $filename, 'dummy-nifti');

    $viewerPath = '/std/medical-viewer/' . rawurlencode($filename) . '/' . $this->encodeStudyUri($studyUri) . '/' . $this->buildToken($filename);
    $this->drupalGet($viewerPath);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Medical Image Viewer');
    $this->assertSession()->pageTextContains('Preview unavailable for this file type.');
    $this->assertSession()->pageTextContains('Open Original File');
    $this->assertSession()->pageTextContains('Download');

    $invalidTokenPath = '/std/medical-viewer/' . rawurlencode($filename) . '/' . $this->encodeStudyUri($studyUri) . '/invalid-token';
    $this->drupalGet($invalidTokenPath);
    $this->assertSession()->statusCodeEquals(403);
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
