<?php
/**
 * The course catalogue, and what it honestly knows.
 *
 * ABSENCE IS A VALUE HERE, NOT A BLANK
 *
 * Every field comes back with a companion flag saying whether it was actually
 * found. That is the whole design, and it exists because of a measured failure
 * on the sibling plugin: a retrieval layer that always returns its closest
 * match, handed to a language model, produces fluent confident answers about
 * things it has no information on. Asked about photosynthesis it returned
 * machine-learning code and wrote a lesson from it.
 *
 * A missing price is far worse than a missing passage. If `price` arrives as an
 * empty string the model will fill the gap — models are trained to complete
 * sentences, and "the course costs" has an overwhelmingly likely continuation.
 * So this returns `price => null, price_known => false`, the renderer prints
 * "not listed", and the prompt tells the model in words that unknown fields
 * must be described as unknown.
 *
 * MEASURED STATE OF THIS SITE, 23 AUGUST 2026
 *
 * Four courses. No descriptions, no excerpts. `duration` empty on all four.
 * Price expressible only as free/open. Zero category or tag terms assigned,
 * though both taxonomies are registered. No schedule field anywhere.
 *
 * So today this catalogue can honestly answer "what is it called" and "is it
 * free", and must say it does not know the rest. That is a content problem
 * rather than a code one, and the code's job is to make it visible instead of
 * papering over it.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Course discovery.
 */
class EduAI_Enquiry_Catalog {

	/**
	 * Post type holding courses, by source.
	 */
	private const SOURCES = array(
		'learndash' => 'sfwd-courses',
		'woo'       => 'product',
		'generic'   => 'course',
	);

	/**
	 * Which source this install actually has.
	 *
	 * Detected rather than configured. A plugin that travels to another site
	 * should work on arrival, and an admin who has to pick "LearnDash" from a
	 * dropdown before anything appears will conclude the plugin is broken.
	 */
	public static function source(): string {
		$override = get_option( 'eduai_enquiry_settings', array() )['source'] ?? '';

		if ( $override && isset( self::SOURCES[ $override ] ) && post_type_exists( self::SOURCES[ $override ] ) ) {
			return $override;
		}

		foreach ( self::SOURCES as $id => $type ) {
			if ( post_type_exists( $type ) ) {
				return $id;
			}
		}

		return '';
	}

	/**
	 * The post type to query.
	 */
	private static function post_type(): string {
		$src = self::source();

		return $src ? self::SOURCES[ $src ] : '';
	}

	/**
	 * Find courses.
	 *
	 * @param array $filters keywords[], level, format, free, limit.
	 * @return array List of normalised course records.
	 */
	public static function search( array $filters = array() ): array {
		$type = self::post_type();

		if ( '' === $type ) {
			return array();
		}

		$limit = max( 1, min( 12, (int) ( $filters['limit'] ?? 4 ) ) );
		$words = array_filter( (array) ( $filters['keywords'] ?? array() ) );

		$args = array(
			'post_type'           => $type,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		if ( $words ) {
			$args['s'] = implode( ' ', array_slice( $words, 0, 6 ) );
		}

		$tax = self::taxonomy_filter( $filters );

		if ( $tax ) {
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$found = get_posts( $args );

		/*
		 * A keyword search that finds nothing falls back to the catalogue
		 * rather than to an empty reply — "we have nothing on quantum
		 * mechanics, but here is what we do run" is a useful answer, and the
		 * caller is told which of the two happened so it can word it correctly.
		 */
		if ( ! $found && $words ) {
			unset( $args['s'] );

			$found = get_posts( $args );

			return array_map(
				static function ( $p ) {
					$row              = self::record( $p );
					$row['fallback']  = true;

					return $row;
				},
				$found
			);
		}

		return array_map( array( __CLASS__, 'record' ), $found );
	}

	/**
	 * One course by id, or null.
	 */
	public static function get( int $id ): ?array {
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== self::post_type() || 'publish' !== $post->post_status ) {
			return null;
		}

		return self::record( $post );
	}

	/**
	 * Category and tag constraints, where terms actually exist.
	 */
	private static function taxonomy_filter( array $filters ): array {
		$terms = array_filter( (array) ( $filters['categories'] ?? array() ) );

		if ( ! $terms ) {
			return array();
		}

		$out = array( 'relation' => 'OR' );

		foreach ( array( 'ld_course_category', 'ld_course_tag', 'category', 'post_tag', 'product_cat' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$out[] = array(
					'taxonomy' => $tax,
					'field'    => 'name',
					'terms'    => $terms,
					'operator' => 'IN',
				);
			}
		}

		return count( $out ) > 1 ? $out : array();
	}

	/**
	 * Turn a post into a course record where every fact is labelled.
	 *
	 * @param WP_Post $post Course post.
	 */
	private static function record( $post ): array {
		$id = (int) $post->ID;

		$description = self::description( $post );
		$duration    = self::first_meta( $id, array( '_learndash_course_grid_duration', '_course_duration', 'duration', '_duration' ) );
		$format      = self::format( $id );
		$price       = self::price( $id );
		$schedule    = self::first_meta( $id, array( '_course_start_date', 'course_start_date', '_start_date', 'schedule', '_schedule' ) );

		return array(
			'id'              => $id,
			'title'           => get_the_title( $post ),
			'url'             => get_permalink( $post ),

			'description'     => $description,
			'description_known' => '' !== $description,

			'duration'        => $duration,
			'duration_known'  => '' !== $duration,

			'format'          => $format,
			'format_known'    => '' !== $format,

			'price'           => $price['label'],
			'price_token'     => $price['token'],
			'price_known'     => $price['known'],
			'is_free'         => $price['free'],

			'schedule'        => $schedule,
			'schedule_known'  => '' !== $schedule,

			'categories'      => self::terms( $id ),
			'fallback'        => false,
		);
	}

	/**
	 * A human description, from the first place that has one.
	 */
	private static function description( $post ): string {
		$excerpt = trim( (string) $post->post_excerpt );

		if ( '' !== $excerpt ) {
			return wp_strip_all_tags( $excerpt );
		}

		$body = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );

		if ( '' === $body ) {
			return '';
		}

		return wp_trim_words( $body, 45, '…' );
	}

	/**
	 * Price, and whether it is genuinely known.
	 *
	 * LearnDash expresses this as a type plus an optional amount. `free` and
	 * `open` are real answers. A `paybynow`/`subscribe` course with no amount
	 * stored is NOT free — it is a course whose price this install does not
	 * record, and saying "free" there would be the most expensive possible
	 * mistake.
	 */
	private static function price( int $id ): array {
		$type   = (string) get_post_meta( $id, '_ld_price_type', true );
		$amount = trim( (string) get_post_meta( $id, '_ld_course_price', true ) );

		if ( '' === $amount ) {
			$amount = trim( (string) get_post_meta( $id, '_price', true ) ); // WooCommerce.
		}

		if ( in_array( $type, array( 'free', 'open' ), true ) ) {
			/*
			 * A TOKEN, not a translated word.
			 *
			 * This used to return __( 'Free' ), which resolves in the SITE's
			 * locale. Front-end caught the result: an Arabic card with Arabic
			 * labels and the value "Free" sitting in Latin script beside them.
			 * Course titles staying English is correct - a name is a name - but
			 * free and open are a controlled vocabulary, so the word has to be
			 * chosen where the visitor's language is known, which is not here.
			 */
			return array(
				'label' => '',
				'token' => $type,
				'known' => true,
				'free'  => true,
			);
		}

		if ( '' !== $amount && is_numeric( str_replace( array( ',', ' ' ), '', $amount ) ) ) {
			return array(
				'label' => self::money( $amount ),
				'token' => '',
				'known' => true,
				'free'  => false,
			);
		}

		return array(
			'label' => '',
			'token' => '',
			'known' => false,
			'free'  => null,
		);
	}

	/**
	 * Format the amount with whatever currency the site knows about.
	 */
	private static function money( string $amount ): string {
		$symbol = '';

		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = get_woocommerce_currency_symbol();
		}

		$symbol = (string) apply_filters( 'eduai_enquiry_currency', $symbol );

		return trim( $symbol . ' ' . $amount );
	}

	/**
	 * Delivery format, where the install records one.
	 */
	private static function format( int $id ): string {
		$raw = self::first_meta( $id, array( '_course_format', 'course_format', '_delivery_format' ) );

		if ( '' !== $raw ) {
			return $raw;
		}

		foreach ( array( 'ld_course_category', 'category', 'post_tag' ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}

			$terms = wp_get_post_terms( $id, $tax, array( 'fields' => 'names' ) );

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( (array) $terms as $t ) {
				if ( preg_match( '/\b(online|in ?person|hybrid|self ?paced|classroom)\b/i', $t ) ) {
					return $t;
				}
			}
		}

		return '';
	}

	/**
	 * First meta key that holds anything.
	 */
	private static function first_meta( int $id, array $keys ): string {
		foreach ( $keys as $k ) {
			$v = get_post_meta( $id, $k, true );

			if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
				return trim( wp_strip_all_tags( (string) $v ) );
			}
		}

		return '';
	}

	/**
	 * Category names across whichever taxonomies exist.
	 */
	private static function terms( int $id ): array {
		$out = array();

		foreach ( array( 'ld_course_category', 'ld_course_tag', 'category', 'post_tag', 'product_cat' ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}

			$terms = wp_get_post_terms( $id, $tax, array( 'fields' => 'names' ) );

			if ( ! is_wp_error( $terms ) && $terms ) {
				$out = array_merge( $out, $terms );
			}
		}

		return array_values( array_unique( array_filter( $out, static fn( $t ) => 'Uncategorized' !== $t ) ) );
	}

	/**
	 * What the catalogue cannot currently answer, for the admin screen.
	 *
	 * The point of surfacing this is that a bot which says "the fee is not
	 * listed" four times in a row looks broken, and the cause is content rather
	 * than software. An administrator should be able to see that at a glance
	 * instead of filing a bug.
	 */
	public static function coverage(): array {
		$type = self::post_type();

		if ( '' === $type ) {
			return array( 'total' => 0 );
		}

		$posts = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
			)
		);

		$out = array(
			'total'       => count( $posts ),
			'description' => 0,
			'duration'    => 0,
			'format'      => 0,
			'price'       => 0,
			'schedule'    => 0,
			'categories'  => 0,
		);

		foreach ( $posts as $p ) {
			$r = self::record( $p );

			$out['description'] += $r['description_known'] ? 1 : 0;
			$out['duration']    += $r['duration_known'] ? 1 : 0;
			$out['format']      += $r['format_known'] ? 1 : 0;
			$out['price']       += $r['price_known'] ? 1 : 0;
			$out['schedule']    += $r['schedule_known'] ? 1 : 0;
			$out['categories']  += $r['categories'] ? 1 : 0;
		}

		return $out;
	}
}
