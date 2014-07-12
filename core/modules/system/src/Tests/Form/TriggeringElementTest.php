<?php

/**
 * @file
 * Definition of Drupal\system\Tests\Form\TriggeringElementTest.
 */

namespace Drupal\system\Tests\Form;

use Drupal\simpletest\BrowserTestBase;

/**
 * Tests that FAPI correctly determines $form_state['triggering_element'].
 *
 * @group Form
 */
class TriggeringElementTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('form_test');

  /**
   * Test the determination of $form_state['triggering_element'] when no button
   * information is included in the POST data, as is sometimes the case when
   * the ENTER key is pressed in a textfield in Internet Explorer.
   */
  function testNoButtonInfoInPost() {
    $path = 'form-test/clicked-button';
    $edit = array();
    $form_html_id = 'form-test-clicked-button';

    // Ensure submitting a form with no buttons results in no
    // $form_state['triggering_element'] and the form submit handler not
    // running.
    $this->drupalPostForm($path, $edit, NULL, array(), array(), $form_html_id);
    $this->assertText('There is no clicked button.', '$form_state[\'triggering_element\'] set to NULL.');
    $this->assertNoText('Submit handler for form_test_clicked_button executed.', 'Form submit handler did not execute.');

    // Ensure submitting a form with one or more submit buttons results in
    // $form_state['triggering_element'] being set to the first one the user has
    // access to. An argument with 'r' in it indicates a restricted
    // (#access=FALSE) button.
    $this->drupalPostForm($path . '/s', $edit, NULL, array(), array(), $form_html_id);
    $this->assertText('The clicked button is button1.', '$form_state[\'triggering_element\'] set to only button.');
    $this->assertText('Submit handler for form_test_clicked_button executed.', 'Form submit handler executed.');

    $this->drupalPostForm($path . '/s/s', $edit, NULL, array(), array(), $form_html_id);
    $this->assertText('The clicked button is button1.', '$form_state[\'triggering_element\'] set to first button.');
    $this->assertText('Submit handler for form_test_clicked_button executed.', 'Form submit handler executed.');

    $this->drupalPostForm($path . '/rs/s', $edit, NULL, array(), array(), $form_html_id);
    $this->assertText('The clicked button is button2.', '$form_state[\'triggering_element\'] set to first available button.');
    $this->assertText('Submit handler for form_test_clicked_button executed.', 'Form submit handler executed.');

    // Ensure submitting a form with buttons of different types results in
    // $form_state['triggering_element'] being set to the first button,
    // regardless of type. For the FAPI 'button' type, this should result in the
    // submit handler not executing. The types are 's'(ubmit), 'b'(utton), and
    // 'i'(mage_button).
    $this->drupalPostForm($path . '/s/b/i', $edit, NULL, array(), array(), $form_html_id);
    $this->assertText('The clicked button is button1.', '$form_state[\'triggering_element\'] set to first button.');
    $this->assertText('Submit handler for form_test_clicked_button executed.', 'Form submit handler executed.');

    $this->drupalPostForm($path . '/b/s/i', $edit, NULL, array(), array(), $form_html_id);
    $this->assertText('The clicked button is button1.', '$form_state[\'triggering_element\'] set to first button.');
    $this->assertNoText('Submit handler for form_test_clicked_button executed.', 'Form submit handler did not execute.');

    $this->drupalPostForm($path . '/i/s/b', $edit, NULL, array(), array(), $form_html_id);
    $this->assertText('The clicked button is button1.', '$form_state[\'triggering_element\'] set to first button.');
    $this->assertText('Submit handler for form_test_clicked_button executed.', 'Form submit handler executed.');
  }

  /**
   * Test that $form_state['triggering_element'] does not get set to a button
   * with #access=FALSE.
   */
  function testAttemptAccessControlBypass() {
    $path = 'form-test/clicked-button';
    $form_html_id = 'form-test-clicked-button';

    // Retrieve a form where 'button1' has #access=FALSE and 'button2' doesn't.
    $this->drupalGet($path . '/rs/s');

    // Submit the form with 'button1=button1' in the POST data, which someone
    // trying to get around security safeguards could easily do. We have to do
    // a little trickery here, to work around the safeguards in drupalPostForm(): by
    // renaming the text field that is in the form to 'button1', we can get the
    // data we want into \Drupal::request()->request.
    // @todo re-instate this somehow, but doesn't seem supported with Mink.
    $elements = $this->xpath('//form[@id="' . $form_html_id . '"]//input[@name="text"]');
    /*$elements[0]['name'] = 'button1';
    $this->drupalPostForm(NULL, array('button1' => 'button1'), NULL, array(), array(), $form_html_id);*/
    $this->fail('Not supported with mink');

    // Ensure that $form_state['triggering_element'] was not set to the
    // restricted button. Do this with both a negative and positive assertion,
    // because negative assertions alone can be brittle. See
    // testNoButtonInfoInPost() for why the triggering element gets set to
    // 'button2'.
    $this->assertNoText('The clicked button is button1.', '$form_state[\'triggering_element\'] not set to a restricted button.');
    $this->assertText('The clicked button is button2.', '$form_state[\'triggering_element\'] not set to a restricted button.');
  }
}
