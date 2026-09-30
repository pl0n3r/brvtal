import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def source(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


class BlogTrashTests(unittest.TestCase):
    def test_schema_is_additive_and_preserves_relations(self):
        migration = source("database/migration_blog_trash_01.sql")
        self.assertIn("ADD COLUMN IF NOT EXISTS deleted_at", migration)
        self.assertIn("ADD COLUMN IF NOT EXISTS deleted_by_admin_id", migration)
        self.assertIn("idx_blog_posts_trash", migration)
        self.assertNotRegex(migration, r"(?i)\\b(?:DROP|TRUNCATE|DELETE\\s+FROM|UPDATE\\s+blog_posts)\\b")

        base = source("database/migration_blog_01.sql")
        self.assertIn("FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE", base)
        api = source("api/blog.php")
        self.assertIn("SET deleted_at=CURRENT_TIMESTAMP,deleted_by_admin_id=?", api)
        self.assertIn("WHERE id=? AND deleted_at IS NULL", api)

    def test_soft_delete_and_restore_preserve_identity_and_audit(self):
        api = source("api/blog.php")
        self.assertIn("'UPDATE blog_posts", api)
        self.assertIn("SET deleted_at=NULL,deleted_by_admin_id=NULL", api)
        self.assertIn("brvtal_blog_fetch($pdo, $id, true)", api)
        self.assertIn("brvtal_blog_fetch($pdo, $id, false)", api)
        self.assertIn("$pdo,'trash','blog',$id,$before,$after", api)
        self.assertIn("$pdo,'restore','blog',$id,$before,$after", api)
        self.assertIn("brvtalIndexNowNotifyChange($pdo, 'blog', $before, null)", api)
        self.assertIn("brvtalIndexNowNotifyChange($pdo, 'blog', null, $after)", api)

    def test_public_and_normal_admin_reads_exclude_trashed_posts(self):
        api = source("api/blog.php")
        public = source("api/public.php")
        self.assertIn("$condition = $trashed ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';", api)
        self.assertIn("brvtal_public_column_exists($pdo, 'blog_posts', 'deleted_at')", public)
        self.assertEqual(public.count("deleted_at IS NULL"), 3)
        self.assertIn("WHERE status='published' AND deleted_at IS NULL", public)
        self.assertEqual(public.count("p.status='published' AND p.deleted_at IS NULL"), 2)

    def test_discadmin_has_distinct_trash_restore_flow(self):
        fragment = source("discadmin/blog.php")
        js = source("discadmin/blog.js")
        css = source("discadmin/blog.css")
        for marker in ("blog-active-view", "blog-trash-view", "blog-trash-count"):
            self.assertIn(marker, fragment)
        self.assertIn("request('?trash=1')", js)
        self.assertIn("method:'PATCH'", js)
        self.assertIn("JSON.stringify({action:'restore'})", js)
        self.assertIn("deleted_by_name", js)
        self.assertIn("data-permanent-delete", js)
        self.assertIn(".blog-row-trash", css)

    def test_permanent_delete_requires_trashed_state_and_confirmation(self):
        api = source("api/blog.php")
        js = source("discadmin/blog.js")
        self.assertIn("if ($permanent)", api)
        self.assertIn("DELETE FROM blog_posts WHERE id=? AND deleted_at IS NOT NULL", api)
        self.assertIn("hash_equals((string)$before['slug'], trim($confirmSlug))", api)
        self.assertIn("BLOG_PERMANENT_DELETE_CONFIRMATION_REQUIRED", api)
        self.assertIn("confirm_slug:typed.trim()", js)
        self.assertIn("Type the exact slug to delete forever", js)
        self.assertNotIn("setInterval(", js)

    def test_retention_scope_and_version_contract(self):
        spec = source("docs/BRVTAL-SPEC.md")
        version = source("config/version.php")
        package = source("package.json")
        self.assertIn("retention target is **30 days**", spec)
        self.assertIn("does **not** schedule or execute automatic purge", spec)
        self.assertIn("Media remains outside Blog Trash semantics", spec)
        self.assertIn("BRVTAL_APP_VERSION = '0.1.88'", version)
        self.assertRegex(package, r'"version":\\s*"0\\.1\\.88"')


if __name__ == "__main__":
    unittest.main()
