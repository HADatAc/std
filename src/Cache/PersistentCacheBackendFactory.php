<?php

namespace Drupal\std\Cache;

use Drupal\Core\Cache\CacheFactoryInterface;

/**
 * Factory for creating persistent cache backend instances.
 */
class PersistentCacheBackendFactory implements CacheFactoryInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The cache tags checksum provider.
   *
   * @var \Drupal\Core\Cache\CacheTagsChecksumInterface
   */
  protected $checksumProvider;

  /**
   * Constructs a PersistentCacheBackendFactory object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksum_provider
   *   The cache tags checksum provider.
   */
  public function __construct($connection, $checksum_provider) {
    $this->connection = $connection;
    $this->checksumProvider = $checksum_provider;
  }

  /**
   * {@inheritdoc}
   */
  public function get($bin) {
    return new PersistentDatabaseBackend($this->connection, $this->checksumProvider, $bin);
  }

}
