<?php

declare(strict_types=1);

namespace Drupal\std\Support;

/**
 * Helper for study search relevance scoring and ordering.
 */
final class StudySearchRanking {

  private const DEFAULT_WEIGHTS = [
    'match' => 0.5,
    'type' => 0.2,
    'completeness' => 0.3,
  ];

  public static function defaultWeights(): array {
    return self::DEFAULT_WEIGHTS;
  }

  public static function score(
    int $matchedCount,
    int $selectedCount,
    int $matchedSourceCount,
    int $selectedSourceCount,
    float $completenessScore,
    ?array $weights = NULL
  ): float {
    $w = self::normalizeWeights($weights ?? self::DEFAULT_WEIGHTS);

    $safeSelectedCount = max(0, $selectedCount);
    $safeMatchedCount = max(0, min($matchedCount, $safeSelectedCount));

    $safeSelectedSourceCount = max(0, $selectedSourceCount);
    $safeMatchedSourceCount = max(0, min($matchedSourceCount, $safeSelectedSourceCount));

    $matchRatio = $safeSelectedCount > 0 ? ($safeMatchedCount / $safeSelectedCount) : 0.0;
    $typeCoverage = $safeSelectedSourceCount > 0 ? ($safeMatchedSourceCount / $safeSelectedSourceCount) : 0.0;
    $safeCompleteness = max(0.0, min(1.0, $completenessScore));

    $score = ($matchRatio * $w['match'])
      + ($typeCoverage * $w['type'])
      + ($safeCompleteness * $w['completeness']);

    return round($score, 6);
  }

  /**
   * Sort callback for ranked cards.
   */
  public static function compareCards(array $a, array $b): int {
    $scoreA = (float) ($a['rank_score'] ?? 0.0);
    $scoreB = (float) ($b['rank_score'] ?? 0.0);
    if ($scoreA !== $scoreB) {
      return ($scoreA < $scoreB) ? 1 : -1;
    }

    $matchedA = (int) ($a['matched_count'] ?? 0);
    $matchedB = (int) ($b['matched_count'] ?? 0);
    if ($matchedA !== $matchedB) {
      return ($matchedA < $matchedB) ? 1 : -1;
    }

    $labelA = (string) ($a['label'] ?? '');
    $labelB = (string) ($b['label'] ?? '');
    return strcasecmp($labelA, $labelB);
  }

  private static function normalizeWeights(array $weights): array {
    $merged = [
      'match' => (float) ($weights['match'] ?? self::DEFAULT_WEIGHTS['match']),
      'type' => (float) ($weights['type'] ?? self::DEFAULT_WEIGHTS['type']),
      'completeness' => (float) ($weights['completeness'] ?? self::DEFAULT_WEIGHTS['completeness']),
    ];

    $total = $merged['match'] + $merged['type'] + $merged['completeness'];
    if ($total <= 0.0) {
      return self::DEFAULT_WEIGHTS;
    }

    return [
      'match' => $merged['match'] / $total,
      'type' => $merged['type'] / $total,
      'completeness' => $merged['completeness'] / $total,
    ];
  }

}
