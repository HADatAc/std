<?php

declare(strict_types=1);

namespace Drupal\std\Support;

/**
 * Resolves upload target folders from file names/extensions.
 */
final class StudyFileTypeResolver {

  private const FOLDER_BY_EXTENSION = [
    'csv' => 'da',
    'xlsx' => 'da',
    'pdf' => 'Publications',
    'doc' => 'Publications',
    'docx' => 'Publications',
    'jpg' => 'media',
    'jpeg' => 'media',
    'png' => 'media',
    'gif' => 'media',
    'mov' => 'media',
    'avi' => 'media',
    'mpeg' => 'media',
    'mp4' => 'media',
    'mp3' => 'media',
    'wav' => 'media',
    'dcm' => 'OHIF',
    'dcim' => 'OHIF',
    'dicom' => 'OHIF',
    'nii' => 'OHIF',
    'nii.gz' => 'OHIF',
  ];

  public static function normalizeExtension(string $filename): string {
    $cleanName = strtolower(trim($filename));
    if ($cleanName === '') {
      return '';
    }

    if (str_ends_with($cleanName, '.nii.gz')) {
      return 'nii.gz';
    }

    $extension = pathinfo($cleanName, PATHINFO_EXTENSION);
    return is_string($extension) ? strtolower($extension) : '';
  }

  public static function resolveStorageFolderFromFilename(string $filename): ?string {
    $extension = self::normalizeExtension($filename);
    if ($extension === '') {
      return NULL;
    }

    return self::FOLDER_BY_EXTENSION[$extension] ?? NULL;
  }

  public static function isOhifFile(string $filename): bool {
    return self::resolveStorageFolderFromFilename($filename) === 'OHIF';
  }

  public static function isExtensionAllowed(string $filename): bool {
    return self::resolveStorageFolderFromFilename($filename) !== NULL;
  }

  /**
   * @return string[]
   */
  public static function allAllowedExtensions(): array {
    return array_keys(self::FOLDER_BY_EXTENSION);
  }

}
