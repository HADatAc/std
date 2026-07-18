# Study Search Cache Management

## Overview

The Study Search page uses a **persistent cache backend** that automatically survives `drush cr` and standard cache clear operations. This ensures optimal performance while allowing selective cache invalidation when needed.

## Persistent Cache Architecture

### Custom Cache Backend
- **Backend class**: `Drupal\std\Cache\PersistentDatabaseBackend`
- **Factory**: `Drupal\std\Cache\PersistentCacheBackendFactory`
- **Cache bin**: `cache.std_study_search`
- **Database table**: `cache_std_study_search`

### Key Features
✅ **Automatic persistence**: Survives `drush cr` by default  
✅ **Selective invalidation**: Cache tags allow per-study invalidation  
✅ **Performance**: Expensive API calls cached permanently  
✅ **Manual control**: Explicit clear method when needed

## How It Works

### 1. Standard Cache Clears (`drush cr`)
```bash
drush cr
```
**Result**: Study search cache **persists** (NOT cleared)

### 2. Selective Invalidation (Automatic)
The cache is automatically invalidated when:
- A Study is updated (EditStudyForm)
- A ProcessBasedStudy is updated (EditProcessBasedStudyForm)
- A StudyObjectCollection is added (AddStudyObjectCollectionForm)
- A StudyObjectCollection is updated (EditStudyObjectCollectionForm)

**Result**: Only affected study's cache is cleared via cache tags

### 3. Complete Cache Clear (Manual)
Use this for major data imports, structural changes, or troubleshooting.

#### Via Drupal UI:
Navigate to: **Configuration > Development > Performance > Clear Study Search Persistent Cache**  
URL: `/admin/std/clear-persistent-cache`

#### Via Drush:
```bash
drush eval "\Drupal\std\Service\StudyVariableSearchService::clearAllCache();"
```

#### Via SQL (Emergency):
```bash
drush sql-query "TRUNCATE cache_std_study_search;"
```

## API Methods

### Selective Invalidation (Preferred)
```php
use Drupal\std\Service\StudyVariableSearchService;

// Invalidate specific study
StudyVariableSearchService::invalidateCache('https://pmsr.net/ont/STD-001');

// Invalidate all studies (by tag)
StudyVariableSearchService::invalidateCache();
```

### Complete Clear (Use Sparingly)
```php
use Drupal\std\Service\StudyVariableSearchService;

// Clear ALL cached data (bypasses persistence)
StudyVariableSearchService::clearAllCache();
```

## Cache Storage Details

### Cache Key Format
- **Pattern**: `std_study_soc_vars:<md5(studyUri)>`
- **Example**: `std_study_soc_vars:abc123def456...`

### Cache Tags
- **Global tag**: `std_study_soc_vars` (all study caches)
- **Per-study tag**: `std_study:<md5(studyUri)>` (individual study)

### Cache Lifetime
- **Duration**: `CACHE_PERMANENT` (never expires)
- **Invalidation**: Only via tags or explicit clear
- **Size**: ~1-5 KB per study

## Performance Characteristics

| Scenario | First Load | Cached Load | Cache Size (1000 studies) |
|----------|------------|-------------|---------------------------|
| Study Search | 5-10 sec | < 1 sec | ~1-5 MB |
| After `drush cr` | N/A (cache persists) | < 1 sec | No change |
| After invalidation | 5-10 sec | < 1 sec | Rebuilt on demand |

## When to Use Each Method

### ✅ Automatic Invalidation (Default)
**Use for**: Normal operations  
**Triggers**: Form saves, data updates  
**Action**: None required (automatic)

### ✅ Selective Invalidation
**Use for**: 
- Programmatic data updates outside forms
- API-based data changes
- Custom migrations

**Command**:
```php
StudyVariableSearchService::invalidateCache($studyUri);
```

### ⚠️ Complete Clear
**Use for**:
- Major data imports/migrations
- Structural schema changes
- Cache corruption troubleshooting
- Testing cache rebuild performance

**Command**:
```php
StudyVariableSearchService::clearAllCache();
```
Or use the Drupal UI at `/admin/std/clear-persistent-cache`

## Troubleshooting

### Study Search shows stale data after editing
**Likely cause**: Data modified outside Drupal forms  
**Solution**: Manually invalidate that study's cache
```php
StudyVariableSearchService::invalidateCache($studyUri);
```

### All studies show stale data
**Likely cause**: Bulk import or migration  
**Solution**: Clear all cached data
```bash
drush eval "\Drupal\std\Service\StudyVariableSearchService::clearAllCache();"
```

### Cache not persisting after drush cr
**Likely cause**: Service configuration issue  
**Check**: 
1. Verify `cache.backend.std_persistent` service exists in `std.services.yml`
2. Verify `PersistentDatabaseBackend` class exists
3. Run `drush cr` to reload service definitions

### Cache grows too large
**Solution**: Implement cache expiration or size limits  
**Current size**: Check with:
```bash
drush sql-query "SELECT COUNT(*), SUM(LENGTH(data)) FROM cache_std_study_search;"
```

## Development Notes

### Testing Persistence
```bash
# 1. Load a study search page (creates cache)
# 2. Run cache clear
drush cr
# 3. Reload study search page (should still be fast = cache persisted)
# 4. Manually clear persistent cache
drush eval "\Drupal\std\Service\StudyVariableSearchService::clearAllCache();"
# 5. Reload study search page (should be slow = cache cleared, rebuilding)
```

### Monitoring Cache
```bash
# Count cached studies
drush sql-query "SELECT COUNT(*) FROM cache_std_study_search;"

# View cache keys
drush sql-query "SELECT cid FROM cache_std_study_search LIMIT 10;"

# Check cache size
drush sql-query "SELECT pg_size_pretty(pg_total_relation_size('cache_std_study_search'));" # PostgreSQL
drush sql-query "SELECT CONCAT(ROUND(SUM(data_length + index_length) / 1024 / 1024, 2), ' MB') FROM information_schema.TABLES WHERE table_name = 'cache_std_study_search';" # MySQL
```

## Architecture Benefits

1. **Performance**: Expensive API calls cached indefinitely
2. **Resilience**: Cache survives system maintenance
3. **Flexibility**: Selective invalidation when needed
4. **Control**: Manual clear for special cases
5. **Transparency**: Explicit methods vs. hidden behavior
