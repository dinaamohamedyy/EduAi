<?php
/**
 * AiCalc — the calculator page, rendered by [eduai_calc].
 *
 * The interface makes one distinction load-bearing: whether the answer was
 * computed exactly in PHP or came from the model. Everything else here is in
 * service of that, because "computed exactly" is a stronger claim than a
 * model's answer and a student is entitled to know which one they have
 * (docs/07 §7).
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

$eduai_calc_id = 'eduai-calc-' . wp_unique_id();
?>
<div class="eduai-calc" id="<?php echo esc_attr( $eduai_calc_id ); ?>" data-eduai-calc>

	<form class="eduai-calc__form" data-eduai-calc-form>
		<label class="screen-reader-text" for="<?php echo esc_attr( $eduai_calc_id ); ?>-input">
			<?php esc_html_e( 'What would you like to work out?', 'eduai' ); ?>
		</label>

		<input type="text" id="<?php echo esc_attr( $eduai_calc_id ); ?>-input"
			data-eduai-calc-input
			placeholder="<?php esc_attr_e( 'e.g. 12 * 8, (2+3)^2, or the derivative of x^3', 'eduai' ); ?>"
			autocomplete="off" spellcheck="false" maxlength="2000">

		<button type="submit" class="eduai-btn eduai-btn--primary" data-eduai-calc-go>
			<?php esc_html_e( 'Work it out', 'eduai' ); ?>
		</button>
	</form>

	<div class="eduai-calc__examples" data-eduai-calc-examples>
		<?php
		// Chosen to show the split: the first three are exact, the last two
		// need the model. -3^2 is here on purpose — it is the case the
		// calculator used to get wrong.
		$eduai_calc_examples = array( '12 * 8', '2 ^ 3 ^ 2', '-3^2', 'derivative of x^3', 'molar mass of Ca(OH)2' );

		foreach ( $eduai_calc_examples as $eduai_example ) :
			?>
			<button type="button" data-eduai-calc-example="<?php echo esc_attr( $eduai_example ); ?>">
				<?php echo esc_html( $eduai_example ); ?>
			</button>
		<?php endforeach; ?>
	</div>

	<div class="eduai-calc__out" data-eduai-calc-out hidden></div>

	<p class="eduai-calc__foot">
		<?php esc_html_e( 'Plain arithmetic is worked out on the server and is exact. Anything symbolic or worded is answered by the assistant, which can make mistakes — it says which you got.', 'eduai' ); ?>
	</p>
</div>
