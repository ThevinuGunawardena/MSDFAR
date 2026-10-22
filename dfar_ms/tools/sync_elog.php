<?php
// tools/sync_elog.php
require __DIR__ . '/../vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=dfar_ms;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

function parseElogDate($s) {
    if (!$s) return null;
    $s = trim($s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
        return date('Y-m-d H:i:s', strtotime($s));
    }
    $clean = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $s);
    $clean = preg_replace('/\s+/', ' ', $clean);
    $ts = strtotime($clean);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function calcGridCode($direction, $n, $e, int $sizeCode, int $cellSize): ?int {
    if ($direction === null || $direction === '' ||
        $n === null || $n === '' ||
        $e === null || $e === '') {
        return null;
    }
    $lat = (int) floor((float) $n);
    $lon = (int) floor((float) $e);
    $lat = intdiv($lat, $cellSize) * $cellSize;
    $lon = intdiv($lon, $cellSize) * $cellSize;
    $quadrant = ($direction === 'S') ? 1 : 2;
    return (int) sprintf('%d%d%02d%03d', $sizeCode, $quadrant, $lat, $lon);
}

// Load variant -> fish_type_id lookup
$variantToType = [];
$stmt = $pdo->query("SELECT id, fish_type_id FROM m_e_log_fish_variant");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $variantToType[(int)$r['id']] = (int)$r['fish_type_id'];
}

$limit = isset($argv[1]) ? (int)$argv[1] : 0;
$limitSql = $limit > 0 ? "LIMIT $limit" : "";

$query = "SELECT id, vesselid, data, time FROM apirequest 
          WHERE data LIKE '%submitData%' AND is_synced = 0 
          ORDER BY id ASC $limitSql";
$requests = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($requests) . " unsynced submitData requests to process.\n";

$syncedCount = 0;
$skippedCount = 0;
$errorCount = 0;

$stmtInsertElog = $pdo->prepare("
    INSERT INTO e_log (
        apirequest_id, vessel_id, skipper_id, phone_number,
        departure_harbour, arrival_harbour, departure_date, arrival_date,
        log_sheet_number, log_book_no, remarks, inspector_id, approve,
        created_at, updated_at
    ) VALUES (
        :apirequest_id, :vessel_id, :skipper_id, :phone_number,
        :departure_harbour, :arrival_harbour, :departure_date, :arrival_date,
        :log_sheet_number, :log_book_no, :remarks, :inspector_id, :approve,
        :created_at, :updated_at
    )
");

$stmtLongline = $pdo->prepare("
    INSERT INTO e_log_longline (
        e_log_id, mainline, branchline, no_of_hooks, hook_type, depth, bait, no_hook_bet
    ) VALUES (
        :e_log_id, :mainline, :branchline, :no_of_hooks, :hook_type, :depth, :bait, :no_hook_bet
    )
");

$stmtGillnet = $pdo->prepare("
    INSERT INTO e_log_gillnet (
        e_log_id, net_material, mesh_size, ply, net_height, set_depth, length, net_pieces
    ) VALUES (
        :e_log_id, :net_material, :mesh_size, :ply, :net_height, :set_depth, :length, :net_pieces
    )
");

$stmtRingnet = $pdo->prepare("
    INSERT INTO e_log_ringnet (
        e_log_id, net_length, net_height, fad
    ) VALUES (
        :e_log_id, :net_length, :net_height, :fad
    )
");

$stmtSet = $pdo->prepare("
    INSERT INTO e_log_sets (
        gear_id, gear_type, set_number, start_datetime, end_datetime,
        start_gps_direction, start_gps_n, start_gps_e,
        end_gps_direction, end_gps_n, end_gps_e,
        five_by_five, one_by_one
    ) VALUES (
        :gear_id, :gear_type, :set_number, :start_datetime, :end_datetime,
        :start_gps_direction, :start_gps_n, :start_gps_e,
        :end_gps_direction, :end_gps_n, :end_gps_e,
        :five_by_five, :one_by_one
    )
");

$stmtCatch = $pdo->prepare("
    INSERT INTO e_log_set_catch (
        e_log_set_id, fish_type_id, fish_variant_id, weight, fish_count
    ) VALUES (
        :e_log_set_id, :fish_type_id, :fish_variant_id, :weight, :fish_count
    )
");

$stmtDead = $pdo->prepare("
    INSERT INTO e_log_set_discarded_dead (
        e_log_set_id, fish_type_id, fish_variant_id, weight, fish_count
    ) VALUES (
        :e_log_set_id, :fish_type_id, :fish_variant_id, :weight, :fish_count
    )
");

$stmtLive = $pdo->prepare("
    INSERT INTO e_log_set_discarded_live (
        e_log_set_id, fish_type_id, fish_variant_id, weight, fish_count
    ) VALUES (
        :e_log_set_id, :fish_type_id, :fish_variant_id, :weight, :fish_count
    )
");

$stmtMarkSynced = $pdo->prepare("
    UPDATE apirequest SET is_synced = 1, synced_at = NOW() WHERE id = :id
");

foreach ($requests as $req) {
    $reqId = (int)$req['id'];
    $json = json_decode($req['data'], true);
    if (!isset($json['submitData'])) {
        $stmtMarkSynced->execute([':id' => $reqId]);
        $skippedCount++;
        continue;
    }

    $sd = $json['submitData'];
    $td = $sd['tripdata'] ?? [];

    $pdo->beginTransaction();
    try {
        $vesselId = trim((string)($td['vessel_id'] ?? $req['vesselid'] ?? 'UNKNOWN'));
        if ($vesselId === '') $vesselId = 'UNKNOWN';
        $skipperId = trim((string)($td['skipper_id'] ?? 'UNKNOWN'));
        if ($skipperId === '') $skipperId = 'UNKNOWN';

        $depDate = parseElogDate($td['departure_date_time'] ?? null) ?: $req['time'];
        $arrDate = parseElogDate($td['arrival_date_time'] ?? null) ?: $depDate;

        $depHarbour = !empty($td['departure_port']) ? (int)$td['departure_port'] : null;
        $arrHarbour = !empty($td['arrival_port']) ? (int)$td['arrival_port'] : null;

        $stmtInsertElog->execute([
            ':apirequest_id'    => $reqId,
            ':vessel_id'        => $vesselId,
            ':skipper_id'       => $skipperId,
            ':phone_number'     => substr(trim($td['phone_number'] ?? $td['departure_remarks'] ?? ''), 0, 100),
            ':departure_harbour'=> $depHarbour,
            ':arrival_harbour'  => $arrHarbour,
            ':departure_date'   => $depDate,
            ':arrival_date'     => $arrDate,
            ':log_sheet_number' => (string)($td['log_sheet_number'] ?? $td['id'] ?? $reqId),
            ':log_book_no'      => (string)($td['log_book_no'] ?? $td['id'] ?? $reqId),
            ':remarks'          => $td['departure_remarks'] ?? null,
            ':inspector_id'     => $td['departure_inspector_id'] ?? null,
            ':approve'          => 1,
            ':created_at'       => $req['time'],
            ':updated_at'       => $req['time'],
        ]);
        $elogId = (int)$pdo->lastInsertId();

        // Gears
        $gearMap = [];
        $rawGears = $sd['Geardata'] ?? [];
        if (!empty($rawGears) && is_array($rawGears)) {
            foreach ($rawGears as $g) {
                $gId = $g['id'] ?? count($gearMap) + 1;
                $gtRaw = strtoupper(trim($g['gearType'] ?? ''));

                if (strpos($gtRaw, 'GILL') !== false) {
                    $stmtGillnet->execute([
                        ':e_log_id'     => $elogId,
                        ':net_material' => substr(trim($g['netMaterial'] ?? 'nylon_braided'), 0, 100),
                        ':mesh_size'    => (float)($g['meshSize'] ?? 0),
                        ':ply'          => (int)($g['plyOfTheNet'] ?? 0),
                        ':net_height'   => (float)($g['heightOfNet'] ?? 0),
                        ':set_depth'    => (float)($g['DepthAtWhichTheNetSet'] ?? 0),
                        ':length'       => (float)($g['lengthOfNetPiece'] ?? 0),
                        ':net_pieces'   => (int)($g['numberOfNetPieces'] ?? 0),
                    ]);
                    $gearMap[$gId] = ['id' => (int)$pdo->lastInsertId(), 'type' => 'gillnet'];
                } elseif (strpos($gtRaw, 'RING') !== false) {
                    $stmtRingnet->execute([
                        ':e_log_id'    => $elogId,
                        ':net_length' => (float)($g['lengthOfTheRingNet'] ?? 0),
                        ':net_height' => (float)($g['heightOfTheRingNet'] ?? 0),
                        ':fad'        => trim($g['fad'] ?? ''),
                    ]);
                    $gearMap[$gId] = ['id' => (int)$pdo->lastInsertId(), 'type' => 'ringnet'];
                } else {
                    // Default to Longline
                    $stmtLongline->execute([
                        ':e_log_id'    => $elogId,
                        ':mainline'   => (float)($g['mainLine'] ?? 0),
                        ':branchline' => (float)($g['branchLine'] ?? 0),
                        ':no_of_hooks'=> (int)($g['noHooks'] ?? 0),
                        ':hook_type'  => substr(trim((string)($g['hooksTypes'] ?? 'J36')), 0, 50),
                        ':depth'      => (float)($g['depth'] ?? 0),
                        ':bait'       => substr(trim((string)($g['bait'] ?? 'other')), 0, 100),
                        ':no_hook_bet'=> (int)($g['no_hook_bet'] ?? 0),
                    ]);
                    $gearMap[$gId] = ['id' => (int)$pdo->lastInsertId(), 'type' => 'longline'];
                }
            }
        }

        // If no gears created yet, check Setdata or create default longline gear
        if (empty($gearMap)) {
            $stmtLongline->execute([
                ':e_log_id'    => $elogId,
                ':mainline'   => 0,
                ':branchline' => 0,
                ':no_of_hooks'=> 0,
                ':hook_type'  => 'J36',
                ':depth'      => 0,
                ':bait'       => 'other',
                ':no_hook_bet'=> 0,
            ]);
            $defaultGearId = (int)$pdo->lastInsertId();
            $gearMap[1] = ['id' => $defaultGearId, 'type' => 'longline'];
        }

        // Sets
        $setMap = [];
        $rawSets = $sd['Setdata'] ?? [];
        if (!empty($rawSets) && is_array($rawSets)) {
            foreach ($rawSets as $idx => $s) {
                $sId = $s['id'] ?? ($idx + 1);
                $gRef = $s['gearType'] ?? 1;

                if (isset($gearMap[$gRef])) {
                    $gearChosen = $gearMap[$gRef];
                } else {
                    $gearChosen = reset($gearMap);
                }

                $startDt = parseElogDate($s['startDate'] ?? null) ?: $depDate;
                $endDt   = parseElogDate($s['endDate'] ?? null) ?: $startDt;
                $startDir = 'N';
                $startN   = trim((string)($s['startGps'] ?? ''));
                $startE   = trim((string)($s['startGpsE'] ?? ''));
                $endDir   = 'N';
                $endN     = trim((string)($s['endGps'] ?? ''));
                $endE     = trim((string)($s['endGpsE'] ?? ''));

                $fiveByFive = calcGridCode($startDir, $startN, $startE, 6, 5);
                $oneByOne   = calcGridCode($startDir, $startN, $startE, 5, 1);

                $stmtSet->execute([
                    ':gear_id'             => $gearChosen['id'],
                    ':gear_type'           => $gearChosen['type'],
                    ':set_number'          => (int)($s['nubmer'] ?? $sId),
                    ':start_datetime'      => $startDt,
                    ':end_datetime'        => $endDt,
                    ':start_gps_direction' => $startDir,
                    ':start_gps_n'         => $startN,
                    ':start_gps_e'         => $startE,
                    ':end_gps_direction'   => $endDir,
                    ':end_gps_n'           => $endN,
                    ':end_gps_e'           => $endE,
                    ':five_by_five'        => $fiveByFive,
                    ':one_by_one'          => $oneByOne,
                ]);
                $setMap[$sId] = (int)$pdo->lastInsertId();
            }
        }

        // If no sets, create a default set so catches have a parent
        if (empty($setMap)) {
            $gearChosen = reset($gearMap);
            $stmtSet->execute([
                ':gear_id'             => $gearChosen['id'],
                ':gear_type'           => $gearChosen['type'],
                ':set_number'          => 1,
                ':start_datetime'      => $depDate,
                ':end_datetime'        => $arrDate,
                ':start_gps_direction' => 'N',
                ':start_gps_n'         => '0',
                ':start_gps_e'         => '0',
                ':end_gps_direction'   => 'N',
                ':end_gps_n'           => '0',
                ':end_gps_e'           => '0',
                ':five_by_five'        => null,
                ':one_by_one'          => null,
            ]);
            $setMap[1] = (int)$pdo->lastInsertId();
        }

        // Catches
        $rawCatches = $sd['catchdata'] ?? [];
        if (!empty($rawCatches) && is_array($rawCatches)) {
            foreach ($rawCatches as $c) {
                $stRef = $c['setType'] ?? 1;
                $setId = $setMap[$stRef] ?? reset($setMap);
                $catchType = strtoupper(trim($c['catchType'] ?? 'RETAINED'));
                $ftId = !empty($c['fishType']) ? (int)$c['fishType'] : 0;

                $targetStmt = $stmtCatch;
                if (strpos($catchType, 'DEAD') !== false) {
                    $targetStmt = $stmtDead;
                } elseif (strpos($catchType, 'ALIVE') !== false) {
                    $targetStmt = $stmtLive;
                }

                $fishList = $c['selectedFish'] ?? [];
                if (!empty($fishList) && is_array($fishList)) {
                    foreach ($fishList as $sf) {
                        $vId = (int)($sf['fishId'] ?? 0);
                        if ($vId <= 0) continue;
                        $curFtId = $ftId > 0 ? $ftId : ($variantToType[$vId] ?? 2);
                        $qty = (int)($sf['quantity'] ?? 0);
                        $wgt = (float)($sf['weight'] ?? 0.00);

                        $targetStmt->execute([
                            ':e_log_set_id'    => $setId,
                            ':fish_type_id'    => $curFtId,
                            ':fish_variant_id' => $vId,
                            ':weight'          => $wgt,
                            ':fish_count'      => $qty,
                        ]);
                    }
                }
            }
        }

        $stmtMarkSynced->execute([':id' => $reqId]);
        $pdo->commit();
        $syncedCount++;

        if ($syncedCount % 200 === 0) {
            echo "Synced $syncedCount trips...\n";
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        $errorCount++;
        echo "Error on apirequest #$reqId: " . $e->getMessage() . "\n";
    }
}

echo "\n================ Sync Summary ================\n";
echo "Successfully Synced: $syncedCount trips\n";
echo "Skipped: $skippedCount non-submitData rows\n";
echo "Errors: $errorCount\n";
echo "==============================================\n";

$counts = [
    'e_log' => $pdo->query("SELECT COUNT(*) FROM e_log")->fetchColumn(),
    'e_log_longline' => $pdo->query("SELECT COUNT(*) FROM e_log_longline")->fetchColumn(),
    'e_log_gillnet' => $pdo->query("SELECT COUNT(*) FROM e_log_gillnet")->fetchColumn(),
    'e_log_ringnet' => $pdo->query("SELECT COUNT(*) FROM e_log_ringnet")->fetchColumn(),
    'e_log_sets' => $pdo->query("SELECT COUNT(*) FROM e_log_sets")->fetchColumn(),
    'e_log_set_catch' => $pdo->query("SELECT COUNT(*) FROM e_log_set_catch")->fetchColumn(),
    'e_log_set_discarded_dead' => $pdo->query("SELECT COUNT(*) FROM e_log_set_discarded_dead")->fetchColumn(),
    'e_log_set_discarded_live' => $pdo->query("SELECT COUNT(*) FROM e_log_set_discarded_live")->fetchColumn(),
];

echo "Database Table Counts:\n";
foreach ($counts as $tbl => $cnt) {
    echo "  $tbl: $cnt\n";
}
