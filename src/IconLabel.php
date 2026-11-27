<?php
/**
 * Shared icon label validation.
 *
 * @package IconLibrary
 */

namespace IconLibrary;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Validates the label length using Unicode code points. */
class IconLabel {
	const MAX_LENGTH = 200;

	/**
	 * Counts code points as the REST string schema does, even without mbstring.
	 *
	 * @param mixed $label Unsanitized label.
	 * @return bool
	 */
	public static function is_valid_length( $label ) {
		if ( ! is_string( $label ) || strlen( $label ) > self::MAX_LENGTH * 4 ) {
			return false;
		}
		$count = preg_match_all( '/./us', $label );
		return false !== $count && $count <= self::MAX_LENGTH;
	}
}
