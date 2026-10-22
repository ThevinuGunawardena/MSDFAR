<?php
// tools/setup_elog_tables.php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=dfar_ms;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

echo "Connected to dfar_ms.\n";

$sql = "
CREATE TABLE IF NOT EXISTS `apirequest` (
  `id` int(11) NOT NULL PRIMARY KEY,
  `type` int(11) NOT NULL,
  `vesselid` int(11) DEFAULT NULL,
  `data` longtext DEFAULT NULL,
  `time` datetime NOT NULL,
  `is_synced` tinyint(1) DEFAULT 0,
  `synced_at` datetime DEFAULT NULL,
  KEY `idx_time` (`time`),
  KEY `idx_is_synced` (`is_synced`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `m_e_log_fish_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(100) NOT NULL,
  UNIQUE KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `m_e_log_fish_variant` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `fish_type_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  KEY `idx_fish_type` (`fish_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `apirequest_id` int(11) DEFAULT NULL,
  `vessel_id` varchar(100) NOT NULL,
  `skipper_id` varchar(100) NOT NULL,
  `phone_number` varchar(100) DEFAULT NULL,
  `departure_harbour` int(11) DEFAULT NULL,
  `arrival_harbour` int(11) DEFAULT NULL,
  `departure_date` datetime DEFAULT NULL,
  `arrival_date` datetime DEFAULT NULL,
  `log_sheet_number` varchar(100) DEFAULT NULL,
  `log_book_no` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `inspector_id` varchar(100) DEFAULT NULL,
  `approve` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_vessel` (`vessel_id`),
  KEY `idx_dep_date` (`departure_date`),
  KEY `idx_arr_date` (`arrival_date`),
  KEY `idx_apirequest_id` (`apirequest_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_longline` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `e_log_id` int(11) NOT NULL,
  `mainline` decimal(10,2) DEFAULT 0.00,
  `branchline` decimal(10,2) DEFAULT 0.00,
  `no_of_hooks` int(11) DEFAULT 0,
  `hook_type` varchar(50) DEFAULT NULL,
  `depth` decimal(10,2) DEFAULT 0.00,
  `bait` varchar(100) DEFAULT NULL,
  `no_hook_bet` int(11) DEFAULT 0,
  KEY `idx_elog_id` (`e_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_gillnet` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `e_log_id` int(11) NOT NULL,
  `net_material` varchar(100) DEFAULT NULL,
  `mesh_size` decimal(10,2) DEFAULT 0.00,
  `ply` int(11) DEFAULT 0,
  `net_height` decimal(10,2) DEFAULT 0.00,
  `set_depth` decimal(10,2) DEFAULT 0.00,
  `length` decimal(10,2) DEFAULT 0.00,
  `net_pieces` int(11) DEFAULT 0,
  KEY `idx_elog_id` (`e_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_ringnet` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `e_log_id` int(11) NOT NULL,
  `net_length` decimal(10,2) DEFAULT 0.00,
  `net_height` decimal(10,2) DEFAULT 0.00,
  `fad` varchar(255) DEFAULT NULL,
  KEY `idx_elog_id` (`e_log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_sets` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `gear_id` int(11) NOT NULL,
  `gear_type` varchar(50) NOT NULL,
  `set_number` int(11) NOT NULL,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `start_gps_direction` varchar(10) DEFAULT 'N',
  `start_gps_n` varchar(50) DEFAULT NULL,
  `start_gps_e` varchar(50) DEFAULT NULL,
  `end_gps_direction` varchar(10) DEFAULT 'N',
  `end_gps_n` varchar(50) DEFAULT NULL,
  `end_gps_e` varchar(50) DEFAULT NULL,
  `five_by_five` int(11) DEFAULT NULL,
  `one_by_one` int(11) DEFAULT NULL,
  KEY `idx_gear` (`gear_id`, `gear_type`),
  KEY `idx_start_dt` (`start_datetime`),
  KEY `idx_five_by_five` (`five_by_five`),
  KEY `idx_one_by_one` (`one_by_one`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_set_catch` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `e_log_set_id` int(11) NOT NULL,
  `fish_type_id` int(11) NOT NULL,
  `fish_variant_id` int(11) NOT NULL,
  `weight` decimal(10,2) DEFAULT 0.00,
  `fish_count` int(11) DEFAULT 0,
  KEY `idx_set_id` (`e_log_set_id`),
  KEY `idx_fish_variant` (`fish_variant_id`),
  KEY `idx_fish_type` (`fish_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_set_discarded_dead` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `e_log_set_id` int(11) NOT NULL,
  `fish_type_id` int(11) NOT NULL,
  `fish_variant_id` int(11) NOT NULL,
  `weight` decimal(10,2) DEFAULT 0.00,
  `fish_count` int(11) DEFAULT 0,
  KEY `idx_set_id` (`e_log_set_id`),
  KEY `idx_fish_variant` (`fish_variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_set_discarded_live` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `e_log_set_id` int(11) NOT NULL,
  `fish_type_id` int(11) NOT NULL,
  `fish_variant_id` int(11) NOT NULL,
  `weight` decimal(10,2) DEFAULT 0.00,
  `fish_count` int(11) DEFAULT 0,
  KEY `idx_set_id` (`e_log_set_id`),
  KEY `idx_fish_variant` (`fish_variant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_temp` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `vessel_id` varchar(100) NOT NULL,
  `phone_number` varchar(100) DEFAULT NULL,
  `arrival_date` datetime NOT NULL,
  `departure_date` datetime DEFAULT NULL,
  `arrival_harbour` int(11) NOT NULL,
  `departure_harbour` int(11) NOT NULL,
  `gear_type` varchar(50) NOT NULL,
  `net_length` decimal(10,2) DEFAULT NULL,
  `net_height` decimal(10,2) DEFAULT NULL,
  `fad` text DEFAULT NULL,
  `mainline` decimal(10,2) DEFAULT NULL,
  `branchline` decimal(10,2) DEFAULT NULL,
  `no_of_hooks` int(11) DEFAULT NULL,
  `no_hook_bet` int(11) DEFAULT NULL,
  `hook_type` varchar(50) DEFAULT NULL,
  `depth` decimal(10,2) DEFAULT NULL,
  `bait` varchar(100) DEFAULT NULL,
  `mesh_size` decimal(10,2) DEFAULT NULL,
  `set_depth` decimal(10,2) DEFAULT NULL,
  `length` decimal(10,2) DEFAULT NULL,
  `ply` int(11) DEFAULT NULL,
  `net_pieces` int(11) DEFAULT NULL,
  `net_material` varchar(100) DEFAULT NULL,
  `approve` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_set_temp` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `elog_id` int(11) NOT NULL,
  `gear_type` varchar(50) NOT NULL,
  `set_number` int(11) NOT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `start_gps_direction` varchar(10) DEFAULT 'N',
  `start_gps_n` decimal(10,4) NOT NULL,
  `start_gps_e` decimal(10,4) NOT NULL,
  `end_gps_direction` varchar(10) DEFAULT 'N',
  `end_gps_n` decimal(10,4) DEFAULT NULL,
  `end_gps_e` decimal(10,4) DEFAULT NULL,
  `five_by_five` int(11) DEFAULT NULL,
  `one_by_one` int(11) DEFAULT NULL,
  KEY `idx_elog_id` (`elog_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `e_log_catch_temp` (
  `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `e_log_set_temp_id` int(11) NOT NULL,
  `fish_type_id` int(11) NOT NULL,
  `fish_variant_id` int(11) NOT NULL,
  `weight` decimal(10,2) DEFAULT 0.00,
  `fish_count` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_set_id` (`e_log_set_temp_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

$pdo->exec($sql);
echo "All E-Log tables created or already exist.\n";

// Seed m_e_log_fish_type
$fishTypes = [
    1 => 'Tuna',
    2 => 'Carangids & Small Pelagics',
    3 => 'Billfish & Large Pelagics',
    4 => 'Swordfish & Marlins',
    5 => 'Sharks & Rays',
    6 => 'Seerfish & Small Tunas',
    7 => 'Protected Sharks & Marine Mammals',
    8 => 'Turtles & Endangered Species',
];

$stmtType = $pdo->prepare("INSERT INTO `m_e_log_fish_type` (`id`, `name`) VALUES (:id, :name) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)");
foreach ($fishTypes as $id => $name) {
    $stmtType->execute([':id' => $id, ':name' => $name]);
}
echo "Seeded " . count($fishTypes) . " fish types.\n";

// Seed m_e_log_fish_variant with EXACT IDs 1 to 54
$variants = [
    1  => [1, 'Albacore'],
    2  => [1, 'Bigeye tuna'],
    3  => [1, 'Other tuna'],
    4  => [1, 'Skipjack tuna'],
    5  => [1, 'Yellowfin tuna'],
    6  => [2, 'Bigeye scad'],
    7  => [2, 'Carangids'],
    8  => [2, 'Dolphin fish'],
    9  => [2, 'Indian mackerel'],
    10 => [2, 'Indian scad'],
    11 => [2, 'Needle cuttle fish'],
    12 => [2, 'Ocean trigger fish'],
    13 => [2, 'Other fish'],
    14 => [3, 'Rainbow runner'],
    15 => [3, 'Toothponies nei'],
    16 => [3, 'Trevally'],
    17 => [3, 'Black marlin'],
    18 => [3, 'Blue marlin'],
    19 => [3, 'Other bill fish'],
    20 => [4, 'Sailfish'],
    21 => [4, 'Shortbill spearfish'],
    22 => [4, 'Striped marlin'],
    23 => [4, 'Swordfish'],
    24 => [4, 'Blue shark'],
    25 => [4, 'Eagle rays nei'],
    26 => [5, 'Giant devil ray'],
    27 => [5, 'Mako shark'],
    28 => [5, 'Manta rays'],
    29 => [5, 'Mantas/devil ray nei'],
    30 => [5, 'Other rays'],
    31 => [5, 'Other sharks'],
    32 => [5, 'Rays/sting rays/mantas nei'],
    33 => [5, 'Silky shark'],
    34 => [5, 'Hammerhead shark'],
    35 => [5, 'Bullet tuna'],
    36 => [6, 'Frigate tuna'],
    37 => [6, 'Kawakawa'],
    38 => [6, 'Longtail tuna'],
    39 => [6, 'Narrow-barred Spanish mackerel'],
    40 => [6, 'Other neritic tuna'],
    41 => [6, 'Spanish mackerel'],
    42 => [6, 'Wahoo'],
    43 => [6, 'Thresher shark'],
    44 => [7, 'Other animals'],
    45 => [7, 'Dolphin'],
    46 => [7, 'Oceanic white tip shark'],
    47 => [8, 'Whale shark'],
    48 => [8, 'Green sea turtle'],
    49 => [8, 'Hawksbill sea turtle'],
    50 => [8, 'Loggerhead sea turtle'],
    51 => [8, 'Leatherback sea turtle'],
    52 => [8, 'Olive ridley sea turtle'],
    53 => [8, 'Flatback sea turtle'],
    54 => [7, 'Other marine mammals'],
];

$stmtVar = $pdo->prepare("INSERT INTO `m_e_log_fish_variant` (`id`, `fish_type_id`, `name`) VALUES (:id, :type_id, :name) ON DUPLICATE KEY UPDATE `fish_type_id` = VALUES(`fish_type_id`), `name` = VALUES(`name`)");
foreach ($variants as $id => [$typeId, $name]) {
    $stmtVar->execute([':id' => $id, ':type_id' => $typeId, ':name' => $name]);
}
echo "Seeded " . count($variants) . " fish variants.\n";
