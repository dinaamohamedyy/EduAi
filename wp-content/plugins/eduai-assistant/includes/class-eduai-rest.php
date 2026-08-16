<?php
/**
 * REST endpoints powering the chat widget.
 *
 * @package EduAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes: /wp-json/eduai/v1/chat, /summarize, /history, /reset
 */
class EduAI_REST {

	public const NS = 'eduai/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/chat', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'chat' ),
			'permission_callback' => array( __CLASS__, 'can_use' ),
			'args'                => array(
				'message'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => static fn( $v ) => is_string( $v ) && strlen( trim( $v ) ) > 0 && strlen( $v ) <= 6000,
				),
				'thread_id' => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
				'post_id'   => array(
					'type'    => 'integer',
					'default' => 0,
				),
				// The scope the student is working inside. Declared but NOT
				// trusted: it is re-resolved and re-gated in the handler,
				// because the localized copy is a UI hint and this is the
				// entry point where material is actually read.
				'source'    => array(
					'type'    => 'integer',
					'default' => 0,
				),
				'agent'     => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
			),
		) );

		register_rest_route( self::NS, '/summarize', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'summarize' ),
			'permission_callback' => array( __CLASS__, 'can_use' ),
		) );

		register_rest_route( self::NS, '/history', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'history' ),
			'permission_callback' => array( __CLASS__, 'can_use' ),
			'args'                => array(
				'thread_id' => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// AiCalc (docs/06 §2.2). Pure arithmetic never reaches the model.
		register_rest_route( self::NS, '/calc', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'calc' ),
			'permission_callback' => array( __CLASS__, 'can_use' ),
			'args'                => array(
				'input' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'validate_callback' => static fn( $v ) => is_string( $v ) && strlen( trim( $v ) ) > 0 && strlen( $v ) <= 2000,
				),
			),
		) );

		// PrepareME (docs/06 §2.4). Contract shapes: docs/05-frontend-handoff.md.
		register_rest_route( self::NS, '/exam', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'exam_create' ),
			'permission_callback' => array( __CLASS__, 'can_use' ),
			'args'                => array(
				// The lesson a student pressed "Prepare me" on. Declared but not
				// trusted: re-resolved and re-gated in exam_material().
				'source'     => array( 'type' => 'integer', 'default' => 0 ),
				'count'      => array( 'type' => 'integer', 'default' => 10 ),
				'title'      => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ),
				'regenerate' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );

		register_rest_route( self::NS, '/exam/(?P<id>\d+)/submit', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'exam_submit' ),
			'permission_callback' => array( __CLASS__, 'can_use' ),
			'args'                => array(
				'answers' => array( 'required' => true ),
			),
		) );

		register_rest_route( self::NS, '/exam/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'exam_get' ),
			'permission_callback' => array( __CLASS__, 'can_use' ),
			'args'                => array(
				'retake' => array( 'type' => 'boolean', 'default' => false ),
			),
		) );
	}

	/**
	 * Permission gate — respects the "signed-in only" setting.
	 *
	 * @return true|WP_Error
	 */
	public static function can_use() {
		if ( EduAI_Settings::get( 'logged_in_only', true ) && ! is_user_logged_in() ) {
			return new WP_Error( 'eduai_auth', __( 'Please sign in to use the assistant.', 'eduai' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * Sliding-window rate limit per user (or per IP for anonymous use).
	 *
	 * @return true|WP_Error
	 */
	private static function check_rate_limit() {
		$limit = (int) EduAI_Settings::get( 'rate_limit', 20 );
		if ( $limit <= 0 ) {
			return true;
		}

		$user_id = get_current_user_id();
		$who     = $user_id ? 'u' . $user_id : 'ip' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key     = 'eduai_rl_' . $who;
		$count   = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return new WP_Error(
				'eduai_rate',
				__( 'You have reached the hourly message limit. Try again a little later.', 'eduai' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Exam generation gets its own, tighter bucket: a whole lecture in and a
	 * full exam out is the most expensive call in the product, and one shared
	 * hourly bucket would let chat volume mask runaway generation (or the
	 * reverse). Reuse of an existing exam never touches this bucket.
	 *
	 * Weighted by size per docs/06 §4: a 20-question paper is roughly twice
	 * the spend of a 10, so it costs two units of the `exam_limit` budget
	 * (default 4/hour at the 10-question baseline), not one.
	 *
	 * @param float $cost Units this generation costs (count / 10).
	 * @return true|WP_Error
	 */
	private static function check_exam_rate_limit( float $cost = 1.0 ) {
		$limit = (float) EduAI_Settings::get( 'exam_limit', 4 );
		if ( $limit <= 0 ) {
			return true;
		}

		$user_id = get_current_user_id();
		$who     = $user_id ? 'u' . $user_id : 'ip' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key     = 'eduai_rl_exam_' . $who;
		$used    = (float) get_transient( $key );

		if ( $used + $cost > $limit + 0.001 ) {
			return new WP_Error(
				'eduai_exam_rate',
				__( 'You have generated several exams this hour already. Re-open one of them, or try again a little later.', 'eduai' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $used + $cost, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Load an exam only if it belongs to the current user. Missing and
	 * not-yours answer identically — 403 per docs/07 §4 — so ids cannot
	 * be probed.
	 *
	 * @param int $exam_id Row id.
	 * @return array|WP_Error
	 */
	private static function exam_owned( int $exam_id ) {
		$exam = EduAI_Exams::get( $exam_id );

		if ( ! $exam || (int) $exam['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'eduai_exam_forbidden', __( 'Only your own exams are visible.', 'eduai' ), array( 'status' => 403 ) );
		}

		return $exam;
	}

	/**
	 * Lecture material for an exam: pasted text or an uploaded file, as
	 * content blocks plus a label and a hash identifying the source.
	 *
	 * Mirrors summarize()'s handling deliberately — same formats, same size
	 * cap, same legacy-format advice, same document-block fallback for a PDF
	 * with no text layer.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error { content, label, hash }
	 */
	private static function exam_material( WP_REST_Request $request ) {
		$files   = $request->get_file_params();
		$content = array();
		$label   = '';
		$hash    = '';

		if ( ! empty( $files['file']['tmp_name'] ) ) {
			$file = $files['file'];

			if ( ! empty( $file['error'] ) ) {
				return new WP_Error( 'eduai_upload', __( 'The file failed to upload.', 'eduai' ), array( 'status' => 400 ) );
			}

			if ( (int) $file['size'] > 20 * MB_IN_BYTES ) {
				return new WP_Error( 'eduai_upload_big', __( 'Files must be 20 MB or smaller.', 'eduai' ), array( 'status' => 413 ) );
			}

			$check = wp_check_filetype( (string) $file['name'], array(
				'pdf'  => 'application/pdf',
				'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'txt'  => 'text/plain',
				'md'   => 'text/markdown',
				'ppt'  => 'application/vnd.ms-powerpoint',
				'doc'  => 'application/msword',
			) );

			$ext   = (string) $check['ext'];
			$label = sanitize_file_name( (string) $file['name'] );

			if ( '' === $ext ) {
				return new WP_Error( 'eduai_upload_type', __( 'Supported files are PDF, PPTX, DOCX, TXT and MD.', 'eduai' ), array( 'status' => 415 ) );
			}

			if ( 'ppt' === $ext || 'doc' === $ext ) {
				return new WP_Error(
					'eduai_upload_legacy',
					__( 'That is the older binary Office format, which cannot be read here. Open it, use Save as to produce a .pptx or .docx, and upload that — or export it to PDF.', 'eduai' ),
					array( 'status' => 415 )
				);
			}

			// Uploads land at /tmp/phpXXXX.tmp — the format must be passed
			// explicitly, the path says nothing.
			$text = EduAI_PDF::extract( (string) $file['tmp_name'], $ext );

			if ( 'pdf' === $ext && ! EduAI_PDF::looks_like_prose( $text ) ) {
				$block = EduAI_Claude::pdf_block( (string) $file['tmp_name'] );
				if ( is_wp_error( $block ) ) {
					return $block;
				}
				$content[] = $block;
				$hash      = (string) hash_file( 'sha256', (string) $file['tmp_name'] );
			} elseif ( strlen( trim( $text ) ) < 40 ) {
				return new WP_Error( 'eduai_empty', self::empty_file_message( $ext ), array( 'status' => 422 ) );
			} else {
				$content[] = array(
					'type' => 'text',
					'text' => self::source_heading( $ext, $label, (string) $file['tmp_name'] ) . "\n\n" . self::cap( $text ),
				);
				$hash      = hash( 'sha256', trim( $text ) );
			}
		} else {
			$text  = trim( (string) $request->get_param( 'text' ) );
			$scope = EduAI_Scope::resolve( (int) $request->get_param( 'source' ) );

			/*
			 * "Prepare me" on a lesson the student has just finished: the
			 * source is the lesson, and they should not have to upload
			 * anything they were already reading.
			 *
			 * Re-gated here through the same resolver the button used, so a
			 * quiz cannot be generated from a lesson somebody may not read —
			 * an exam is a projection of the material, and generating one is
			 * a read of it.
			 *
			 * The pasted text still wins when there is any: someone who typed
			 * into the box meant that, even on a scoped page.
			 */
			if ( strlen( $text ) < 200 && $scope ) {
				$document = self::scoped_source_text( $scope );

				if ( strlen( trim( $document ) ) < 200 ) {
					return new WP_Error(
						'eduai_scope_thin',
						__( 'There is not enough text in this lesson to build an exam from. Open the lecture it came from, or paste the part you want to be tested on.', 'eduai' ),
						array( 'status' => 422 )
					);
				}

				$label     = $scope['title'];
				$document  = self::fit( $document );
				$content[] = array(
					'type' => 'text',
					'text' => sprintf( "Lesson: %s\n\n", $scope['title'] ) . $document,
				);

				// Keyed on the scope rather than the text, so re-opening the
				// same lesson hands back the same paper instead of spending
				// the generation budget again.
				$hash = hash( 'sha256', 'scope:' . $scope['id'] . ':' . md5( $document ) );

				return array(
					'content' => $content,
					'label'   => $label,
					'hash'    => $hash,
				);
			}

			// 200 minimum, higher than /summarize's: an exam needs more
			// source material than a summary (docs/07 §2).
			if ( strlen( $text ) < 200 ) {
				return new WP_Error( 'eduai_short', __( 'Paste at least a couple of paragraphs of the lecture, or attach a file — an exam needs more source than that.', 'eduai' ), array( 'status' => 400 ) );
			}
			$text      = wp_strip_all_tags( $text );
			$content[] = array( 'type' => 'text', 'text' => self::cap( $text ) );
			$hash      = hash( 'sha256', trim( $text ) );
		}

		return array(
			'content' => $content,
			'label'   => $label,
			'hash'    => $hash,
		);
	}

	/**
	 * POST /exam — lecture in, sittable exam out (answers withheld).
	 *
	 * The response never contains answer_index, expected or explanation:
	 * those exist in the stored row and only come back through marking.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function exam_create( WP_REST_Request $request ) {
		// Allowlist per docs/06 §4, enforced server-side: a dropdown is not a
		// control, and generation is the most expensive call in the product.
		$count = (int) $request->get_param( 'count' );
		if ( ! in_array( $count, array( 5, 10, 20 ), true ) ) {
			return new WP_Error( 'eduai_exam_count', __( 'Exams come in 5, 10 or 20 questions.', 'eduai' ), array( 'status' => 400 ) );
		}

		$material = self::exam_material( $request );
		if ( is_wp_error( $material ) ) {
			return $material;
		}

		$user_id    = get_current_user_id();
		$regenerate = (bool) $request->get_param( 'regenerate' );
		$title      = mb_substr( (string) $request->get_param( 'title' ), 0, 190 );

		// The dedupe key covers material AND size: a 10-question and a
		// 20-question exam from one lecture are different artefacts.
		$hash = hash( 'sha256', $count . ':' . $material['hash'] );

		// Same material, same size: hand back the stored exam unless a fresh
		// one was explicitly asked for. Costs nothing — no rate-limit spend.
		if ( ! $regenerate ) {
			$existing = EduAI_Exams::find_by_hash( $user_id, $hash );
			if ( $existing ) {
				return new WP_REST_Response( EduAI_Exams::for_client( $existing, true ), 200 );
			}
		}

		$limited = self::check_exam_rate_limit( $count / 10 );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$exam = EduAI_Exams::generate( $user_id, $material['content'], $material['label'], $hash, $count, $title );
		if ( is_wp_error( $exam ) ) {
			return $exam;
		}

		return new WP_REST_Response( EduAI_Exams::for_client( $exam, false ), 200 );
	}

	/**
	 * POST /exam/<id>/submit — answers in, mark and corrections out.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function exam_submit( WP_REST_Request $request ) {
		$limited = self::check_rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$exam = self::exam_owned( (int) $request['id'] );
		if ( is_wp_error( $exam ) ) {
			return $exam;
		}

		$answers = $request->get_param( 'answers' );
		if ( ! is_array( $answers ) || count( $answers ) > 100 ) {
			return new WP_Error( 'eduai_exam_answers', __( 'Send the answers as a list of { id, choice } and { id, text } entries.', 'eduai' ), array( 'status' => 400 ) );
		}

		$graded = EduAI_Exams::grade( $exam, $answers );
		if ( is_wp_error( $graded ) ) {
			return $graded;
		}

		$attempt_id = EduAI_Exams::store_attempt( $exam, $answers, $graded );

		return new WP_REST_Response( array(
			'exam_id'    => (int) $exam['id'],
			'attempt_id' => $attempt_id,
			'score'      => $graded['score'],
			'total'      => $graded['total'],
			'percent'    => $graded['percent'],
			'bands'      => $graded['bands'],
			'results'    => $graded['results'],
		), 200 );
	}

	/**
	 * GET /exam/<id> — one route, both moments (docs/07 §4): the §2 shape
	 * with attempted:false to resume an unsat paper, or the latest attempt's
	 * §3 shape plus the questions with attempted:true to review a marked one.
	 *
	 * Id 0 is the committed sample (fixtures/exam-sample.json), served so the
	 * front-end can build the whole PrepareME UI before generation ever runs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function exam_get( WP_REST_Request $request ) {
		$exam_id = (int) $request['id'];

		if ( 0 === $exam_id ) {
			$fixture = EduAI_Exams::fixture();
			if ( ! $fixture ) {
				return new WP_Error( 'eduai_exam_forbidden', __( 'Only your own exams are visible.', 'eduai' ), array( 'status' => 403 ) );
			}

			$out              = EduAI_Exams::for_client( $fixture, false );
			$out['attempted'] = false;
			return new WP_REST_Response( $out, 200 );
		}

		$exam = self::exam_owned( $exam_id );
		if ( is_wp_error( $exam ) ) {
			return $exam;
		}

		$attempts = EduAI_Exams::attempts_for( $exam_id );

		if ( ! $attempts ) {
			$out              = EduAI_Exams::for_client( $exam, false );
			$out['attempted'] = false;
			return new WP_REST_Response( $out, 200 );
		}

		// Retake: the same paper, blank. Deliberately the SAME projection a
		// never-attempted exam gets — for_client() redacts, so sitting a paper
		// once cannot be turned into a way to read its answer key by asking for
		// it again. The previous attempt stays in the table; store_attempt()
		// inserts, so a retake adds a row rather than overwriting the mark.
		if ( $request['retake'] ) {
			$out              = EduAI_Exams::for_client( $exam, false );
			$out['attempted'] = true;
			$out['retake']    = true;
			return new WP_REST_Response( $out, 200 );
		}

		$out              = EduAI_Exams::attempt_for_client( $exam, $attempts[0] );
		$out['questions'] = EduAI_Exams::redact( $exam['questions'] );
		$out['attempted'] = true;
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * POST /chat
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function chat( WP_REST_Request $request ) {
		$limited = self::check_rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$message   = trim( (string) $request->get_param( 'message' ) );
		$thread_id = (string) $request->get_param( 'thread_id' );
		$post_id   = (int) $request->get_param( 'post_id' );
		$agent_id  = EduAI_Agents::resolve( (string) $request->get_param( 'agent' ) );
		$user_id   = get_current_user_id();

		if ( '' === $thread_id ) {
			$thread_id = wp_generate_password( 16, false, false );
		}

		// Re-resolved from the id, never taken on the browser's word, and
		// never taken from `post_id` — that one is an ungated relevance bias
		// and must not be allowed to become a scope by accident.
		$scope = EduAI_Scope::resolve( (int) $request->get_param( 'source' ) );

		// ------------------------------------------------------------- context
		$passages = array();
		if ( EduAI_Settings::get( 'enable_rag', true ) ) {
			$limit    = (int) EduAI_Settings::get( 'context_chunks', 6 );

			$passages = EduAI_Knowledge::retrieve( $message, $limit );

			// A BOOST, never a filter — see EduAI_Scope, which is one gate
			// with two meanings. Ask retrieves from the whole deck: "what is
			// a residual" asked inside lesson four is defined in lesson one,
			// and filtering to the lesson would hide the definition and
			// return a confidently incomplete answer. Ordering can be wrong
			// without being harmful; a filter cannot.
			//
			// The scoped pull is gated the same as any other — retrieve()
			// applies may_read() per row — so boosting cannot surface a
			// passage the plain query would have been refused.
			if ( $scope ) {
				$scoped   = EduAI_Knowledge::retrieve( $message, 2, $scope['id'] );
				$passages = array_merge( $scoped, $passages );
			} elseif ( $post_id ) {
				// Same shape, older mechanism: the document the student is
				// reading. Yields to a resolved scope, which is the gated and
				// deliberate version of the same idea.
				$current  = EduAI_Knowledge::retrieve( get_the_title( $post_id ) . ' ' . $message, 2 );
				$passages = array_merge( $current, $passages );
			}

			// De-duplicate by opening characters and cap the count.
			$seen  = array();
			$final = array();
			foreach ( $passages as $p ) {
				$key = md5( substr( $p['text'], 0, 120 ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$final[]      = $p;
				if ( count( $final ) >= $limit ) {
					break;
				}
			}
			$passages = $final;
		}

		$system = EduAI_Agents::system_prompt( $agent_id );

		$user = wp_get_current_user();
		if ( $user && $user->display_name ) {
			$system .= "\n\nThe student's name is " . sanitize_text_field( $user->display_name ) . '.';
		}

		$context = EduAI_Knowledge::to_context( $passages );
		if ( $context ) {
			$system .= "\n\n" . $context;

			if ( $scope ) {
				// Careful with this wording: the boost means SOME of the
				// material above is from the scope and some is not. Telling
				// the model it is all from one lecture would make it attribute
				// the rest to that lecture, which is the misattribution the
				// filter was rejected for causing in the other direction.
				$system .= "\n\nThe student is reading \"" . $scope['title'] . '". Some of the material above is from it and some from elsewhere in the same course. Prefer it where both fit, and do not imply the rest came from that lecture.';
			}
		} elseif ( EduAI_Settings::get( 'allow_general_knowledge', true ) ) {
			$system .= "\n\nNo course material matched this question. Open with one short line saying so, then answer it in full anyway under the heading \"Beyond the course material\".";
		} else {
			$system .= "\n\nNo course material matched this question. Say so plainly and point the student to their lecturer.";
		}

		// ---------------------------------------------------------- generation
		$args = array();

		$temperature = EduAI_Agents::temperature( $agent_id );
		if ( null !== $temperature ) {
			$args['temperature'] = $temperature;
		}

		$model = EduAI_Agents::model( $agent_id );
		if ( $model ) {
			$args['model'] = $model;
		}

		// Arithmetic and derivations want no sampling noise, room to show the
		// working, and the strongest model available — whichever agent the
		// student happens to have selected.
		//
		// Q&A is the highest-volume feature in the product, so it runs on the
		// balanced tier by default (docs/06 §2, model table) and escalates only
		// where the extra reasoning actually shows: a dropped sign or a skipped
		// step in a derivation is a wrong answer, while a slightly less elegant
		// explanation of a concept is not. An agent that pins its own model in
		// its Markdown file still wins — that is a deliberate choice by whoever
		// wrote the agent, and this must not silently override it.
		if ( self::looks_computational( $message ) ) {
			$system .= "\n\nThis question involves a calculation or a derivation. Work it through step by step, show the arithmetic, and check the result before presenting it.";

			$args['temperature'] = 0.0;
			$args['max_tokens']  = max( (int) EduAI_Settings::get( 'max_tokens', 1200 ), 2000 );

			if ( ! $model ) {
				$args['model'] = 'strongest';
			}
		} elseif ( ! $model ) {
			$args['model'] = 'balanced';
		}

		// ------------------------------------------------------------- history
		$messages = array();
		foreach ( EduAI_Conversation::history( $user_id, $thread_id, 8 ) as $turn ) {
			$messages[] = array( 'role' => $turn['role'], 'content' => $turn['content'] );
		}
		$messages[] = array( 'role' => 'user', 'content' => $message );

		// --------------------------------------------------------------- call
		$result = EduAI_Claude::message( $messages, $system, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$sources = array();
		foreach ( $passages as $p ) {
			$sources[ $p['url'] ] = array( 'title' => $p['title'], 'url' => $p['url'] );
		}
		$sources = array_values( $sources );

		EduAI_Conversation::add( $user_id, $thread_id, 'user', $message );
		EduAI_Conversation::add( $user_id, $thread_id, 'assistant', $result['text'], $sources, $result['usage'] );

		return new WP_REST_Response( array(
			'reply'     => $result['text'],
			'html'      => self::to_html( $result['text'] ),
			'sources'   => $sources,
			'thread_id' => $thread_id,
			'agent'     => $agent_id,
			'grounded'  => ! empty( $passages ),
		), 200 );
	}

	/**
	 * POST /calc — AiCalc.
	 *
	 * Routes on the input rather than sending everything to the model. A pure
	 * arithmetic expression is evaluated by EduAI_Calc: exact, instant, free,
	 * and reproducible. Anything symbolic, worded or unit-bearing — derivatives,
	 * integrals, simultaneous equations, "how long until it cools to 20 °C" —
	 * goes to the model at temperature 0 with the house rules, which already
	 * demand the givens restated, every step shown, units carried and a closing
	 * sanity check.
	 *
	 * `method` in the response says which path ran, and the two are not the same
	 * kind of claim: "computed exactly" means the arithmetic is right, full
	 * stop, while a model answer is a very good answer that can still be wrong.
	 * The student is entitled to know which one they are looking at.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function calc( WP_REST_Request $request ) {
		$input = trim( (string) $request->get_param( 'input' ) );

		// The deterministic path costs nothing and cannot be abused, so it is
		// answered before the rate limit is consulted rather than after.
		$exact = EduAI_Calc::evaluate( $input );

		if ( null !== $exact ) {
			return new WP_REST_Response( array(
				'method' => 'exact',
				'input'  => $input,
				'answer' => $exact['display'],
				'steps'  => $exact['steps'],
				'html'   => self::to_html( self::calc_markdown( $exact ) ),
			), 200 );
		}

		$limited = self::check_rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		// The house rules already carry a Notation section, but it sits a long
		// way up a long prompt and a maths-tuned model reaches for LaTeX by
		// reflex — observed emitting \(f(x)=x^{3}\) for a plain derivative.
		// to_html() has no LaTeX handling, so that reaches the student as
		// literal backslashes and braces. On a surface where every single reply
		// is mathematics, the rule is worth restating last, where it lands.
		$system = EduAI_Agents::system_prompt( 'stem-solver' )
			. "\n\nNOTATION — this overrides any formatting habit:"
			. "\n- Plain text only. NO LaTeX: no \\( \\), no \\[ \\], no $…$, no \\frac, no \\times, no ^{ } braces."
			. "\n- Write powers as x^2, roots as sqrt(x), fractions as (a+b)/c, products as 3 x 10^8."
			. "\n- Put a multi-line derivation in a fenced code block so the alignment survives.";

		$result = EduAI_Claude::message(
			array( array( 'role' => 'user', 'content' => $input ) ),
			$system,
			array(
				'model'       => 'strongest',
				'temperature' => 0,
				'max_tokens'  => max( (int) EduAI_Settings::get( 'max_tokens', 2000 ), 2000 ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array(
			'method' => 'model',
			'input'  => $input,
			'answer' => '',
			'steps'  => array(),
			'html'   => self::to_html( $result['text'] ),
		), 200 );
	}

	/**
	 * Render an exact result as Markdown, so it goes through the same renderer
	 * as everything else rather than growing a second HTML path.
	 *
	 * @param array $exact From EduAI_Calc::evaluate().
	 */
	private static function calc_markdown( array $exact ): string {
		$out = '**' . $exact['steps'][0] . '**' . "\n\n";

		if ( $exact['mixed'] ) {
			$out .= __( 'Multiplication, division and powers bind tighter than addition and subtraction, so those resolve first.', 'eduai' ) . "\n\n";
		}

		$steps = array_slice( $exact['steps'], 1 );

		foreach ( $steps as $step ) {
			$out .= '- = ' . $step . "\n";
		}

		if ( ! $steps ) {
			$out .= '- = ' . $exact['display'] . "\n";
		}

		$out .= "\n**= " . $exact['display'] . "**\n\n";
		$out .= __( 'Computed exactly, one operation per line, highest precedence first — this is arithmetic done in code, not a model\'s best guess.', 'eduai' );

		return $out;
	}

	/**
	 * Does this message look like it wants a calculation or a derivation?
	 *
	 * Deliberately generous: a false positive costs a little determinism and a
	 * larger token ceiling, while a false negative costs a student a truncated
	 * derivation. It reads intent only — nothing is evaluated here.
	 *
	 * @param string $message Student question.
	 */
	private static function looks_computational( string $message ): bool {
		// Arithmetic. A digit has to appear somewhere, and the minus sign is
		// treated separately, so that ordinary hyphenation — "well-known",
		// "problem-solving" — is not read as subtraction.
		if ( preg_match( '/\d/', $message ) ) {
			if ( preg_match( '#[0-9a-z)\]]\s*(?:[+*/^=]|×|÷)\s*[0-9a-z(\[]#i', $message ) ) {
				return true;
			}
			if ( preg_match( '#\d\s*-\s*\d|[0-9a-z)\]]\s+-\s+[0-9a-z(\[]#i', $message ) ) {
				return true;
			}
		}

		// Notation that is computational on its own.
		if ( preg_match( '/[√∑∏∫∂≈≤≥±]/u', $message ) ) {
			return true;
		}

		// Vocabulary. Terms with a common everyday sense — "current", "force",
		// "mean", "power" — are qualified rather than listed bare, so that an
		// ordinary question does not get treated as a derivation.
		$vocabulary = 'calculate|compute|solve|evaluate|derive|derivative|integral|integrate|differentiate'
			. '|simplify|factorise|factorize|prove|equation|inequality|matrix|determinant|eigen\w*'
			. '|probability|permutation|logarithm|exponential|sqrt|square root|percentage'
			. '|arithmetic mean|median|standard deviation|variance|regression|significant figures'
			. '|mole|molar|molecular|stoichiometry|titration|concentration|balance the equation'
			. '|velocity|acceleration|momentum|torque|net force|voltage|electric current|resistance'
			. '|capacitance|wavelength|frequency|half.?life|entropy|enthalpy|gibbs'
			. '|energy required|work done';

		return (bool) preg_match( '/\b(?:' . $vocabulary . ')\b/i', $message );
	}

	/**
	 * POST /summarize — accepts either pasted text or an uploaded file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function summarize( WP_REST_Request $request ) {
		$limited = self::check_rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$style = sanitize_key( (string) $request->get_param( 'style' ) );
		$style = in_array( $style, array( 'brief', 'detailed', 'exam', 'critical' ), true ) ? $style : 'detailed';

		$instruction = self::summary_instruction( $style );
		$files       = $request->get_file_params();
		$content     = array();
		$label       = '';
		// Initialised here so the response key is present on every path,
		// including the ones that never truncate. An absent key and a null
		// one are the same value in JS, which is the whole reason docs/07
		// requires every result key to exist.
		$truncated   = null;

		// Re-gated here and not trusted from the render pass. The banner and
		// this call are one predicate invoked twice, never two predicates
		// that agree today — same discipline as SL_Private::is_secured()
		// being the single question asked before claiming a file is safe.
		$scope = EduAI_Scope::resolve( (int) $request->get_param( 'source' ) );

		if ( ! empty( $files['file']['tmp_name'] ) ) {
			$file = $files['file'];

			if ( ! empty( $file['error'] ) ) {
				return new WP_Error( 'eduai_upload', __( 'The file failed to upload.', 'eduai' ), array( 'status' => 400 ) );
			}

			if ( (int) $file['size'] > 20 * MB_IN_BYTES ) {
				return new WP_Error( 'eduai_upload_big', __( 'Files must be 20 MB or smaller.', 'eduai' ), array( 'status' => 413 ) );
			}

			// The legacy binary formats are listed only so that uploading one
			// produces advice rather than "unsupported file".
			$check = wp_check_filetype( (string) $file['name'], array(
				'pdf'  => 'application/pdf',
				'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'txt'  => 'text/plain',
				'md'   => 'text/markdown',
				'ppt'  => 'application/vnd.ms-powerpoint',
				'doc'  => 'application/msword',
			) );

			$ext   = (string) $check['ext'];
			$label = sanitize_file_name( (string) $file['name'] );

			if ( '' === $ext ) {
				return new WP_Error(
					'eduai_upload_type',
					__( 'Supported files are PDF, PPTX, DOCX, TXT and MD.', 'eduai' ),
					array( 'status' => 415 )
				);
			}

			if ( 'ppt' === $ext || 'doc' === $ext ) {
				return new WP_Error(
					'eduai_upload_legacy',
					__( 'That is the older binary Office format, which cannot be read here. Open it, use Save as to produce a .pptx or .docx, and upload that — or export it to PDF.', 'eduai' ),
					array( 'status' => 415 )
				);
			}

			// An upload lands at a path like /tmp/phpA1B2.tmp, so the format has
			// to be passed explicitly: the extension on disk says nothing.
			$text = EduAI_PDF::extract( (string) $file['tmp_name'], $ext );

			if ( 'pdf' === $ext && ! EduAI_PDF::looks_like_prose( $text ) ) {
				// Either a scan with no text layer, or fonts this reader cannot
				// decode. Claude reads the pages better than we do either way.
				$block = EduAI_Claude::pdf_block( (string) $file['tmp_name'] );
				if ( is_wp_error( $block ) ) {
					return $block;
				}
				$content[] = $block;
			} elseif ( strlen( trim( $text ) ) < 40 ) {
				return new WP_Error( 'eduai_empty', self::empty_file_message( $ext ), array( 'status' => 422 ) );
			} else {
				$content[] = array(
					'type' => 'text',
					'text' => self::source_heading( $ext, $label, (string) $file['tmp_name'] ) . "\n\n" . self::cap( $text ),
				);
			}
		} else {
			$text = trim( (string) $request->get_param( 'text' ) );

			if ( strlen( $text ) >= 80 ) {
				// An upload or a paste is an explicit act and wins over the
				// scope, even on a scoped page. The response echoes `label`
				// so the client can correct a banner that now names the wrong
				// thing — a screen claiming to summarise the lesson while
				// summarising something else is the lie worth avoiding.
				$content[] = array( 'type' => 'text', 'text' => self::cap( wp_strip_all_tags( $text ) ) );
			} elseif ( $scope ) {
				// Scoped summarise does not retrieve: the object is given.
				// This is the one request a scoped page exists to make, which
				// is why the "attach or paste something" guard yields here.
				$document = self::scoped_source_text( $scope );

				if ( '' === $document ) {
					return new WP_Error(
						'eduai_scope_empty',
						__( 'There is no readable text on that lecture yet. Attach a file or paste the part you need.', 'eduai' ),
						array( 'status' => 422 )
					);
				}

				/*
				 * Fitted to what the provider will actually accept, which is
				 * well under cap()'s 220 KB.
				 *
				 * BE CAREFUL WHAT YOU CONCLUDE FROM A FAILING SIZE HERE. The
				 * first pass at this read 35,928 → 413, 24,000 → 413, 20,149
				 * → 200 and recorded it as a measured context ceiling. It is
				 * not one. Re-run later the same value both passed and failed,
				 * and the failures arrived as 429 naming a per-minute token
				 * allowance — so what those numbers actually measured was how
				 * much of the free tier's minute was left, which a bigger
				 * request exhausts sooner. Both hypotheses predict "large
				 * fails, small passes"; only the error code tells them apart.
				 *
				 * So this number is a pragmatic fit, not a discovered
				 * boundary: big enough to be worth reading, small enough to
				 * leave room for 3,000 output tokens from the same allowance.
				 * The filter is the real answer, because the right value is a
				 * property of the key in use.
				 *
				 * Truncating and saying so beats both alternatives. Failing
				 * outright gives the student nothing; sending half quietly —
				 * which is what reading the chunk index was doing before this
				 * — gives them a summary that reads complete and covers a bit
				 * over half the lecture, with no way to tell.
				 *
				 * A filter rather than a constant because the right number is
				 * a property of the key in use, not of this code: a paid tier
				 * or a long-context model should raise it.
				 */
				$truncated = self::truncation_of( $document );
				$document  = self::fit( $document );

				$label     = $scope['title'];
				$content[] = array(
					'type' => 'text',
					'text' => sprintf( "Lecture: %s\n\n", $scope['title'] ) . $document,
				);
			} else {
				return new WP_Error( 'eduai_short', __( 'Paste at least a paragraph of the lecture, or attach a file.', 'eduai' ), array( 'status' => 400 ) );
			}
		}

		$content[] = array( 'type' => 'text', 'text' => $instruction );

		// The notation rules ride along because to_html() has no LaTeX
		// handling — \(a_0\) would reach the student as literal backslashes.
		// Only that section: the full house rules are chat rules, and Scope
		// would tell a summary to answer questions. The preview pages mirror
		// this composition in SUM_SYSTEM.
		$result = EduAI_Claude::message(
			array( array( 'role' => 'user', 'content' => $content ) ),
			"You are an expert lecturer's assistant. You produce accurate, well-structured study notes from lecture material. "
				. "Never invent facts that are not in the source. If the source is unclear or incomplete, say which part is unclear."
				. "\n\n" . EduAI_Agents::house_rules_section( 'Notation' ),
			array(
				'model'      => EduAI_Settings::get( 'summary_model', 'strongest' ),
				'max_tokens' => 3000,
				'temperature' => 0.2,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$thread_id = 'summary';
		EduAI_Conversation::add( get_current_user_id(), $thread_id, 'user', sprintf( '[Summarise: %s]', $label ?: __( 'pasted text', 'eduai' ) ) );
		EduAI_Conversation::add( get_current_user_id(), $thread_id, 'assistant', $result['text'], array(), $result['usage'] );

		return new WP_REST_Response( array(
			'summary'   => $result['text'],
			'html'      => self::to_html( $result['text'] ),
			'label'     => $label,
			'style'     => $style,
			// { used, of } or null. Reports what was actually read, which can
			// differ from what the page predicted at render: an explicit
			// upload overrides the scope, and then the render-time figure
			// describes a deck that was never opened.
			'truncated' => $truncated,
		), 200 );
	}

	/**
	 * The full text of a scoped source, for summarising.
	 *
	 * Takes the RESOLVED scope array rather than an id, deliberately. The
	 * only way to obtain one is EduAI_Scope::resolve(), so an ungated id
	 * cannot reach this function by any call that type-checks — the gate is
	 * enforced by the signature instead of by remembering to call it.
	 *
	 * Extracts from the document, and NOT from the chunk index. Reassembling
	 * chunks looks like the tidy reuse — same text the assistant retrieves,
	 * no second extraction path — and it is wrong twice over. Chunks overlap
	 * by 200 characters by design, so the seams come back as duplicated
	 * sentences; measured on material 143, seven long sentences appeared
	 * twice in the reassembly. And the index is a cache of the document
	 * rather than the document: the same material reassembled to 20,149
	 * characters against 35,928 in the file, so a summary built from it would
	 * silently cover a bit over half the lecture and read as complete.
	 *
	 * `_scholaris_file_id` is the authority for which attachment is the
	 * document — the same meta EduAI_Knowledge::index_post() reads. NOT
	 * get_children(), which returns whatever is attached in whatever order:
	 * material 123 has an unrelated mp4 that comes back first and extracts to
	 * nothing.
	 *
	 * Takes the RESOLVED scope array rather than an id, deliberately. The
	 * only way to obtain one is EduAI_Scope::resolve(), so an ungated id
	 * cannot reach this function by any call that type-checks — the gate is
	 * enforced by the signature instead of by remembering to call it.
	 *
	 * @param array{id:int,title:string} $scope Resolved, gated scope.
	 */
	public static function source_budget(): int {
		return max( 1000, (int) apply_filters( 'eduai_document_budget', 18000 ) );
	}

	/**
	 * `{ used, of }` when a document will not fit, null when it will.
	 *
	 * ALWAYS RETURNED, null rather than absent, per docs/07's rule that every
	 * result key is present: in JS an absent key and a false one read the
	 * same, so "no truncation" and "this build is too old to tell you" become
	 * indistinguishable at exactly the moment the distinction matters.
	 *
	 * Two numbers rather than a percentage or a phrase — the ratio is the
	 * caller's to word, and "about the first half" is something a student can
	 * act on where "18,000 characters" is not.
	 *
	 * @param string $document Full source text.
	 * @return array{used:int,of:int}|null
	 */
	private static function truncation_of( string $document ): ?array {
		// Bytes, because the budget is in bytes — it was measured against
		// what the provider accepts, and counting characters instead let a
		// deck with maths glyphs through at 18,000 characters and rather more
		// than 18,000 bytes, which 413'd again. Units are part of the
		// measurement.
		$full   = strlen( $document );
		$budget = self::source_budget();

		return $full > $budget ? array( 'used' => $budget, 'of' => $full ) : null;
	}

	/**
	 * Trim a document to the request budget, on a character boundary.
	 *
	 * mb_strcut rather than cap()'s substr, and rather than mb_substr either.
	 * substr cuts UTF-8 mid-sequence and leaves a broken byte that
	 * wp_json_encode refuses to encode, turning a long lecture into an empty
	 * response — and lecture decks are exactly where the multibyte characters
	 * live, the maths glyphs and ligatures that were silently costing the
	 * chunk index whole rows this morning. mb_substr fixes that but counts
	 * CHARACTERS, which overshoots a byte budget and 413s on the decks most
	 * likely to need trimming. mb_strcut is the one that respects a byte
	 * limit without splitting a character.
	 *
	 * @param string $document Full source text.
	 */
	private static function fit( string $document ): string {
		$budget = self::source_budget();

		if ( strlen( $document ) <= $budget ) {
			return $document;
		}

		return mb_strcut( $document, 0, $budget )
			. "\n\n[…truncated: the document was longer than one request allows…]";
	}

	/**
	 * What a scoped page can say BEFORE the student presses anything.
	 *
	 * The response reports what was used; this reports what will be, and only
	 * the second one can change a decision — somebody who learns at render
	 * time that their lecture is too long can attach the section they
	 * actually need instead of waiting out the slowest call in the product
	 * for a partial answer.
	 *
	 * Cached, because it costs a PDF extraction and this runs on a page
	 * render rather than in a request the student has already committed to.
	 * Keyed on the attachment id so replacing the document invalidates it by
	 * construction rather than by remembering to purge.
	 *
	 * @param array{id:int,title:string} $scope Resolved, gated scope.
	 * @return array{used:int,of:int}|null
	 */
	public static function scope_truncation( array $scope ): ?array {
		$post_id = (int) $scope['id'];
		$file_id = (int) get_post_meta( $post_id, '_scholaris_file_id', true );
		$key     = 'eduai_srclen_' . $post_id . '_' . $file_id;
		$length  = get_transient( $key );

		if ( false === $length ) {
			// Bytes, matching source_budget()'s units — see truncation_of().
			$length = strlen( self::scoped_source_text( $scope ) );
			set_transient( $key, $length, DAY_IN_SECONDS );
		}

		$budget = self::source_budget();

		return (int) $length > $budget ? array( 'used' => $budget, 'of' => (int) $length ) : null;
	}

	public static function scoped_source_text( array $scope ): string {
		$post = get_post( (int) $scope['id'] );

		if ( ! $post ) {
			return '';
		}

		$parts = array();

		// A lesson carries its text in the body; a material usually does not.
		$body = trim( wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) );
		if ( '' !== $body ) {
			$parts[] = $body;
		}

		$file_id = (int) get_post_meta( (int) $post->ID, '_scholaris_file_id', true );
		$path    = $file_id ? get_attached_file( $file_id ) : '';

		if ( $path ) {
			$text = EduAI_PDF::extract( $path );
			if ( strlen( trim( $text ) ) > 40 ) {
				$parts[] = $text;
			}
		}

		return trim( implode( "\n\n", $parts ) );
	}

	/**
	 * GET /history
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function history( WP_REST_Request $request ): WP_REST_Response {
		$turns = EduAI_Conversation::history(
			get_current_user_id(),
			(string) $request->get_param( 'thread_id' ),
			30
		);

		foreach ( $turns as &$turn ) {
			$turn['html'] = self::to_html( $turn['content'] );
		}

		return new WP_REST_Response( array( 'messages' => $turns ), 200 );
	}

	/**
	 * Line introducing the source, written for the model rather than the student.
	 *
	 * @param string $ext   Normalised extension.
	 * @param string $label Original file name.
	 * @param string $path  Uploaded temp path.
	 */
	private static function source_heading( string $ext, string $label, string $path ): string {
		if ( 'pptx' !== $ext ) {
			return "Lecture file: {$label}";
		}

		$slides  = EduAI_PDF::slide_count( $path );
		$heading = "Lecture slides: {$label}";

		if ( $slides ) {
			$heading .= " ({$slides} slides)";
		}

		return $heading
			. "\nSlide boundaries are marked '--- Slide n ---'. A line beginning 'Speaker notes:' is the "
			. 'lecturer\'s own note for that slide, not text the students saw on screen — it is usually the '
			. 'fullest statement of the point, so use it.';
	}

	/**
	 * Why a file yielded nothing, phrased so the student can act on it.
	 *
	 * @param string $ext Normalised extension.
	 */
	private static function empty_file_message( string $ext ): string {
		if ( 'pptx' === $ext ) {
			return __( 'That deck has no text on its slides — they are most likely images or photographs. Export it to PDF and upload that instead: the pages can then be read directly.', 'eduai' );
		}

		return __( 'No readable text was found in that file.', 'eduai' );
	}

	/**
	 * Prompt text for each summary style.
	 */
	private static function summary_instruction( string $style ): string {
		$common = "\n\nUse clean Markdown. Do not add a preamble — start directly with the first heading.";

		switch ( $style ) {
			case 'critical':
				return "Read the material above the way the critical-thinking agent would — take nothing on trust:\n"
					. "## What it claims — the substantive claims, stated plainly\n"
					. "## What it rests on — the assumptions, definitions and conditions the claims depend on\n"
					. "## What is actually supported — separate what the material demonstrates from what it merely asserts\n"
					. "## Where it stops being true — the limits, edge cases and competing accounts it does not mention\n"
					. "## Questions worth asking — 5 questions to take to the lecturer, sharpest first\n\n"
					. "Do not manufacture weaknesses. Where the material is sound, say so and move on." . $common;

			case 'brief':
				return "Summarise the lecture above in under 250 words:\n"
					. "## In one paragraph\n## The five things that matter\n## One thing students usually get wrong" . $common;

			case 'exam':
				return "Turn the lecture above into exam preparation:\n"
					. "## Core concepts (with a one-line definition each)\n"
					. "## Formulas, rules or dates worth memorising\n"
					. "## 8 practice questions of increasing difficulty\n"
					. "## Answer key (brief, after all questions)" . $common;

			default:
				return "Produce structured study notes from the lecture above:\n"
					. "## Overview — 3 to 4 sentences\n"
					. "## Key concepts — each with a short explanation in plain language\n"
					. "## Important terms — term: definition\n"
					. "## Worked examples or applications mentioned\n"
					. "## Check your understanding — 5 short questions\n"
					. "## Where students usually struggle" . $common;
		}
	}

	/**
	 * Trim very long inputs so a single request cannot blow the context window.
	 */
	private static function cap( string $text, int $max = 220000 ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		return substr( $text, 0, $max ) . "\n\n[…truncated: the document was longer than one request allows…]";
	}

	/**
	 * Turn LaTeX maths into the plain notation this renderer can actually show.
	 *
	 * There is no MathJax on the page and no LaTeX handling in to_html(), so
	 * `\(f(x)=x^{3}\)` reaches a student as literal backslashes and braces. The
	 * house rules forbid LaTeX and the AiCalc prompt forbids it again in
	 * capitals, and a maths-tuned model still reaches for it by reflex — asked
	 * to differentiate x^3 it emitted `\(x^{3}\)` under both. Prompting is a
	 * request; this is the guarantee, and it sits in to_html() so every surface
	 * that renders model output gets it rather than AiCalc alone.
	 *
	 * Deliberately small: unwrap the delimiters, convert the handful of commands
	 * that actually turn up in student-level maths, and leave anything unknown
	 * alone. Half-translated notation is worse than none, so this never guesses.
	 *
	 * @param string $text Raw model output.
	 */
	public static function plain_maths( string $text ): string {
		// Fenced blocks are NOT exempt. The house rules ask for a multi-line
		// derivation in a fence, so a fence is exactly where a model puts its
		// densest LaTeX — exempting them protected the worst case. Nothing
		// below touches whitespace or line structure, so the alignment a fence
		// exists to preserve survives the rewrite.

		/*
		 * Environments are lifted out whole and put back untouched.
		 *
		 * `\begin{bmatrix} a & b \\ c & d \end{bmatrix}` has no faithful
		 * plain-text rendering — a matrix is a two-dimensional thing and this
		 * function produces a line — so the docblock's rule applies: leave
		 * what you cannot convert alone.
		 *
		 * Except it was NOT leaving it alone. Measured: the row separator
		 * `\\` was being eaten by the trailing-backslash cleanup, so a matrix
		 * arrived with its rows silently joined. That is worse than either
		 * option — the student cannot read it AND cannot copy it into
		 * anything that could. Intact LaTeX is at least recognisable and
		 * paste-able; half-eaten LaTeX is neither.
		 *
		 * The real fix is upstream — a model told not to emit environments
		 * will not — but this is the boundary, and the boundary should not
		 * make things worse than it found them.
		 */
		$environments = array();

		$text = preg_replace_callback(
			'/\\\\begin\{([a-z*]+)\}.*?\\\\end\{\1\}/s',
			static function ( $m ) use ( &$environments ) {
				$token                  = 'EDUAIENV' . count( $environments ) . 'ENDENV';
				$environments[ $token ] = $m[0];
				return $token;
			},
			$text
		) ?? $text;

		// Innermost-first, repeated: \frac{a^{2}}{b} has nested braces, and a
		// single pass of a non-nesting pattern would leave the outer command
		// behind. Three passes covers any nesting depth a student-level
		// expression reaches; anything deeper is left alone rather than
		// half-converted.
		for ( $pass = 0; $pass < 3; $pass++ ) {
			$before = $text;

			// Superscripts and subscripts first, so they stop being braces that
			// block the structural patterns below. x^{3} is x^3, but x^{n+1}
			// keeps brackets or it stops meaning the same thing.
			$text = preg_replace( '/([\^_])\{(\w)\}/', '$1$2', $text ) ?? $text;
			$text = preg_replace( '/([\^_])\{([^{}]*)\}/', '$1($2)', $text ) ?? $text;

			$text = preg_replace( '/\\\\(?:d?frac|tfrac)\s*\{([^{}]*)\}\s*\{([^{}]*)\}/', '($1)/($2)', $text ) ?? $text;
			$text = preg_replace( '/\\\\sqrt\s*\{([^{}]*)\}/', 'sqrt($1)', $text ) ?? $text;

			/*
			 * `\hat{y}` becomes `y-hat`, not `ŷ`, and that is a decision about
			 * register rather than fidelity. Measured on a real generated
			 * lesson: the surrounding text is already plain — `w_1`, `x_j`,
			 * `sum_(j=1)^d` — so a combining accent would be the only Unicode
			 * in an ASCII expression, and it renders inconsistently across
			 * fonts at small sizes. 25 occurrences in one linear-algebra deck.
			 *
			 * In THIS pass and not the symbol map below, because it takes a
			 * braced argument. Anything brace-consuming has to run while the
			 * braces are still there — the degrees handler learned that the
			 * other way, matching `^{\circ}` after an earlier pass had already
			 * rewritten it to `^(\circ)`.
			 */
			$text = preg_replace( '/\\\\hat\s*\{([^{}]*)\}/', '$1-hat', $text ) ?? $text;

			/*
			 * The BRACE-LESS form, which is the same command and not a new
			 * one. LaTeX lets a single-token argument drop its braces, so a
			 * model emits `\hat{y}` and `\hat y_i` interchangeably — and a
			 * handler that only consumes braces catches half the instances
			 * while looking complete, which is the worse failure because the
			 * map appears finished.
			 *
			 * One letter, deliberately: `\hat y_i` accents the `y` and leaves
			 * `_i` a subscript on the result, so `y-hat_i` is right and
			 * swallowing the subscript would not be.
			 */
			$text = preg_replace( '/\\\\hat\s+([A-Za-z])/', '$1-hat', $text ) ?? $text;

			// boldsymbol and mathsf join the unwrappers: a font is typography,
			// and there is no font on the other side to carry it. mathsf is
			// transpose's third spelling after \top and \intercal — `x^\mathsf
			// T` reduces to `x^T`, which is where the other two already land.
			$text = preg_replace( '/\\\\(?:text|mathrm|mathbf|mathsf|boldsymbol|operatorname)\s*\{([^{}]*)\}/', '$1', $text ) ?? $text;
			$text = preg_replace( '/\\\\(?:text|mathrm|mathbf|mathsf|boldsymbol|operatorname)\s+([A-Za-z])/', '$1', $text ) ?? $text;

			if ( $before === $text ) {
				break;
			}
		}

		// Degrees arrive as a superscripted command, so the caret has to go with
		// it or "90 °C" comes out as "90 ^°C".
		// `^(\circ)` is in this list because the brace pass above rewrites
		// `^{\circ}` before this line ever sees it — so the braced form, the
		// one a model actually emits, was reaching students as `90^(°)C`.
		// That is the general trap for every command-specific superscript
		// below: by here, `^{X}` is already `^(X)`.
		$text = str_replace( array( '^\\circ', '^{\\circ}', '^(\\circ)' ), '\\circ', $text );

		// Transpose, same shape as degrees and for the same reason — it is a
		// superscript, so the caret has to travel with it. Note the form with
		// PARENTHESES: the brace pass above has already turned `^{\top}` into
		// `^(\top)` by the time we get here, which is why a plain symbol-map
		// entry renders `x_i^(T)` rather than the `x_i^T` a student writes.
		// Unavoidable in any linear-algebra deck; observed on the page as
		// `x_i^(\top)`.
		$text = str_replace(
			array( '^(\\top)', '^{\\top}', '^\\top', '^(\\intercal)', '^{\\intercal}', '^\\intercal' ),
			'^T',
			$text
		);

		$symbols = array(
			// Bare, without a caret — `A \top B` and friends.
			'\\top' => 'T', '\\intercal' => 'T',
			'\\times' => ' x ', '\\cdot' => '·', '\\div' => ' ÷ ',
			'\\leq' => ' <= ', '\\geq' => ' >= ', '\\neq' => ' != ',
			'\\approx' => ' ~ ', '\\pm' => ' +/- ', '\\infty' => 'infinity',
			'\\circ' => '°', '\\degree' => '°',
			// `\dots` observed 6 times; `\ldots` and `\cdots` ride along
			// because the mapping is mechanical rather than a judgement —
			// unlike `\hat`, which needed a decision about register and so
			// waited for real output before being written at all.
			'\\dots' => '...', '\\ldots' => '...', '\\cdots' => '...',
			'\\varepsilon' => 'epsilon', '\\epsilon' => 'epsilon',
			'\\alpha' => 'alpha', '\\beta' => 'beta', '\\theta' => 'theta',
			'\\lambda' => 'lambda', '\\mu' => 'mu', '\\pi' => 'pi',
			'\\omega' => 'omega', '\\Delta' => 'delta', '\\sum' => 'sum',
			'\\int' => 'integral', '\\partial' => 'd',
			// Sizing hints carry no meaning once the glyphs are plain, and
			// \left/\right were already stripped for exactly that reason —
			// \bigl and \bigr are the same instruction at a fixed size.
			// Observed: \bigl ×2, \bigr ×2. The rest of the family joins them
			// because dropping a sizing hint has one honest answer.
			'\\bigl' => '', '\\bigr' => '', '\\Bigl' => '', '\\Bigr' => '',
			'\\bigg' => '', '\\Bigg' => '', '\\big' => '', '\\Big' => '',
			'\\left' => '', '\\right' => '',
			// Spelled out, matching the Greek letters above rather than
			// inventing "grad": this map's convention is the symbol's name.
			'\\nabla' => 'nabla',
			// The norm's double bar. `||y - Xw||` is how it is written when
			// nobody has LaTeX, and it is unambiguous.
			'\\|' => '||',
			// Operator names, where the whole conversion is dropping the
			// backslash — one honest answer each. Only \min was observed;
			// the rest ride along on that basis, and they cannot touch prose
			// because they only match the escaped form.
			'\\min' => 'min', '\\max' => 'max', '\\lim' => 'lim',
			'\\log' => 'log', '\\ln' => 'ln', '\\exp' => 'exp',
			'\\,' => ' ', '\\;' => ' ', '\\!' => '', '\\quad' => '  ', '\\qquad' => '    ',
		);
		$text = strtr( $text, $symbols );

		// Delimiters last, so the commands inside them were handled first.
		$text = str_replace( array( '\\(', '\\)', '\\[', '\\]' ), '', $text );
		$text = preg_replace( '/\\\\\s/', ' ', $text ) ?? $text;

		// $$…$$ only. Single-$ maths is deliberately left alone: "costs $5 and
		// $7 total" is a perfectly ordinary sentence in an economics lecture and
		// unwrapping it silently deletes both currency signs, which is a worse
		// and much harder-to-spot failure than leaving inline TeX intact. In
		// practice these models emit \(…\), which is handled above and carries
		// no such ambiguity.
		$text = preg_replace( '/\$\$([^\n$]+)\$\$/', '$1', $text ) ?? $text;

		// Environments back, byte-for-byte as they arrived.
		if ( $environments ) {
			$text = strtr( $text, $environments );
		}

		return $text;
	}

	/**
	 * Convert the model's Markdown into a safe HTML subset for the chat bubble.
	 */
	public static function to_html( string $markdown ): string {
		$text = esc_html( self::plain_maths( $markdown ) );

		// Lift fenced code blocks out of the string entirely. Replacing them in
		// place did not protect them: every pass below still ran over the code,
		// so a derivation line reading "- 4" came back as a bullet and "**" as
		// bold. The token is padded with blank lines so it always lands in a
		// block of its own and never ends up nested inside a <p>.
		$fences = array();
		$text   = preg_replace_callback(
			'/```[a-z]*\n(.*?)```/s',
			static function ( $m ) use ( &$fences ) {
				$token            = 'EDUAIPRE' . count( $fences ) . 'ENDPRE';
				$fences[ $token ] = '<pre><code>' . rtrim( $m[1] ) . '</code></pre>';
				return "\n\n" . $token . "\n\n";
			},
			$text
		) ?? $text;

		$text = preg_replace( '/`([^`\n]+)`/', '<code>$1</code>', $text ) ?? $text;
		$text = preg_replace( '/^###\s+(.+)$/m', '<h4>$1</h4>', $text ) ?? $text;
		$text = preg_replace( '/^##\s+(.+)$/m', '<h3>$1</h3>', $text ) ?? $text;
		$text = preg_replace( '/^#\s+(.+)$/m', '<h3>$1</h3>', $text ) ?? $text;
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text ) ?? $text;
		$text = preg_replace( '/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text ) ?? $text;

		// Lists. The indent class is [ \t] rather than \s: in multiline mode \s
		// matches a newline, so ^\s* ate the blank line *before* a list and glued
		// it to the preceding block — which is how a code block ended up inside
		// a paragraph.
		$text = preg_replace( '/^[ \t]*[-*+][ \t]+(.+)$/m', '<li>$1</li>', $text ) ?? $text;
		$text = preg_replace( '/^[ \t]*\d+\.[ \t]+(.+)$/m', '<li>$1</li>', $text ) ?? $text;
		// Group only *consecutive* items. The previous pattern was greedy across
		// newlines, so two lists either side of a heading were merged into one
		// <ul> with the heading swallowed inside it.
		$text = preg_replace( '/(?:^<li>.*<\/li>$\n?)+/m', '<ul>$0</ul>', $text ) ?? $text;

		// Paragraphs.
		$blocks = preg_split( '/\n{2,}/', $text ) ?: array();
		$out    = array();

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			if ( preg_match( '/^<(h3|h4|ul|pre)/', $block ) || preg_match( '/^EDUAIPRE\d+ENDPRE$/', $block ) ) {
				$out[] = $block;
			} else {
				$out[] = '<p>' . nl2br( $block ) . '</p>';
			}
		}

		$html = implode( "\n", $out );

		if ( $fences ) {
			$html = strtr( $html, $fences );
		}

		return wp_kses(
			$html,
			array(
				'p'      => array(), 'br' => array(), 'strong' => array(), 'em' => array(),
				'ul'     => array(), 'ol' => array(), 'li' => array(),
				'h3'     => array(), 'h4' => array(),
				'code'   => array(), 'pre' => array(),
				'a'      => array( 'href' => array(), 'target' => array(), 'rel' => array() ),
			)
		);
	}
}
