<?php
// wcma-calculator/tests/RequireOnceGuardTest.php
//
// Guard against the "Cannot redeclare" class of bug (fix wave re-review, item 4 regression):
// garage-lib.php require_once's ice-sheet-lib.php, but garage.php ALSO plain-`require`d
// ice-sheet-lib.php after requiring garage-lib.php, so it got executed twice and fataled on
// every Garage page load. Tests missed it because garage.php needs config.php to run.
//
// This scans every top-level wcma-calculator/*.php file (not tests/, not phpmailer/) and
// collects the set of "X.php" targets that are require_once'd (via `__DIR__ . '/X.php'`)
// ANYWHERE in the app. It then asserts that no file anywhere includes any of those targets
// via a plain `require`/`include` of the same `__DIR__ . '/X.php'` form: once a file is
// require_once'd somewhere, every inclusion of it must be require_once (or include_once),
// so it is never double-executed no matter which entry point pulls it in first.
use PHPUnit\Framework\TestCase;

final class RequireOnceGuardTest extends TestCase
{
    /** @return array<int, string> top-level app PHP files: wcma-calculator/*.php, no tests/, no phpmailer/. */
    private function appFiles(): array {
        return glob(__DIR__ . '/../*.php');
    }

    /** @return array<string, array<int, string>> "X.php" => list of "file.php:line" that require_once/include_once it. */
    private function onceTargets(): array {
        $targets = [];
        foreach ($this->appFiles() as $file) {
            $lines = explode("\n", str_replace("\r\n", "\n", file_get_contents($file)));
            foreach ($lines as $i => $line) {
                if (preg_match('/\b(?:require_once|include_once)\s*\(?\s*__DIR__\s*\.\s*[\'"]\/([A-Za-z0-9_\-]+\.php)[\'"]/', $line, $m)) {
                    $targets[$m[1]][] = basename($file) . ':' . ($i + 1);
                }
            }
        }
        return $targets;
    }

    /** @return array<int, string> "file.php:line -> X.php" for every plain require/include of __DIR__.'/X.php'. */
    private function plainRequires(): array {
        $found = [];
        foreach ($this->appFiles() as $file) {
            $lines = explode("\n", str_replace("\r\n", "\n", file_get_contents($file)));
            foreach ($lines as $i => $line) {
                // Negative lookbehind for "_once" so this never matches require_once/include_once.
                if (preg_match('/(?<!_once)\b(?:require|include)\s*\(?\s*__DIR__\s*\.\s*[\'"]\/([A-Za-z0-9_\-]+\.php)[\'"]/', $line, $m)) {
                    $found[] = basename($file) . ':' . ($i + 1) . ' -> ' . $m[1];
                }
            }
        }
        return $found;
    }

    public function testEveryFileRequiredOnceSomewhereIsNeverAlsoPlainRequiredElsewhere(): void {
        $onceTargets = $this->onceTargets();
        $this->assertNotEmpty($onceTargets, 'sanity check: the app must require_once something');

        $violations = [];
        foreach ($this->plainRequires() as $entry) {
            [$where, $target] = explode(' -> ', $entry);
            if (isset($onceTargets[$target])) {
                $violations[] = $where . ' (require_once elsewhere at: ' . implode(', ', $onceTargets[$target]) . ')';
            }
        }
        $this->assertSame([], $violations, "Plain require/include of a file that is require_once'd elsewhere risks a "
            . "double-execution fatal (\"Cannot redeclare ...\") depending on load order:\n" . implode("\n", $violations));
    }
}
