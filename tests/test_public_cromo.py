#!/usr/bin/env python3
"""Contract for the Cromo layer: a WebGL chrome backdrop over Concept 05.

The layer is progressive enhancement. These checks keep it from regressing
into a hard dependency: every visual rule is gated by `html.cromo`, the class
is only added after the shader compiled, and constrained devices, Save-Data and
reduced motion never pay for or are forced into the effect.
"""
from __future__ import annotations

import hashlib
import re
import struct
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = (ROOT / "css/public-cromo.css").read_text(encoding="utf-8")
JS = (ROOT / "js/public-cromo.js").read_text(encoding="utf-8")
HOME = (ROOT / "config/public_home.php").read_text(encoding="utf-8")
FONT = ROOT / "assets/fonts/BrokenGlass.ttf"
LICENSE = ROOT / "assets/fonts/BrokenGlass-LICENSE.txt"
CSP = (ROOT / ".htaccess").read_text(encoding="utf-8")


def selectors(css: str) -> list[str]:
    stripped = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    stripped = re.sub(r"@font-face\s*\{[^{}]*\}", "", stripped, flags=re.S)
    found: list[str] = []
    for match in re.finditer(r"([^{}@]+)\{", stripped):
        prelude = match.group(1).strip()
        at_rule = re.match(r"(media|keyframes)\b", prelude)
        if prelude and not at_rule and not re.fullmatch(r"(from|to|\d+%)", prelude):
            found.extend(
                part.strip()
                for part in re.split(r",(?![^(]*\))", prelude)
                if not re.fullmatch(r"\s*(from|to|\d+%)\s*", part)
            )
    return found


class CromoLayerTests(unittest.TestCase):
    def test_every_rule_is_gated_by_the_cromo_class(self) -> None:
        for selector in selectors(CSS):
            self.assertTrue(
                selector.startswith("html.cromo"),
                f"ungated selector would change the non-WebGL look: {selector}",
            )

    def test_class_is_added_only_after_shader_and_context_checks(self) -> None:
        for needle in (
            "getContext('webgl'",
            "COMPILE_STATUS",
            "LINK_STATUS",
            "root.classList.add('cromo')",
            "webglcontextlost",
        ):
            self.assertIn(needle, JS)
        self.assertLess(JS.index("COMPILE_STATUS"), JS.index("root.classList.add('cromo')"))

    def test_constrained_and_reduced_motion_paths_are_respected(self) -> None:
        for needle in (
            "saveData",
            "deviceMemory",
            "hardwareConcurrency",
            "prefers-reduced-motion: reduce",
            "document.hidden",
        ):
            self.assertIn(needle, JS)
        self.assertIn("prefers-reduced-motion:reduce", CSS)

    def test_accent_follows_theme_studio_red(self) -> None:
        self.assertIn("'--red'", JS)
        self.assertIn("var(--red", CSS)

    def test_canvas_is_decorative_and_non_interactive(self) -> None:
        self.assertIn("aria-hidden", JS)
        self.assertRegex(CSS, r"#cromoGL\{[^}]*pointer-events:none")

    def test_dark_hero_plates_do_not_use_important(self) -> None:
        self.assertNotIn("!important", CSS)
        self.assertIn("html.cromo body main#top .home-phase-a-hero .hero-copy .hero-sub", CSS)

    def test_title_entrance_never_hides_text(self) -> None:
        self.assertNotIn("opacity:0", CSS)
        self.assertNotIn("clip-path", CSS)
        self.assertNotIn("visibility:hidden", CSS)

    def test_home_injects_assets_once(self) -> None:
        self.assertEqual(HOME.count("css/public-cromo.css"), 2)
        self.assertEqual(HOME.count("js/public-cromo.js"), 2)
        self.assertIn("defer", HOME[HOME.rindex("js/public-cromo.js"):][:60])

    def test_version_bumped_for_deploy_bound_change(self) -> None:
        version = re.search(
            r"BRVTAL_APP_VERSION = '([^']+)'",
            (ROOT / "config/version.php").read_text(encoding="utf-8"),
        )
        package = re.search(
            r'"version": "([^"]+)"', (ROOT / "package.json").read_text(encoding="utf-8")
        )
        self.assertIsNotNone(version)
        self.assertEqual(version.group(1), package.group(1))


    def test_broken_glass_is_original_and_hosted_with_license(self) -> None:
        self.assertTrue(FONT.is_file(), "Broken Glass must be served locally")
        data = FONT.read_bytes()
        self.assertEqual(data[:4], bytes((0, 1, 0, 0)))
        self.assertEqual(
            hashlib.sha256(data).hexdigest(),
            "ac64e57f8ce4ea8a4a4e05d6b42e10e8d34f9668600573df7ba9a985af2e9993",
        )
        self.assertTrue(LICENSE.is_file())
        self.assertIn("This font is in the public domain.", LICENSE.read_text(encoding="utf-8"))
        face = re.search(r"@font-face\s*\{([^{}]*)\}", CSS)
        self.assertIsNotNone(face)
        self.assertIn('font-family:"Broken Glass"', face.group(1))
        self.assertIn('src:url("../assets/fonts/BrokenGlass.ttf") format("truetype")', face.group(1))
        self.assertIn("font-display:swap", face.group(1))
        self.assertIn("font-src 'self'", CSP)

    def test_broken_glass_font_has_accents_and_spanish_punctuation(self) -> None:
        """Read the Unicode TrueType cmap without a nonstandard test dependency."""
        data = FONT.read_bytes()
        u16 = lambda offset: struct.unpack_from(">H", data, offset)[0]
        count = u16(4)
        table_offsets = {
            data[12 + 16 * i:16 + 16 * i]:
            struct.unpack_from(">I", data, 20 + 16 * i)[0]
            for i in range(count)
        }
        cmap = table_offsets[b"cmap"]
        records = [
            struct.unpack_from(">HHI", data, cmap + 4 + 8 * i)
            for i in range(u16(cmap + 2))
        ]
        subtable = next(
            cmap + offset for platform, encoding, offset in records
            if platform == 3 and encoding == 1 and u16(cmap + offset) == 4
        )
        segments = u16(subtable + 6) // 2
        ends = subtable + 14
        starts = ends + 2 * segments + 2
        deltas = starts + 2 * segments
        ranges = deltas + 2 * segments

        def glyph(codepoint: int) -> int:
            for i in range(segments):
                first, last = u16(starts + 2 * i), u16(ends + 2 * i)
                if first <= codepoint <= last:
                    delta = struct.unpack_from(">h", data, deltas + 2 * i)[0]
                    range_offset = u16(ranges + 2 * i)
                    if not range_offset:
                        return (codepoint + delta) & 0xFFFF
                    index = u16(ranges + 2 * i + range_offset + 2 * (codepoint - first))
                    return (index + delta) & 0xFFFF if index else 0
            return 0

        for character in "BRVTALÁÉÍÓÚáéíóúñÑ¿¡":
            self.assertNotEqual(glyph(ord(character)), 0, f"missing glyph {character}")

    def test_broken_glass_is_only_used_by_cromo_display_headings(self) -> None:
        no_face = re.sub(r"@font-face\s*\{[^{}]*\}", "", CSS, flags=re.S)
        no_comments = re.sub(r"/\*.*?\*/", "", no_face, flags=re.S)
        used = []
        for match in re.finditer(r"([^{}]+)\{([^{}]*)\}", no_comments):
            if 'font-family:"Broken Glass"' in match.group(2):
                used.extend(s.strip() for s in match.group(1).split(","))
        self.assertEqual(set(used), {
            "html.cromo .home-phase-a-hero .hero-title",
            "html.cromo main#top section.scene h2",
        })
        self.assertNotRegex(CSS, r"html\.cromo\s+body\s*\{[^}]*font-family")

    def test_mobile_broken_glass_headers_stack_labels_in_cromo_only(self) -> None:
        """At 390px the Cromo headings and labels must occupy separate flex rows."""
        self.assertEqual(CSS.count("@media(max-width:700px){"), 1)
        mobile = CSS.split("/* Narrow Cromo section headers:", 1)[1]
        self.assertIn("@media(max-width:700px){", mobile)
        for selector in (
            "html.cromo body main#top .section-head",
            "html.cromo body main#top .media-title",
            "html.cromo body main#top .section-head h2",
            "html.cromo body main#top .media-title h2",
            "html.cromo body main#top .section-head > span:last-child",
            "html.cromo body main#top .media-title > span:last-child",
        ):
            self.assertIn(selector, mobile)
        for rule in (
            "display:flex", "flex-direction:column", "align-items:flex-start",
            "gap:8px", "order:2", "font-size:clamp(38px,10vw,60px)",
            "line-height:1", "overflow-wrap:anywhere", "display:inline-flex",
            "order:3", "white-space:normal",
        ):
            self.assertIn(rule, mobile)
        # Legacy style.css hides the final header label below 900px.
        label_rule = mobile.split(
            "html.cromo body main#top .section-head > span:last-child", 1
        )[1].split("}", 1)[0]
        self.assertIn("display:inline-flex", label_rule)
        self.assertNotIn("!important", mobile)

    def test_mobile_night_badges_outrank_corner_decoration(self) -> None:
        mobile = CSS.split("/* Narrow Cromo section headers:", 1)[1]
        stamp = mobile.split(".event-card.c5-night-card::after{", 1)[1].split("}", 1)[0]
        info = mobile.split(".c5-night-card .event-info{", 1)[1].split("}", 1)[0]
        labels = mobile.split(".c5-night-card .event-status{", 1)[1].split("}", 1)[0]
        artwork = mobile.split(".c5-experience-artwork-label{", 1)[1].split("}", 1)[0]
        self.assertIn("z-index:1", stamp)
        self.assertIn("z-index:5", info)
        self.assertIn("z-index:6", labels)
        self.assertIn("z-index:6", artwork)
        self.assertIn("pointer-events:none", stamp)
        self.assertIn("white-space:normal", labels)

    def test_mobile_wordmark_has_responsive_cromo_only_width(self) -> None:
        self.assertIn("@media(max-width:600px)", CSS)
        mobile = CSS.split("/* Compact display glyphs fill the mobile hero")[1]
        self.assertIn('html.cromo [data-concept="05"] .home-phase-a-hero .hero-title{', mobile)
        for rule in (
            "width:100%", "max-width:100%", "min-width:0",
            "font-size:clamp(110px,46vw,260px)", "word-break:normal",
            "overflow-wrap:normal", "white-space:nowrap",
        ):
            self.assertIn(rule, mobile)

REDESIGN = (ROOT / "css/public-cromo-redesign.css").read_text(encoding="utf-8")
SOUND = (ROOT / "js/public-cromo-sound.js").read_text(encoding="utf-8")


class CromoRedesignTests(unittest.TestCase):
    def test_every_redesign_rule_is_gated_by_the_cromo_class(self) -> None:
        for selector in selectors(REDESIGN):
            self.assertTrue(selector.startswith("html.cromo"), f"ungated selector: {selector}")

    def test_palette_is_one_red_with_readable_contrast(self) -> None:
        self.assertIn("--cromo-red:#e12e34", REDESIGN)
        for variable in ("--acid:", "--signal:", "--c5-signal-green:"):
            self.assertIn(variable, REDESIGN)

    def test_hero_animation_never_hides_text(self) -> None:
        self.assertNotIn("opacity:0", REDESIGN)
        self.assertNotIn("visibility:hidden", REDESIGN)
        self.assertNotIn("clip-path", REDESIGN)
        self.assertIn("cromo-l", REDESIGN)
        self.assertIn("aria-label", JS)
        self.assertIn("reduceMotion.matches", JS)

    def test_sound_media_uses_blob_urls_allowed_by_the_production_csp(self) -> None:
        # CSP media-src is 'self' blob: https: (no data:): a data: <audio> is blocked.
        self.assertNotIn("new Audio(SILENT_WAV)", SOUND)
        self.assertEqual(2, SOUND.count("new Audio(silentClip())"))
        self.assertIn("URL.createObjectURL(new Blob([bytes]", SOUND)

    def test_sound_only_starts_after_a_click_and_is_accessible(self) -> None:
        self.assertIn("addEventListener('click', toggle)", SOUND)
        self.assertIn("aria-pressed", SOUND)
        self.assertIn("Activar sonido", SOUND)
        self.assertIn("Apagar el pulso", SOUND)
        self.assertIn("visibilitychange", SOUND)
        self.assertIn("clearInterval", SOUND)
        code = re.sub(r"/\*.*?\*/", "", SOUND, flags=re.S)
        self.assertNotIn("autoplay", code.lower())
        # The audio context is created inside start(), never at load.
        self.assertLess(SOUND.index("const start"), SOUND.index("new AudioCtx()") + 200)
        self.assertNotRegex(SOUND, r"^\s*start\(\);", re.M)

    def test_sound_button_meets_touch_target_and_nav_hook(self) -> None:
        self.assertRegex(REDESIGN, r"\.cromo-sound\{[^}]*min-height:44px")
        self.assertIn("header.nav .nav-right", SOUND)

    def test_redesign_assets_are_injected_on_home_and_contact(self) -> None:
        contact = (ROOT / "config/public_contact_page.php").read_text(encoding="utf-8")
        self.assertIn("css/public-cromo-redesign.css", HOME)
        self.assertIn("js/public-cromo-sound.js", HOME)
        self.assertIn("public-cromo-redesign.css", contact)
        self.assertIn("public-cromo.js", contact)

    def test_beat_bridge_between_sound_and_backdrop(self) -> None:
        self.assertIn("cromo:beat", SOUND)
        self.assertIn("cromo:beat", JS)


HOME_CSS = (ROOT / "css/public-cromo-home.css").read_text(encoding="utf-8")
HOME_JS = (ROOT / "js/public-cromo-home.js").read_text(encoding="utf-8")


class CromoHomeTests(unittest.TestCase):
    def test_home_css_is_scoped_to_the_mounted_root(self) -> None:
        for selector in selectors(HOME_CSS):
            self.assertTrue(
                selector.startswith("#cromo-home") or selector.startswith("html.cromo-home"),
                f"unscoped selector: {selector}",
            )

    def test_home_classes_are_prefixed_to_avoid_legacy_collisions(self) -> None:
        for legacy in (".hero{", ".sound{", ".sets{", ".artists{", ".journal{", ".btn{"):
            self.assertNotIn(legacy, HOME_CSS.replace(" ", ""))
        self.assertIn("'cr-' + c", HOME_JS)
        self.assertNotIn("id: 'top'", HOME_JS)

    def test_home_uses_installed_fonts_only(self) -> None:
        self.assertIn('"Broken Glass"', HOME_CSS)
        self.assertIn('"Barlow Condensed"', HOME_CSS)
        self.assertNotIn("Rubik Mono One", HOME_CSS)
        self.assertNotIn("fonts.googleapis.com", HOME_CSS)

    def test_home_inserts_cms_text_without_html(self) -> None:
        code = re.sub(r"/\*.*?\*/", "", HOME_JS, flags=re.S)
        self.assertNotIn("innerHTML", code)
        self.assertNotIn("outerHTML", code)
        self.assertNotIn("insertAdjacentHTML", code)
        self.assertIn("textContent", code)

    def test_home_falls_back_to_the_previous_home(self) -> None:
        self.assertIn("/api/public.php", HOME_JS)
        self.assertIn("keep the Concept 05 home", HOME_JS)
        self.assertIn("catch", HOME_JS)
        self.assertIn("root.classList.add('cromo-home')", HOME_JS)

    def test_home_keeps_the_real_functions(self) -> None:
        for needle in ("/api/contact.php", "captcha_token", "captcha_answer", "'/privacy'", "BRVTALCromoSound", "aria-pressed"):
            self.assertIn(needle, HOME_JS)
        self.assertIn("Saltar al contenido", HOME_JS)

    def test_home_assets_are_injected(self) -> None:
        self.assertIn("css/public-cromo-home.css", HOME)
        self.assertIn("js/public-cromo-home.js", HOME)

    def test_sets_section_has_a_real_empty_state(self) -> None:
        self.assertIn("Los próximos sets aparecerán aquí cuando estén publicados.", HOME_JS)
        self.assertIn("id: 'discos'", HOME_JS)

    def test_legacy_archive_links_land_on_the_new_home_archive(self) -> None:
        # #events and #archive no longer fall back to the old home: they scroll to Noches / Últimas noches.
        self.assertNotIn("['#events', '#archive'].includes", HOME_JS)
        self.assertIn("'#events': 'noches'", HOME_JS)
        self.assertIn("'#archive': 'archivo'", HOME_JS)
        self.assertIn("Últimas noches", HOME_JS)
        self.assertIn("data.archive && data.archive.events", HOME_JS)
        self.assertIn("route", "route")  # the archive cards link to each night's own page
        self.assertIn("'/events/' + encodeURIComponent(event.slug)", HOME_JS)
        self.assertNotIn("innerHTML", HOME_JS)

    def test_home_respects_reduced_motion(self) -> None:
        self.assertIn("prefers-reduced-motion:reduce", HOME_CSS)
        self.assertIn("reduce.matches", HOME_JS)


class CromoHomeKeepsEverySectionTests(unittest.TestCase):
    """Identical to the artifact never means dropping a section: Experiencia and Conectado stay."""

    JS = (ROOT / "js/public-cromo-home.js").read_text(encoding="utf-8")
    CSS = (ROOT / "css/public-cromo-home.css").read_text(encoding="utf-8")

    def test_experiencia_and_conectado_are_built_from_real_data_in_the_main_flow(self) -> None:
        self.assertIn("buildExperience(next, artists, data.relations || {})", self.JS)
        self.assertIn("buildConnected(data)", self.JS)
        order = re.search(r"el\('main', \{ id: 'cromo-top' \}, ([^)]*)\)", self.JS)
        self.assertIsNotNone(order)
        parts = [p.strip() for p in order.group(1).split(",")]
        for before, after in (("artistsSec", "experience"), ("experience", "sound"),
                              ("journalSec", "connected"), ("connected", "end")):
            self.assertLess(parts.index(before), parts.index(after), parts)

    def test_both_sections_are_spanish_and_only_use_published_relations(self) -> None:
        for phrase in ("Próxima experiencia", "Comprar tickets", "Ver la noche", "Toca una noche, un artista o un set",
                       "Noches", "Artistas", "Sonido", "Quitar selección"):
            self.assertIn(phrase, self.JS)
        self.assertNotRegex(self.JS, r"NEXT EXPERIENCE|VERIFIED RELATIONAL|ENTER EXPERIENCE")
        self.assertIn("adjacency", self.JS)          # links come from the published relations, never invented
        self.assertIn("entities.has(a)", self.JS)    # and only between items that are actually published
        self.assertNotIn("innerHTML", self.JS)
    def test_section_styles_are_scoped_and_have_a_mobile_layout(self) -> None:
        for sel in selectors(self.CSS):
            if "-xp" in sel or "-cn2" in sel:
                self.assertTrue(sel.strip().startswith("#cromo-home"), sel)
        self.assertRegex(self.CSS, r"@media\(max-width:900px\)\{[^@]*cr-xp")
        self.assertRegex(self.CSS, r"@media\(max-width:700px\)\{[^@]*cr-cn2-board\{grid-template-columns:1fr")
        self.assertIn("prefers-reduced-motion", self.CSS)


class CromoConnectedMemoriesTests(unittest.TestCase):
    """A fourth column exists only for explicit published Memory relations."""

    JS = (ROOT / "js/public-cromo-home.js").read_text(encoding="utf-8")
    CSS = (ROOT / "css/public-cromo-home.css").read_text(encoding="utf-8")

    def test_memory_edges_require_real_visible_published_targets(self) -> None:
        for name, prefix in (("event", "e"), ("artist", "a"), ("set", "s"), ("release", "r")):
            self.assertIn("['" + name + "', '" + prefix + "']", self.JS)
        self.assertIn("Array.isArray(memory.relations)", self.JS)
        self.assertIn("relation?.related_type", self.JS)
        self.assertIn("relation?.related_id", self.JS)
        self.assertIn("Number.isSafeInteger(id) && id > 0", self.JS)
        self.assertIn(".filter((key) => key && entities.has(key))", self.JS)
        self.assertIn("if (!links.length) return;", self.JS)
        self.assertIn("links.forEach((target) => join(key, target))", self.JS)
        self.assertIn("add('memorias', key, 'memoria'", self.JS)
        self.assertIn("'#memorias'", self.JS)

    def test_without_links_three_columns_and_same_intro_remain(self) -> None:
        self.assertIn("noches: [], artistas: [], sonido: [], memorias: []", self.JS)
        self.assertIn("if (columns.memorias.length) board.classList.add('cr-has-memories')", self.JS)
        self.assertIn("Object.keys(columns).filter((name) => columns[name].length)", self.JS)
        self.assertIn("Toca una noche, un artista o un set y mira con qué se conecta.", self.JS)
        self.assertIn("grid-template-columns:repeat(3,minmax(0,1fr))", self.CSS)

    def test_real_memory_labels_and_contextual_sentences(self) -> None:
        for fragment in ("memorias: 'Memorias'", "memoria: 'Ver la memoria'",
                         "plural(memories.length, 'memoria', 'memorias')",
                         "'Quedó registrada'", "'Aparece'", "'Recuerda '",
                         "'Conecta con '", "'Incluye '"):
            self.assertIn(fragment, self.JS)
        self.assertIn("const withMemories = (message) => memoryText ? message + ' ' + memoryText : message", self.JS)
        self.assertNotIn("innerHTML", self.JS)

    def test_four_column_desktop_stacked_mobile_and_static_reduced_motion(self) -> None:
        self.assertIn(".cr-cn2-board.cr-has-memories{grid-template-columns:repeat(4,minmax(0,1fr))", self.CSS)
        self.assertIn("@media(max-width:1050px)", self.CSS)
        self.assertIn(".cr-cn2-board.cr-has-memories{grid-template-columns:repeat(2,minmax(0,1fr))}", self.CSS)
        self.assertIn("@media(max-width:700px)", self.CSS)
        self.assertIn(".cr-cn2-board.cr-has-memories{grid-template-columns:1fr}", self.CSS)
        self.assertIn("#cromo-home .cr-cn2-lines{display:none}", self.CSS)
        self.assertIn("@media(prefers-reduced-motion:reduce)", self.CSS)
        self.assertIn(".cr-cn2-path{stroke-dashoffset:0}", self.CSS)
        self.assertIn(".cr-cn2-path,#cromo-home .t-text-soft", self.CSS)


class CromoMotionTests(unittest.TestCase):
    """Motion follows the transitions.dev scale and the connected graph is an interactive control."""

    FILES = ("css/public-cromo-home.css", "css/public-cromo-pages.css", "css/public-cromo-redesign.css")
    JS = (ROOT / "js/public-cromo-home.js").read_text(encoding="utf-8")
    HOME = (ROOT / "css/public-cromo-home.css").read_text(encoding="utf-8")

    def test_transitions_and_one_shot_animations_use_the_shared_motion_tokens(self) -> None:
        for path in self.FILES:
            css = re.sub(r"/\*.*?\*/", "", (ROOT / path).read_text(encoding="utf-8"), flags=re.S)
            for decl in re.findall(r"(?<![-\w])(?:transition|animation)\s*:\s*([^;}]+)", css):
                if "infinite" in decl or decl.strip() == "none":
                    continue  # looping ambience (float, roll, spin, pulse) keeps its own cadence
                stripped = re.sub(r"var\([^)]*\)", "", decl)
                self.assertNotRegex(re.sub(r"\b0m?s\b", "", stripped), r"\b\d*\.?\d+m?s\b", path + ": " + decl)  # a literal 0s (no delay) is fine
                self.assertNotRegex(stripped, r"cubic-bezier|\bease\b", path + ": " + decl)

    def test_motion_tokens_exist_and_match_the_scale(self) -> None:
        for token, value in (("--duration-quick", "150ms"), ("--duration-fast", "250ms"),
                             ("--duration-slow", "400ms"), ("--duration-very-slow", "500ms"),
                             ("--ease-smooth-out", "cubic-bezier(.22,1,.36,1)")):
            self.assertIn(token + ":" + value, self.HOME.replace(" ", ""), token)

    def test_connected_explorer_is_interactive_and_accessible(self) -> None:
        self.assertIn("'aria-pressed'", self.JS)
        self.assertIn("class: 'cn2-item', type: 'button'", self.JS)   # every item is a real button
        self.assertIn("softSwap(headingText", self.JS)
        self.assertIn("softSwap(sentenceText", self.JS)
        self.assertIn("event.key === 'Escape'", self.JS)
        self.assertIn("matchMedia('(prefers-reduced-motion: reduce)')", self.JS)
        self.assertIn("createElementNS(SVG_NS", self.JS)               # the lines are drawn, not faked with images
        self.assertNotIn("innerHTML", self.JS)
    def test_explorer_and_swap_have_reduced_motion_fallbacks(self) -> None:
        block = "".join(self.HOME[m.start():] for m in re.finditer("prefers-reduced-motion", self.HOME))
        for needle in ("cr-cn2-item", "cr-cn2-path", "t-text-soft"):
            self.assertIn(needle, block)
    def test_titles_get_the_red_glitch_only_through_data_text_layers(self) -> None:
        self.assertIn("dataset.text = text(title.textContent)", self.JS)
        self.assertIn("cr-glitch", self.JS)
        self.assertIn("content:attr(data-text)", self.HOME.replace(" ", ""))
        # The glitch is the site's red (never the old lime): a short burst on a long cycle that never hides the text.
        self.assertNotIn("#b6ff00", self.HOME)
        self.assertRegex(self.HOME, r"\.cr-glitch::before\{color:var\(--red\)")
        for name in ("cromo-glitch-a", "cromo-glitch-b"):
            self.assertIn("@keyframes " + name, self.HOME)
        self.assertNotIn("cromo-glitch-base", self.HOME)                      # the title itself never shakes
        self.assertNotIn("skewX(", self.HOME)
        shifts = [abs(int(v)) for v in re.findall(r"translate\((-?\d+)px", self.HOME[self.HOME.index("@keyframes cromo-glitch-a"):])]
        self.assertTrue(shifts and max(shifts) <= 12, shifts)                 # shifts stay small enough to read through
        self.assertRegex(self.HOME, r"cromo-glitch-a 5\.\d+s steps\(1\) infinite")   # one burst every ~5s, not constant
        self.assertIn("cr-glitch::before,#cromo-home .cr-glitch::after{animation:none", self.HOME.replace("\n", "").replace("  ", ""))
        self.assertNotIn("innerHTML", self.JS)


class CromoBannersTests(unittest.TestCase):
    """The banners managed in DISCADMIN (Slider Manager) live in the Cromo home, with the site's motion."""

    JS = (ROOT / "js/public-cromo-home.js").read_text(encoding="utf-8")
    CSS = (ROOT / "css/public-cromo-home.css").read_text(encoding="utf-8")

    def test_banners_come_from_the_managed_endpoint_and_never_block_the_home(self) -> None:
        self.assertIn("/api/hero-slider.php", self.JS)
        self.assertIn(".catch(() => null)", self.JS)  # optional: the home mounts without them
        self.assertIn("buildBanners(slider)", self.JS)
        order = re.search(r"el\('main', \{ id: 'cromo-top' \}, ([^)]*)\)", self.JS)
        parts = [p.strip() for p in order.group(1).split(",")]
        self.assertLess(parts.index("band"), parts.index("banners"))
        self.assertLess(parts.index("banners"), parts.index("nights"))

    def test_banner_options_of_the_editor_are_honoured_safely(self) -> None:
        for needle in ("cfg.autoplay", "cfg.interval", "slide.transition", "slide.mobileSrc", "layer.mobileX",
                       "layer.hiddenMobile", "layer.animation", "layer.delay", "layer.duration", "slide.overlay"):
            self.assertIn(needle, self.JS)
        self.assertIn("safeUrl(layer.url)", self.JS)
        self.assertIn("safeUrl(slide.ctaUrl)", self.JS)
        self.assertNotIn("innerHTML", self.JS)

    def test_banners_are_an_accessible_carousel_with_a_pause_control(self) -> None:
        for needle in ("aria-roledescription': 'carousel'", "aria-roledescription': 'slide'",
                       "Pausar los banners", "Reanudar los banners", "Banner anterior", "Banner siguiente",
                       "matchMedia('(prefers-reduced-motion: reduce)')", "document.hidden"):
            self.assertIn(needle, self.JS)
        self.assertIn("min-width:52px;min-height:52px", self.CSS)

    def test_banners_keep_the_site_motion_and_a_reduced_motion_fallback(self) -> None:
        self.assertIn("cromo:beat", self.JS)            # the frame pulses with the sound loop
        self.assertIn("u glitch", self.JS)              # legacy-copy titles get the green glitch
        for anim in ("fade", "slide-up", "slide-left", "zoom"):
            self.assertIn("cr-bn-a-" + anim, self.CSS)
        blocks = "".join(self.CSS[m.start():] for m in re.finditer("prefers-reduced-motion", self.CSS))
        self.assertIn("cr-bn-layer{animation:none", blocks.replace(" ", ""))


    def test_explorer_has_no_card_template_and_names_real_items(self) -> None:
        # No eyebrow above the heading, no big-number/small-label cards: columns of the real items instead.
        self.assertNotIn("Conexiones del archivo", self.JS)
        self.assertIn("cn2-col", self.JS)
        self.assertIn("aria-hidden', 'true'", self.JS)             # the drawn lines are decorative; the sentence says it
        self.assertIn("IntersectionObserver", self.JS)             # the lines draw in once, when first seen
        self.assertRegex(HOME_CSS, r"cr-cn2-board\.cr-is-in \.cr-cn2-path\{stroke-dashoffset:0")
    def test_choosing_an_item_shows_what_it_is_linked_to_in_plain_words(self) -> None:
        for route in ("route('events', e.slug)", "route('artists', a.slug)", "route('sets', x.slug)", "route('releases', r.slug)"):
            self.assertIn(route, self.JS)
        self.assertIn("encodeURIComponent(slug)", self.JS)   # slugs from the CMS never build a raw URL
        for phrase in ("Tocan ", "toca en ", "Puedes escuchar ", "todavía no tiene conexiones publicadas"):
            self.assertIn(phrase, self.JS)
        self.assertIn("LIMIT", self.JS)                      # long columns are capped with a "Ver todos" toggle
        self.assertNotIn("innerHTML", self.JS)


if __name__ == "__main__":
    unittest.main()
