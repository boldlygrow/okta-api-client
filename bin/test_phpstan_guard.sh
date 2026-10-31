#!/usr/bin/env sh
set -eu

# Fail on PHPStan errors we never allow, even while other PHPStan errors are allowed in CI. The rules behind these
# identifiers report non-ignorable errors, so @phpstan-ignore comments and ignoreErrors entries can't hide them.
# The phpstan CI job runs this after the full analysis, so the analysis here comes from the result cache.
# Extra arguments are passed to phpstan analyse, e.g. a path to check.
GUARDED_IDENTIFIERS="provisionr.modelPropertyHook,provisionr.modelDeclaredProperty"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

REPORT="$(mktemp)"
trap 'rm -f "$REPORT"' EXIT

echo "==> php vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=2G --error-format=json"

# PHPStan exits 1 for any reported error, and also when it can't run at all, so check the JSON report instead.
php vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=2G --no-progress --error-format=json "$@" > "$REPORT" || true

php -r '
$report = json_decode((string) file_get_contents($argv[1]), true);

// A report without totals means PHPStan never finished an analysis, so nothing was checked.
if (! is_array($report) || ! isset($report["totals"], $report["files"])) {
    fwrite(STDERR, "PHPStan did not produce a report, so the guarded rules were not checked.\n");
    exit(2);
}

// An internal error can mean a rule crashed, so its errors may be missing from the report.
foreach ($report["errors"] ?? [] as $error) {
    if (str_contains((string) $error, "Internal error")) {
        fwrite(STDERR, "PHPStan reported an internal error, so the guarded rules may not have run:\n" . $error . "\n");
        exit(2);
    }
}

$guarded = explode(",", $argv[2]);
$found = 0;

foreach ($report["files"] as $file => $result) {
    foreach ($result["messages"] as $message) {
        if (in_array($message["identifier"] ?? "", $guarded, true)) {
            echo $file, ":", $message["line"], " ", $message["message"], " [", $message["identifier"], "]", PHP_EOL;
            $found++;
        }
    }
}

if ($found > 0) {
    fwrite(STDERR, $found . " guarded PHPStan error(s) found.\n");
    exit(1);
}

echo "No guarded PHPStan errors (" . implode(", ", $guarded) . ").", PHP_EOL;
' "$REPORT" "$GUARDED_IDENTIFIERS"
