<?php

/**
 * @file
 * Definition of Drupal\language\Tests\LanguageSwitchingTest.
 */

namespace Drupal\language\Tests;

use Drupal\Core\Language\Language;
use Drupal\simpletest\WebTestBase;

/**
 * Functional tests for the language switching feature.
 */
class LanguageSwitchingTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('language', 'block', 'language_test');

  public static function getInfo() {
    return array(
      'name' => 'Language switching',
      'description' => 'Tests for the language switching feature.',
      'group' => 'Language',
    );
  }

  function setUp() {
    parent::setUp();

    // Create and login user.
    $admin_user = $this->drupalCreateUser(array('administer blocks', 'administer languages', 'access administration pages'));
    $this->drupalLogin($admin_user);
  }

  /**
   * Functional tests for the language switcher block.
   */
  function testLanguageBlock() {
    // Enable the language switching block.
    $block = $this->drupalPlaceBlock('language_block:' . Language::TYPE_INTERFACE, array('machine_name' => 'test_language_block'));

    // Add language.
    $edit = array(
      'predefined_langcode' => 'fr',
    );
    $this->drupalPostForm('admin/config/regional/language/add', $edit, t('Add language'));

    // Enable URL language detection and selection.
    $edit = array('language_interface[enabled][language-url]' => '1');
    $this->drupalPostForm('admin/config/regional/language/detection', $edit, t('Save settings'));

    // Assert that the language switching block is displayed on the frontpage.
    $this->drupalGet('');
    $this->assertText($block->label(), 'Language switcher block found.');

    // Assert that only the current language is marked as active.
    list($language_switcher) = $this->xpath('//div[@id=:id]/div[contains(@class, "content")]', array(':id' => 'block-test-language-block'));
    $links = array(
      'active' => array(),
      'inactive' => array(),
    );
    $anchors = array(
      'active' => array(),
      'inactive' => array(),
    );
    foreach ($language_switcher->ul->li as $link) {
      $classes = explode(" ", (string) $link['class']);
      list($langcode) = array_intersect($classes, array('en', 'fr'));
      if (in_array('active', $classes)) {
        $links['active'][] = $langcode;
      }
      else {
        $links['inactive'][] = $langcode;
      }
      $anchor_classes = explode(" ", (string) $link->a['class']);
      if (in_array('active', $anchor_classes)) {
        $anchors['active'][] = $langcode;
      }
      else {
        $anchors['inactive'][] = $langcode;
      }
    }
    $this->assertIdentical($links, array('active' => array('en'), 'inactive' => array('fr')), 'Only the current language list item is marked as active on the language switcher block.');
    $this->assertIdentical($anchors, array('active' => array('en'), 'inactive' => array('fr')), 'Only the current language anchor is marked as active on the language switcher block.');
  }

  /**
   * Test active class on links when switching languages.
   */
  function testLanguageLinkActiveClass() {
    // Add language.
    $edit = array(
      'predefined_langcode' => 'fr',
    );
    $this->drupalPostForm('admin/config/regional/language/add', $edit, t('Add language'));

    // Enable URL language detection and selection.
    $edit = array('language_interface[enabled][language-url]' => '1');
    $this->drupalPostForm('admin/config/regional/language/detection', $edit, t('Save settings'));

    $function_name = '#type link';

    // Test links generated by l() on an English page.
    $current_language = 'English';
    $this->drupalGet('language_test/type-link-active-class');

    // Language code 'none' link should be active.
    $langcode = 'none';
    $links = $this->xpath('//a[@id = :id and contains(@class, :class)]', array(':id' => 'no_lang_link', ':class' => 'active'));
    $this->assertTrue(isset($links[0]), t('A link generated by :function to the current :language page with langcode :langcode is marked active.', array(':function' => $function_name, ':language' => $current_language, ':langcode' => $langcode)));

    // Language code 'en' link should be active.
    $langcode = 'en';
    $links = $this->xpath('//a[@id = :id and contains(@class, :class)]', array(':id' => 'en_link', ':class' => 'active'));
    $this->assertTrue(isset($links[0]), t('A link generated by :function to the current :language page with langcode :langcode is marked active.', array(':function' => $function_name, ':language' => $current_language, ':langcode' => $langcode)));

    // Language code 'fr' link should not be active.
    $langcode = 'fr';
    $links = $this->xpath('//a[@id = :id and not(contains(@class, :class))]', array(':id' => 'fr_link', ':class' => 'active'));
    $this->assertTrue(isset($links[0]), t('A link generated by :function to the current :language page with langcode :langcode is NOT marked active.', array(':function' => $function_name, ':language' => $current_language, ':langcode' => $langcode)));

    // Test links generated by l() on a French page.
    $current_language = 'French';
    $this->drupalGet('fr/language_test/type-link-active-class');

    // Language code 'none' link should be active.
    $langcode = 'none';
    $links = $this->xpath('//a[@id = :id and contains(@class, :class)]', array(':id' => 'no_lang_link', ':class' => 'active'));
    $this->assertTrue(isset($links[0]), t('A link generated by :function to the current :language page with langcode :langcode is marked active.', array(':function' => $function_name, ':language' => $current_language, ':langcode' => $langcode)));

    // Language code 'en' link should not be active.
    $langcode = 'en';
    $links = $this->xpath('//a[@id = :id and not(contains(@class, :class))]', array(':id' => 'en_link', ':class' => 'active'));
    $this->assertTrue(isset($links[0]), t('A link generated by :function to the current :language page with langcode :langcode is NOT marked active.', array(':function' => $function_name, ':language' => $current_language, ':langcode' => $langcode)));

    // Language code 'fr' link should be active.
    $langcode = 'fr';
    $links = $this->xpath('//a[@id = :id and contains(@class, :class)]', array(':id' => 'fr_link', ':class' => 'active'));
    $this->assertTrue(isset($links[0]), t('A link generated by :function to the current :language page with langcode :langcode is marked active.', array(':function' => $function_name, ':language' => $current_language, ':langcode' => $langcode)));
  }

}
