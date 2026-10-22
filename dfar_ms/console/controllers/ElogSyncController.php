<?php

namespace console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use PDO;
use Exception;

class ElogSyncController extends Controller
{
    /**
     * Run full sync from apirequest table to core e_log tables.
     */
    public function actionIndex($limit = 0): int
    {
        $db = Yii::$app->db;
        $limit = (int)$limit;
        $limitSql = $limit > 0 ? "LIMIT $limit" : "";

        $this->stdout("Starting E-Log sync...\n");

        $requests = $db->createCommand("
            SELECT id, vesselid, data, time FROM apirequest 
            WHERE data LIKE '%submitData%' AND is_synced = 0 
            ORDER BY id ASC $limitSql
        ")->queryAll();

        $count = count($requests);
        $this->stdout("Found {$count} unsynced requests.\n");
        if ($count === 0) {
            $this->stdout("Nothing to sync.\n");
            return ExitCode::OK;
        }

        $variantToType = [];
        $varRows = $db->createCommand("SELECT id, fish_type_id FROM m_e_log_fish_variant")->queryAll();
        foreach ($varRows as $r) {
            $variantToType[(int)$r['id']] = (int)$r['fish_type_id'];
        }

        $synced = 0;
        $errors = 0;

        foreach ($requests as $req) {
            $reqId = (int)$req['id'];
            $json = json_decode($req['data'], true);
            if (!isset($json['submitData'])) {
                $db->createCommand("UPDATE apirequest SET is_synced = 1, synced_at = NOW() WHERE id = :id", [':id' => $reqId])->execute();
                continue;
            }

            $sd = $json['submitData'];
            $td = $sd['tripdata'] ?? [];

            $tx = $db->beginTransaction();
            try {
                $vesselId = trim((string)($td['vessel_id'] ?? $req['vesselid'] ?? 'UNKNOWN'));
                if ($vesselId === '') $vesselId = 'UNKNOWN';
                $skipperId = trim((string)($td['skipper_id'] ?? 'UNKNOWN'));
                if ($skipperId === '') $skipperId = 'UNKNOWN';

                $depDate = $this->parseDate($td['departure_date_time'] ?? null) ?: $req['time'];
                $arrDate = $this->parseDate($td['arrival_date_time'] ?? null) ?: $depDate;
                $depHarbour = !empty($td['departure_port']) ? (int)$td['departure_port'] : null;
                $arrHarbour = !empty($td['arrival_port']) ? (int)$td['arrival_port'] : null;

                $db->createCommand("
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
                ", [
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
                ])->execute();
                $elogId = (int)$db->getLastInsertID();

                // Gears
                $gearMap = [];
                $rawGears = $sd['Geardata'] ?? [];
                if (!empty($rawGears) && is_array($rawGears)) {
                    foreach ($rawGears as $g) {
                        $gId = $g['id'] ?? count($gearMap) + 1;
                        $gtRaw = strtoupper(trim($g['gearType'] ?? ''));

                        if (strpos($gtRaw, 'GILL') !== false) {
                            $db->createCommand("
                                INSERT INTO e_log_gillnet (e_log_id, net_material, mesh_size, ply, net_height, set_depth, length, net_pieces)
                                VALUES (:e_log_id, :net_material, :mesh_size, :ply, :net_height, :set_depth, :length, :net_pieces)
                            ", [
                                ':e_log_id'     => $elogId,
                                ':net_material' => substr(trim($g['netMaterial'] ?? 'nylon_braided'), 0, 100),
                                ':mesh_size'    => (float)($g['meshSize'] ?? 0),
                                ':ply'          => (int)($g['plyOfTheNet'] ?? 0),
                                ':net_height'   => (float)($g['heightOfNet'] ?? 0),
                                ':set_depth'    => (float)($g['DepthAtWhichTheNetSet'] ?? 0),
                                ':length'       => (float)($g['lengthOfNetPiece'] ?? 0),
                                ':net_pieces'   => (int)($g['numberOfNetPieces'] ?? 0),
                            ])->execute();
                            $gearMap[$gId] = ['id' => (int)$db->getLastInsertID(), 'type' => 'gillnet'];
                        } elseif (strpos($gtRaw, 'RING') !== false) {
                            $db->createCommand("
                                INSERT INTO e_log_ringnet (e_log_id, net_length, net_height, fad)
                                VALUES (:e_log_id, :net_length, :net_height, :fad)
                            ", [
                                ':e_log_id'    => $elogId,
                                ':net_length' => (float)($g['lengthOfTheRingNet'] ?? 0),
                                ':net_height' => (float)($g['heightOfTheRingNet'] ?? 0),
                                ':fad'        => trim($g['fad'] ?? ''),
                            ])->execute();
                            $gearMap[$gId] = ['id' => (int)$db->getLastInsertID(), 'type' => 'ringnet'];
                        } else {
                            $db->createCommand("
                                INSERT INTO e_log_longline (e_log_id, mainline, branchline, no_of_hooks, hook_type, depth, bait, no_hook_bet)
                                VALUES (:e_log_id, :mainline, :branchline, :no_of_hooks, :hook_type, :depth, :bait, :no_hook_bet)
                            ", [
                                ':e_log_id'    => $elogId,
                                ':mainline'   => (float)($g['mainLine'] ?? 0),
                                ':branchline' => (float)($g['branchLine'] ?? 0),
                                ':no_of_hooks'=> (int)($g['noHooks'] ?? 0),
                                ':hook_type'  => substr(trim((string)($g['hooksTypes'] ?? 'J36')), 0, 50),
                                ':depth'      => (float)($g['depth'] ?? 0),
                                ':bait'       => substr(trim((string)($g['bait'] ?? 'other')), 0, 100),
                                ':no_hook_bet'=> (int)($g['no_hook_bet'] ?? 0),
                            ])->execute();
                            $gearMap[$gId] = ['id' => (int)$db->getLastInsertID(), 'type' => 'longline'];
                        }
                    }
                }

                if (empty($gearMap)) {
                    $db->createCommand("
                        INSERT INTO e_log_longline (e_log_id, mainline, branchline, no_of_hooks, hook_type, depth, bait, no_hook_bet)
                        VALUES (:e_log_id, 0, 0, 0, 'J36', 0, 'other', 0)
                    ", [':e_log_id' => $elogId])->execute();
                    $gearMap[1] = ['id' => (int)$db->getLastInsertID(), 'type' => 'longline'];
                }

                // Sets
                $setMap = [];
                $rawSets = $sd['Setdata'] ?? [];
                if (!empty($rawSets) && is_array($rawSets)) {
                    foreach ($rawSets as $idx => $s) {
                        $sId = $s['id'] ?? ($idx + 1);
                        $gRef = $s['gearType'] ?? 1;
                        $gearChosen = $gearMap[$gRef] ?? reset($gearMap);

                        $startDt  = $this->parseDate($s['startDate'] ?? null) ?: $depDate;
                        $endDt    = $this->parseDate($s['endDate'] ?? null) ?: $startDt;
                        $startDir = 'N';
                        $startN   = trim((string)($s['startGps'] ?? ''));
                        $startE   = trim((string)($s['startGpsE'] ?? ''));
                        $endDir   = 'N';
                        $endN     = trim((string)($s['endGps'] ?? ''));
                        $endE     = trim((string)($s['endGpsE'] ?? ''));

                        $fiveByFive = $this->calcGrid($startDir, $startN, $startE, 6, 5);
                        $oneByOne   = $this->calcGrid($startDir, $startN, $startE, 5, 1);

                        $db->createCommand("
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
                        ", [
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
                        ])->execute();
                        $setMap[$sId] = (int)$db->getLastInsertID();
                    }
                }

                if (empty($setMap)) {
                    $gearChosen = reset($gearMap);
                    $db->createCommand("
                        INSERT INTO e_log_sets (
                            gear_id, gear_type, set_number, start_datetime, end_datetime,
                            start_gps_direction, start_gps_n, start_gps_e,
                            end_gps_direction, end_gps_n, end_gps_e,
                            five_by_five, one_by_one
                        ) VALUES (
                            :gear_id, :gear_type, 1, :start_datetime, :end_datetime,
                            'N', '0', '0', 'N', '0', '0', NULL, NULL
                        )
                    ", [
                        ':gear_id'        => $gearChosen['id'],
                        ':gear_type'      => $gearChosen['type'],
                        ':start_datetime' => $depDate,
                        ':end_datetime'   => $arrDate,
                    ])->execute();
                    $setMap[1] = (int)$db->getLastInsertID();
                }

                // Catches
                $rawCatches = $sd['catchdata'] ?? [];
                if (!empty($rawCatches) && is_array($rawCatches)) {
                    foreach ($rawCatches as $c) {
                        $stRef = $c['setType'] ?? 1;
                        $setId = $setMap[$stRef] ?? reset($setMap);
                        $catchType = strtoupper(trim($c['catchType'] ?? 'RETAINED'));
                        $ftId = !empty($c['fishType']) ? (int)$c['fishType'] : 0;

                        $table = 'e_log_set_catch';
                        if (strpos($catchType, 'DEAD') !== false) {
                            $table = 'e_log_set_discarded_dead';
                        } elseif (strpos($catchType, 'ALIVE') !== false) {
                            $table = 'e_log_set_discarded_live';
                        }

                        $fishList = $c['selectedFish'] ?? [];
                        if (!empty($fishList) && is_array($fishList)) {
                            foreach ($fishList as $sf) {
                                $vId = (int)($sf['fishId'] ?? 0);
                                if ($vId <= 0) continue;
                                $curFtId = $ftId > 0 ? $ftId : ($variantToType[$vId] ?? 2);
                                $qty = (int)($sf['quantity'] ?? 0);
                                $wgt = (float)($sf['weight'] ?? 0.00);

                                $db->createCommand("
                                    INSERT INTO {$table} (e_log_set_id, fish_type_id, fish_variant_id, weight, fish_count)
                                    VALUES (:set_id, :ft_id, :v_id, :wgt, :count)
                                ", [
                                    ':set_id' => $setId,
                                    ':ft_id'  => $curFtId,
                                    ':v_id'   => $vId,
                                    ':wgt'    => $wgt,
                                    ':count'  => $qty,
                                ])->execute();
                            }
                        }
                    }
                }

                $db->createCommand("UPDATE apirequest SET is_synced = 1, synced_at = NOW() WHERE id = :id", [':id' => $reqId])->execute();
                $tx->commit();
                $synced++;

                if ($synced % 200 === 0) {
                    $this->stdout("Synced {$synced} trips...\n");
                }
            } catch (Exception $e) {
                $tx->rollBack();
                $errors++;
                $this->stderr("Error on request #{$reqId}: {$e->getMessage()}\n");
            }
        }

        $this->stdout("Sync finished. Success: {$synced}, Errors: {$errors}\n");
        return ExitCode::OK;
    }

    /**
     * Show counts of E-Log tables.
     */
    public function actionStatus(): int
    {
        $db = Yii::$app->db;
        $tables = [
            'apirequest',
            'e_log',
            'e_log_longline',
            'e_log_gillnet',
            'e_log_ringnet',
            'e_log_sets',
            'e_log_set_catch',
            'e_log_set_discarded_dead',
            'e_log_set_discarded_live',
            'm_e_log_fish_type',
            'm_e_log_fish_variant',
        ];

        $this->stdout("=== E-Log Database Status ===\n");
        foreach ($tables as $t) {
            $cnt = $db->createCommand("SELECT COUNT(*) FROM `{$t}`")->queryScalar();
            $this->stdout(sprintf("  %-28s: %d\n", $t, $cnt));
        }

        $unsynced = $db->createCommand("SELECT COUNT(*) FROM apirequest WHERE data LIKE '%submitData%' AND is_synced = 0")->queryScalar();
        $this->stdout(sprintf("  %-28s: %d\n", 'Unsynced submitData in API', $unsynced));

        return ExitCode::OK;
    }

    private function parseDate($s): ?string
    {
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

    private function calcGrid($direction, $n, $e, int $sizeCode, int $cellSize): ?int
    {
        if ($direction === null || $direction === '' || $n === null || $n === '' || $e === null || $e === '') {
            return null;
        }
        $lat = (int) floor((float) $n);
        $lon = (int) floor((float) $e);
        $lat = intdiv($lat, $cellSize) * $cellSize;
        $lon = intdiv($lon, $cellSize) * $cellSize;
        $quadrant = ($direction === 'S') ? 1 : 2;
        return (int) sprintf('%d%d%02d%03d', $sizeCode, $quadrant, $lat, $lon);
    }
}
