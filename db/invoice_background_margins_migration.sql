-- ============================================================
-- Company profiles: letterhead background printable-area insets
-- Adds configurable margin (mm) so invoice content can sit inside
-- the letterhead design when a full-page background image is used.
-- Safe to re-run.
-- ============================================================

ALTER TABLE `company_profiles`
  ADD COLUMN IF NOT EXISTS `bg_margin_top` decimal(5,2) NOT NULL DEFAULT '45.00' COMMENT 'Printable-area inset (mm) when letterhead background is used' AFTER `tax_rate`,
  ADD COLUMN IF NOT EXISTS `bg_margin_bottom` decimal(5,2) NOT NULL DEFAULT '20.00' AFTER `bg_margin_top`,
  ADD COLUMN IF NOT EXISTS `bg_margin_side` decimal(5,2) NOT NULL DEFAULT '12.00' AFTER `bg_margin_bottom`;
