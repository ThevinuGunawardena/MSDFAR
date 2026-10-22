<?php
// tools/import_apirequest.php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=dfar_ms;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$csvPath = dirname(__DIR__, 2) . '/u679629199_elog.csv';
if (!file_exists($csvPath)) {
    $csvPath = 'C:/Users/Administrator/Downloads/New folder/u679629199_elog.csv';
}
if (!file_exists($csvPath)) {
    die("CSV not found: $csvPath\n");
}

echo "Importing apirequest data from $csvPath into table `apirequest`...\n";

$handle = fopen($csvPath, 'r');
$header = fgetcsv($handle);

$batchSize = 500;
$batch = [];
$total = 0;

$sql = "INSERT INTO `apirequest` (`id`, `type`, `vesselid`, `data`, `time`)
        VALUES (:id, :type, :vesselid, :data, :time)
        ON DUPLICATE KEY UPDATE
            `type` = VALUES(`type`),
            `vesselid` = VALUES(`vesselid`),
            `data` = VALUES(`data`),
            `time` = VALUES(`time`)";
$stmt = $pdo->prepare($sql);

$pdo->beginTransaction();

while (($row = fgetcsv($handle)) !== false) {
    $id = (int)$row[0];
    $type = (int)$row[1];
    $vesselid = ($row[2] === '' || strtolower($row[2]) === 'null') ? null : (int)$row[2];
    $data = $row[3] ?? null;
    $time = $row[4] ?? date('Y-m-d H:i:s');

    $stmt->execute([
        ':id' => $id,
        ':type' => $type,
        ':vesselid' => $vesselid,
        ':data' => $data,
        ':time' => $time,
    ]);

    $total++;
    if ($total % $batchSize === 0) {
        $pdo->commit();
        echo "Imported $total rows...\n";
        $pdo->beginTransaction();
    }
}
$pdo->commit();
fclose($handle);

$count = $pdo->query("SELECT COUNT(*) FROM `apirequest`")->fetchColumn();
echo "Import complete! Total rows in `apirequest`: $count\n";
