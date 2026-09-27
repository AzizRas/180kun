-- Отдельное явное согласие на обработку данных о здоровье (Р-19):
-- отдельным экраном, а не галочкой в оферте. Без него анкета не сохраняется.
ALTER TABLE onboarding_profiles ADD COLUMN consent_health_at TEXT NULL;
