<?php
// tools/test_elog_system.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/../common/config/bootstrap.php';
require __DIR__ . '/../backend/config/bootstrap.php';

$config = yii\helpers\ArrayHelper::merge(
    require __DIR__ . '/../common/config/main.php',
    file_exists(__DIR__ . '/../common/config/main-local.php') ? require __DIR__ . '/../common/config/main-local.php' : [],
    require __DIR__ . '/../backend/config/main.php',
    file_exists(__DIR__ . '/../backend/config/main-local.php') ? require __DIR__ . '/../backend/config/main-local.php' : []
);

// CLI-safe adjustments
unset($config['as beforeRequest']);
if (isset($config['components']['errorHandler'])) unset($config['components']['errorHandler']);
if (isset($config['components']['request'])) unset($config['components']['request']);
if (isset($config['components']['session'])) unset($config['components']['session']);
if (isset($config['components']['user'])) unset($config['components']['user']);

$config['components']['db']['dsn'] = 'mysql:host=127.0.0.1;dbname=dfar_ms;port=3306';

$app = new yii\console\Application($config);

echo "=== 1. Checking Database Tables and Record Counts ===\n";
$tables = [
    'apirequest'               => 'Raw API logs',
    'e_log'                    => 'Trips (E-Log)',
    'e_log_longline'           => 'Longline gears',
    'e_log_gillnet'            => 'Gillnet gears',
    'e_log_ringnet'            => 'Ringnet gears',
    'e_log_sets'               => 'Fishing sets',
    'e_log_set_catch'          => 'Retained catches',
    'e_log_set_discarded_dead' => 'Discarded dead catches',
    'e_log_set_discarded_live' => 'Discarded live catches',
    'm_e_log_fish_type'        => 'Fish types master',
    'm_e_log_fish_variant'     => 'Fish variants master',
];

foreach ($tables as $t => $desc) {
    $count = Yii::$app->db->createCommand("SELECT COUNT(*) FROM `{$t}`")->queryScalar();
    printf("  %-25s (%-22s): %d records\n", $t, $desc, $count);
}

echo "\n=== 2. Testing Models and Relations ===\n";
$sampleElog = \backend\models\ELog::find()->orderBy(['id' => SORT_DESC])->one();
if ($sampleElog) {
    echo "Sample ELog ID: {$sampleElog->id}\n";
    echo "  Vessel: {$sampleElog->vessel_id}\n";
    echo "  Skipper: {$sampleElog->skipper_id}\n";
    echo "  Departure Date: {$sampleElog->departure_date}\n";
    echo "  Arrival Date: {$sampleElog->arrival_date}\n";
    echo "  Departure Harbour: " . ($sampleElog->departureHarbour->Name ?? 'N/A (ID: ' . $sampleElog->departure_harbour . ')') . "\n";
    echo "  Arrival Harbour: " . ($sampleElog->arrivalHarbour->Name ?? 'N/A (ID: ' . $sampleElog->arrival_harbour . ')') . "\n";

    // Sets & Catches
    $longlines = \backend\models\ELogLongline::find()->where(['e_log_id' => $sampleElog->id])->all();
    $gillnets  = \backend\models\ELogGillnet::find()->where(['e_log_id' => $sampleElog->id])->all();
    $ringnets  = \backend\models\ELogRingnet::find()->where(['e_log_id' => $sampleElog->id])->all();

    echo "  Gears attached: " . count($longlines) . " Longline, " . count($gillnets) . " Gillnet, " . count($ringnets) . " Ringnet\n";

    $sets = \backend\models\ELogSets::find()->where(['gear_id' => array_merge(
        array_map(fn($g) => $g->id, $longlines),
        array_map(fn($g) => $g->id, $gillnets),
        array_map(fn($g) => $g->id, $ringnets)
    )])->all();

    echo "  Sets attached: " . count($sets) . "\n";
    foreach (array_slice($sets, 0, 3) as $s) {
        $catches = \backend\models\ELogSetCatch::find()->where(['e_log_set_id' => $s->id])->all();
        echo "    Set #{$s->set_number} ({$s->gear_type}): Start={$s->start_datetime}, End={$s->end_datetime}, GPS N={$s->start_gps_n}, E={$s->start_gps_e}, 5x5={$s->five_by_five}, 1x1={$s->one_by_one}, Catches=" . count($catches) . "\n";
        foreach ($catches as $c) {
            $ftName = $c->fishType->name ?? 'Type ' . $c->fish_type_id;
            $fvName = $c->fishVariant->name ?? 'Variant ' . $c->fish_variant_id;
            echo "      -> {$ftName} - {$fvName}: {$c->fish_count} fish, {$c->weight} kg\n";
        }
    }
} else {
    echo "No ELog records found yet.\n";
}

echo "\n=== 3. Testing Complex ELogViewController Aggregate Query ===\n";
$reportSql = "
    SELECT
        COUNT(DISTINCT e.id) as total_trips,
        COUNT(esc.id) as total_catch_rows,
        SUM(esc.weight) as total_catch_weight,
        SUM(esc.fish_count) as total_fish_count
    FROM e_log e
    LEFT JOIN e_log_longline ll ON e.id = ll.e_log_id
    LEFT JOIN e_log_ringnet  rn ON e.id = rn.e_log_id
    LEFT JOIN e_log_gillnet  gn ON e.id = gn.e_log_id
    LEFT JOIN e_log_sets es
        ON es.gear_id = COALESCE(ll.id, rn.id, gn.id)
        AND es.gear_type = CASE
            WHEN ll.id IS NOT NULL THEN 'longline'
            WHEN rn.id IS NOT NULL THEN 'ringnet'
            WHEN gn.id IS NOT NULL THEN 'gillnet'
        END
    LEFT JOIN e_log_set_catch esc ON esc.e_log_set_id = es.id
    WHERE esc.id IS NOT NULL
";
$stats = Yii::$app->db->createCommand($reportSql)->queryOne();
echo "Aggregate Statistics:\n";
echo "  Total Trips with Catches: {$stats['total_trips']}\n";
echo "  Total Catch Records: {$stats['total_catch_rows']}\n";
echo "  Total Fish Weight (kg): " . number_format($stats['total_catch_weight'], 2) . "\n";
echo "  Total Fish Count: " . number_format($stats['total_fish_count']) . "\n";

echo "\n=== 4. Testing ELogSearch and ELogReportSearch ===\n";
$searchModel = new \backend\models\ELogSearch();
$dataProvider = $searchModel->search([]);
echo "  ELogSearch Total Count: " . $dataProvider->getTotalCount() . "\n";

$reportSearch = new \backend\models\ELogReportSearch();
$reportProvider = $reportSearch->search([]);
echo "  ELogReportSearch Total Count: " . $reportProvider->getTotalCount() . "\n";

echo "\n=== All tests passed successfully! ===\n";
