/* Postful entry editor. Vanilla JS; wp.media is used only for image/file fields. */
( function () {
	'use strict';

	var cfg = window.postfulEditor || { taken: {}, months: [], i18n: {} };
	var canvas = document.querySelector( '[data-pf-canvas]' );

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%(\d\$)?[sd]/g, function ( m, pos ) {
			return pos ? args[ parseInt( pos, 10 ) - 1 ] : args[ i++ ];
		} );
	}

	/* ---- Auto-growing textareas ---------------------------------------- */

	function grow( el ) {
		el.style.height = 'auto';
		el.style.height = el.scrollHeight + 2 + 'px';
	}
	document.querySelectorAll( '[data-pf-autogrow]' ).forEach( function ( el ) {
		grow( el );
		el.addEventListener( 'input', function () { grow( el ); } );
	} );
	window.addEventListener( 'resize', function () {
		document.querySelectorAll( '[data-pf-autogrow]' ).forEach( grow );
	} );

	/* ---- Day picker ----------------------------------------------------- */

	var box = document.querySelector( '[data-pf-keybox]' );
	if ( box ) {
		var month = box.querySelector( '[data-pf-month]' );
		var day = box.querySelector( '[data-pf-day]' );
		var hint = box.querySelector( '[data-pf-key-hint]' );
		var label = box.querySelector( '[data-pf-key-label]' );
		var warn = box.querySelector( '[data-pf-key-warn]' );
		var dup = box.querySelector( '[data-pf-key-dup]' );
		var lengths = [ 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31 ]; // Feb 29 is a real day here.

		var updateKey = function () {
			var m = parseInt( month.value, 10 ) || 0;
			var max = m ? lengths[ m - 1 ] : 31;
			Array.prototype.forEach.call( day.options, function ( opt ) {
				if ( opt.value ) {
					opt.disabled = parseInt( opt.value, 10 ) > max;
				}
			} );
			if ( parseInt( day.value, 10 ) > max ) {
				day.value = String( max );
			}
			var d = parseInt( day.value, 10 ) || 0;
			var set = m && d;
			hint.hidden = ! set;
			warn.hidden = !! set;
			if ( set ) {
				label.textContent = fmt( cfg.i18n.monthDay, cfg.months[ m - 1 ], d );
			}
			var other = set ? cfg.taken[ m * 100 + d ] : null;
			dup.hidden = ! other;
			if ( other ) {
				dup.textContent = fmt( cfg.i18n.dup, other );
			}
		};
		month.addEventListener( 'change', updateKey );
		day.addEventListener( 'change', updateKey );
		updateKey();
	}

	/* ---- Translation progress per language ------------------------------ */

	function cellValue( cell ) {
		var rich = cell.querySelector( '.wp-editor-area' );
		if ( rich ) {
			var ed = window.tinymce && window.tinymce.get( rich.id );
			var html = ed && ! ed.isHidden() ? ed.getContent() : rich.value;
			var tmp = document.createElement( 'div' );
			tmp.innerHTML = html;
			return ( tmp.textContent || '' ).trim() || ( /<(img|iframe|video|audio)\b/i.test( html ) ? 'x' : '' );
		}
		var input = cell.querySelector( 'input:not([type=hidden]):not([type=checkbox]), textarea, select, input[type=hidden]' );
		return input ? String( input.value || '' ).trim() : '';
	}

	function progress() {
		if ( ! canvas ) {
			return;
		}
		canvas.querySelectorAll( '.pf-lang-head' ).forEach( function ( head ) {
			var lang = head.getAttribute( 'data-pf-lang' );
			var cells = canvas.querySelectorAll( '.pf-cell[data-pf-lang="' + lang + '"]:not(.pf-cell--toggle)' );
			var filled = 0;
			cells.forEach( function ( c ) {
				if ( cellValue( c ) ) {
					filled++;
				}
			} );
			var out = head.querySelector( '[data-pf-progress]' );
			var done = filled === cells.length;
			out.textContent = done ? cfg.i18n.complete : fmt( cfg.i18n.progress, filled, cells.length );
			out.classList.toggle( 'is-complete', done );
			out.classList.toggle( 'is-partial', ! done && filled > 0 );
		} );
	}

	if ( canvas ) {
		canvas.addEventListener( 'input', progress );
		canvas.addEventListener( 'change', progress );
		window.addEventListener( 'load', function () {
			progress();
			if ( window.tinymce ) {
				window.tinymce.editors.forEach( function ( ed ) {
					ed.on( 'keyup change SetContent', progress );
				} );
			}
		} );
		progress();

		// Flag empty required fields when publishing; never block the save.
		var form = document.getElementById( 'post' );
		if ( form ) {
			form.addEventListener( 'submit', function () {
				canvas.querySelectorAll( '.pf-cell[data-pf-required]' ).forEach( function ( c ) {
					c.classList.toggle( 'is-missing', ! cellValue( c ) );
				} );
			} );
		}
	}

	/* ---- Machine-translated drafts (DeepL) ------------------------------- */

	function toast( text, isError ) {
		var el = document.createElement( 'div' );
		el.className = 'pf-toast' + ( isError ? ' is-error' : '' );
		el.setAttribute( 'role', 'status' );
		el.textContent = text;
		document.body.appendChild( el );
		setTimeout( function () { el.classList.add( 'is-out' ); }, 4200 );
		setTimeout( function () { el.remove(); }, 4700 );
	}

	function readCell( cell ) {
		var rich = cell.querySelector( '.wp-editor-area' );
		if ( rich ) {
			var ed = window.tinymce && window.tinymce.get( rich.id );
			return ed && ! ed.isHidden() ? ed.getContent() : rich.value;
		}
		var input = cell.querySelector( 'input:not([type=hidden]), textarea' );
		return input ? input.value : '';
	}

	function writeCell( cell, value ) {
		var rich = cell.querySelector( '.wp-editor-area' );
		if ( rich ) {
			var ed = window.tinymce && window.tinymce.get( rich.id );
			if ( ed && ! ed.isHidden() ) {
				ed.setContent( value );
				ed.save();
			} else {
				rich.value = value;
			}
			return;
		}
		var input = cell.querySelector( 'input:not([type=hidden]), textarea' );
		if ( input ) {
			input.value = value;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	}

	document.querySelectorAll( '[data-pf-translate]' ).forEach( function ( btn ) {
		var lang = btn.getAttribute( 'data-pf-translate' );
		var label = btn.textContent;

		btn.addEventListener( 'click', function () {
			var body = new FormData();
			body.append( 'action', 'postful_translate' );
			body.append( '_wpnonce', cfg.nonce );
			body.append( 'post_id', cfg.postId );
			body.append( 'lang', lang );

			var targets = {};
			var any = false;
			( cfg.machine || [] ).forEach( function ( key ) {
				var src = canvas.querySelector( '.pf-cell[data-pf-field="' + key + '"][data-pf-lang="' + cfg.defLang + '"]' );
				var dst = canvas.querySelector( '.pf-cell[data-pf-field="' + key + '"][data-pf-lang="' + lang + '"]' );
				if ( ! src || ! dst ) {
					return;
				}
				// Only fill gaps: never overwrite something a person wrote.
				var tmp = document.createElement( 'div' );
				tmp.innerHTML = readCell( dst );
				if ( ( tmp.textContent || '' ).trim() || tmp.querySelector( 'img, iframe, video, audio' ) ) {
					return;
				}
				var value = readCell( src );
				if ( value.trim() ) {
					body.append( 'values[' + key + ']', value );
					targets[ key ] = dst;
					any = true;
				}
			} );
			if ( ! any ) {
				toast( cfg.i18n.nothing );
				return;
			}

			btn.disabled = true;
			btn.textContent = cfg.i18n.translating;
			fetch( cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( json && json.data && json.data.message ? json.data.message : cfg.i18n.failed );
					}
					var filled = [];
					Object.keys( json.data.values ).forEach( function ( key ) {
						if ( targets[ key ] ) {
							writeCell( targets[ key ], json.data.values[ key ] );
							filled.push( key );
						}
					} );
					var n = filled.length;
					var review = canvas.querySelector( '[data-pf-review="' + lang + '"]' );
					if ( review && n ) {
						// Only these fields become drafts; anything a person wrote stays live.
						var flag = review.querySelector( '[data-pf-review-flag]' );
						flag.value = flag.value ? flag.value + ',' + filled.join( ',' ) : filled.join( ',' );
						review.hidden = false;
						review.querySelector( 'input[type=checkbox]' ).checked = false;
					}
					document.querySelectorAll( '[data-pf-autogrow]' ).forEach( grow );
					progress();
					toast( fmt( cfg.i18n.filled, n ) );
				} )
				.catch( function ( err ) {
					toast( err.message || cfg.i18n.failed, true );
				} )
				.then( function () {
					btn.disabled = false;
					btn.textContent = label;
				} );
		} );
	} );

	/* ---- Image / file picker -------------------------------------------- */

	document.querySelectorAll( '[data-pf-media]' ).forEach( function ( wrap ) {
		var kind = wrap.getAttribute( 'data-pf-media' );
		var input = wrap.querySelector( 'input[type=hidden]' );
		var preview = wrap.querySelector( '[data-pf-media-preview]' );
		var clear = wrap.querySelector( '[data-pf-media-clear]' );
		var frame;

		wrap.querySelector( '[data-pf-media-pick]' ).addEventListener( 'click', function () {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( {
					title: kind === 'image' ? cfg.i18n.chooseImage : cfg.i18n.chooseFile,
					button: { text: cfg.i18n.use },
					library: kind === 'image' ? { type: 'image' } : {},
					multiple: false
				} );
				frame.on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					input.value = a.id;
					preview.textContent = '';
					if ( kind === 'image' ) {
						var img = document.createElement( 'img' );
						img.src = ( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url );
						img.alt = '';
						preview.appendChild( img );
					} else {
						preview.textContent = a.filename || a.title;
					}
					clear.hidden = false;
					progress();
				} );
			}
			frame.open();
		} );

		clear.addEventListener( 'click', function () {
			input.value = '';
			preview.textContent = '';
			clear.hidden = true;
			progress();
		} );
	} );
}() );
