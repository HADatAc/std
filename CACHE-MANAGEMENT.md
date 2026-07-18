# Study Search Cache Management

## Overview

The Study Search page uses a **persistent cache** that survives `drush cr` to improve performance. Each study's data (SOCs, variables, etc.) is cached permanently until explicitly invalidated.

## Cache Storage

- **Cache bin**: `cache.std_study_search`
- **Database table**: `cache_std_study_search`
- **Persistence**: Survives `drush cr` (cache rebuild)
- **Invalidation**: Only when study/SOC data changes

## Automatic Cache Invalidation

The cache is automatically invalidated when:
- A Study is updated (EditStudyForm)
- A ProcessBasedStudy is updated (EditProcessBasedStudyForm)
- A StudyObjectCollection is added (AddStudyObjectCollectionForm)
- A StudyObjectCollection is updated (EditStudyObjectCollectionForm)

## Manual Cache Clear (if needed)

### Clear specific study cache:
```php
\Drupal\std\Service\StudyVariableSearchService::invalidateCache('https://pmsr.net/ont/STD-001');
```

### Clear all study search caches:
```php
\Drupal\std\Service\StudyVariableSearchService::invalidateCache();
```

### Clear via Drush (SQL):
```bash
# Clear specific study (replace HASH with md5 of study URI)
drush sql-query "DELETE FROM cache_std_study_search WHERE cid = 'std_study_soc_vars:HASH';"

# Clear all study search cache
drush sql-query "TRUNCATE cache_std_study_search;"
```

### Clear via Drupal cache service:
```bash
drush eval "\Drupal::service('cache.std_study_search')->deleteAll();"
```

## Benefits

✅ **Performance**: Page loads are fast after first visit
✅ **Scalability**: Cache per study means 1000 studies = 1000 independent caches
✅ **Persistence**: Survives system restarts and cache rebuilds
✅ **Selective**: Only modified studies need cache refresh

## Cache Key Format

- **Pattern**: `std_study_soc_vars:<md5(studyUri)>`
- **Tags**: 
  - `std_study_soc_vars` (global tag)
  - `std_study:<md5(studyUri)>` (per-study tag)

## Troubleshooting

If Study Search shows stale data:

1. Check if study was modified outside Drupal forms
2. Manually invalidate cache for that study
3. If problem persists, clear entire cache bin

## Performance Impact

- **First load**: 5-10 seconds (SOC API calls)
- **Cached loads**: < 1 second
- **Cache size**: ~1-5 KB per study
- **1000 studies**: ~1-5 MB total cache size
