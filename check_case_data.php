<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$case = DB::table('law_cases')
    ->select('caseId', 'title', 'case_type_fee_json')
    ->first();

if ($case) {
    echo "Case ID: {$case->caseId}\n";
    echo "Title: {$case->title}\n";
    echo "Has case_type_fee_json: " . (!empty($case->case_type_fee_json) ? "YES" : "NO") . "\n";
    if ($case->case_type_fee_json) {
        echo "\nFee JSON:\n";
        echo json_encode(json_decode($case->case_type_fee_json, true), JSON_PRETTY_PRINT);
        echo "\n";
    }
} else {
    echo "No cases found in database\n";
}
