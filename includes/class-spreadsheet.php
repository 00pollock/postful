<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal .xlsx / .csv reading and .xlsx writing, with no external libraries
 * (PHP's zip and SimpleXML only). Built for the devotional workbooks: one sheet
 * per month, a header row, and long text cells with paragraphs and bold/italic.
 *
 * A read cell is ['text' => plain text, 'html' => <p> paragraphs with
 * <strong>/<em>, 'num' => float|null for numeric cells], or null when empty.
 */
final class Spreadsheet {

	private const NS      = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const NS_REL  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const NS_PKG  = 'http://schemas.openxmlformats.org/package/2006/relationships';
	private const MAX_XML = 50 * MB_IN_BYTES; // Refuse absurdly large parts (zip bombs).

	public static function can_read_xlsx(): bool {
		return class_exists( '\ZipArchive' ) && function_exists( 'simplexml_load_string' );
	}

	/**
	 * @return array<int,array{name:string,rows:array<int,array<int,?array>>}> sheets in workbook order
	 */
	public static function read( string $path, string $ext ) {
		return 'csv' === $ext ? self::read_csv( $path ) : self::read_xlsx( $path );
	}

	// ------------------------------------------------------------------
	// CSV
	// ------------------------------------------------------------------

	private static function read_csv( string $path ): array {
		$csv = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$csv = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $csv, 'UTF-8' ) ) {
			$csv = mb_convert_encoding( $csv, 'UTF-8', 'Windows-1252' );
		}
		$first = (string) strtok( $csv, "\n" );
		$delim = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';

		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $handle, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		$rows = [];
		while ( ( $cells = fgetcsv( $handle, 0, $delim, '"', '' ) ) !== false ) {
			$rows[] = array_map( fn( $v ) => self::plain_cell( (string) $v ), $cells );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return [ [ 'name' => '', 'rows' => $rows ] ];
	}

	/** A cell from plain text: line breaks become paragraphs. */
	private static function plain_cell( string $text ): ?array {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		if ( '' === trim( $text ) ) {
			return null;
		}
		$paras = array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' );
		return [
			'text' => trim( $text ),
			'html' => implode( '', array_map( fn( $p ) => '<p>' . esc_html( $p ) . '</p>', $paras ) ),
			'num'  => is_numeric( trim( $text ) ) ? (float) $text : null,
		];
	}

	// ------------------------------------------------------------------
	// XLSX
	// ------------------------------------------------------------------

	private static function xml( \ZipArchive $zip, string $name ): ?\SimpleXMLElement {
		$stat = $zip->statName( $name );
		if ( ! $stat || $stat['size'] > self::MAX_XML ) {
			return null;
		}
		$raw = $zip->getFromName( $name );
		if ( false === $raw ) {
			return null;
		}
		// LIBXML_NONET: never fetch anything referenced by the file.
		$xml = simplexml_load_string( $raw, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT );
		return false === $xml ? null : $xml;
	}

	private static function read_xlsx( string $path ) {
		if ( ! self::can_read_xlsx() ) {
			return new \WP_Error( 'xlsx', __( 'This server can’t open Excel files (the PHP zip extension is missing). Save the workbook as CSV, one sheet at a time, or ask your host to enable zip.', 'postful' ) );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'xlsx', __( 'That file couldn’t be opened as an Excel workbook (.xlsx).', 'postful' ) );
		}

		$shared = [];
		$ss     = self::xml( $zip, 'xl/sharedStrings.xml' );
		if ( $ss ) {
			foreach ( $ss->children( self::NS )->si as $si ) {
				$shared[] = self::rich( $si );
			}
		}

		$book = self::xml( $zip, 'xl/workbook.xml' );
		$rels = self::xml( $zip, 'xl/_rels/workbook.xml.rels' );
		if ( ! $book || ! $rels ) {
			$zip->close();
			return new \WP_Error( 'xlsx', __( 'That file doesn’t look like an Excel workbook (.xlsx).', 'postful' ) );
		}
		$targets = [];
		foreach ( $rels->children( self::NS_PKG ) as $rel ) {
			$targets[ self::attr( $rel, 'Id' ) ] = self::attr( $rel, 'Target' );
		}

		$sheets = [];
		foreach ( $book->children( self::NS )->sheets->sheet as $sheet ) {
			$rid    = (string) $sheet->attributes( self::NS_REL )['id'];
			$target = $targets[ $rid ] ?? '';
			$target = str_starts_with( $target, '/' ) ? ltrim( $target, '/' ) : 'xl/' . $target;
			$xml    = self::xml( $zip, $target );
			if ( $xml ) {
				$sheets[] = [ 'name' => self::attr( $sheet, 'name' ), 'rows' => self::sheet_rows( $xml, $shared ) ];
			}
		}
		$zip->close();
		return $sheets;
	}

	private static function sheet_rows( \SimpleXMLElement $xml, array $shared ): array {
		$rows = [];
		$data = $xml->children( self::NS )->sheetData;
		if ( ! $data ) {
			return $rows;
		}
		foreach ( $data->row as $row ) {
			$r     = max( 1, (int) self::attr( $row, 'r' ) ) - 1;
			$cells = [];
			foreach ( $row->c as $c ) {
				$col           = self::col_index( self::attr( $c, 'r' ) );
				$cells[ $col ] = self::cell( $c, $shared );
			}
			if ( $cells ) {
				$max  = max( array_keys( $cells ) );
				$line = [];
				for ( $i = 0; $i <= $max; $i++ ) {
					$line[] = $cells[ $i ] ?? null;
				}
				$rows[ $r ] = $line;
			}
		}
		ksort( $rows );
		return array_values( $rows );
	}

	/**
	 * An unprefixed attribute. ($node['x'] silently returns nothing once SimpleXML
	 * has stepped into the spreadsheet namespace, so always read them this way.)
	 */
	private static function attr( \SimpleXMLElement $node, string $name ): string {
		$attrs = $node->attributes();
		return isset( $attrs[ $name ] ) ? (string) $attrs[ $name ] : '';
	}

	private static function col_index( string $ref ): int {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $ref ) );
		$n       = 0;
		foreach ( str_split( $letters ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return max( 0, $n - 1 );
	}

	private static function cell( \SimpleXMLElement $c, array $shared ): ?array {
		$t = self::attr( $c, 't' );
		$x = $c->children( self::NS );
		if ( 's' === $t ) {
			return $shared[ (int) $x->v ] ?? null;
		}
		if ( 'inlineStr' === $t ) {
			return $x->is ? self::rich( $x->is ) : null;
		}
		$v = isset( $x->v ) ? (string) $x->v : '';
		if ( '' === $v ) {
			return null;
		}
		if ( 'str' === $t || 'b' === $t || 'e' === $t ) {
			return self::plain_cell( $v );
		}
		$cell        = self::plain_cell( $v );
		$cell['num'] = is_numeric( $v ) ? (float) $v : null;
		return $cell;
	}

	/**
	 * Text from <si> / <is>: plain <t>, or rich runs <r><rPr><b/><i/></rPr><t>…</t></r>.
	 * Line breaks become paragraphs; tags never cross a paragraph.
	 */
	private static function rich( \SimpleXMLElement $node ): ?array {
		$n     = $node->children( self::NS );
		$runs  = [];
		if ( isset( $n->t ) && ! isset( $n->r ) ) {
			$runs[] = [ (string) $n->t, false, false ];
		}
		foreach ( $n->r as $r ) {
			$rpr    = $r->rPr;
			$bold   = $rpr && isset( $rpr->b ) && ! in_array( self::attr( $rpr->b, 'val' ), [ '0', 'false' ], true );
			$italic = $rpr && isset( $rpr->i ) && ! in_array( self::attr( $rpr->i, 'val' ), [ '0', 'false' ], true );
			$runs[] = [ (string) $r->t, $bold, $italic ];
		}

		$paras = [ '' ];
		$plain = '';
		foreach ( $runs as [ $text, $bold, $italic ] ) {
			$text   = str_replace( [ "\r\n", "\r" ], "\n", $text );
			$plain .= $text;
			foreach ( explode( "\n", $text ) as $i => $piece ) {
				if ( $i > 0 ) {
					$paras[] = '';
				}
				if ( '' === $piece ) {
					continue;
				}
				$html = esc_html( $piece );
				if ( $italic ) {
					$html = '<em>' . $html . '</em>';
				}
				if ( $bold ) {
					$html = '<strong>' . $html . '</strong>';
				}
				$paras[ count( $paras ) - 1 ] .= $html;
			}
		}
		if ( '' === trim( $plain ) ) {
			return null;
		}
		$paras = array_filter( $paras, fn( $p ) => '' !== trim( wp_strip_all_tags( $p ) ) );
		return [
			'text' => trim( $plain ),
			'html' => implode( '', array_map( fn( $p ) => '<p>' . trim( $p ) . '</p>', $paras ) ),
			'num'  => null,
		];
	}

	// ------------------------------------------------------------------
	// Writing
	// ------------------------------------------------------------------

	/**
	 * Build an .xlsx file and return its path (caller deletes it).
	 *
	 * @param array $sheets [ ['name' => 'Jan', 'rows' => [ [ cell, … ], … ] ], … ]
	 *                      A cell is a plain string or ['html' => '<p>…</p>'] (kept as rich text).
	 *                      The first row is written bold (header).
	 */
	public static function write( array $sheets ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return new \WP_Error( 'xlsx', __( 'This server can’t create Excel files (the PHP zip extension is missing).', 'postful' ) );
		}
		$path = wp_tempnam( 'postful-export' );
		$zip  = new \ZipArchive();
		if ( true !== $zip->open( $path, \ZipArchive::OVERWRITE ) ) {
			return new \WP_Error( 'xlsx', __( 'Couldn’t create the Excel file.', 'postful' ) );
		}

		$sheet_xml = '';
		$rels      = '';
		$types     = '';
		foreach ( array_values( $sheets ) as $i => $sheet ) {
			$n          = $i + 1;
			$sheet_xml .= sprintf( '<sheet name="%s" sheetId="%d" r:id="rId%d"/>', self::x( mb_substr( $sheet['name'], 0, 31 ) ), $n, $n );
			$rels      .= sprintf( '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>', $n, $n );
			$types     .= sprintf( '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', $n );
			$zip->addFromString( "xl/worksheets/sheet{$n}.xml", self::sheet_xml( $sheet['rows'] ) );
		}
		$styles_rel = count( $sheets ) + 1;

		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $types . '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="' . self::NS . '" xmlns:r="' . self::NS_REL . '"><sheets>' . $sheet_xml . '</sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . $styles_rel . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
		// Style 0: normal, wrapped, top-aligned. Style 1: bold header.
		$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="' . self::NS . '"><fonts count="2"><font><sz val="12"/><name val="Calibri"/></font><font><b/><sz val="12"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf><xf fontId="1" applyFont="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf></cellXfs></styleSheet>' );
		$zip->close();
		return $path;
	}

	private static function sheet_xml( array $rows ): string {
		$out = '';
		foreach ( array_values( $rows ) as $r => $row ) {
			$cells = '';
			foreach ( array_values( $row ) as $c => $cell ) {
				$ref   = self::col_letter( $c ) . ( $r + 1 );
				$style = 0 === $r ? ' s="1"' : '';
				if ( is_array( $cell ) ) {
					$cells .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is>' . self::runs_xml( (string) ( $cell['html'] ?? '' ) ) . '</is></c>';
				} elseif ( '' !== (string) $cell ) {
					$cells .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::x( (string) $cell ) . '</t></is></c>';
				}
			}
			$out .= '<row r="' . ( $r + 1 ) . '">' . $cells . '</row>';
		}
		$cols = '<cols><col min="1" max="1" width="14" customWidth="1"/><col min="2" max="2" width="34" customWidth="1"/><col min="3" max="4" width="40" customWidth="1"/><col min="5" max="30" width="90" customWidth="1"/></cols>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="' . self::NS . '"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>' . $cols . '<sheetData>' . $out . '</sheetData></worksheet>';
	}

	/** HTML paragraphs with <strong>/<em> → rich-text runs; paragraphs separated by a blank line. */
	private static function runs_xml( string $html ): string {
		$runs = [];
		if ( '' !== trim( $html ) ) {
			$doc = new \DOMDocument();
			libxml_use_internal_errors( true );
			$doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();
			$blocks = [ 'p', 'div', 'h1', 'h2', 'h3', 'h4', 'li', 'blockquote' ];
			$walk   = function ( \DOMNode $node, bool $bold, bool $italic ) use ( &$walk, &$runs, $blocks ) {
				foreach ( $node->childNodes as $child ) {
					if ( XML_TEXT_NODE === $child->nodeType ) {
						$runs[] = [ $child->nodeValue, $bold, $italic ];
					} elseif ( XML_ELEMENT_NODE === $child->nodeType ) {
						$tag = strtolower( $child->nodeName );
						if ( 'br' === $tag ) {
							$runs[] = [ "\n", false, false ];
							continue;
						}
						$walk( $child, $bold || in_array( $tag, [ 'strong', 'b' ], true ), $italic || in_array( $tag, [ 'em', 'i' ], true ) );
						if ( in_array( $tag, $blocks, true ) ) {
							$runs[] = [ "\n\n", false, false ];
						}
					}
				}
			};
			$walk( $doc->documentElement, false, false );
		}
		// Trim trailing paragraph breaks.
		while ( $runs && '' === trim( end( $runs )[0] ) ) {
			array_pop( $runs );
		}
		if ( ! $runs ) {
			return '<t></t>';
		}
		$out = '';
		foreach ( $runs as [ $text, $bold, $italic ] ) {
			$text = preg_replace( "/\n{3,}/", "\n\n", $text );
			$rpr  = ( $bold ? '<b/>' : '' ) . ( $italic ? '<i/>' : '' );
			$out .= '<r>' . ( $rpr ? '<rPr>' . $rpr . '</rPr>' : '' ) . '<t xml:space="preserve">' . self::x( $text ) . '</t></r>';
		}
		return $out;
	}

	private static function col_letter( int $i ): string {
		$s = '';
		for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	/** XML-escape, dropping characters XML can't hold. */
	private static function x( string $s ): string {
		$s = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s );
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}
