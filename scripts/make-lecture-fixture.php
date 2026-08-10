<?php
/**
 * Builds a .pptx designed to fail a plausible-but-wrong extractor.
 *
 *     php scripts/make-lecture-fixture.php [output.pptx]
 *
 * With no argument it writes scripts/roundtrip-deck.pptx, which is where the
 * round-trip harness looks. Needs only ext-zip — no WordPress, no plugin. If the
 * host has no PHP, build it in the container and copy it back:
 *
 *     docker cp scripts/make-lecture-fixture.php scholaris-wp:/tmp/
 *     docker exec -i scholaris-wp php /tmp/make-lecture-fixture.php /tmp/deck.pptx
 *     docker cp scholaris-wp:/tmp/deck.pptx scripts/roundtrip-deck.pptx
 *
 * WHY A GENERATOR RATHER THAN A COMMITTED .pptx. A deck is a zip: opaque in
 * review, undiffable, and its traps invisible. Here the trap is the point, so it
 * has to be readable. Regenerating is deterministic — same bytes every run, so
 * the .pptx itself is gitignored: build it once per working copy. A harness that
 * cannot find it should say so loudly rather than quietly test something easier.
 *
 * THE TRAPS, AND WHAT EACH ONE CATCHES
 *
 * 1. THE NOTES BELONG TO SLIDE 2 BUT LIVE IN notesSlide1.xml. That is not
 *    contrived: PowerPoint numbers notes parts independently of slides, so a
 *    deck where only slide 2 carries a note stores it as notesSlide1. An
 *    extractor that pairs slideN with notesSlideN attaches it to slide 1 — or,
 *    if slide 1 has no note, silently drops it. Only reading
 *    ppt/slides/_rels/slide2.xml.rels gets it right.
 *
 * 2. THE NOTE CARRIES A FACT THAT IS ON NO SLIDE. "RuBisCO is the rate-limiting
 *    step because of its oxygenase activity" appears nowhere in the slide text.
 *    So a summary that mentions it proves the notes were *used*, not merely
 *    extracted — an assertion that the summary is non-empty would pass on a
 *    build that dropped every note.
 *
 * 3. SLIDE 10 EXISTS. Sorting part names as strings puts slide10 between slide1
 *    and slide2, so the deck reads out of order and any "first slide" logic
 *    picks the wrong one. Numeric sorting is required.
 *
 * @package EduAI
 */

$out = $argv[1] ?? __DIR__ . '/roundtrip-deck.pptx';

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "ext-zip is required\n" );
	exit( 2 );
}

@unlink( $out );

$zip = new ZipArchive();

if ( true !== $zip->open( $out, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "could not create $out\n" );
	exit( 2 );
}

/**
 * One DrawingML part: each line becomes its own paragraph, as PowerPoint writes
 * a bulleted slide.
 *
 * @param string[] $lines Text runs.
 */
$part = static function ( array $lines ): string {
	$runs = '';

	foreach ( $lines as $line ) {
		$runs .= '<a:p><a:r><a:t>' . htmlspecialchars( $line, ENT_XML1 ) . '</a:t></a:r></a:p>';
	}

	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
		. 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
		. '<p:cSld><p:spTree><p:sp><p:txBody>' . $runs . '</p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
};

$zip->addFromString(
	'[Content_Types].xml',
	'<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'
);

$zip->addFromString( 'ppt/slides/slide1.xml', $part( array(
	'Photosynthesis: the light-dependent reactions',
	'Occur in the thylakoid membrane',
	'Produce ATP and NADPH',
	'Water is split, releasing oxygen',
) ) );

$zip->addFromString( 'ppt/slides/slide2.xml', $part( array(
	'The Calvin cycle',
	'Takes place in the stroma',
	'Fixes carbon dioxide using RuBisCO',
	'Consumes the ATP and NADPH made earlier',
) ) );

// Trap 3: string-sorted, this lands between slide1 and slide2.
$zip->addFromString( 'ppt/slides/slide10.xml', $part( array(
	'Summary and exam pointers',
	'Know where each stage happens',
	'Be able to say what limits the rate',
) ) );

// Trap 1: slide 2's note, stored as notesSlide1 because it is the first note in
// the deck. Reached only through the relationship file.
$zip->addFromString(
	'ppt/slides/_rels/slide2.xml.rels',
	'<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	. '<Relationship Id="rId2" '
	. 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" '
	. 'Target="../notesSlides/notesSlide1.xml"/></Relationships>'
);

// Trap 2: a fact that appears on no slide.
$zip->addFromString( 'ppt/notesSlides/notesSlide1.xml', $part( array(
	'Examiners always ask why RuBisCO is the rate-limiting step. The answer is its oxygenase activity, which competes with carbon fixation.',
) ) );

$zip->close();

printf( "built %s (%d bytes)\n\n", $out, filesize( $out ) );

print "what a correct extractor produces:\n";
print "  - three slides, in the order 1, 2, 10 — not 1, 10, 2\n";
print "  - a speaker note attached to slide 2, not slide 1\n";
print "  - the word RuBisCO's oxygenase activity present, though no slide says it\n";
