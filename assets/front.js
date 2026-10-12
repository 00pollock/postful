/*
 * Postful front end.
 *
 * Language: a follower, never a source of truth. Reads the language the site
 * is already showing (GTranslate cookie, <html lang>, /fr/ path, browser) and
 * reveals the matching copy. Never writes cookies, storage or URLs.
 *
 * Navigation: index and previous/next swap the current entry in place via
 * REST. The address bar never changes, so a refresh returns to today.
 */
( function () {
	'use strict';

	var states = {};

	/* ---- Language detection -------------------------------------------- */

	function primary( tag ) {
		return tag ? String( tag ).toLowerCase().split( /[-_]/ )[ 0 ] : '';
	}

	function readCookie( name ) {
		var m = document.cookie.match( new RegExp( '(?:^|;\\s*)' + name + '=([^;]*)' ) );
		return m ? decodeURIComponent( m[ 1 ] ) : '';
	}

	function detect( d ) {
		var resolve = function ( code ) {
			return d.langs.indexOf( code ) !== -1 ? code : d.def;
		};

		// 0. Optional read-only preview (?lang=fr), off unless enabled in settings.
		if ( d.override && window.URLSearchParams ) {
			var preview = new URLSearchParams( window.location.search ).get( 'lang' );
			if ( preview ) {
				return resolve( primary( preview ) );
			}
		}

		// 1. GTranslate cookie: "/en/fr" → fr.
		var gt = readCookie( 'googtrans' );
		if ( gt ) {
			var parts = gt.split( '/' ).filter( Boolean );
			if ( parts.length ) {
				return resolve( primary( parts[ parts.length - 1 ] ) );
			}
		}

		// 2. <html lang>, once it differs from the site's own language or
		//    GTranslate has marked the page as translated.
		var html = document.documentElement;
		var htmlLang = primary( html.getAttribute( 'lang' ) );
		if ( htmlLang && ( htmlLang !== d.siteLang || /\btranslated-(ltr|rtl)\b/.test( html.className ) ) ) {
			return resolve( htmlLang );
		}

		// 3. GTranslate subdirectory mode: /fr/…
		var seg = window.location.pathname.split( '/' )[ 1 ] || '';
		if ( /^[a-z]{2,3}(-[a-z]{2,4})?$/i.test( seg ) && d.langs.indexOf( primary( seg ) ) !== -1 ) {
			return primary( seg );
		}

		// 4. Browser's first language — the same thing GTranslate auto-switches on,
		//    so on a first visit we already agree with what it is about to do.
		var nav = ( navigator.languages && navigator.languages[ 0 ] ) || navigator.language;
		if ( nav ) {
			return resolve( primary( nav ) );
		}

		// 5. Default.
		return d.def;
	}

	function applyLanguage() {
		Object.keys( states ).forEach( function ( type ) {
			var lang = detect( states[ type ].d );
			document.querySelectorAll( '.postful[data-pf-type="' + type + '"]' ).forEach( function ( root ) {
				root.setAttribute( 'data-pf-lang', lang );
				root.querySelectorAll( '.pf-lang' ).forEach( function ( el ) {
					el.hidden = el.getAttribute( 'data-lang' ) !== lang;
				} );
			} );
		} );
	}

	/* ---- Today in the site's timezone ------------------------------------ */

	function siteToday( tz ) {
		var now = new Date();
		var off = /^([+-])(\d{2}):(\d{2})$/.exec( tz || '' );
		if ( off ) {
			var mins = ( off[ 1 ] === '-' ? -1 : 1 ) * ( parseInt( off[ 2 ], 10 ) * 60 + parseInt( off[ 3 ], 10 ) );
			var t = new Date( now.getTime() + mins * 60000 );
			return ( t.getUTCMonth() + 1 ) * 100 + t.getUTCDate();
		}
		try {
			var m = 0;
			var day = 0;
			new Intl.DateTimeFormat( 'en-US', { timeZone: tz, month: 'numeric', day: 'numeric' } )
				.formatToParts( now )
				.forEach( function ( p ) {
					if ( p.type === 'month' ) {
						m = parseInt( p.value, 10 );
					} else if ( p.type === 'day' ) {
						day = parseInt( p.value, 10 );
					}
				} );
			return m && day ? m * 100 + day : null;
		} catch ( e ) {
			return null;
		}
	}

	/* ---- In-place navigation --------------------------------------------- */

	function neighbour( state, dir ) {
		var list = state.d.entries;
		var i;
		if ( ! list.length ) {
			return null;
		}
		if ( dir > 0 ) {
			for ( i = 0; i < list.length; i++ ) {
				if ( list[ i ][ 0 ] > state.cur ) {
					return list[ i ][ 0 ];
				}
			}
			return list[ 0 ][ 0 ]; // Dec 31 → Jan 1
		}
		for ( i = list.length - 1; i >= 0; i-- ) {
			if ( list[ i ][ 0 ] < state.cur ) {
				return list[ i ][ 0 ];
			}
		}
		return list[ list.length - 1 ][ 0 ];
	}

	function highlight( state ) {
		document.querySelectorAll( '.pf-index[data-pf-type="' + state.d.type + '"]' ).forEach( function ( index ) {
			index.querySelectorAll( '.pf-day' ).forEach( function ( btn ) {
				var on = parseInt( btn.getAttribute( 'data-pf-sort' ), 10 ) === state.cur;
				btn.classList.toggle( 'is-current', on );
				if ( on ) {
					btn.setAttribute( 'aria-current', 'true' );
					var month = btn.closest( 'details' );
					if ( month ) {
						month.open = true;
					}
				} else {
					btn.removeAttribute( 'aria-current' );
				}
			} );
		} );
	}

	function errorHtml( state ) {
		var article = document.createElement( 'article' );
		article.className = 'pf-entry pf-entry--empty';
		state.d.langs.forEach( function ( code ) {
			var div = document.createElement( 'div' );
			div.className = 'pf-lang';
			div.setAttribute( 'data-lang', code );
			var p = document.createElement( 'p' );
			p.className = 'pf-message';
			p.textContent = state.d.strings[ code ].error;
			div.appendChild( p );
			article.appendChild( div );
		} );
		return article.outerHTML;
	}

	/** @param {{html:string, aside:string}} res */
	function swap( targets, res, scroll ) {
		targets.forEach( function ( t ) {
			var part = t.getAttribute( 'data-pf-part' );
			t.innerHTML = part && res[ part ] !== undefined ? res[ part ] : res.html;
			t.classList.remove( 'is-loading' );
			t.removeAttribute( 'aria-busy' );
		} );
		applyLanguage();
		var main = targets.filter( function ( t ) { return t.getAttribute( 'data-pf-part' ) !== 'body'; } )[ 0 ];
		if ( scroll && main ) {
			var rect = main.getBoundingClientRect();
			if ( rect.top < 0 || rect.top > window.innerHeight * 0.6 ) {
				main.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}
	}

	function go( state, sort, auto ) {
		if ( ! sort ) {
			return;
		}
		state.cur = sort;
		if ( ! auto ) {
			state.navigated = true;
		}
		highlight( state );

		var targets = Array.prototype.slice.call( document.querySelectorAll( '.pf-current[data-pf-type="' + state.d.type + '"]' ) );
		if ( ! targets.length ) {
			return;
		}
		var req = ++state.req; // Any older in-flight fetch is now stale.
		if ( state.cache[ sort ] ) {
			swap( targets, state.cache[ sort ], ! auto );
			return;
		}

		targets.forEach( function ( t ) {
			t.classList.add( 'is-loading' );
			t.setAttribute( 'aria-busy', 'true' );
		} );
		var url = state.d.rest + ( state.d.rest.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'type=' + encodeURIComponent( state.d.type ) + '&sort=' + sort +
			( targets.some( function ( t ) { return t.hasAttribute( 'data-pf-part' ); } ) ? '&split=1' : '' );

		fetch( url, { headers: { Accept: 'application/json' } } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( r.status );
				}
				return r.json();
			} )
			.then( function ( json ) {
				state.cache[ sort ] = json;
				if ( req === state.req ) {
					swap( targets, json, ! auto );
				}
			} )
			.catch( function () {
				if ( req === state.req ) {
					swap( targets, { html: errorHtml( state ), head: '', body: errorHtml( state ) }, ! auto );
				}
			} );
	}

	// One month open at a time (also for browsers without <details name>).
	document.addEventListener( 'toggle', function ( e ) {
		var month = e.target;
		if ( ! month.open || ! month.classList || ! month.classList.contains( 'pf-month' ) ) {
			return;
		}
		var index = month.closest( '.pf-index' );
		if ( index ) {
			index.querySelectorAll( 'details.pf-month[open]' ).forEach( function ( other ) {
				if ( other !== month ) {
					other.open = false;
				}
			} );
		}
	}, true );

	document.addEventListener( 'click', function ( e ) {
		var root = e.target.closest( '.postful[data-pf-type]' );
		if ( ! root ) {
			return;
		}
		var state = states[ root.getAttribute( 'data-pf-type' ) ];
		if ( ! state ) {
			return;
		}
		var nav = e.target.closest( '[data-pf-go]' );
		if ( nav ) {
			var dir = nav.getAttribute( 'data-pf-go' );
			go( state, dir === 'today' ? state.today : neighbour( state, dir === 'next' ? 1 : -1 ) );
			return;
		}
		var day = e.target.closest( '.pf-day[data-pf-sort]' );
		if ( day ) {
			go( state, parseInt( day.getAttribute( 'data-pf-sort' ), 10 ) );
		}
	} );

	/* ---- Boot ------------------------------------------------------------ */

	function init() {
		document.querySelectorAll( 'script.pf-data' ).forEach( function ( el ) {
			var d;
			try {
				d = JSON.parse( el.textContent );
			} catch ( e ) {
				return;
			}
			var shown = document.querySelector( '.pf-current[data-pf-type="' + d.type + '"] .pf-entry' );
			var state = {
				d: d,
				cur: shown ? parseInt( shown.getAttribute( 'data-pf-sort' ), 10 ) || d.today : d.today,
				today: d.today,
				cache: {},
				req: 0,
				navigated: false
			};
			states[ d.type ] = state;

			// A cached page may have been rendered yesterday: fix it quietly.
			var today = siteToday( d.tz );
			if ( today && today !== d.today ) {
				state.today = today;
				go( state, today, true );
			}
		} );

		applyLanguage();

		// Re-detect when the site's own switcher changes the language.
		if ( window.MutationObserver ) {
			new MutationObserver( applyLanguage ).observe( document.documentElement, {
				attributes: true,
				attributeFilter: [ 'lang', 'class' ]
			} );
		}
		var lastCookie = readCookie( 'googtrans' );
		setInterval( function () {
			var now = readCookie( 'googtrans' );
			if ( now !== lastCookie ) {
				lastCookie = now;
				applyLanguage();
			}
		}, 1000 );
		window.addEventListener( 'pageshow', applyLanguage );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
