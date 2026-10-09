<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * Locales, language codes, month names and the plugin's own visitor-facing strings.
 *
 * Languages are identified internally by primary subtag ("en", "fr"), which is
 * also how the front end matches GTranslate / browser languages.
 */
final class Languages {

	/** Common WordPress locales → native name. */
	public static function common(): array {
		return [
			'en_US' => 'English',
			'en_GB' => 'English (UK)',
			'fr_FR' => 'Français',
			'fr_CA' => 'Français (Canada)',
			'es_ES' => 'Español',
			'es_MX' => 'Español (México)',
			'pt_BR' => 'Português (Brasil)',
			'pt_PT' => 'Português',
			'de_DE' => 'Deutsch',
			'it_IT' => 'Italiano',
			'nl_NL' => 'Nederlands',
			'sw'    => 'Kiswahili',
			'rw'    => 'Kinyarwanda',
			'yor'   => 'Yorùbá',
			'am'    => 'አማርኛ',
			'ar'    => 'العربية',
			'ru_RU' => 'Русский',
			'uk'    => 'Українська',
			'pl_PL' => 'Polski',
			'ro_RO' => 'Română',
			'hi_IN' => 'हिन्दी',
			'id_ID' => 'Bahasa Indonesia',
			'tl'    => 'Tagalog',
			'zh_CN' => '简体中文',
			'ja'    => '日本語',
			'ko_KR' => '한국어',
		];
	}

	/** Primary subtag: fr_CA → fr, yor → yor. */
	public static function code( string $locale ): string {
		$parts = preg_split( '/[_-]/', strtolower( $locale ) );
		return $parts[0] ?? '';
	}

	/** BCP 47 tag for the HTML lang attribute: fr_FR → fr-FR. */
	public static function html_lang( string $locale ): string {
		return str_replace( '_', '-', $locale );
	}

	public static function is_rtl( string $code ): bool {
		return in_array( $code, [ 'ar', 'he', 'fa', 'ur' ], true );
	}

	public static function name( string $locale ): string {
		$common = self::common();
		return $common[ $locale ] ?? $locale;
	}

	/** Admin-facing labels for the plugin-generated strings. */
	public static function string_keys(): array {
		return [
			'prev'  => __( 'Previous button', 'postful' ),
			'next'  => __( 'Next button', 'postful' ),
			'today' => __( 'Today button', 'postful' ),
			'index' => __( 'Index heading', 'postful' ),
			'empty' => __( 'No entry message', 'postful' ),
			'error' => __( 'Loading error message', 'postful' ),
		];
	}

	/** Built-in defaults. Languages without a set fall back to English, editable per type. */
	public static function default_strings( string $code ): array {
		$sets = [
			'en' => [
				'prev'  => 'Previous',
				'next'  => 'Next',
				'today' => 'Today',
				'index' => 'Browse by month',
				'empty' => 'Nothing has been published for this day yet.',
				'error' => 'This couldn’t be loaded. Please try again.',
			],
			'fr' => [
				'prev'  => 'Précédent',
				'next'  => 'Suivant',
				'today' => 'Aujourd’hui',
				'index' => 'Parcourir par mois',
				'empty' => 'Rien n’a encore été publié pour ce jour.',
				'error' => 'Le contenu n’a pas pu être chargé. Veuillez réessayer.',
			],
			'es' => [
				'prev'  => 'Anterior',
				'next'  => 'Siguiente',
				'today' => 'Hoy',
				'index' => 'Explorar por mes',
				'empty' => 'Todavía no se ha publicado nada para este día.',
				'error' => 'No se pudo cargar. Inténtalo de nuevo.',
			],
			'pt' => [
				'prev'  => 'Anterior',
				'next'  => 'Seguinte',
				'today' => 'Hoje',
				'index' => 'Navegar por mês',
				'empty' => 'Ainda não foi publicado nada para este dia.',
				'error' => 'Não foi possível carregar. Tente novamente.',
			],
			'de' => [
				'prev'  => 'Zurück',
				'next'  => 'Weiter',
				'today' => 'Heute',
				'index' => 'Nach Monat durchsuchen',
				'empty' => 'Für diesen Tag wurde noch nichts veröffentlicht.',
				'error' => 'Konnte nicht geladen werden. Bitte erneut versuchen.',
			],
			'sw' => [
				'prev'  => 'Iliyotangulia',
				'next'  => 'Inayofuata',
				'today' => 'Leo',
				'index' => 'Vinjari kwa mwezi',
				'empty' => 'Bado hakuna kilichochapishwa kwa siku hii.',
				'error' => 'Imeshindwa kupakia. Tafadhali jaribu tena.',
			],
		];
		return $sets[ $code ] ?? $sets['en'];
	}

	/** The type's configured strings for one language, filled from defaults. */
	public static function strings( array $type, string $code ): array {
		$saved = $type['strings'][ $code ] ?? [];
		$out   = self::default_strings( $code );
		foreach ( $out as $key => $default ) {
			if ( isset( $saved[ $key ] ) && '' !== trim( (string) $saved[ $key ] ) ) {
				$out[ $key ] = (string) $saved[ $key ];
			}
		}
		return $out;
	}

	/**
	 * Month name in a content language. Built-in tables first, because the
	 * site may not have that language pack installed; intl as a fallback.
	 */
	public static function month( int $month, string $code, string $locale = '' ): string {
		$tables = [
			'en' => [ 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December' ],
			'fr' => [ 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' ],
			'es' => [ 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' ],
			'pt' => [ 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro' ],
			'de' => [ 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' ],
			'it' => [ 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre' ],
			'nl' => [ 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december' ],
			'sw' => [ 'Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba' ],
		];
		if ( isset( $tables[ $code ][ $month - 1 ] ) ) {
			return $tables[ $code ][ $month - 1 ];
		}
		if ( class_exists( '\IntlDateFormatter' ) ) {
			$fmt = new \IntlDateFormatter( $locale ?: $code, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'LLLL' );
			$out = $fmt->format( gmmktime( 0, 0, 0, $month, 1, 2024 ) );
			if ( is_string( $out ) && '' !== $out ) {
				return $out;
			}
		}
		return $tables['en'][ $month - 1 ] ?? '';
	}

	/** "March 5" / "5 mars" / "1er janvier" / "5. März". */
	public static function day_label( int $month, int $day, string $code, string $locale = '' ): string {
		$m = self::month( $month, $code, $locale );
		switch ( $code ) {
			case 'en':
				return $m . ' ' . $day;
			case 'fr':
				return ( 1 === $day ? '1er' : (string) $day ) . ' ' . $m;
			case 'de':
				return $day . '. ' . $m;
			case 'es':
			case 'pt':
				return $day . ' de ' . $m;
			default:
				return $day . ' ' . $m;
		}
	}
}
