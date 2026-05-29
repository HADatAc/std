<?php

declare(strict_types=1);

namespace Drupal\Tests\std\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\user\UserInterface;

/**
 * Validates Study Search interactive behavior.
 *
 * @group std
 */
final class StudySearchBehaviorTest extends WebDriverTestBase {

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

    $account = $this->createUserWithEmail(['access study search'], 'owner1@example.com');
    $this->drupalLogin($account);
  }

  public function testAndOrChipsAndRanking(): void {
    $this->drupalGet('/std/search/studies');
    $this->assertSession()->pageTextContains('Study Search');

    $this->checkVariable('heart-rate');
    $this->waitForVisibleResults('3');

    $titles = $this->getVisibleCardTitles();
    $this->assertNotEmpty($titles);
    $this->assertSame('Study Alpha', $titles[0]);

    $this->checkVariable('blood-pressure');
    $this->waitForChipCount(2);
    $this->waitForVisibleResults('4');

    $this->assertSession()->elementExists('css', 'input[name="std-search-logic"][value="and"]');
    $this->getSession()->executeScript("const el = document.querySelector('input[name=\"std-search-logic\"][value=\"and\"]'); if (el) { el.click(); }");
    $this->waitForVisibleResults('1');

    $removeChip = $this->assertSession()->elementExists('css', '#std-selected-preview .std-chip-remove[data-target="variable"][data-value="blood-pressure"]');
    $removeChip->click();

    $this->waitForVisibleResults('3');
    $this->waitForChipCount(1);

    $removedFilter = $this->assertSession()->elementExists('css', '.study-variable-checkbox[value="blood-pressure"]');
    $this->assertFalse($removedFilter->isChecked());
  }

  private function checkVariable(string $value): void {
    $checkbox = $this->assertSession()->elementExists('css', '.study-variable-checkbox[value="' . $value . '"]');
    $checkbox->setValue(TRUE);
  }

  private function waitForVisibleResults(string $expectedCount): void {
    $condition = "document.querySelector('#std-visible-results') && document.querySelector('#std-visible-results').textContent.trim() === '" . $expectedCount . "'";
    $this->assertTrue((bool) $this->getSession()->wait(5000, $condition), 'Timed out waiting for visible results count ' . $expectedCount . '.');
  }

  private function waitForChipCount(int $expectedCount): void {
    $condition = "document.querySelectorAll('#std-selected-preview .std-selected-chip').length === " . $expectedCount;
    $this->assertTrue((bool) $this->getSession()->wait(5000, $condition), 'Timed out waiting for selected chip count ' . $expectedCount . '.');
  }

  private function getVisibleCardTitles(): array {
    $titles = [];

    $cards = $this->getSession()->getPage()->findAll('css', '.std-study-card');
    foreach ($cards as $card) {
      $style = (string) ($card->getAttribute('style') ?? '');
      if (str_contains($style, 'display: none')) {
        continue;
      }

      $heading = $card->find('css', 'h4');
      if ($heading) {
        $titles[] = trim($heading->getText());
      }
    }

    return $titles;
  }

  private function createUserWithEmail(array $permissions, string $email): UserInterface {
    $account = $this->createUser($permissions);
    $account->setEmail($email);
    $account->save();
    return $account;
  }

}
