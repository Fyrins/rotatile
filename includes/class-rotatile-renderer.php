<?php
/**
 * Server-side rendering of the rotation.
 *
 * @package Rotatile
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns the `rotation` block attribute into a CSS transform at render time.
 *
 * This is the only place the rotation is ever written. The attribute is
 * declared without a `source`, so it lives in the block comment rather than in
 * the saved markup, and the markup in the database stays exactly what the
 * block's own save function produced. Deactivating the plugin therefore
 * invalidates nothing: the blocks simply stop being tilted.
 */
final class Rotatile_Renderer {

	/**
	 * Class added to the element that carries the transform.
	 *
	 * @var string
	 */
	const ROTATED_CLASS = 'rotatile-rotated';

	/**
	 * Elements skipped when looking for the block's outermost rendering element.
	 *
	 * Block supports and third-party filters may prepend a `<style>` or a
	 * `<link>` to a block's markup, which would otherwise be mistaken for the
	 * block itself. The tag processor does not walk into the contents of
	 * `<style>` and `<script>`, so skipping them cannot land the cursor inside
	 * one of them.
	 *
	 * @var string[]
	 */
	const NON_RENDERING_TAGS = array( 'STYLE', 'SCRIPT', 'LINK', 'META' );

	/**
	 * Applies the rotation to a rendered block.
	 *
	 * @param string $block_content Rendered markup of the block.
	 * @param array  $block         Parsed block, including its attributes.
	 * @return string Markup, rotated when it should be and untouched otherwise.
	 */
	public static function filter_render_block( $block_content, $block ) {
		if ( ! is_string( $block_content ) || '' === $block_content ) {
			return $block_content;
		}

		if ( ! is_array( $block ) || empty( $block['blockName'] ) || ! is_string( $block['blockName'] ) ) {
			return $block_content;
		}

		if ( ! in_array( $block['blockName'], Rotatile_Settings::get_supported_blocks(), true ) ) {
			return $block_content;
		}

		/*
		 * Guards against the plugin surviving a core downgrade below 6.2, the
		 * release the tag processor shipped in. WordPress refuses to activate
		 * it below its 6.6 floor, but it can stay active across a rollback.
		 */
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $block_content;
		}

		$attributes = ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) ? $block['attrs'] : array();
		$raw_angle  = isset( $attributes['rotation'] ) ? $attributes['rotation'] : null;
		$angle      = Rotatile_Settings::sanitize_angle( $raw_angle );

		if ( 0 === $angle ) {
			return $block_content;
		}

		$processor = new WP_HTML_Tag_Processor( $block_content );

		if ( ! self::move_to_target( $processor ) ) {
			return $block_content;
		}

		$processor->add_class( self::ROTATED_CLASS );

		/*
		 * $angle is an integer confined to the configured range, and %d emits
		 * nothing but digits and a possible minus sign.
		 */
		self::append_declaration( $processor, 'transform', sprintf( 'rotate(%ddeg)', $angle ) );

		Rotatile_Assets::enqueue_front_style();

		return $processor->get_updated_html();
	}

	/**
	 * Moves the cursor to the element the transform belongs on.
	 *
	 * The target is the block's outermost rendering element, which in document
	 * order is the first tag that is not one of the metadata elements listed in
	 * NON_RENDERING_TAGS. That element is the one whose layout box the browser
	 * reserves for the block, so rotating it rotates the block and nothing
	 * else.
	 *
	 * Looking for the first element carrying a `wp-block-` class was the
	 * alternative, and it is the more fragile one: a dynamic block that renders
	 * its own markup without calling get_block_wrapper_attributes() has no such
	 * class on its outer element, and the search would then descend into an
	 * inner block and tilt a fragment of the content instead of the block.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor positioned before the first tag.
	 * @return bool True when a usable element was found, false to change nothing.
	 */
	private static function move_to_target( WP_HTML_Tag_Processor $processor ) {
		while ( $processor->next_tag() ) {
			$tag_name = $processor->get_tag();

			if ( ! is_string( $tag_name ) ) {
				return false;
			}

			if ( in_array( $tag_name, self::NON_RENDERING_TAGS, true ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Appends a declaration to the element's inline style.
	 *
	 * The existing value is read, kept whole and rewritten with the new
	 * declaration after it. A missing trailing semicolon is supplied, and the
	 * old value is never parsed: splitting it would corrupt a legitimate
	 * `url(data:image/png;base64,...)` whose own semicolon means nothing to
	 * the cascade.
	 *
	 * Appending also settles what happens when the element already carries the
	 * same property: the later declaration wins, which is plain CSS cascade
	 * rather than a rule of the plugin's own invention.
	 *
	 * @param WP_HTML_Tag_Processor $processor Processor positioned on an element.
	 * @param string                $property  CSS property name.
	 * @param string                $value     CSS value, already validated.
	 * @return void
	 */
	private static function append_declaration( WP_HTML_Tag_Processor $processor, $property, $value ) {
		$declaration = $property . ':' . $value . ';';
		$existing    = $processor->get_attribute( 'style' );

		if ( ! is_string( $existing ) || '' === trim( $existing ) ) {
			$processor->set_attribute( 'style', $declaration );

			return;
		}

		$existing = rtrim( trim( $existing ), "; \t\n\r\0" );

		if ( '' === $existing ) {
			$processor->set_attribute( 'style', $declaration );

			return;
		}

		$processor->set_attribute( 'style', $existing . ';' . $declaration );
	}
}
