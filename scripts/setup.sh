#!/usr/bin/env bash
#
# One-shot bootstrap for the Scholaris learning platform.
#
# Installs WordPress, the recommended plugin stack, activates the custom theme
# and plugins, and creates the pages the site expects (Library, My Progress,
# Study Assistant, plus the Sign in / Create account / Reset password pages
# the theme's auth flow uses) with the right shortcodes and menu.
#
# Run it against the Docker stack:
#     docker compose up -d
#     docker compose run --rm --profile tools cli bash /scripts/setup.sh
#
# Or on a live host that has wp-cli available:
#     cd /path/to/wordpress && bash /path/to/scripts/setup.sh
#
set -euo pipefail

WP="wp --allow-root --path=${WP_PATH:-/var/www/html}"

SITE_URL="${SITE_URL:-http://localhost:8080}"
SITE_TITLE="${SITE_TITLE:-EduAi}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-change-me-now}"
ADMIN_EMAIL="${ADMIN_EMAIL:-you@example.com}"

say() { printf '\n\033[1;36m▸ %s\033[0m\n' "$1"; }
ok()  { printf '  \033[0;32m✓\033[0m %s\n' "$1"; }

# ---------------------------------------------------------------- core -----
say "Checking WordPress core"
if ! $WP core is-installed 2>/dev/null; then
	$WP core install \
		--url="$SITE_URL" \
		--title="$SITE_TITLE" \
		--admin_user="$ADMIN_USER" \
		--admin_password="$ADMIN_PASSWORD" \
		--admin_email="$ADMIN_EMAIL" \
		--skip-email
	ok "WordPress installed"
else
	ok "WordPress already installed"
fi

# ------------------------------------------------------------- settings ----
say "Applying site settings"
# blogname is set by `core install` only on a fresh install — update it here
# too so the Scholaris→EduAi display rename reaches already-installed sites.
$WP option update blogname "$SITE_TITLE"
$WP option update blogdescription "Study material, quizzes and an AI study assistant"
$WP option update permalink_structure '/%postname%/'
$WP option update timezone_string 'Africa/Cairo'
$WP option update users_can_register 1
# default_role is set to "student" in the roles section, after the role exists.
$WP rewrite flush --hard
ok "Settings applied"

# -------------------------------------------------------------- plugins ----
say "Installing the plugin stack"

# Core LMS + quizzes
PLUGINS=(
	tutor                       # Tutor LMS — courses, lessons, quizzes, attempt history
	classic-editor              # Predictable editing for course authors (optional)
	wordpress-seo               # Yoast SEO
	wordfence                   # Firewall + login hardening
	updraftplus                 # Scheduled backups
	wp-super-cache              # Page caching
	webp-express                # Image optimisation
	wps-hide-login              # Move wp-login.php away from the default URL
	user-role-editor            # Fine-grained student/instructor capabilities
	redirection                 # Manage redirects
	loco-translate              # Edit theme/plugin strings without touching code
)

for plugin in "${PLUGINS[@]}"; do
	if $WP plugin is-installed "$plugin" 2>/dev/null; then
		ok "$plugin already installed"
	else
		$WP plugin install "$plugin" --activate && ok "$plugin installed"
	fi
done

# Activate ours (they are bind-mounted, not downloaded)
$WP plugin activate scholaris-library eduai-assistant 2>/dev/null || true
$WP plugin activate tutor 2>/dev/null || true
ok "Custom plugins activated"

# ---------------------------------------------------------------- theme ----
say "Activating the Scholaris theme"
$WP theme activate scholaris
ok "Theme active"

# ---------------------------------------------------------------- pages ----
say "Creating pages"

# Create-only, deliberately: it never modifies an existing page, which is what
# makes re-running this script safe on a live site.
#
# The cost of that is a silent collision. Tutor LMS creates /dashboard/ when it
# activates — before this section runs — so "My Progress" was skipped, never
# created, and eight links pointed at Tutor's empty page for weeks. The old
# message was "✓ /dashboard/ already exists", which is true and tells you
# nothing. A skip now names the page ID and says whether the shortcode we
# expected is actually on it, so a collision reads as a collision on the day it
# happens rather than after someone reads a 200 as working.
make_page() {
	local title="$1" slug="$2" content="$3"

	if $WP post list --post_type=page --name="$slug" --format=count | grep -q '^0$'; then
		$WP post create \
			--post_type=page \
			--post_title="$title" \
			--post_name="$slug" \
			--post_status=publish \
			--post_content="$content" >/dev/null
		ok "created /$slug/"
		return
	fi

	local id shortcode note=""
	id=$($WP post list --post_type=page --name="$slug" --field=ID | head -1)
	shortcode=$(printf '%s' "$content" | grep -oE '^\[[a-z0-9_]+' | tr -d '[' || true)

	if [ -n "$shortcode" ]; then
		if $WP post get "$id" --field=content 2>/dev/null | grep -q "\[$shortcode"; then
			note=" carrying [$shortcode]"
		else
			note=" WITHOUT [$shortcode] — another plugin may own this slug; that feature renders nowhere"
		fi
	fi

	ok "/$slug/ already exists as page $id$note — left untouched"
}

# Titles converge on every run (nav labels are spec — docs/06 names the tabs),
# while content stays create-only: titles are ours, page bodies may hold edits.
retitle_page() {
	local slug="$1" title="$2" id current
	id=$($WP post list --post_type=page --name="$slug" --field=ID | head -1)
	[ -z "$id" ] && return 0
	current=$($WP post get "$id" --field=post_title)
	if [ "$current" != "$title" ]; then
		$WP post update "$id" --post_title="$title" >/dev/null
		ok "retitled /$slug/ to \"$title\""
	fi
}

# The lede under the h1, for the same reason and by the same rule as titles.
#
# page.php renders post_excerpt as the page lede when has_excerpt() — the four
# tool pages are a bare word over a shortcode without it, which is what they
# shipped as. The excerpt is WordPress's own field for this, so the owner can
# reword a lede in wp-admin without touching a template.
#
# It CANNOT ride along on make_page(): that is create-only and skips any page
# that already exists, so every installed site — including the owner's — would
# stay lede-less while a fresh install looked correct. That is the exact shape
# of the five drift bugs already on the board, and why retitle_page exists as
# a separate converge-on-every-run setter. This is its sibling.
#
# Create-only still governs page BODIES; a lede is ours the way a title is.
set_lede() {
	local slug="$1" lede="$2" id current
	id=$($WP post list --post_type=page --name="$slug" --field=ID | head -1)
	[ -z "$id" ] && return 0
	current=$($WP post get "$id" --field=post_excerpt)
	if [ "$current" != "$lede" ]; then
		$WP post update "$id" --post_excerpt="$lede" >/dev/null
		ok "lede set on /$slug/"
	fi
}

make_page "Home" "home" "Welcome to $SITE_TITLE."
make_page "Library" "library" "[scholaris_library per_page=\"12\" filters=\"yes\"]"
# /dashboard/ belongs to Tutor LMS, which creates it on activation before this
# section runs. Ours lives at /progress/ — see inc/template-tags.php.
make_page "My Progress" "progress" "[scholaris_dashboard]"
make_page "Study Assistant" "assistant" "[eduai_panel height=\"600\"]"
make_page "Summarise" "summarise" "[eduai_summarizer]"
make_page "Privacy Policy" "privacy" "How student data and AI conversations are handled."

# The four AI tabs (docs/06-eduai-rebuild.md §2). Q&A reuses the existing chat
# panel shortcode today; AiCalc and PrepareME get placeholder copy until their
# shortcodes land — the back end swaps the content, the slug and menu slot are
# already right.
make_page "Q&A" "ask" "[eduai_panel tabs=\"chat\" page=\"1\"]"
make_page "AiCalc" "calc" "[eduai_calc]"
make_page "PrepareME" "prepare" "[eduai_prepare]"

# Converge the nav-facing titles (make_page above is create-only, so a page
# created under an older title keeps it forever otherwise — the live site
# showed "Summarise a Lecture" in the nav for exactly this reason).
retitle_page "summarise" "Summarise"
retitle_page "calc" "AiCalc"
retitle_page "ask" "Q&A"
retitle_page "prepare" "PrepareME"
retitle_page "progress" "My Progress"

# /profile/ is the one page with no nav tab, so nothing pins its title short the
# way the seven tabs are pinned by the header geometry — and the header control
# that reaches it says "Your account", which is what the page should answer to.
retitle_page "profile" "Your account"

# The four tool pages' ledes, lifted from the mock's .pagehero copy so the two
# surfaces say the same sentence.
#
# The em dashes here are real em dashes, U+2014, the three bytes e2 80 94. The
# mock's summarise and calc ledes carry a double-encoded one instead — the eight
# bytes c3 a2 e2 82 ac e2 80 9d, which is a UTF-8 em dash decoded as
# Windows-1252 and re-encoded — so copying those two strings verbatim would have
# published the corruption onto the live site too. Repaired here; the mock is
# fixed separately. Named as bytes rather than pasted, so that a scanner looking
# for the corrupt sequence does not match this explanation of it.
set_lede "summarise" "Upload a PDF, PowerPoint, Word or text file — or paste the text — and get study notes in the style you need."
set_lede "calc" "Pure arithmetic is computed exactly, in code, with every step shown. Symbolic and worded problems go to the model — and the answer always says which path it took."
set_lede "ask" "Ask your material anything. Answers are grounded in the library and cite their sources; off-syllabus maths and science are answered in full."
set_lede "prepare" "Upload a lecture, sit an exam generated from it, and get it marked with corrections."

# Auth pages. Content stays empty: the theme routes wp_login_url()/
# wp_registration_url()/wp_lostpassword_url() to these slugs and applies the
# matching page-templates/auth-*.php by slug (see inc/auth.php) with no
# configuration; the template meta below just makes the choice visible in
# the editor.
make_page "Sign in" "sign-in" ""
make_page "Create account" "register" ""
make_page "Reset password" "reset-password" ""

# The account screen. Deliberately a themed page rather than wp-admin/profile.php:
# that form is a different product wearing a different typeface, and students
# who land on it think they have left the site.
make_page "Profile" "profile" ""

set_page_template() {
	local slug="$1" tpl="$2" id
	id=$($WP post list --post_type=page --name="$slug" --field=ID | head -1)
	if [ -n "$id" ]; then
		$WP post meta update "$id" _wp_page_template "$tpl" >/dev/null
		ok "template assigned for /$slug/"
	fi
}
set_page_template "sign-in" "page-templates/auth-signin.php"
set_page_template "register" "page-templates/auth-register.php"
set_page_template "reset-password" "page-templates/auth-reset.php"
set_page_template "profile" "page-templates/profile.php"

# Static front page
HOME_ID=$($WP post list --post_type=page --name=home --field=ID | head -1)
if [ -n "$HOME_ID" ]; then
	$WP option update show_on_front page
	$WP option update page_on_front "$HOME_ID"
	ok "Front page set"
fi

# ----------------------------------------------------------------- menu ----
say "Building the primary menu"

if ! $WP menu list --format=csv | grep -q '^.*,Primary,'; then
	$WP menu create "Primary" >/dev/null || true
fi

# Rebuild the Primary menu from scratch on every run so restructures converge
# on already-installed sites: stale items (Material, Study Assistant) drop out,
# nothing duplicates, and the order below is exactly what ships.
for item_id in $($WP menu item list Primary --format=ids 2>/dev/null); do
	$WP menu item delete "$item_id" >/dev/null 2>&1 || true
done

add_menu_item() {
	local slug="$1"
	local id
	id=$($WP post list --post_type=page --name="$slug" --field=ID | head -1)
	[ -n "$id" ] && $WP menu item add-post Primary "$id" >/dev/null 2>&1 || true
}

# Nav per docs/06-eduai-rebuild.md, plus the owner's ruling on open question 1:
# Home · Library · Summarise · AiCalc · Q&A · PrepareME · My Progress.
# Material is reached through Library; /assistant/ keeps its page but stays
# off the menu (the Q&A tab supersedes it).
for slug in home library summarise calc ask prepare progress; do
	add_menu_item "$slug"
done

$WP menu location assign Primary primary >/dev/null 2>&1 || true
ok "Menu assigned"

# --------------------------------------------------------------- widgets ---
say "Emptying the sidebar"

# WordPress populates the FIRST registered sidebar on a fresh install with five
# stock block widgets — Search, Recent Posts, Recent Comments, Archives,
# Categories. The theme registers exactly one (`sidebar-1`, functions.php), so
# they land in ours, and footer.php renders it whenever is_active_sidebar() is
# true. Nothing in this script ever emptied it.
#
# The result was ~800px of WordPress demo content in the footer of EVERY page
# of the running site: "Recent Posts → Hello world!" and "Recent Comments → A
# WordPress Commenter on Hello world!", under the real footer, on the home
# page, the library, the four AI tabs and both auth screens. The design mock
# has no sidebar anywhere, so this is the single most visible way the site and
# the mock disagree — and it reads as a broken install rather than a product.
#
# Emptying it also makes the `no-sidebar` body class apply for the first time
# (functions.php's scholaris_body_class adds it when the sidebar is inactive —
# with stock widgets present it never fired). No stylesheet reads that class
# today, so nothing moves as a result; it is the hook the front end would use
# if a sidebar-less layout ever needs its own rules, and it has simply never
# been reachable on this install.
#
# Unconditional on every run, exactly like the Primary menu rebuild above, and
# for the same reason: this has to converge on sites that were bootstrapped
# before the fix, not only on fresh ones. It is the safe direction — the theme
# supports an empty sidebar by design, and `wp widget reset` moves widgets to
# Inactive Widgets rather than destroying them, so a deliberate widget added
# later is recoverable from Appearance → Widgets.
#
# If this project ever does want a sidebar, add the widgets here rather than in
# wp-admin, or the next run of this script will sweep them out again.
$WP widget reset sidebar-1 >/dev/null 2>&1 || true

remaining=$($WP widget list sidebar-1 --format=count 2>/dev/null || echo 0)
if [ "$remaining" = "0" ]; then
	ok "sidebar-1 is empty — no stock widgets in the footer"
else
	ok "sidebar-1 still holds $remaining widget(s) — check Appearance → Widgets"
fi

# ------------------------------------------------------- seeded content ---
say "Clearing WordPress's seeded content"

# The sibling of the sidebar reset above, and the same root cause: this script
# converges what it CREATES and had nothing to say about what WordPress LEAVES
# BEHIND. A fresh install seeds three posts that are pure scaffolding — the
# "Hello world!" post, "Sample Page", and an auto-drafted "Privacy Policy" that
# duplicates the real /privacy/ page made above — plus one canned comment from
# "A WordPress Commenter".
#
# Emptying sidebar-1 took those off the page but not off the site: /hello-world/
# and /sample-page/ both still answered 200. On a student-facing product they
# are crawlable URLs, and "Sample Page" in a sitemap reads as an abandoned
# install rather than a product.
#
# TRASHED, NEVER --force. wp_trash_post() moves to Trash, so every one of these
# is recoverable from wp-admin. This script must not be the reason something
# becomes unrecoverable.
#
# GUARDED ON post_modified === post_date, which is WordPress's own record that
# a seeded row has never been edited. Matching the slug alone is not enough: if
# somebody ever writes a real post that lands on /hello-world/, an unguarded
# run would trash their work. The guard asks the database rather than carrying
# a hardcoded copy of whatever the current WordPress happens to seed, so it
# does not rot when that copy changes.
$WP eval '
$ours = get_page_by_path( "privacy" );

// WordPress points wp_page_for_privacy_policy at the draft it seeded, never at
// ours — it was page 3 here while the real policy was page 11. So
// get_privacy_policy_url() resolved to a DRAFT, which 404s for every logged-out
// visitor, and that is the URL WordPress hands to login forms and to any plugin
// that asks for it. Re-point it BEFORE the draft is trashed, so the option is
// never left aimed at a trashed post.
if ( $ours && (int) get_option( "wp_page_for_privacy_policy" ) !== $ours->ID ) {
	update_option( "wp_page_for_privacy_policy", $ours->ID );
	echo "  ok  privacy policy option now points at /privacy/ (page {$ours->ID})\n";
}

foreach ( array(
	array( "hello-world", "post" ),
	array( "sample-page", "page" ),
	array( "privacy-policy", "page" ),
) as $seed ) {
	list( $slug, $type ) = $seed;

	$found = get_posts( array(
		"name"        => $slug,
		"post_type"   => $type,
		"post_status" => array( "publish", "draft", "pending", "private", "future" ),
		"numberposts" => 1,
	) );

	if ( ! $found ) {
		continue;
	}

	$p = $found[0];

	if ( $p->post_modified !== $p->post_date ) {
		echo "  --  /{$slug}/ has been edited since WordPress seeded it — left alone\n";
		continue;
	}

	wp_trash_post( $p->ID );
	echo "  ok  trashed the seeded {$type} /{$slug}/ (id {$p->ID})\n";
}

// Handled separately rather than left to wp_trash_post() cascading over the
// Hello world! comments: if that post was edited and skipped above, the canned
// comment is still there and still WordPress scaffolding.
//
// Matched on the seeded address, not the display name. wapuu@wordpress.example
// is on the reserved .example TLD (RFC 2606), so it cannot belong to a real
// commenter, whereas a display name is free text a real person could type.
//
// "any", not "all". They are not synonyms in WP_Comment_Query: "all" means the
// moderation statuses (approved + pending) and silently excludes trash, spam
// and post-trashed, while "any" means every status there is. Measured on this
// install — with the comment sitting at post-trashed, "all" returned 0 rows and
// "any" returned 1. Written with "all" this loop would have done nothing at all
// and still printed a clean run.
foreach ( get_comments( array(
	"author_email" => "wapuu@wordpress.example",
	"status"       => "any",
) ) as $c ) {
	if ( "trash" === $c->comment_approved ) {
		continue;
	}
	wp_trash_comment( $c->comment_ID );
	echo "  ok  trashed the seeded comment (id {$c->comment_ID})\n";
}
'

# ------------------------------------------------------------ taxonomies ---
say "Seeding subjects"
for subject in "Mathematics" "Physics" "Computer Science" "Biology" "Chemistry"; do
	$WP term create material_subject "$subject" >/dev/null 2>&1 || true
done
ok "Subjects created"

# ----------------------------------------------------------------- roles ---
say "Creating the student role"
$WP role create student "Student" --clone=subscriber >/dev/null 2>&1 || true
$WP cap add student read >/dev/null 2>&1 || true
# Everyone who signs up through /register/ becomes a student.
$WP option update default_role 'student'
ok "Student role ready and set as the default for signups"

# -------------------------------------------------------------- database ---
say "Verifying the database"
$WP eval '
global $wpdb;
$fail = 0;
foreach ( array( "eduai_messages", "eduai_chunks" ) as $t ) {
	$table = $wpdb->prefix . $t;
	$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
	if ( $found === $table ) { echo "  ok  table {$table}\n"; } else { echo "  !!  MISSING table {$table} — reactivate eduai-assistant\n"; $fail = 1; }
}
$tutor = $wpdb->prefix . "tutor_quiz_attempts";
$found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tutor ) );
echo ( $found === $tutor ) ? "  ok  table {$tutor}\n" : "  --  {$tutor} not there yet (created when Tutor LMS activates)\n";
echo get_option( "users_can_register" ) ? "  ok  self-registration open\n" : "  !!  self-registration CLOSED\n";
echo ( "student" === get_option( "default_role" ) ) ? "  ok  default role: student\n" : "  !!  default role is " . get_option( "default_role" ) . "\n";
// "progress" is ours; checking "dashboard" would only verify the page Tutor LMS owns.
foreach ( array( "sign-in", "register", "reset-password", "progress", "library" ) as $slug ) {
	echo get_page_by_path( $slug ) ? "  ok  page /{$slug}/\n" : "  !!  MISSING page /{$slug}/\n";
}
exit( $fail );
'
ok "Database ready"

# --------------------------------------------------------------- wrap up ---
say "Done"
cat <<EOF

  Site:      $SITE_URL
  Admin:     $SITE_URL/wp-admin  ($ADMIN_USER)
  Mail:      http://localhost:\${MAILPIT_PORT:-8025}  (catches every e-mail the site sends)

  Next steps
  ----------
  1. Give the assistant an API key — either provider works:
     ANTHROPIC_API_KEY or GROQ_API_KEY in .env (Docker passes them through),
     or define EDUAI_ANTHROPIC_API_KEY / EDUAI_GROQ_API_KEY in wp-config.php.
  2. Library → Add study material: upload a lecture PDF.
  3. Settings → EduAI Assistant → "Rebuild index" to feed it to the assistant.
  4. Tutor LMS → Courses: create a course and a quiz.
  5. Open $SITE_URL/register/ and create a student account — you should land
     on /progress/ signed in, with the "student" role.
  6. Ask the assistant a question.

EOF
