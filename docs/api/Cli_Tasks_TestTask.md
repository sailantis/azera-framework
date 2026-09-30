# Class: TestTask

**Full name:** [Azera\Cli\Tasks\TestTask](../../src/Cli/Tasks/TestTask.php)

Run the project's test suite.

Automatically detects the installed test runner (PHPUnit, Pest, or
Codeception) and delegates to it. All unknown options are passed
through to the underlying runner.

Usage:
  test                       Run all tests
  test --filter=UserTest     Run tests matching a filter
  test --group=api           Run tests in a group
  test --bootstrap           Boot AppContext before running

Options:
  --bootstrap        Boot the AppContext before running tests (useful
                     for integration tests that need DB connections)
  --runner=<path>    Path to a custom test runner binary
  --colors           Force colored output (passed through to PHPUnit)

Any other flags (e.g. --filter, --group, --verbose) are forwarded
directly to the test runner.

Exit code matches the test runner's exit code.

Examples:
  test
  test --filter=UserControllerTest
  test --group=api
  test --runner=vendor/bin/pest
  test --bootstrap --filter=DatabaseTest

## Public Properties

- `public` [Console](Cli_Console.md) `$console` · <small>[🗎](../../src/Cli/Tasks/TestTask.php)</small>
- `public` array `$options` · <small>[🗎](../../src/Cli/Tasks/TestTask.php)</small>

## Public methods

### runAction() · <small>[🗎](../../src/Cli/Tasks/TestTask.php#L50)</small>

`public function runAction(): void`

**Return value**

- Type: `void`



---

[Back to the Index ⤴](README.md)
