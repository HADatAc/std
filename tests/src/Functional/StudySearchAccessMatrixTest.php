<?php

declare(strict_types=1);

namespace Drupal\Tests\std\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Validates Study Search access and visibility matrix.
 *
 * @group std
 */
final class StudySearchAccessMatrixTest extends BrowserTestBase {

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

  public function testSearchAccessMatrix(): void {
    $this->drupalGet('/std/search/studies');
    $this->assertSession()->statusCodeEquals(403);

    $noPermissionUser = $this->createUserWithEmail([], 'noperm@example.com');
    $this->drupalLogin($noPermissionUser);
    $this->drupalGet('/std/search/studies');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    $ownerUser = $this->createUserWithEmail(['access study search'], 'owner1@example.com');
    $this->drupalLogin($ownerUser);
    $this->drupalGet('/std/search/studies');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Study Alpha');
    $this->assertSession()->pageTextContains('Study Beta');
    $this->assertSession()->pageTextContains('Study Gamma');
    $this->assertSession()->pageTextContains('Study Delta');
    $this->assertSession()->pageTextNotContains('Study Hidden');
    $this->drupalLogout();

    $viewerUser = $this->createUserWithEmail(['access study search'], 'viewer@example.com');
    $this->drupalLogin($viewerUser);
    $this->drupalGet('/std/search/studies');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Study Alpha');
    $this->assertSession()->pageTextContains('Study Gamma');
    $this->assertSession()->pageTextContains('Study Delta');
    $this->assertSession()->pageTextNotContains('Study Beta');
    $this->assertSession()->pageTextNotContains('Study Hidden');
    $this->drupalLogout();

    $searchAdmin = $this->createUserWithEmail(['administer study search'], 'searchadmin@example.com');
    $this->drupalLogin($searchAdmin);
    $this->drupalGet('/std/search/studies');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Study Alpha');
    $this->assertSession()->pageTextContains('Study Beta');
    $this->assertSession()->pageTextContains('Study Gamma');
    $this->assertSession()->pageTextContains('Study Delta');
    $this->assertSession()->pageTextContains('Study Hidden');
  }

  private function createUserWithEmail(array $permissions, string $email): UserInterface {
    $account = $this->createUser($permissions);
    $account->setEmail($email);
    $account->save();
    return $account;
  }

}
