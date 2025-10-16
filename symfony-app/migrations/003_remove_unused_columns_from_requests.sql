-- Supprimer les colonnes request_type et priority de la table requests
-- Ces informations sont maintenant gérées par l'agent Analyzer dans la table analyses

ALTER TABLE maestro.requests
DROP COLUMN IF EXISTS request_type,
DROP COLUMN IF EXISTS priority;

-- Commentaire pour documentation
COMMENT ON TABLE maestro.requests IS 'Demandes brutes des utilisateurs avant analyse IA. Les métadonnées (type, priorité) sont déterminées par l''agent Analyzer et stockées dans maestro.analyses';
