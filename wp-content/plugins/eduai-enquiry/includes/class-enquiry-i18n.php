<?php
/**
 * Two languages, shipped rather than translated later.
 *
 * WHY A PHRASE TABLE AND NOT JUST __()
 *
 * WordPress translation switches on the SITE's locale. This has to switch on
 * the VISITOR's, mid-conversation, because somebody who opens in English and
 * then types Arabic should be answered in Arabic without reloading anything.
 * Those are different questions and only one of them `__()` can answer.
 *
 * So: interface strings that must follow the visitor live here in both
 * languages. Admin strings, which follow the site, stay on `__()` where they
 * belong.
 *
 * Arabic here is Modern Standard, and deliberately plain. A sales assistant
 * that reads as machine-translated marketing is worse than one that reads as a
 * clear, slightly formal human.
 *
 * @package EduAI_Enquiry
 */

defined( 'ABSPATH' ) || exit;

/**
 * Visitor-facing language.
 */
class EduAI_Enquiry_I18n {

	/**
	 * Supported languages, and how each is written.
	 */
	public const LANGUAGES = array(
		'en' => array( 'label' => 'English', 'dir' => 'ltr' ),
		'ar' => array( 'label' => 'العربية', 'dir' => 'rtl' ),
	);

	/**
	 * Every string the widget can show.
	 */
	private const STRINGS = array(
		'launcher'          => array( 'en' => 'Ask about courses', 'ar' => 'اسأل عن الدورات' ),
		'title'             => array( 'en' => 'Course assistant', 'ar' => 'مساعد الدورات' ),
		'greeting'          => array(
			'en' => 'Hello. I can help you find a course, explain how to enrol, or put you in touch with a person. What are you looking for?',
			'ar' => 'مرحباً. يمكنني مساعدتك في إيجاد دورة مناسبة، أو شرح خطوات التسجيل، أو توصيلك بأحد موظفينا. ما الذي تبحث عنه؟',
		),
		'placeholder'       => array( 'en' => 'Type your question…', 'ar' => 'اكتب سؤالك…' ),
		'send'              => array( 'en' => 'Send', 'ar' => 'إرسال' ),
		'close'             => array( 'en' => 'Close', 'ar' => 'إغلاق' ),
		'thinking'          => array( 'en' => 'Thinking…', 'ar' => 'جارٍ التفكير…' ),
		'unavailable'       => array(
			'en' => 'I cannot answer right now. You can leave your details and someone will come back to you.',
			'ar' => 'لا أستطيع الرد الآن. يمكنك ترك بياناتك وسيتواصل معك أحد الموظفين.',
		),
		'not_listed'        => array( 'en' => 'not listed', 'ar' => 'غير مذكور' ),
		'duration'          => array( 'en' => 'Duration', 'ar' => 'المدة' ),
		'format'            => array( 'en' => 'Format', 'ar' => 'نمط الدراسة' ),
		'price'             => array( 'en' => 'Price', 'ar' => 'الرسوم' ),
		'schedule'          => array( 'en' => 'Starts', 'ar' => 'يبدأ' ),
		'free'              => array( 'en' => 'Free', 'ar' => 'مجاني' ),
		'open'              => array( 'en' => 'Open access', 'ar' => 'وصول مفتوح' ),
		'form_title'        => array( 'en' => 'Leave your details', 'ar' => 'اترك بياناتك' ),
		'view_course'       => array( 'en' => 'View course', 'ar' => 'عرض الدورة' ),
		'enrol_now'         => array( 'en' => 'How to enrol', 'ar' => 'كيفية التسجيل' ),
		'no_courses'        => array(
			'en' => 'I could not find a course matching that. Here is everything we currently run.',
			'ar' => 'لم أجد دورة مطابقة لطلبك. إليك كل ما نقدمه حالياً.',
		),
		'nothing_at_all'    => array(
			'en' => 'There are no published courses on this site yet.',
			'ar' => 'لا توجد دورات منشورة على هذا الموقع حتى الآن.',
		),
		'ask_name'          => array( 'en' => 'What name should I use?', 'ar' => 'ما الاسم الذي أستخدمه؟' ),
		'ask_contact'       => array(
			'en' => 'What is the best email or phone number to reach you on?',
			'ar' => 'ما هو أفضل بريد إلكتروني أو رقم هاتف للتواصل معك؟',
		),
		'ask_interest'      => array( 'en' => 'Which course or subject is this about?', 'ar' => 'عن أي دورة أو موضوع؟' ),
		'consent'           => array(
			'en' => 'I agree to be contacted about this enquiry.',
			'ar' => 'أوافق على التواصل معي بخصوص هذا الطلب.',
		),
		'consent_required'  => array(
			'en' => 'I need your agreement before I can pass your details on.',
			'ar' => 'أحتاج إلى موافقتك قبل تمرير بياناتك.',
		),
		'lead_thanks'       => array(
			'en' => 'Thank you. Your details are with our team and someone will be in touch.',
			'ar' => 'شكراً لك. وصلت بياناتك إلى فريقنا وسيتواصل معك أحدهم قريباً.',
		),
		'lead_failed'       => array(
			'en' => 'Something went wrong saving that. Please try again, or use the contact page.',
			'ar' => 'حدث خطأ أثناء الحفظ. يرجى المحاولة مرة أخرى أو استخدام صفحة التواصل.',
		),
		'human_intro'       => array(
			'en' => 'Of course. Leave your name and how to reach you, and a person will follow up.',
			'ar' => 'بالطبع. اترك اسمك وطريقة التواصل معك، وسيتابع معك أحد الموظفين.',
		),
		'human_hours'       => array( 'en' => 'Our team replies during working hours.', 'ar' => 'يرد فريقنا خلال ساعات العمل.' ),
		'register_steps'    => array( 'en' => 'How to enrol', 'ar' => 'خطوات التسجيل' ),
		'step_open'         => array( 'en' => 'Open the course page.', 'ar' => 'افتح صفحة الدورة.' ),
		'step_account'      => array( 'en' => 'Create an account or sign in.', 'ar' => 'أنشئ حساباً أو سجّل الدخول.' ),
		'step_enrol'        => array( 'en' => 'Choose enrol, and complete any payment.', 'ar' => 'اختر التسجيل، وأكمل الدفع إن وجد.' ),
		'step_start'        => array( 'en' => 'The course appears in your account straight away.', 'ar' => 'ستظهر الدورة في حسابك مباشرة.' ),
		'price_unknown_note'=> array(
			'en' => 'I do not have the fee for that one on file — I can ask someone to confirm it.',
			'ar' => 'لا تتوفر لدي الرسوم لهذه الدورة — يمكنني طلب تأكيدها من أحد الموظفين.',
		),
		'f_email'           => array( 'en' => 'Email', 'ar' => 'البريد الإلكتروني' ),
		'f_phone'           => array( 'en' => 'Phone', 'ar' => 'رقم الهاتف' ),
		'switch_language'   => array( 'en' => 'العربية', 'ar' => 'English' ),
		'restart'           => array( 'en' => 'Start over', 'ar' => 'ابدأ من جديد' ),
		'chip_browse'       => array( 'en' => 'Show me courses', 'ar' => 'اعرض الدورات' ),
		'chip_recommend'    => array( 'en' => 'Help me choose', 'ar' => 'ساعدني في الاختيار' ),
		'chip_enrol'        => array( 'en' => 'How do I enrol?', 'ar' => 'كيف أسجل؟' ),
		'chip_human'        => array( 'en' => 'Talk to a person', 'ar' => 'التحدث مع موظف' ),
	);

	/**
	 * Load admin translations.
	 */
	public static function init(): void {
		load_plugin_textdomain( 'eduai-enquiry', false, dirname( plugin_basename( EDUAI_ENQUIRY_FILE ) ) . '/languages' );
	}

	/**
	 * A visitor-facing string.
	 *
	 * @param string $key      Phrase key.
	 * @param string $language 'en' or 'ar'.
	 */
	public static function t( string $key, string $language = 'en' ): string {
		$language = isset( self::LANGUAGES[ $language ] ) ? $language : 'en';

		if ( ! isset( self::STRINGS[ $key ] ) ) {
			return '';
		}

		return self::STRINGS[ $key ][ $language ] ?? self::STRINGS[ $key ]['en'];
	}

	/**
	 * Every string at once, for handing to the browser.
	 */
	public static function all( string $language = 'en' ): array {
		$out = array();

		foreach ( array_keys( self::STRINGS ) as $key ) {
			$out[ $key ] = self::t( $key, $language );
		}

		return $out;
	}

	/**
	 * Writing direction.
	 */
	public static function dir( string $language ): string {
		return self::LANGUAGES[ $language ]['dir'] ?? 'ltr';
	}
}
