<?php

namespace Drupal\std\Cache;

use Drupal\Core\Cache\DatabaseBackend;

/**
 * Persistent cache backend that survives general cache clears.
 * 
 * This backend extends DatabaseBackend but overrides deleteAll() to prevent
 * the cache from being cleared during standard cache rebuild operations (drush cr).
 * Use invalidateCache() or clearPersistentCache() to explicitly clear this cache.
 */
class PersistentDatabaseBackend extends DatabaseBackend {

  /**
   * {@inheritdoc}
   * 
   * Override to prevent deleteAll during standard cache clears.
   * This makes the cache persist across 'drush cr' operations.
   */
  public function deleteAll() {
    // Do nothing - cache persists across standard cache clears
    // Use invalidateTags() or clearPersistentCache() to clear explicitly
  }

  /**
   * Explicitly clear all items in this cache bin.
   * 
   * This method should be called when you actually want to clear the cache,
   * bypassing the protection provided by the overridden deleteAll().
   */
  public function clearPersistentCache() {
    parent::deleteAll();
  }

  /**
   * Clear cache by specific tags (still works as normal).
   * 
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags) {
    parent::invalidateTags($tags);
  }

}
