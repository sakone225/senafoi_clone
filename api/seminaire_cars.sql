CREATE TABLE IF NOT EXISTS `seminaire_cars` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL,
  `nom` VARCHAR(120) NOT NULL,
  `capacite` INT UNSIGNED NOT NULL DEFAULT 0,
  `description` TEXT DEFAULT NULL,
  `annee` INT NOT NULL DEFAULT 2026,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seminaire_cars_code_annee` (`code`, `annee`),
  KEY `idx_seminaire_cars_annee` (`annee`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exemples a adapter dans l'admin si besoin.
INSERT IGNORE INTO `seminaire_cars` (`code`, `nom`, `capacite`, `description`, `annee`)
VALUES
  ('CAR_01', 'Car 01', 70, 'Convoi SENAFOI 2026', 2026),
  ('CAR_02', 'Car 02', 70, 'Convoi SENAFOI 2026', 2026),
  ('CAR_03', 'Car 03', 70, 'Convoi SENAFOI 2026', 2026);
