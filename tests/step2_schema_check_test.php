<?php
declare(strict_types=1);

// Tests Step 2 schema discovery without MySQL or information_schema access.
// A fake DB connection is sufficient because this module uses fixed, read-only
// SELECT ... LIMIT 0 statements to verify the three Step 2 tables.
final class Step2FakeConnection
{
    public array $queries = [];
    public ?string $unavailableTable = null;

    public function query(string $sql): bool
    {
        $this->queries[] = $sql;
        if (str_contains(strtolower($sql), 'information_schema')) {
            throw new RuntimeException('Restricted information_schema was accessed.');
        }
        if ($this->unavailableTable !== null
            && str_contains($sql, '`' . $this->unavailableTable . '`')) {
            throw new RuntimeException('Missing table or SELECT privilege.');
        }
        return true;
    }
}

$step2FakeDb = new Step2FakeConnection();
function db(): Step2FakeConnection
{
    global $step2FakeDb;
    return $step2FakeDb;
}

require_once __DIR__ . '/../app/permit_step2.php';

function step2_check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Step 2 schema test failed: ' . $message);
}

step2_check(step2_schema_ready(), 'All three available tables should pass.');
$tables = ['permit_step2_applications','permit_step2_documents','permit_step2_events'];
step2_check(count($step2FakeDb->queries) === 3, 'Exactly three table checks should run.');
foreach ($tables as $index => $table) {
    step2_check(
        $step2FakeDb->queries[$index] === 'SELECT 1 FROM `' . $table . '` LIMIT 0',
        'A read-only check must query the correct table with LIMIT 0.'
    );
}
step2_check(
    !str_contains(strtolower(implode(' ', $step2FakeDb->queries)), 'information_schema'),
    'No information_schema references should be issued.'
);

$step2FakeDb->queries = [];
$step2FakeDb->unavailableTable = 'permit_step2_documents';
step2_check(!step2_schema_ready(), 'A missing/inaccessible table must block Step 2.');
step2_check(count($step2FakeDb->queries) === 2, 'Stop on the first failed table check.');

echo "Step 2 schema compatibility checks passed (8 assertions).\n";
