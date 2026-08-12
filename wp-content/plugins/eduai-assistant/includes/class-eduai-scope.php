<?php
/**
 * `?source=<post_id>` — the one place a tool decides what it is scoped to.
 *
 * ONE PARAMETER, ONE RESOLVER, ONE GATE. Two parameters (`?lesson=` and
 * `?doc=`) would mean two resolution paths and therefore two access gates,
 * and a guarantee enforced at one of two entry points is not a guarantee.
 * The name is `source` because the codebase already means that by it —
 * `wp_eduai_chunks` carries `source_title` and `source_url`.
 *
 * RESOLVED SCOPE OR NOTHING. Every failure — malformed id, missing post,
 * unsupported type, or **not allowed** — returns null, and the caller falls
 * through to the tool's ordinary unscoped behaviour. That makes a refused
 * scope indistinguishable from no scope, which is deliberate: a distinct
 * error would be an oracle telling someone whether post 47 exists and is
 * forbidden, versus does not exist.
 *
 * THE TITLE COMES FROM HERE, NEVER FROM THE URL. A title carried in a query
 * string is attacker-controlled text that renders into the page.
 *
 * This is the ACCESS gate, and it is the only one. Relevance is a separate
 * mechanism with a separate purpose and must not be mistaken for it.
 *
 * ONE ID, TWO MEANINGS, DECIDED BY THE CONSUMER — worth stating here so no
 * caller has to infer it:
 *
 *   Summarise treats the scope as the SUBJECT. It does not retrieve; the
 *   object is given, and the answer is about that thing exclusively.
 *
 *   Ask treats it as the ROOT OF A CONTEXT and boosts, never filters. "What
 *   is a residual", asked inside lesson four, is defined in lesson one;
 *   filtering to the lesson would hide the definition and produce a
 *   confidently incomplete answer. Ordering can be wrong without being
 *   harmful — a filter cannot. "Scope it tighter" is the first thing anyone
 *   asks for the moment they see an unexpected passage, and it is the wrong
 *   instinct.
 *
 * Both call the same gate. The difference is what they do after it says yes.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and gates the scope parameter.
 */
class EduAI_Scope {

	/**
	 * The scope for this request, or null.
	 *
	 * @return array{id:int,title:string,type:string}|null
	 */
	public static function current(): ?array {
		// Resolved once per request. The banner, the button label and the
		// localized config all ask, and asking is a capability check —
		// memoising is also what keeps "one predicate" literally true rather
		// than merely intended.
		static $resolved = false;
		static $scope    = null;

		if ( $resolved ) {
			return $scope;
		}

		$resolved = true;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only scope hint, gated below.
		$raw = isset( $_GET['source'] ) ? absint( wp_unslash( $_GET['source'] ) ) : 0;

		$scope = $raw ? self::resolve( $raw ) : null;

		return $scope;
	}

	/**
	 * Resolve one id, applying the gate belonging to its own post type.
	 *
	 * @param int $source_id Candidate post id.
	 * @return array{id:int,title:string,type:string}|null
	 */
	public static function resolve( int $source_id ): ?array {
		$post = $source_id ? get_post( $source_id ) : null;

		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}

		if ( ! self::allowed( $post ) ) {
			return null;
		}

		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );

		return array(
			'id'    => (int) $post->ID,
			'title' => $title,
			'type'  => (string) $post->post_type,
		);
	}

	/**
	 * The types a tool can meaningfully be pointed at.
	 *
	 * This list answers "is this a scopable thing", NOT "may you read it" —
	 * they are different questions and conflating them is how an allowlist
	 * quietly becomes an access rule. A published page is readable by anyone
	 * and still makes no sense as a scope.
	 *
	 * Anything absent gets no scope, so adding a type here means deciding
	 * its access rule in may_read() first. That is the intended friction.
	 */
	private static function scopable(): array {
		return (array) apply_filters(
			'eduai_scopable_post_types',
			array( 'study_material', 'lesson' )
		);
	}

	/**
	 * May the current visitor read this thing?
	 *
	 * Delegates to EduAI_Knowledge::may_read(), which is already the gate on
	 * the retrieval path — deliberately, and this is the whole point of the
	 * indirection. Restating the rule here would create a second copy that
	 * agrees today and diverges on the first change to either, and the
	 * divergence would be silent. One resolver, one place to be wrong.
	 *
	 * It also means the `eduai_may_read_source` filter governs both paths at
	 * once: whatever future per-enrolment rule is hung on that hook cannot be
	 * enforced on retrieval but forgotten on scope.
	 *
	 * @param WP_Post $post Source post.
	 */
	private static function allowed( WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, self::scopable(), true ) ) {
			return false;
		}

		return EduAI_Knowledge::may_read( (int) $post->ID );
	}

	/**
	 * The shape handed to the browser: null, or an id and a server-supplied
	 * title. Never the raw parameter.
	 */
	public static function for_script(): ?array {
		$scope = self::current();

		if ( ! $scope ) {
			return null;
		}

		return array(
			'id'    => $scope['id'],
			'title' => $scope['title'],
		);
	}
}
