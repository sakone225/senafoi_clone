-- Remplacez NOM_DE_LA_TABLE_MEMBRES par la table detectee/utilisee par api/membres.php.
-- Si l'utilisateur MySQL de l'API a le droit ALTER, membres.php tente aussi de creer
-- automatiquement ces colonnes au premier envoi de notification.
ALTER TABLE `NOM_DE_LA_TABLE_MEMBRES`
  ADD COLUMN card_notified_at DATETIME NULL,
  ADD COLUMN card_notified_by VARCHAR(100) NULL;
