"""Executable contract for #1634, with safe no-production file and behavior checks."""
from pathlib import Path
import json
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

def source(path):
    return (ROOT / path).read_text()

def php_eval(code):
    p = subprocess.run(["php", "-r", code], cwd=ROOT, capture_output=True, text=True, check=True)
    return json.loads(p.stdout)

class SetsAdminMultiArtistsTests(unittest.TestCase):
    def test_modal_resets_scroll_and_focus(self):
        src = source("discadmin/index-core.php")
        self.assertIn("if(box)box.scrollTop=0", src)
        self.assertIn("title.focus({preventScroll:true})", src)
        self.assertIn("requestAnimationFrame(()=>requestAnimationFrame", src)
        self.assertIn("id='saveKeepOpenBtn'", src)
        self.assertIn("SAVE & CLOSE", src)
        self.assertIn("aria-live=\"polite\"", src)
        js = src.split("<script>", 1)[1].split("</script>", 1)[0]
        p = subprocess.run(["node", "--check", "--input-type=commonjs"], input=js, text=True, capture_output=True)
        self.assertEqual(p.returncode, 0, p.stderr)

    def test_multi_artists_migration_and_public_visibility(self):
        ddl = source("database/migration_set_artists_01.sql")
        self.assertIn("CREATE TABLE IF NOT EXISTS set_artists", ddl)
        self.assertIn("PRIMARY KEY (set_id, artist_id)", ddl)
        self.assertIn("ON DELETE CASCADE", ddl)
        self.assertIn("ON DELETE RESTRICT", ddl)
        self.assertIn("migration_set_artists_01.sql", source("config/migration_reconcile.php"))
        self.assertIn("INSERT IGNORE INTO set_artists", source("config/migrations.php"))
        self.assertIn("brvtalSetArtistsBackfill($pdo)", source("config/migrations.php"))
        admin = source("api/index.php")
        self.assertIn("function brvtalSetArtistsInput(", admin)
        self.assertIn("function brvtalSetArtistsEnrich(", admin)
        self.assertIn("brvtalSetArtistsSync($pdo,$newId,$setArtistIds)", admin)
        self.assertIn("brvtalSetArtistsSync($pdo,$id,$setArtistIds)", admin)
        self.assertIn("SET_ARTISTS_SCHEMA_PENDING", admin)
        public = source("api/public.php")
        self.assertIn("JOIN artists a ON a.id=sa.artist_id AND a.status='published'", public)
        self.assertIn("$publicSet['artist_names']", public)
        self.assertIn("brvtal_public_sanitize_set_relations", public)
        # No production writes or network access are permitted in these tests.
        for path in ("api/index.php", "api/public.php", "config/migrations.php"):
            p = subprocess.run(["php", "-l", path], cwd=ROOT, capture_output=True, text=True)
            self.assertEqual(p.returncode, 0, p.stdout + p.stderr)

    def test_save_and_save_close_respect_status(self):
        admin = source("discadmin/admin-modules.js")
        self.assertIn("status:fieldValue('status') || 'draft'", admin)
        self.assertIn("artist_ids:typeof selectedSetArtistIds", admin)
        self.assertIn("const keepOpen=type==='sets' && options.close===false", admin)
        self.assertIn("if (keepOpen) {", admin)
        self.assertIn("if (typeof closeModal === 'function') closeModal(true)", admin)
        self.assertIn("if (type==='sets' && setSavePending) return", admin)
        self.assertIn("draftState?.keepOpen", admin)
        self.assertIn("editorCurrent()", admin)
        self.assertIn("await req('/sets/'", admin)
        drafts = source("discadmin/legacy-editor-drafts.js")
        self.assertIn("artist_ids:artistIds", drafts)
        self.assertIn("const order = new Map(restoredIds", drafts)
        self.assertIn("renderSetArtistChips()", drafts)
        self.assertIn("button.textContent='SAVE & CLOSE'", source("discadmin/admin-form-dialogs.js"))

    def test_bulk_delete_is_transactional_and_safe(self):
        api = source("api/bulk-actions.php")
        lib = source("api/bulk-actions-lib.php")
        self.assertIn("brvtal_admin_require_csrf()", api)
        self.assertIn("brvtal_bulk_delete_sets($pdo,$request,$audit)", api)
        self.assertIn("SELECT * FROM sets_media WHERE id IN", lib)
        self.assertIn("FOR UPDATE", lib)
        self.assertIn("blog_post_relations", lib)
        self.assertIn("memory_relations", lib)
        self.assertIn("BULK_SET_REFERENCED", lib)
        self.assertIn("if ($pdo->inTransaction()) $pdo->rollBack()", lib)
        self.assertIn("if ($audit) foreach ($rows as $row)", lib)
        out = php_eval("""require 'api/bulk-actions-lib.php';
          $accepted=brvtal_bulk_normalize_request(['action'=>'delete_selected','resource'=>'sets','ids'=>[3,'4',3]]);
          $rejected=[];
          foreach ([
            ['action'=>'delete_selected','resource'=>'artists','ids'=>[3]],
            ['action'=>'delete_selected','resource'=>'sets','ids'=>[]],
            ['action'=>'delete_selected','resource'=>'sets','ids'=>[0]],
          ] as $input) {
            try {brvtal_bulk_normalize_request($input);$rejected[]=false;}
            catch (InvalidArgumentException $e) {$rejected[]=$e->getMessage();}
          }
          echo json_encode(compact('accepted','rejected'));""")
        self.assertEqual(out["accepted"]["ids"], [3,4])
        self.assertEqual(out["accepted"]["action"], "delete_selected")
        self.assertEqual(out["rejected"], ["INVALID_BULK_ACTION","BULK_IDS_REQUIRED","INVALID_BULK_ID"])
        ui = source("discadmin/bulk-actions.js")
        self.assertIn("DELETE SELECTED", ui)
        self.assertIn("window.confirm(message)", ui)
        self.assertIn("action:'delete_selected'", ui)

    def test_artist_names_are_rendered_without_ids(self):
        js = source("discadmin/admin-data-grid.js")
        self.assertIn("label:'ARTISTS'", js)
        self.assertIn("Array.isArray(r.artist_names)", js)
        self.assertIn("names.map(name=>'<span", js)
        self.assertIn("Sin artista", js)
        self.assertNotIn("r.artist_name||r.artist_id", js)
        self.assertNotIn("x.artist_name||x.artist_id", source("discadmin/index-core.php"))
        picker = source("discadmin/index-core.php")
        self.assertIn("data-set-artist", picker)
        self.assertIn("id=\"setArtistChips\"", picker)
        self.assertIn("id=\"setArtistSearch\"", picker)

    def test_ordering_accessible_and_recoverable(self):
        order = source("discadmin/content-ordering.js")
        self.assertIn("function moveByButton(", order)
        self.assertIn("'data-order-move'", order.replace("dataset.orderMove","'data-order-move'"))
        self.assertIn("button.disabled=!enabled || boundary", order)
        self.assertIn("void save(container,previous)", order)
        self.assertIn("restore(container, previousIds)", order)
        self.assertIn("await persist(resource, orderedIds, previousIds)", order)
        self.assertIn("aria-label", order)
        self.assertIn("prefers-reduced-motion:reduce", source("discadmin/content-ordering.css"))

if __name__ == "__main__":
    unittest.main()
