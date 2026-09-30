-- BRVTAL Blog Trash v1
-- Additive, idempotent metadata for recoverable Blog deletion.
-- No rows are deleted or rewritten by this migration.

ALTER TABLE blog_posts
  ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER updated_at,
  ADD COLUMN IF NOT EXISTS deleted_by_admin_id INT UNSIGNED NULL AFTER deleted_at;

SET @blog_trash_index_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE()
    AND TABLE_NAME='blog_posts'
    AND INDEX_NAME='idx_blog_posts_trash'
);
SET @blog_trash_index_sql := IF(
  @blog_trash_index_exists=0,
  'CREATE INDEX idx_blog_posts_trash ON blog_posts(deleted_at,status,updated_at,id)',
  'SELECT 1'
);
PREPARE blog_trash_index_stmt FROM @blog_trash_index_sql;
EXECUTE blog_trash_index_stmt;
DEALLOCATE PREPARE blog_trash_index_stmt;
