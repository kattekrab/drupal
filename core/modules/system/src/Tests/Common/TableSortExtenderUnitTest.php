<?php

/**
 * @file
 * Definition of Drupal\system\Tests\Common\TableSortExtenderTest.
 */

namespace Drupal\system\Tests\Common;

use Drupal\simpletest\UnitTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests table sorting features implemented in tablesort.inc.
 */
class TableSortExtenderUnitTest extends UnitTestBase {

  public static function getInfo() {
    return array(
      'name' => 'Tablesort',
      'description' => 'Tests table sorting.',
      'group' => 'System',
    );
  }

  /**
   * Tests tablesort_init().
   */
  function testTableSortInit() {

    // Test simple table headers.

    $headers = array('foo', 'bar', 'baz');
    // Reset $requesr->query to prevent parameters from Simpletest and Batch API
    // ending up in $ts['query'].
    $expected_ts = array(
      'name' => 'foo',
      'sql' => '',
      'sort' => 'asc',
      'query' => array(),
    );
    $request = Request::createFromGlobals();
    $request->query->replace(array());
    \Drupal::getContainer()->set('request', $request);
    $ts = tablesort_init($headers);
    $this->verbose(strtr('$ts: <pre>!ts</pre>', array('!ts' => check_plain(var_export($ts, TRUE)))));
    $this->assertEqual($ts, $expected_ts, 'Simple table headers sorted correctly.');

    // Test with simple table headers plus $_GET parameters that should _not_
    // override the default.
    $request = Request::createFromGlobals();
    $request->query->replace(array(
      // This should not override the table order because only complex
      // headers are overridable.
      'order' => 'bar',
    ));
    \Drupal::getContainer()->set('request', $request);
    $ts = tablesort_init($headers);
    $this->verbose(strtr('$ts: <pre>!ts</pre>', array('!ts' => check_plain(var_export($ts, TRUE)))));
    $this->assertEqual($ts, $expected_ts, 'Simple table headers plus non-overriding $_GET parameters sorted correctly.');

    // Test with simple table headers plus $_GET parameters that _should_
    // override the default.
    $request = Request::createFromGlobals();
    $request->query->replace(array(
      'sort' => 'DESC',
      // Add an unrelated parameter to ensure that tablesort will include
      // it in the links that it creates.
      'alpha' => 'beta',
    ));
    \Drupal::getContainer()->set('request', $request);
    $expected_ts['sort'] = 'desc';
    $expected_ts['query'] = array('alpha' => 'beta');
    $ts = tablesort_init($headers);
    $this->verbose(strtr('$ts: <pre>!ts</pre>', array('!ts' => check_plain(var_export($ts, TRUE)))));
    $this->assertEqual($ts, $expected_ts, 'Simple table headers plus $_GET parameters sorted correctly.');

    // Test complex table headers.

    $headers = array(
      'foo',
      array(
        'data' => '1',
        'field' => 'one',
        'sort' => 'asc',
        'colspan' => 1,
      ),
      array(
        'data' => '2',
        'field' => 'two',
        'sort' => 'desc',
      ),
    );
    // Reset $_GET from previous assertion.
    $request = Request::createFromGlobals();
    $request->query->replace(array(
      'order' => '2',
    ));
    \Drupal::getContainer()->set('request', $request);
    $ts = tablesort_init($headers);
    $expected_ts = array(
      'name' => '2',
      'sql' => 'two',
      'sort' => 'desc',
      'query' => array(),
    );
    $this->verbose(strtr('$ts: <pre>!ts</pre>', array('!ts' => check_plain(var_export($ts, TRUE)))));
    $this->assertEqual($ts, $expected_ts, 'Complex table headers sorted correctly.');

    // Test complex table headers plus $_GET parameters that should _not_
    // override the default.
    $request = Request::createFromGlobals();
    $request->query->replace(array(
      // This should not override the table order because this header does not
      // exist.
      'order' => 'bar',
    ));
    \Drupal::getContainer()->set('request', $request);
    $ts = tablesort_init($headers);
    $expected_ts = array(
      'name' => '1',
      'sql' => 'one',
      'sort' => 'asc',
      'query' => array(),
    );
    $this->verbose(strtr('$ts: <pre>!ts</pre>', array('!ts' => check_plain(var_export($ts, TRUE)))));
    $this->assertEqual($ts, $expected_ts, 'Complex table headers plus non-overriding $_GET parameters sorted correctly.');

    // Test complex table headers plus $_GET parameters that _should_
    // override the default.
    $request = Request::createFromGlobals();
    $request->query->replace(array(
      'order' => '1',
      'sort' => 'ASC',
      // Add an unrelated parameter to ensure that tablesort will include
      // it in the links that it creates.
      'alpha' => 'beta',
    ));
    \Drupal::getContainer()->set('request', $request);
    $expected_ts = array(
      'name' => '1',
      'sql' => 'one',
      'sort' => 'asc',
      'query' => array('alpha' => 'beta'),
    );
    $ts = tablesort_init($headers);
    $this->verbose(strtr('$ts: <pre>!ts</pre>', array('!ts' => check_plain(var_export($ts, TRUE)))));
    $this->assertEqual($ts, $expected_ts, 'Complex table headers plus $_GET parameters sorted correctly.');
  }
}
