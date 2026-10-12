/* Postful admin: wizard, field builder, copy buttons, CSV drop zone. Vanilla JS. */
( function () {
	'use strict';

	var i18n = ( window.postfulAdmin && window.postfulAdmin.i18n ) || {};
	document.body.classList.add( 'pf-js' );

	function slug( str, max ) {
		return String( str || '' )
			.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.replace( /^[0-9_]+/, '' )
			.slice( 0, max );
	}

	/* ---- Copy buttons ---------------------------------------------------- */

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-pf-copy]' );
		if ( ! btn ) {
			return;
		}
		var text = btn.getAttribute( 'data-pf-copy' );
		var done = function () {
			btn.textContent = i18n.copied || 'Copied';
			btn.classList.add( 'is-done' );
			setTimeout( function () {
				btn.textContent = i18n.copy || 'Copy';
				btn.classList.remove( 'is-done' );
			}, 1600 );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			ta.remove();
			done();
		}
	} );

	/* ---- Confirmations --------------------------------------------------- */

	document.querySelectorAll( 'form[data-pf-confirm]' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			if ( ! window.confirm( i18n[ form.getAttribute( 'data-pf-confirm' ) ] ) ) {
				e.preventDefault();
			}
		} );
	} );

	/* ---- CSV drop zone --------------------------------------------------- */

	document.querySelectorAll( '[data-pf-drop]' ).forEach( function ( drop ) {
		var input = drop.querySelector( 'input[type=file]' );
		var text = drop.querySelector( '[data-pf-drop-text]' );
		[ 'dragenter', 'dragover' ].forEach( function ( ev ) {
			drop.addEventListener( ev, function () { drop.classList.add( 'is-over' ); } );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( ev ) {
			drop.addEventListener( ev, function () { drop.classList.remove( 'is-over' ); } );
		} );
		input.addEventListener( 'change', function () {
			if ( input.files.length ) {
				text.textContent = ( i18n.fileChosen || '%s' ).replace( '%s', input.files[ 0 ].name );
				drop.classList.add( 'has-file' );
			}
		} );
	} );

	/* ---- Bulk translation drafts ---------------------------------------- */

	document.querySelectorAll( '[data-pf-bulk]' ).forEach( function ( box ) {
		var btn = box.querySelector( '[data-pf-bulk-go]' );
		var bar = box.querySelector( '[data-pf-bar]' );
		var status = box.querySelector( '[data-pf-status]' );
		var total = parseInt( box.getAttribute( 'data-total' ), 10 ) || 0;

		btn.addEventListener( 'click', function () {
			var last = Infinity;
			var skip = [];
			btn.disabled = true;
			bar.parentNode.hidden = false;
			status.classList.remove( 'is-error' );

			var step = function () {
				var body = new FormData();
				body.append( 'action', 'postful_translate_batch' );
				body.append( '_wpnonce', box.getAttribute( 'data-nonce' ) );
				body.append( 'type', box.getAttribute( 'data-type' ) );
				body.append( 'lang', box.getAttribute( 'data-lang' ) );
				skip.forEach( function ( id ) { body.append( 'skip[]', id ); } );
				fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							throw new Error( json && json.data && json.data.message ? json.data.message : 'Error' );
						}
						// Entries that didn't fully translate are skipped next time, not re-billed.
						skip = skip.concat( json.data.failed || [] );
						var left = json.data.remaining;
						var done = Math.max( 0, total - left );
						bar.style.width = ( total ? Math.round( done / total * 100 ) : 100 ) + '%';
						status.textContent = ( i18n.bulkProgress || '%1$d of %2$d' ).replace( '%1$d', done ).replace( '%2$d', total );
						// Stop if a batch made no progress, so a stuck entry can't loop forever.
						if ( left > 0 && left < last && json.data.done > 0 ) {
							last = left;
							step();
						} else {
							status.textContent = skip.length ? ( i18n.bulkStuck || '' ) : ( i18n.bulkDone || '' );
							btn.disabled = ! skip.length;
						}
					} )
					.catch( function ( err ) {
						status.textContent = err.message;
						status.classList.add( 'is-error' );
						btn.disabled = false;
					} );
			};
			step();
		} );
	} );

	/* ---- Wizard ---------------------------------------------------------- */

	var wizard = document.querySelector( '[data-pf-wizard]' );
	if ( ! wizard ) {
		return;
	}

	var data = JSON.parse( wizard.querySelector( '[data-pf-wizard-data]' ).textContent );
	var steps = Array.prototype.slice.call( wizard.querySelectorAll( '[data-pf-step]' ) );
	var stepper = wizard.querySelectorAll( '[data-pf-stepper] li' );
	var errorEl = wizard.querySelector( '[data-pf-error]' );
	var current = 1;
	var reached = 1;

	function show( n ) {
		n = Math.min( steps.length, Math.max( 1, n ) );
		current = n;
		reached = Math.max( reached, n );
		steps.forEach( function ( s, i ) {
			s.classList.toggle( 'is-active', i + 1 === n );
		} );
		stepper.forEach( function ( li, i ) {
			li.classList.toggle( 'is-active', i + 1 === n );
			li.classList.toggle( 'is-done', i + 1 < n || ( i + 1 <= reached && i + 1 !== n ) );
		} );
		wizard.classList.toggle( 'is-first', n === 1 );
		wizard.classList.toggle( 'is-last', n === steps.length );
		errorEl.textContent = '';
		if ( steps[ n - 1 ].querySelector( '[data-pf-style]' ) ) {
			onStyleStep();
		}
		var first = steps[ n - 1 ].querySelector( 'input:not([type=hidden]):not([readonly]):not([disabled])' );
		if ( first && n === 1 ) {
			first.focus();
		}
		var top = wizard.getBoundingClientRect().top + window.scrollY - 48;
		if ( window.scrollY > top ) {
			window.scrollTo( { top: top, behavior: 'smooth' } );
		}
	}

	function validate( n ) {
		var msg = '';
		if ( n === 1 ) {
			var p = wizard.querySelector( '[data-pf-plural]' );
			var s = wizard.querySelector( '[data-pf-singular]' );
			if ( ! s.value.trim() ) {
				s.value = singularOf( p.value );
			}
			p.classList.toggle( 'is-invalid', ! p.value.trim() );
			if ( ! p.value.trim() ) {
				msg = i18n.needName;
			}
		} else if ( n === 2 ) {
			if ( ! wizard.querySelector( '[data-pf-langs] input:checked' ) ) {
				msg = i18n.needLanguage;
			}
		} else if ( n === 3 ) {
			var any = Array.prototype.some.call( wizard.querySelectorAll( '[data-pf-field-label]' ), function ( el ) {
				return el.value.trim();
			} );
			if ( ! any ) {
				msg = i18n.needField;
			}
		}
		errorEl.textContent = msg;
		return ! msg;
	}

	wizard.querySelector( '[data-pf-next]' ).addEventListener( 'click', function () {
		if ( validate( current ) ) {
			show( current + 1 );
		}
	} );
	wizard.querySelector( '[data-pf-back]' ).addEventListener( 'click', function () {
		show( Math.max( 1, current - 1 ) );
	} );
	wizard.querySelectorAll( '[data-pf-goto]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var target = parseInt( btn.getAttribute( 'data-pf-goto' ), 10 );
			for ( var i = current; i < target; i++ ) {
				if ( ! validate( i ) ) {
					show( i );
					validate( i );
					return;
				}
			}
			show( target );
		} );
	} );
	wizard.addEventListener( 'submit', function ( e ) {
		for ( var i = 1; i <= steps.length; i++ ) {
			if ( ! validate( i ) ) {
				e.preventDefault();
				show( i );
				validate( i );
				return;
			}
		}
	} );
	// Enter in a text box moves forward instead of submitting early.
	wizard.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' && e.target.tagName === 'INPUT' && current < steps.length ) {
			e.preventDefault();
			wizard.querySelector( '[data-pf-next]' ).click();
		}
	} );

	/* Errors clear as soon as the person starts fixing them. */
	wizard.addEventListener( 'input', function ( e ) {
		if ( e.target.classList.contains( 'is-invalid' ) && e.target.value.trim() ) {
			e.target.classList.remove( 'is-invalid' );
		}
		if ( ! wizard.querySelector( '.is-invalid' ) ) {
			errorEl.textContent = '';
		}
	} );
	wizard.addEventListener( 'change', function () {
		if ( current === 2 && wizard.querySelector( '[data-pf-langs] input:checked' ) ) {
			errorEl.textContent = '';
		}
	} );

	/*
	 * Singular follows the plural (Devotionals → Devotional, Stories → Story),
	 * and the internal key follows the singular, until either is edited by hand.
	 */
	function singularOf( plural ) {
		var w = plural.trim();
		var rules = [
			[ /ies$/i, 'y' ],
			[ /(ss|x|ch|sh|zz)es$/i, '$1' ],
			[ /([^s])s$/i, '$1' ]
		];
		for ( var i = 0; i < rules.length; i++ ) {
			if ( rules[ i ][ 0 ].test( w ) ) {
				return w.replace( rules[ i ][ 0 ], rules[ i ][ 1 ] );
			}
		}
		return w;
	}
	var keyInput = wizard.querySelector( '[data-pf-key]' );
	var plural = wizard.querySelector( '[data-pf-plural]' );
	var singular = wizard.querySelector( '[data-pf-singular]' );
	var keyDirty = keyInput.readOnly || !! keyInput.value;
	var singularDirty = !! singular.value && singular.value !== singularOf( plural.value );
	plural.addEventListener( 'input', function () {
		if ( ! singularDirty ) {
			singular.value = singularOf( plural.value );
			singular.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}
	} );
	singular.addEventListener( 'input', function ( e ) {
		if ( e.isTrusted ) {
			singularDirty = singular.value !== singularOf( plural.value );
		}
	} );
	keyInput.addEventListener( 'input', function () {
		keyDirty = true;
		keyInput.value = keyInput.value.toLowerCase().replace( /[^a-z0-9_-]/g, '' );
	} );
	singular.addEventListener( 'input', function () {
		if ( ! keyDirty ) {
			keyInput.value = slug( singular.value, 20 );
		}
	} );

	/* Languages → default select + per-language strings */
	var langBox = wizard.querySelector( '[data-pf-langs]' );
	var defaultSelect = wizard.querySelector( '[data-pf-default-locale]' );
	var stringsBox = wizard.querySelector( '[data-pf-strings]' );

	function checkedLocales() {
		return Array.prototype.map.call( langBox.querySelectorAll( 'input:checked' ), function ( el ) {
			return el.value;
		} );
	}
	function code( locale ) {
		return locale.split( /[_-]/ )[ 0 ].toLowerCase();
	}

	function syncLanguages() {
		var locales = checkedLocales();
		var keep = defaultSelect.value || defaultSelect.getAttribute( 'data-selected' );
		defaultSelect.textContent = '';
		locales.forEach( function ( loc ) {
			var opt = document.createElement( 'option' );
			opt.value = loc;
			opt.textContent = data.names[ loc ] || loc;
			defaultSelect.appendChild( opt );
		} );
		if ( locales.indexOf( keep ) !== -1 ) {
			defaultSelect.value = keep;
		}

		// Preserve anything typed before re-rendering.
		stringsBox.querySelectorAll( 'input' ).forEach( function ( input ) {
			var m = input.name.match( /^strings\[(\w+)\]\[(\w+)\]$/ );
			if ( m && data.strings[ m[ 1 ] ] ) {
				data.strings[ m[ 1 ] ].saved[ m[ 2 ] ] = input.value;
			}
		} );
		stringsBox.textContent = '';
		var seen = {};
		locales.forEach( function ( loc ) {
			var c = code( loc );
			if ( seen[ c ] || ! data.strings[ c ] ) {
				return;
			}
			seen[ c ] = true;
			var block = document.createElement( 'div' );
			block.className = 'pf-strings-lang';
			var h = document.createElement( 'h4' );
			h.textContent = data.names[ loc ] || loc;
			block.appendChild( h );
			var grid = document.createElement( 'div' );
			grid.className = 'pf-strings-grid';
			Object.keys( data.stringKeys ).forEach( function ( k ) {
				var id = 'pf_str_' + c + '_' + k;
				var label = document.createElement( 'label' );
				label.htmlFor = id;
				label.textContent = data.stringKeys[ k ];
				var input = document.createElement( 'input' );
				input.type = 'text';
				input.className = 'pf-text';
				input.id = id;
				input.name = 'strings[' + c + '][' + k + ']';
				input.placeholder = data.strings[ c ].defaults[ k ];
				input.value = data.strings[ c ].saved[ k ] || '';
				input.lang = c;
				grid.appendChild( label );
				grid.appendChild( input );
			} );
			block.appendChild( grid );
			stringsBox.appendChild( block );
		} );
	}
	langBox.addEventListener( 'change', syncLanguages );
	syncLanguages();

	/* Field builder: name, type, order, remove. Keys follow the name until saved. */
	var list = wizard.querySelector( '[data-pf-fields]' );
	var tpl = wizard.querySelector( '[data-pf-field-template]' );
	var counter = 1000;

	function bindRow( row ) {
		var label = row.querySelector( '[data-pf-field-label]' );
		var key = row.querySelector( '[data-pf-field-key]' );
		var locked = key.hasAttribute( 'data-locked' );
		var follows = ! locked && ( ! key.value || key.value === slug( label.value, 40 ) );

		label.addEventListener( 'input', function () {
			if ( follows ) {
				key.value = slug( label.value, 40 );
			}
		} );
		row.querySelector( '[data-pf-remove]' ).addEventListener( 'click', function () {
			if ( row.hasAttribute( 'data-pf-locked' ) && ! window.confirm( i18n.removeLocked ) ) {
				return;
			}
			row.remove();
		} );
		row.querySelectorAll( '[data-pf-move]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( btn.getAttribute( 'data-pf-move' ) === '-1' && row.previousElementSibling ) {
					list.insertBefore( row, row.previousElementSibling );
				} else if ( btn.getAttribute( 'data-pf-move' ) === '1' && row.nextElementSibling ) {
					list.insertBefore( row.nextElementSibling, row );
				}
				btn.focus();
			} );
		} );
	}

	list.querySelectorAll( '[data-pf-row]' ).forEach( bindRow );

	wizard.querySelectorAll( '[data-pf-add-field]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var holder = document.createElement( 'div' );
			holder.innerHTML = tpl.innerHTML.replace( /__i__/g, String( counter++ ) );
			var row = holder.firstElementChild;
			row.querySelector( '[data-pf-field-type]' ).value = btn.getAttribute( 'data-pf-add-field' );
			row.classList.add( 'is-new' );
			list.appendChild( row );
			bindRow( row );
			row.querySelector( '[data-pf-field-label]' ).focus();
		} );
	} );

	function fieldRows() {
		return Array.prototype.slice.call( wizard.querySelectorAll( '[data-pf-row]' ) ).map( function ( row ) {
			return {
				key: row.querySelector( '[data-pf-field-key]' ).value,
				label: row.querySelector( '[data-pf-field-label]' ).value.trim(),
				type: row.querySelector( '[data-pf-field-type]' ).value
			};
		} ).filter( function ( f ) { return f.label && f.key; } );
	}

	/* Style step: one group per field (first is the title), plus page groups. */
	var stylePanel = wizard.querySelector( '[data-pf-style]' );
	var fieldBox = wizard.querySelector( '[data-pf-field-styles]' );
	var frame = wizard.querySelector( '[data-pf-preview]' );
	var previewTimer;

	function esc( t ) {
		var d = document.createElement( 'div' );
		d.textContent = t;
		return d.innerHTML;
	}

	function control( name, kind, value, label ) {
		var wrap = document.createElement( 'label' );
		wrap.className = 'pf-style-field';
		var l = document.createElement( 'span' );
		l.className = 'pf-style-label';
		l.textContent = label;
		wrap.appendChild( l );
		var input;
		if ( kind === 'color' ) {
			var box = document.createElement( 'span' );
			box.className = 'pf-color';
			var swatch = document.createElement( 'input' );
			swatch.type = 'color';
			swatch.value = /^#[0-9a-f]{6}$/i.test( value ) ? value : '#000000';
			swatch.setAttribute( 'data-pf-swatch', '' );
			swatch.setAttribute( 'aria-label', label );
			input = document.createElement( 'input' );
			input.type = 'text';
			input.className = 'pf-text pf-mono';
			input.maxLength = 7;
			input.placeholder = data.i18n.theme;
			box.appendChild( swatch );
			box.appendChild( input );
			wrap.appendChild( box );
		} else if ( typeof kind === 'object' ) {
			input = document.createElement( 'select' );
			input.className = 'pf-text';
			var none = document.createElement( 'option' );
			none.value = '';
			none.textContent = data.i18n.themeDefault;
			input.appendChild( none );
			Object.keys( kind ).forEach( function ( v ) {
				var o = document.createElement( 'option' );
				o.value = v;
				o.textContent = kind[ v ];
				input.appendChild( o );
			} );
			wrap.appendChild( input );
		} else {
			var unit = document.createElement( 'span' );
			unit.className = 'pf-unit';
			input = document.createElement( 'input' );
			input.type = 'number';
			input.className = 'pf-text';
			input.min = 0;
			input.max = 2000;
			input.placeholder = data.i18n.theme;
			unit.appendChild( input );
			var em = document.createElement( 'em' );
			em.textContent = 'px';
			unit.appendChild( em );
			wrap.appendChild( unit );
		}
		input.name = name;
		input.value = value || '';
		input.setAttribute( 'data-kind', typeof kind === 'object' ? 'select' : kind );
		return wrap;
	}

	function buildFieldStyles() {
		// Keep values typed so far (fields may have been renamed or reordered).
		fieldBox.querySelectorAll( '[name^="field_style"]' ).forEach( function ( input ) {
			var m = input.name.match( /^field_style\[(\w+)\]\[(\w+)\]$/ );
			if ( m ) {
				data.fieldStyle[ m[ 1 ] ] = data.fieldStyle[ m[ 1 ] ] || {};
				data.fieldStyle[ m[ 1 ] ][ m[ 2 ] ] = input.value;
			}
		} );
		fieldBox.textContent = '';
		fieldRows().forEach( function ( f, i ) {
			var group = document.createElement( 'details' );
			group.className = 'pf-style-group';
			group.open = i === 0;
			var sum = document.createElement( 'summary' );
			sum.textContent = f.label + ( i === 0 ? ' (' + data.i18n.title + ')' : '' );
			group.appendChild( sum );
			var body = document.createElement( 'div' );
			body.className = 'pf-style-fields';
			var saved = data.fieldStyle[ f.key ] || {};
			Object.keys( data.fieldProps ).forEach( function ( prop ) {
				body.appendChild( control( 'field_style[' + f.key + '][' + prop + ']', data.fieldProps[ prop ][ 1 ], saved[ prop ], data.fieldProps[ prop ][ 0 ] ) );
			} );
			group.appendChild( body );
			fieldBox.appendChild( group );
		} );
	}

	function fieldCss( key ) {
		var css = '';
		var get = function ( prop ) {
			var el = fieldBox.querySelector( '[name="field_style[' + key + '][' + prop + ']"]' );
			return el ? el.value.trim() : '';
		};
		var map = { size: 'font-size:%px', weight: 'font-weight:%', italic: 'font-style:%', 'case': 'text-transform:%', align: 'text-align:%', color: 'color:%', space: 'margin-bottom:%px' };
		Object.keys( map ).forEach( function ( prop ) {
			var v = get( prop );
			if ( v && ( prop !== 'color' || /^#([0-9a-f]{3}){1,2}$/i.test( v ) ) ) {
				css += map[ prop ].replace( '%', v ) + ';';
			}
		} );
		var frameStyle = get( 'frame' );
		if ( frameStyle === 'left' ) {
			css += 'border-left:3px solid var(--pf-rule);padding-left:1em;';
		} else if ( frameStyle === 'around' ) {
			css += 'border-top:1px solid var(--pf-rule);border-bottom:1px solid var(--pf-rule);padding:1em 0;';
		}
		return css;
	}

	function pageVars() {
		var css = '';
		stylePanel.querySelectorAll( '[data-pf-var]' ).forEach( function ( input ) {
			var v = input.value.trim();
			var kind = input.getAttribute( 'data-kind' );
			if ( ! v || ( kind === 'color' && ! /^#([0-9a-f]{3}){1,2}$/i.test( v ) ) ) {
				return;
			}
			css += input.getAttribute( 'data-pf-var' ) + ':' + v + ( kind === 'size' ? 'px' : '' ) + ';';
		} );
		return css;
	}

	function previewHtml() {
		var fields = fieldRows();
		var title = fields[ 0 ] && ( fields[ 0 ].type === 'text' || fields[ 0 ].type === 'textarea' ) ? fields[ 0 ] : null;
		var head = '<header class="pf-entry-head">' + ( title ? '<h2 class="pf-entry-title" style="' + esc( fieldCss( title.key ) ) + '">' + esc( title.label ) + '</h2>' : '' ) + '<p class="pf-date">October 10</p></header>';
		var body = fields.slice( title ? 1 : 0 ).map( function ( f ) {
			var text = f.type === 'richtext' || f.type === 'textarea'
				? '<p>' + esc( f.label ) + ': longer text reads like this, with comfortable spacing between lines so a whole page stays easy to follow.</p><p>A second paragraph shows the gap between paragraphs.</p>'
				: esc( f.label ) + ': sample text';
			return '<div class="pf-field" style="' + esc( fieldCss( f.key ) ) + '">' + text + '</div>';
		} ).join( '' );
		var headPart = '<div class="postful pf-current"><article class="pf-entry"><div class="pf-lang">' + head + '</div></article></div>';
		var bodyPart = '<div class="postful pf-current"><article class="pf-entry"><div class="pf-lang">' + body + '</div></article></div>';
		var nav = '<nav class="postful pf-nav"><button class="pf-nav-btn">Previous</button><button class="pf-nav-btn">Today</button><button class="pf-nav-btn">Next</button></nav>';
		var index = '<div class="postful pf-index"><h2 class="pf-index-title">Browse by month</h2>' + [ 'September', 'October', 'November' ].map( function ( m ) {
			var open = m === 'October';
			var days = open ? '<ol class="pf-days">' + [ 9, 10, 11 ].map( function ( d ) {
				return '<li><button class="pf-day' + ( d === 10 ? ' is-current' : '' ) + '"><span class="pf-day-num">' + d + '</span><span class="pf-day-text">' + esc( title ? title.label : 'Entry' ) + ' ' + d + '</span></button></li>';
			} ).join( '' ) + '</ol>' : '';
			return '<details class="pf-month"' + ( open ? ' open' : '' ) + '><summary><span class="pf-month-name">' + m + '</span></summary>' + days + '</details>';
		} ).join( '' ) + '</div>';
		return '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="' + frame.getAttribute( 'data-css' ) + '">' +
			'<style>body{margin:20px;font:15px/1.6 Georgia,"Times New Roman",serif;color:#1f2328;background:#fff}h2{font-family:system-ui,sans-serif}</style></head><body>' +
			'<div class="postful-layout" style="' + esc( pageVars() ) + '"><div class="pf-area-head">' + headPart + '</div><div class="pf-area-nav">' + nav + '</div><div class="pf-area-body">' + bodyPart + '</div><div class="pf-area-index">' + index + '</div></div></body></html>';
	}

	function renderPreview() {
		if ( ! frame ) {
			return;
		}
		clearTimeout( previewTimer );
		previewTimer = setTimeout( function () {
			frame.onload = function () {
				try {
					frame.style.height = Math.min( 900, frame.contentDocument.body.scrollHeight + 40 ) + 'px';
				} catch ( e ) {}
			};
			frame.srcdoc = previewHtml();
		}, 120 );
	}

	function onStyleStep() {
		buildFieldStyles();
		renderPreview();
	}

	if ( stylePanel ) {
		stylePanel.addEventListener( 'input', function ( e ) {
			var color = e.target.closest( '.pf-color' );
			if ( color ) {
				var text = color.querySelector( 'input[type=text]' );
				var swatch = color.querySelector( '[data-pf-swatch]' );
				if ( e.target === swatch ) {
					text.value = swatch.value;
				} else if ( /^#[0-9a-f]{6}$/i.test( text.value ) ) {
					swatch.value = text.value;
				}
			}
			renderPreview();
		} );
		stylePanel.addEventListener( 'change', renderPreview );
		stylePanel.querySelector( '[data-pf-style-reset]' ).addEventListener( 'click', function () {
			stylePanel.querySelectorAll( 'input[type=text], input[type=number], select' ).forEach( function ( input ) { input.value = ''; } );
			renderPreview();
		} );
	}

	show( Math.min( steps.length, Math.max( 1, parseInt( wizard.getAttribute( 'data-pf-start' ), 10 ) || 1 ) ) );
}() );
