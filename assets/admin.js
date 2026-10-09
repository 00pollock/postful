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
			p.classList.toggle( 'is-invalid', ! p.value.trim() );
			s.classList.toggle( 'is-invalid', ! s.value.trim() );
			if ( ! p.value.trim() || ! s.value.trim() ) {
				msg = i18n.needName;
			}
		} else if ( n === 3 ) {
			if ( ! wizard.querySelector( '[data-pf-langs] input:checked' ) ) {
				msg = i18n.needLanguage;
			}
		} else if ( n === 4 ) {
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

	/* Internal key follows the singular name until edited by hand. */
	var keyInput = wizard.querySelector( '[data-pf-key]' );
	var singular = wizard.querySelector( '[data-pf-singular]' );
	var keyDirty = keyInput.readOnly || !! keyInput.value;
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

	/* Field builder */
	var list = wizard.querySelector( '[data-pf-fields]' );
	var tpl = wizard.querySelector( '[data-pf-field-template]' );
	var counter = 1000;

	function bindRow( row ) {
		var label = row.querySelector( '[data-pf-field-label]' );
		var key = row.querySelector( '[data-pf-field-key]' );
		var type = row.querySelector( '[data-pf-field-type]' );
		var trans = row.querySelector( '[data-pf-translatable-wrap]' );
		var more = row.querySelector( '[data-pf-more]' );
		var panel = row.querySelector( '[data-pf-more-panel]' );
		var dirty = key.readOnly || !! key.value;

		key.addEventListener( 'input', function () {
			dirty = true;
			key.value = key.value.toLowerCase().replace( /[^a-z0-9_]/g, '' );
		} );
		label.addEventListener( 'input', function () {
			if ( ! dirty ) {
				key.value = slug( label.value, 40 );
			}
		} );
		var syncType = function () {
			row.querySelector( '[data-pf-choices]' ).hidden = type.value !== 'select';
			trans.style.visibility = type.value === 'toggle' ? 'hidden' : '';
		};
		type.addEventListener( 'change', syncType );
		syncType();

		more.addEventListener( 'click', function () {
			panel.hidden = ! panel.hidden;
			more.setAttribute( 'aria-expanded', String( ! panel.hidden ) );
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

	show( 1 );
}() );
