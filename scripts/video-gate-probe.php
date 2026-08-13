<?php
/**
 * Throwaway probe: is an UPLOADED VIDEO on a members-only material reachable
 * at its raw uploads URL?
 *
 *   docker compose --profile tools run --rm cli \
 *     wp eval-file /scripts/video-gate-probe.php --allow-root
 *
 * docs/11-admin-console.md §9.3 item 3 instructs the tester to "confirm — and
 * record — that its raw wp-content/uploads/… URL returns 200 anonymously", and
 * §3.3 builds a user-facing honesty label on top of that being true. Both were
 * written before placement was extended to `_scholaris_video_id`. This measures
 * it instead of arguing about the call graph.
 *
 * Fixture is 2 MB with a marker at offset 1500000 deliberately: anything under
 * ~64 KB is satisfied by Apache's byterange filter whether or not the PHP
 * handler implements ranges at all, so a small fixture would prove the wrong
 * thing for the range half.
 *
 * @package Scholaris
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp-cli, not php.\n" );
	exit( 1 );
}

$slug   = 'gate-video';
$marker = 'VIDEO-GATE-MARKER-AT-1500000';

$existing = get_posts( array(
	'post_type'      => 'study_material',
	'name'           => $slug,
	'post_status'    => 'publish',
	'posts_per_page' => 1,
	'fields'         => 'ids',
) );

$post_id = $existing ? (int) $existing[0] : (int) wp_insert_post( array(
	'post_type'   => 'study_material',
	'post_status' => 'publish',
	'post_title'  => 'Gate test — members video',
	'post_name'   => $slug,
) );

// Keeps this fixture out of the front-end library — see SL_Post_Types.
// Written every run, so an existing fixture picks the flag up too.
update_post_meta( $post_id, '_scholaris_fixture', 1 );

$video_id = (int) get_post_meta( $post_id, '_scholaris_video_id', true );

if ( ! $video_id || ! file_exists( (string) get_attached_file( $video_id ) ) ) {
	$uploads = wp_upload_dir();
	$path    = trailingslashit( $uploads['path'] ) . $slug . '.mp4';

	$body = str_repeat( 'A', 1500000 ) . $marker;
	$body = str_pad( $body, 2097152, 'B' );

	file_put_contents( $path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

	$video_id = (int) wp_insert_attachment(
		array(
			'post_mime_type' => 'video/mp4',
			'post_title'     => $slug,
			'post_status'    => 'inherit',
		),
		$path,
		$post_id
	);
}

/* Order matters: set access BEFORE the video id, so the video-id write is the
 * meta change that sees a material already marked members-only. Writing them
 * the other way round is the exact sequencing that hid the fresh-install hole. */
update_post_meta( $post_id, '_scholaris_access', 'members' );
update_post_meta( $post_id, '_scholaris_video_id', $video_id );

/*
 * REQUIRED, and its absence cost an hour and nearly a false defect report.
 *
 * SL_Meta::has_video() keys on `_scholaris_video_source` and returns false when
 * it is unset, WHATEVER `_scholaris_video_id` holds. Without this line the
 * material has a video attached, streams correctly over ?sl_stream=, and the
 * template renders no player at all — which from outside is indistinguishable
 * from the video block not having been built yet. That is what I reported, and
 * it was wrong.
 *
 * Worth knowing beyond this fixture: any material created programmatically -
 * an import, a migration, a seeder - that writes the attachment id without the
 * source has a video nothing will ever display, and nothing anywhere fails.
 * Same shape as the fresh-install placement hole, which was also a fixture
 * writing meta in an order the product did not expect.
 */
update_post_meta( $post_id, '_scholaris_video_source', 'file' );

printf( "POST_VIDEO=%d\n", $post_id );
printf( "VIDEO_ID=%d\n", $video_id );
/*
 * Derived from the PATH, not from wp_get_attachment_url().
 *
 * This value means "the address the bytes sit at on disk", and the assertion
 * built on it is that Apache refuses that address. wp_get_attachment_url() was
 * only ever a proxy for it — and as of the media-library fix it is filtered to
 * return the gated streaming route for placed files, so the harness would have
 * been asking whether the gated route refuses anonymous callers, which the
 * check above already covers, while the direct path went untested.
 *
 * The failure was loud (an unset shell variable, because the streaming URL
 * carries `&` and `?`), and that is luck: a proxy that silently starts
 * answering a different question is the shape this project keeps finding.
 */
$sl_uploads = wp_upload_dir();
$sl_path    = (string) get_attached_file( $video_id );

printf(
	"RAW_URL=%s\n",
	str_replace( $sl_uploads['basedir'], $sl_uploads['baseurl'], $sl_path )
);
printf( "ON_DISK=%s\n", (string) get_attached_file( $video_id ) );
printf( "SIZE=%d\n", (int) @filesize( (string) get_attached_file( $video_id ) ) );
printf( "MARKER_OFFSET=%d\n", 1500000 );
printf( "MARKER=%s\n", $marker );
printf( "PERMALINK=%s\n", get_permalink( $post_id ) );
