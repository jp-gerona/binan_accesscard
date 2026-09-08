-- v23: the Informal Worker sector.
--
-- The Cluster1 survey files use IW as the most common sector code (4,863 rows
-- in the real workbook), but the sector table has no such row, so every one of
-- them fell back to the OTHER catch-all on import. The office confirmed the
-- category is real. Data-only change: no schema, no column.
--
-- Idempotent: safe to re-run against a database that already has the row.

INSERT INTO `sector` (`shortcode`, `name`, `description`)
SELECT 'IW', 'Informal Worker',
        'Informal workers: seasonal, contractual, or self-employed persons without formal employment registration'
WHERE NOT EXISTS (
    SELECT 1 FROM `sector` WHERE `shortcode` = 'IW'
);
