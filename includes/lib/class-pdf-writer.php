<?php
/**
 * Minimal, dependency-free PDF writer.
 *
 * Emits a valid PDF 1.4 document using only the base-14 fonts, which every
 * conforming reader already has — so no font files ship, and no external
 * library is bundled.
 *
 * Scope is deliberate: text, lines and rectangles at fixed positions, which is
 * all a printable worksheet needs. It is not a general-purpose PDF library and
 * does not pretend to be — no images, no embedded fonts, no unicode beyond
 * WinAnsi.
 *
 * @package FFLCS
 */

namespace FFLCS\Lib;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a simple multi-page PDF.
 */
class Pdf_Writer {

	/**
	 * Page width in points (US Letter).
	 */
	const PAGE_WIDTH = 612.0;

	/**
	 * Page height in points (US Letter).
	 */
	const PAGE_HEIGHT = 792.0;

	/**
	 * Content streams, one per page.
	 *
	 * @var string[]
	 */
	private array $pages = array();

	/**
	 * The page currently being written.
	 *
	 * @var string
	 */
	private string $current = '';

	/**
	 * Start a new page, banking the current one.
	 */
	public function add_page(): void {
		if ( '' !== $this->current ) {
			$this->pages[] = $this->current;
		}

		$this->current = '';
	}

	/**
	 * Draw text.
	 *
	 * Coordinates are top-left origin, which is how humans lay out a form; the
	 * conversion to PDF's bottom-left origin happens here.
	 *
	 * @param float  $x     Points from the left edge.
	 * @param float  $y     Points from the top edge.
	 * @param string $text  Text.
	 * @param int    $size  Font size in points.
	 * @param bool   $bold  Whether to use the bold face.
	 */
	public function text( float $x, float $y, string $text, int $size = 10, bool $bold = false ): void {
		$font = $bold ? '/F2' : '/F1';

		$this->current .= sprintf(
			"BT %s %d Tf %.2F %.2F Td (%s) Tj ET\n",
			$font,
			$size,
			$x,
			self::PAGE_HEIGHT - $y,
			$this->escape( $text )
		);
	}

	/**
	 * Draw a horizontal rule.
	 *
	 * @param float $x     Left edge.
	 * @param float $y     Points from the top.
	 * @param float $width Length.
	 */
	public function line( float $x, float $y, float $width ): void {
		$this->current .= sprintf(
			"0.5 w %.2F %.2F m %.2F %.2F l S\n",
			$x,
			self::PAGE_HEIGHT - $y,
			$x + $width,
			self::PAGE_HEIGHT - $y
		);
	}

	/**
	 * Draw a rectangle outline.
	 *
	 * @param float $x      Left edge.
	 * @param float $y      Points from the top.
	 * @param float $width  Width.
	 * @param float $height Height.
	 */
	public function rect( float $x, float $y, float $width, float $height ): void {
		$this->current .= sprintf(
			"0.5 w %.2F %.2F %.2F %.2F re S\n",
			$x,
			self::PAGE_HEIGHT - $y - $height,
			$width,
			$height
		);
	}

	/**
	 * Wrap and draw a paragraph, returning the y position after it.
	 *
	 * Wrapping uses an approximate average glyph width rather than real font
	 * metrics — correct metrics would mean shipping AFM tables, and for a
	 * worksheet an approximation that never overflows the column is enough.
	 *
	 * @param float  $x     Left edge.
	 * @param float  $y     Points from the top.
	 * @param float  $width Column width in points.
	 * @param string $text  Text.
	 * @param int    $size  Font size.
	 * @return float The y position below the paragraph.
	 */
	public function paragraph( float $x, float $y, float $width, string $text, int $size = 9 ): float {
		$chars_per_line = max( 10, (int) floor( $width / ( $size * 0.5 ) ) );

		foreach ( explode( "\n", wordwrap( $text, $chars_per_line, "\n", true ) ) as $line ) {
			$this->text( $x, $y, $line, $size );
			$y += $size + 3;
		}

		return $y;
	}

	/**
	 * Render the document.
	 */
	public function output(): string {
		$this->add_page();

		if ( ! $this->pages ) {
			$this->pages[] = '';
		}

		$objects   = array();
		$page_count = count( $this->pages );

		// Object 1 is the catalog, 2 the page tree, 3 and 4 the fonts; page
		// objects and their content streams follow in pairs.
		$kids = array();

		for ( $i = 0; $i < $page_count; $i++ ) {
			$kids[] = ( 5 + ( $i * 2 ) ) . ' 0 R';
		}

		$objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
		$objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $page_count . ' >>';
		$objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

		foreach ( $this->pages as $index => $content ) {
			$page_object    = 5 + ( $index * 2 );
			$content_object = $page_object + 1;

			$objects[ $page_object ] = sprintf(
				'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
				self::PAGE_WIDTH,
				self::PAGE_HEIGHT,
				$content_object
			);

			$objects[ $content_object ] = '<< /Length ' . strlen( $content ) . " >>\nstream\n" . $content . "\nendstream";
		}

		ksort( $objects );

		$pdf     = "%PDF-1.4\n";
		$offsets = array();

		foreach ( $objects as $number => $body ) {
			$offsets[ $number ] = strlen( $pdf );
			$pdf               .= $number . " 0 obj\n" . $body . "\nendobj\n";
		}

		$xref_offset = strlen( $pdf );
		$max         = max( array_keys( $objects ) );

		$pdf .= 'xref' . "\n" . '0 ' . ( $max + 1 ) . "\n";
		$pdf .= "0000000000 65535 f \n";

		for ( $number = 1; $number <= $max; $number++ ) {
			$pdf .= sprintf( "%010d 00000 n \n", $offsets[ $number ] ?? 0 );
		}

		$pdf .= 'trailer' . "\n" . '<< /Size ' . ( $max + 1 ) . ' /Root 1 0 R >>' . "\n";
		$pdf .= 'startxref' . "\n" . $xref_offset . "\n" . '%%EOF';

		return $pdf;
	}

	/**
	 * Escape a string for a PDF literal.
	 *
	 * @param string $text Raw text.
	 */
	private function escape( string $text ): string {
		// Transliterate to the WinAnsi range: the base-14 fonts have no glyphs
		// beyond it, and an unmapped byte renders as a wrong character rather
		// than failing loudly.
		$text = remove_accents( $text );
		$text = preg_replace( '/[^\x20-\x7E]/', '', $text ) ?? '';

		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $text );
	}
}
