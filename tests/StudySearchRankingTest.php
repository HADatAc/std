<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/StudySearchRanking.php';

use Drupal\std\Support\StudySearchRanking;

function assertSameValue($expected, $actual, string $message): void {
  if ($expected !== $actual) {
    fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, TRUE) . "\nActual: " . var_export($actual, TRUE) . "\n");
    exit(1);
  }
}

function assertApprox(float $expected, float $actual, float $delta, string $message): void {
  if (abs($expected - $actual) > $delta) {
    fwrite(STDERR, "FAIL: {$message}\nExpected: {$expected}\nActual: {$actual}\n");
    exit(1);
  }
}

$weights = StudySearchRanking::defaultWeights();
assertApprox(0.5, (float) $weights['match'], 0.000001, 'Default match weight should be 0.5');
assertApprox(0.2, (float) $weights['type'], 0.000001, 'Default type weight should be 0.2');
assertApprox(0.3, (float) $weights['completeness'], 0.000001, 'Default completeness weight should be 0.3');

$scoreA = StudySearchRanking::score(3, 4, 2, 3, 0.666666, $weights);
// match=0.75*0.5 + type=0.666666*0.2 + completeness=0.666666*0.3 = 0.7083332
assertApprox(0.708333, $scoreA, 0.00001, 'Score formula should apply weighted blend correctly');

$scoreB = StudySearchRanking::score(0, 0, 0, 0, 0.5, $weights);
assertApprox(0.15, $scoreB, 0.00001, 'No selection should score only completeness contribution');

$cards = [
  [
    'label' => 'Study B',
    'rank_score' => 0.91,
    'matched_count' => 2,
  ],
  [
    'label' => 'Study A',
    'rank_score' => 0.91,
    'matched_count' => 3,
  ],
  [
    'label' => 'Study C',
    'rank_score' => 0.74,
    'matched_count' => 4,
  ],
];

usort($cards, [StudySearchRanking::class, 'compareCards']);

assertSameValue('Study A', $cards[0]['label'], 'Tie-break should prioritize matched_count when scores are equal');
assertSameValue('Study B', $cards[1]['label'], 'Second tie item should follow matched_count rule');
assertSameValue('Study C', $cards[2]['label'], 'Lower score should sort after higher score cards');

fwrite(STDOUT, "PASS: StudySearchRankingTest\n");
