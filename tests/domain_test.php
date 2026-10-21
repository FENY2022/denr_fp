<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/domain.php';
function must(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Test failure: ' . $message);
}
must(check_password_strength('SamplePassword2026!') === null, 'Strong password accepted');
must(check_password_strength('weak') !== null, 'Short password rejected');
must(check_password_strength('abcdefghijklmnop') !== null, 'Must include a number');
must(check_password_strength('123456789123456') !== null, 'Must include a letter');
$valid = ['representative_name'=>'Test User','company_name'=>'Example Energy Corp',
    'company_address'=>'Butuan City','contact_number'=>'+63 917 123 4567', 'representative_position'=>'Manager'];
must(registration_validation($valid) === [], 'Valid registration accepted');
$invalid = $valid; $invalid['contact_number'] = 'call me';
must(registration_validation($invalid) !== [], 'Invalid phone rejected');
$invalid = $valid; $invalid['company_name'] = '';
must(registration_validation($invalid) !== [], 'Missing company rejected');
must(status_label('active') === 'Account Activated', 'Status maps correctly');
must(isset(role_definitions()['lpdd_fus']), 'LPDD-FUS role exists');
echo "PASS: 9 basic domain assertions.\n";
