<?php

/**
 * Base test case for the very simple libphutil test framework.
 *
 * @task assert       Making Test Assertions
 * @task exceptions   Exception Handling
 * @task hook         Hooks for Setup and Teardown
 * @task internal     Internals
 */
abstract class PhutilTestCase extends Phobject {

  private $assertions = 0;
  private $runningTest;
  private $testStartTime;
  private $results = array();
  private $enableCoverage;
  private $coverage = array();
  private $workingCopy;
  private $paths;
  private $renderer;


/* -(  Making Test Assertions  )--------------------------------------------- */


  /**
   * Assert that a value is `false`, strictly. The test fails if it is not.
   *
   * @param wild    The empirically derived value, generated by executing the
   *                test.
   * @param string  A human-readable description of what these values represent,
   *                and particularly of what a discrepancy means.
   *
   * @return void
   * @task assert
   */
  final protected function assertFalse($result, $message = null) {
    if ($result === false) {
      $this->assertions++;
      return;
    }

    $this->failAssertionWithExpectedValue('false', $result, $message);
  }


  /**
   * Assert that a value is `true`, strictly. The test fails if it is not.
   *
   * @param wild    The empirically derived value, generated by executing the
   *                test.
   * @param string  A human-readable description of what these values represent,
   *                and particularly of what a discrepancy means.
   *
   * @return void
   * @task assert
   */
  final protected function assertTrue($result, $message = null) {
    if ($result === true) {
      $this->assertions++;
      return;
    }

    $this->failAssertionWithExpectedValue('true', $result, $message);
  }


  /**
   * Assert that two values are equal, strictly. The test fails if they are not.
   *
   * NOTE: This method uses PHP's strict equality test operator (`===`) to
   * compare values. This means values and types must be equal, key order must
   * be identical in arrays, and objects must be referentially identical.
   *
   * @param wild    The theoretically expected value, generated by careful
   *                reasoning about the properties of the system.
   * @param wild    The empirically derived value, generated by executing the
   *                test.
   * @param string  A human-readable description of what these values represent,
   *                and particularly of what a discrepancy means.
   *
   * @return void
   * @task assert
   */
  final protected function assertEqual($expect, $result, $message = null) {
    if ($expect === $result) {
      $this->assertions++;
      return;
    }

    $expect = PhutilReadableSerializer::printableValue($expect);
    $result = PhutilReadableSerializer::printableValue($result);
    $caller = self::getCallerInfo();
    $file = $caller['file'];
    $line = $caller['line'];

    if ($message !== null) {
      $output = pht(
        'Assertion failed, expected values to be equal (at %s:%d): %s',
        $file,
        $line,
        $message);
    } else {
      $output = pht(
        'Assertion failed, expected values to be equal (at %s:%d).',
        $file,
        $line);
    }

    $output .= "\n";

    if (strpos($expect, "\n") === false && strpos($result, "\n") === false) {
      $output .= pht("Expected: %s\n  Actual: %s", $expect, $result);
    } else {
      $output .= pht(
        "Expected vs Actual Output Diff\n%s",
        ArcanistDiffUtils::renderDifferences(
          $expect,
          $result,
          $lines = 0xFFFF));
    }

    $this->failTest($output);
    throw new PhutilTestTerminatedException($output);
  }


  /**
   * Assert an unconditional failure. This is just a convenience method that
   * better indicates intent than using dummy values with assertEqual(). This
   * causes test failure.
   *
   * @param   string  Human-readable description of the reason for test failure.
   * @return  void
   * @task    assert
   */
  final protected function assertFailure($message) {
    $this->failTest($message);
    throw new PhutilTestTerminatedException($message);
  }

  /**
   * End this test by asserting that the test should be skipped for some
   * reason.
   *
   * @param   string  Reason for skipping this test.
   * @return  void
   * @task    assert
   */
  final protected function assertSkipped($message) {
    $this->skipTest($message);
    throw new PhutilTestSkippedException($message);
  }


/* -(  Exception Handling  )------------------------------------------------- */


  /**
   * This simplest way to assert exceptions are thrown.
   *
   * @param exception   The expected exception.
   * @param callable    The thing which throws the exception.
   *
   * @return void
   * @task exceptions
   */
  final protected function assertException(
    $expected_exception_class,
    $callable) {

    $this->tryTestCases(
      array('assertException' => array()),
      array(false),
      $callable,
      $expected_exception_class);
  }

  /**
   * Straightforward method for writing unit tests which check if some block of
   * code throws an exception. For example, this allows you to test the
   * exception behavior of ##is_a_fruit()## on various inputs:
   *
   *    public function testFruit() {
   *      $this->tryTestCases(
   *        array(
   *          'apple is a fruit'    => new Apple(),
   *          'rock is not a fruit' => new Rock(),
   *        ),
   *        array(
   *          true,
   *          false,
   *        ),
   *        array($this, 'tryIsAFruit'),
   *        'NotAFruitException');
   *    }
   *
   *    protected function tryIsAFruit($input) {
   *      is_a_fruit($input);
   *    }
   *
   * @param map       Map of test case labels to test case inputs.
   * @param list      List of expected results, true to indicate that the case
   *                  is expected to succeed and false to indicate that the case
   *                  is expected to throw.
   * @param callable  Callback to invoke for each test case.
   * @param string    Optional exception class to catch, defaults to
   *                  'Exception'.
   * @return void
   * @task exceptions
   */
  final protected function tryTestCases(
    array $inputs,
    array $expect,
    $callable,
    $exception_class = 'Exception') {

    if (count($inputs) !== count($expect)) {
      $this->assertFailure(
        pht('Input and expectations must have the same number of values.'));
    }

    $labels = array_keys($inputs);
    $inputs = array_values($inputs);
    $expecting = array_values($expect);
    foreach ($inputs as $idx => $input) {
      $expect = $expecting[$idx];
      $label  = $labels[$idx];

      $caught = null;
      try {
        call_user_func($callable, $input);
      } catch (Exception $ex) {
        if ($ex instanceof PhutilTestTerminatedException) {
          throw $ex;
        }
        if (!($ex instanceof $exception_class)) {
          throw $ex;
        }
        $caught = $ex;
      }

      $actual = !($caught instanceof Exception);

      if ($expect === $actual) {
        if ($expect) {
          $message = pht("Test case '%s' did not throw, as expected.", $label);
        } else {
          $message = pht("Test case '%s' threw, as expected.", $label);
        }
      } else {
        if ($expect) {
          $message = pht(
            "Test case '%s' was expected to succeed, but it ".
            "raised an exception of class %s with message: %s",
            $label,
            get_class($ex),
            $ex->getMessage());
        } else {
          $message = pht(
            "Test case '%s' was expected to raise an ".
            "exception, but it did not throw anything.",
            $label);
        }
      }

      $this->assertEqual($expect, $actual, $message);
    }
  }


  /**
   * Convenience wrapper around @{method:tryTestCases} for cases where your
   * inputs are scalar. For example:
   *
   *    public function testFruit() {
   *      $this->tryTestCaseMap(
   *        array(
   *          'apple' => true,
   *          'rock'  => false,
   *        ),
   *        array($this, 'tryIsAFruit'),
   *        'NotAFruitException');
   *    }
   *
   *    protected function tryIsAFruit($input) {
   *      is_a_fruit($input);
   *    }
   *
   * For cases where your inputs are not scalar, use @{method:tryTestCases}.
   *
   * @param map       Map of scalar test inputs to expected success (true
   *                  expects success, false expects an exception).
   * @param callable  Callback to invoke for each test case.
   * @param string    Optional exception class to catch, defaults to
   *                  'Exception'.
   * @return void
   * @task exceptions
   */
  final protected function tryTestCaseMap(
    array $map,
    $callable,
    $exception_class = 'Exception') {

    return $this->tryTestCases(
      array_fuse(array_keys($map)),
      array_values($map),
      $callable,
      $exception_class);
  }


/* -(  Hooks for Setup and Teardown  )--------------------------------------- */


  /**
   * This hook is invoked once, before any tests in this class are run. It
   * gives you an opportunity to perform setup steps for the entire class.
   *
   * @return void
   * @task hook
   */
  protected function willRunTests() {
    return;
  }


  /**
   * This hook is invoked once, after any tests in this class are run. It gives
   * you an opportunity to perform teardown steps for the entire class.
   *
   * @return void
   * @task hook
   */
  protected function didRunTests() {
    return;
  }


  /**
   * This hook is invoked once per test, before the test method is invoked.
   *
   * @param string Method name of the test which will be invoked.
   * @return void
   * @task hook
   */
  protected function willRunOneTest($test_method_name) {
    return;
  }


  /**
   * This hook is invoked once per test, after the test method is invoked.
   *
   * @param string Method name of the test which was invoked.
   * @return void
   * @task hook
   */
  protected function didRunOneTest($test_method_name) {
    return;
  }


  /**
   * This hook is invoked once, before any test cases execute. It gives you
   * an opportunity to perform setup steps for the entire suite of test cases.
   *
   * @param list<PhutilTestCase> List of test cases to be run.
   * @return void
   * @task hook
   */
  public function willRunTestCases(array $test_cases) {
    return;
  }


  /**
   * This hook is invoked once, after all test cases execute.
   *
   * @param list<PhutilTestCase> List of test cases that ran.
   * @return void
   * @task hook
   */
  public function didRunTestCases(array $test_cases) {
    return;
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * Construct a new test case. This method is ##final##, use willRunTests() to
   * provide test-wide setup logic.
   *
   * @task internal
   */
  final public function __construct() {}


  /**
   * Mark the currently-running test as a failure.
   *
   * @param string  Human-readable description of problems.
   * @return void
   *
   * @task internal
   */
  private function failTest($reason) {
    $this->resultTest(ArcanistUnitTestResult::RESULT_FAIL, $reason);
  }


  /**
   * This was a triumph. I'm making a note here: HUGE SUCCESS.
   *
   * @param string  Human-readable overstatement of satisfaction.
   * @return void
   *
   * @task internal
   */
  private function passTest($reason) {
    $this->resultTest(ArcanistUnitTestResult::RESULT_PASS, $reason);
  }


  /**
   * Mark the current running test as skipped.
   *
   * @param string  Description for why this test was skipped.
   * @return void
   * @task internal
   */
  private function skipTest($reason) {
    $this->resultTest(ArcanistUnitTestResult::RESULT_SKIP, $reason);
  }


  private function resultTest($test_result, $reason) {
    $coverage = $this->endCoverage();

    $result = new ArcanistUnitTestResult();
    $result->setCoverage($coverage);
    $result->setNamespace(get_class($this));
    $result->setName($this->runningTest);
    $result->setLink($this->getLink($this->runningTest));
    $result->setResult($test_result);
    $result->setDuration(microtime(true) - $this->testStartTime);
    $result->setUserData($reason);
    $this->results[] = $result;

    if ($this->renderer) {
      echo $this->renderer->renderUnitResult($result);
    }
  }


  /**
   * Execute the tests in this test case. You should not call this directly;
   * use @{class:PhutilUnitTestEngine} to orchestrate test execution.
   *
   * @return void
   * @task internal
   */
  final public function run() {
    $this->results = array();

    $reflection = new ReflectionClass($this);
    $methods = $reflection->getMethods();

    // Try to ensure that poorly-written tests which depend on execution order
    // (and are thus not properly isolated) will fail.
    shuffle($methods);

    $this->willRunTests();
    foreach ($methods as $method) {
      $name = $method->getName();
      if (preg_match('/^test/', $name)) {
        $this->runningTest = $name;
        $this->assertions = 0;
        $this->testStartTime = microtime(true);

        try {
          $this->willRunOneTest($name);

          $this->beginCoverage();
          $exceptions = array();
          try {
            call_user_func_array(
              array($this, $name),
              array());
            $this->passTest(
              pht(
                '%s assertion(s) passed.',
                new PhutilNumber($this->assertions)));
          } catch (Exception $ex) {
            $exceptions['Execution'] = $ex;
          }

          try {
            $this->didRunOneTest($name);
          } catch (Exception $ex) {
            $exceptions['Shutdown'] = $ex;
          }

          if ($exceptions) {
            if (count($exceptions) == 1) {
              throw head($exceptions);
            } else {
              throw new PhutilAggregateException(
                pht('Multiple exceptions were raised during test execution.'),
                $exceptions);
            }
          }

          if (!$this->assertions) {
            $this->failTest(
              pht(
                'This test case made no assertions. Test cases must make at '.
                'least one assertion.'));
          }

        } catch (PhutilTestTerminatedException $ex) {
          // Continue with the next test.
        } catch (PhutilTestSkippedException $ex) {
          // Continue with the next test.
        } catch (Exception $ex) {
          $ex_class = get_class($ex);
          $ex_message = $ex->getMessage();
          $ex_trace = $ex->getTraceAsString();
          $message = sprintf(
            "%s (%s): %s\n%s",
            pht('EXCEPTION'),
            $ex_class,
            $ex_message,
            $ex_trace);
          $this->failTest($message);
        }
      }
    }
    $this->didRunTests();

    return $this->results;
  }

  final public function setEnableCoverage($enable_coverage) {
    $this->enableCoverage = $enable_coverage;
    return $this;
  }

  /**
   * @phutil-external-symbol function xdebug_start_code_coverage
   */
  private function beginCoverage() {
    if (!$this->enableCoverage) {
      return;
    }

    $this->assertCoverageAvailable();
    xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
  }

  /**
   * @phutil-external-symbol function xdebug_get_code_coverage
   * @phutil-external-symbol function xdebug_stop_code_coverage
   */
  private function endCoverage() {
    if (!$this->enableCoverage) {
      return;
    }

    $result = xdebug_get_code_coverage();
    xdebug_stop_code_coverage($cleanup = false);

    $coverage = array();

    foreach ($result as $file => $report) {
      $project_root = $this->getProjectRoot();

      if (strncmp($file, $project_root, strlen($project_root))) {
        continue;
      }

      $max = max(array_keys($report));
      $str = '';
      for ($ii = 1; $ii <= $max; $ii++) {
        $c = null;
        if (isset($report[$ii])) {
          $c = $report[$ii];
        }
        if ($c === -1) {
          $str .= 'U'; // Un-covered.
        } else if ($c === -2) {
          // TODO: This indicates "unreachable", but it flags the closing braces
          // of functions which end in "return", which is super ridiculous. Just
          // ignore it for now.
          //
          // See http://bugs.xdebug.org/view.php?id=1041
          $str .= 'N'; // Not executable.
        } else if ($c === 1) {
          $str .= 'C'; // Covered.
        } else {
          $str .= 'N'; // Not executable.
        }
      }
      $coverage[substr($file, strlen($project_root) + 1)] = $str;
    }

    // Only keep coverage information for files modified by the change. In
    // the case of --everything, we won't have paths, so just return all the
    // coverage data.
    if ($this->paths) {
      $coverage = array_select_keys($coverage, $this->paths);
    }

    return $coverage;
  }

  private function assertCoverageAvailable() {
    if (!function_exists('xdebug_start_code_coverage')) {
      throw new Exception(
        pht("You've enabled code coverage but XDebug is not installed."));
    }
  }

  final public function getWorkingCopy() {
    return $this->workingCopy;
  }

  final public function setWorkingCopy(
    ArcanistWorkingCopyIdentity $working_copy) {

    $this->workingCopy = $working_copy;
    return $this;
  }

  final public function getProjectRoot() {
    $working_copy = $this->getWorkingCopy();

    if (!$working_copy) {
      throw new PhutilInvalidStateException('setWorkingCopy');
    }

    return $working_copy->getProjectRoot();
  }

  final public function setPaths(array $paths) {
    $this->paths = $paths;
    return $this;
  }

  final protected function getLink($method) {
    $base_uri = $this
      ->getWorkingCopy()
      ->getProjectConfig('phabricator.uri');

    $uri = id(new PhutilURI($base_uri))
      ->setPath("/diffusion/symbol/{$method}/")
      ->setQueryParam('context', get_class($this))
      ->setQueryParam('jump', 'true')
      ->setQueryParam('lang', 'php');

    return (string)$uri;
  }

  final public function setRenderer(ArcanistUnitRenderer $renderer) {
    $this->renderer = $renderer;
    return $this;
  }

  /**
   * Returns info about the caller function.
   *
   * @return map
   */
  private static function getCallerInfo() {
    $callee = array();
    $caller = array();
    $seen = false;

    foreach (array_slice(debug_backtrace(), 1) as $location) {
      $function = idx($location, 'function');

      if (!$seen && preg_match('/^assert[A-Z]/', $function)) {
        $seen = true;
        $caller = $location;
      } else if ($seen && !preg_match('/^assert[A-Z]/', $function)) {
        $callee = $location;
        break;
      }
    }

    return array(
      'file' => basename(idx($caller, 'file')),
      'line' => idx($caller, 'line'),
      'function' => idx($callee, 'function'),
      'class' => idx($callee, 'class'),
      'object' => idx($caller, 'object'),
      'type' => idx($callee, 'type'),
      'args' => idx($caller, 'args'),
    );
  }


  /**
   * Fail an assertion which checks that some result is equal to a specific
   * value, like 'true' or 'false'. This prints a readable error message and
   * fails the current test.
   *
   * This method throws and does not return.
   *
   * @param   string      Human readable description of the expected value.
   * @param   string      The actual value.
   * @param   string|null Optional assertion message.
   * @return  void
   * @task    internal
   */
  private function failAssertionWithExpectedValue(
    $expect_description,
    $actual_result,
    $message) {

    $caller = self::getCallerInfo();
    $file = $caller['file'];
    $line = $caller['line'];

    if ($message !== null) {
      $description = pht(
        "Assertion failed, expected '%s' (at %s:%d): %s",
        $expect_description,
        $file,
        $line,
        $message);
    } else {
      $description = pht(
        "Assertion failed, expected '%s' (at %s:%d).",
        $expect_description,
        $file,
        $line);
    }

    $actual_result = PhutilReadableSerializer::printableValue($actual_result);
    $header = pht('ACTUAL VALUE');
    $output = $description."\n\n".$header."\n".$actual_result;

    $this->failTest($output);
    throw new PhutilTestTerminatedException($output);
  }

}
