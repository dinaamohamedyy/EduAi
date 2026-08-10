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
make_page "Q&A" "ask" "[eduai_panel height=\"600\" tabs=\"chat\"]"
make_page "AiCalc" "calc" "[eduai_calc]"
make_page "PrepareME" "prepare" "PrepareME is being wired up: upload a lecture, sit an exam generated from it, and get it marked with corrections."

# Auth pages. Content stays empty: the theme routes wp_login_url()/
# wp_registration_url()/wp_lostpassword_url() to these slugs and applies the
# matching page-templates/auth-*.php by slug (see inc/auth.php) with no
# configuration; the template meta below just makes the choice visible in
# the editor.
make_page "Sign in" "sign-in" ""
make_page "Create account" "register" ""
make_page "Reset password" "reset-password" ""

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
foreach ( array( "sign-in", "register", "reset-password", "dashboard", "library" ) as $slug ) {
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
     on the dashboard signed in, with the "student" role.
  6. Ask the assistant a question.

EOF
