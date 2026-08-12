/**
 * Media Library integration for Swift Image Optimizer.
 *
 * Two surfaces:
 *   1. A "Bulk Optimize" button in the grid-view toolbar, driving a multi-select run.
 *   2. An optimization panel in the attachment details modal.
 *
 * Deliberately no React. The media modal is Backbone, and this bundle loads on
 * many admin screens, so it stays on wp.media globals and plain DOM.
 *
 * The wp.media view hooks below are the Media Library's extension API and
 * have no alternative. Everything this renders inside them, though, uses the
 * plugin's own sio-* classes rather than core's button classes.
 */

import { __, _n, sprintf } from '@wordpress/i18n';

import sioConfirm from './confirm';

import '../styles/media.scss';

const config = window.swiftImageOptimizerMedia || {};

/**
 * POST to one of the plugin's REST routes.
 *
 * @param {string} path Route path relative to the plugin namespace.
 * @param {Object} body JSON payload.
 * @return {Promise<Object>} Parsed response.
 */
const request = ( path, body ) =>
	window
		.fetch( config.restUrl + path, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( body ),
		} )
		.then( ( response ) =>
			response.json().then( ( json ) => {
				if ( ! response.ok ) {
					throw new Error( json.message || response.statusText );
				}
				return json;
			} )
		);

const formatBytes = ( bytes ) => {
	const value = Number( bytes ) || 0;

	if ( value < 1024 ) {
		return `${ value } B`;
	}

	const units = [ 'KB', 'MB', 'GB' ];
	let size = value / 1024;
	let i = 0;

	while ( size >= 1024 && i < units.length - 1 ) {
		size /= 1024;
		i++;
	}

	return `${ size.toFixed( size < 10 ? 2 : 1 ) } ${ units[ i ] }`;
};

const formatDuration = ( ms ) => {
	const value = Number( ms ) || 0;

	if ( value <= 0 ) {
		return '';
	}

	if ( value < 1000 ) {
		return sprintf( /* translators: %d: milliseconds. */ __( '%d ms', 'swift-image-optimizer' ), Math.round( value ) );
	}

	return sprintf(
		/* translators: %s: seconds, to one decimal place. */
		__( '%s sec', 'swift-image-optimizer' ),
		( value / 1000 ).toFixed( 1 )
	);
};

const formatFormat = ( mime ) => {
	const known = {
		'image/jpeg': 'JPEG',
		'image/jpg': 'JPEG',
		'image/png': 'PNG',
		'image/webp': 'WebP',
		'image/gif': 'GIF',
	};

	return known[ mime ] || String( mime || '' ).replace( 'image/', '' ).toUpperCase();
};

/**
 * Create an element. Text is always set via textContent, never innerHTML.
 *
 * @param {string} tag       Tag name.
 * @param {string} className Class attribute.
 * @param {string} text      Text content.
 * @return {HTMLElement} The element.
 */
const el = ( tag, className, text ) => {
	const node = document.createElement( tag );

	if ( className ) {
		node.className = className;
	}

	if ( undefined !== text && null !== text ) {
		node.textContent = text;
	}

	return node;
};

/**
 * Build the optimization panel for an attachment.
 *
 * @param {Object}   data      The swiftImageOptimizer payload.
 * @param {Function} onAction  Called with 'optimize' or 'restore'.
 * @return {HTMLElement|null} The panel, or null when there is nothing to say.
 */
function buildPanel( data, onAction ) {
	if ( ! data ) {
		return null;
	}

	const optimized = 'optimized' === data.status;

	if ( ! optimized && ! data.canOptimize && ! data.reason ) {
		return null;
	}

	const panel = el( 'div', 'sio-panel' );

	if ( optimized ) {
		panel.classList.add( 'is-optimized' );

		const head = el( 'div', 'sio-panel__head' );
		head.appendChild( el( 'span', 'sio-panel__dot' ) );
		head.appendChild( el( 'strong', null, __( 'Image Optimized', 'swift-image-optimizer' ) ) );
		panel.appendChild( head );

		const saved = el( 'div', 'sio-panel__saved' );
		saved.appendChild(
			el(
				'span',
				'sio-panel__percent',
				sprintf(
					/* translators: %s: percentage saved. */
					__( 'Saved %s%%', 'swift-image-optimizer' ),
					data.percent
				)
			)
		);
		saved.appendChild(
			el(
				'span',
				'sio-panel__bytes',
				sprintf(
					/* translators: %s: formatted file size. */
					__( '(-%s)', 'swift-image-optimizer' ),
					formatBytes( data.savedBytes )
				)
			)
		);
		panel.appendChild( saved );

		const rows = el( 'ul', 'sio-panel__rows' );

		const addRow = ( label, value ) => {
			const row = el( 'li' );
			row.appendChild( el( 'span', 'sio-panel__label', label ) );
			row.appendChild( el( 'span', 'sio-panel__value', value ) );
			rows.appendChild( row );
		};

		addRow( __( 'Original:', 'swift-image-optimizer' ), formatBytes( data.originalSize ) );
		addRow( __( 'Optimized:', 'swift-image-optimizer' ), `↓ ${ formatBytes( data.optimizedSize ) }` );
		panel.appendChild( rows );

		const meta = el( 'ul', 'sio-panel__meta' );

		meta.appendChild(
			el(
				'li',
				null,
				sprintf(
					/* translators: %s: image format, for example WebP. */
					__( 'Converted to %s', 'swift-image-optimizer' ),
					formatFormat( data.currentMime )
				)
			)
		);

		if ( data.engine ) {
			meta.appendChild(
				el(
					'li',
					null,
					sprintf(
						/* translators: %s: conversion engine name. */
						__( 'Engine: %s', 'swift-image-optimizer' ),
						data.engine
					)
				)
			);
		}

		if ( data.conversionMs > 0 ) {
			meta.appendChild(
				el(
					'li',
					null,
					sprintf(
						/* translators: %s: formatted duration. */
						__( 'Done in %s', 'swift-image-optimizer' ),
						formatDuration( data.conversionMs )
					)
				)
			);
		}

		panel.appendChild( meta );

		if ( data.canRestore ) {
			const restore = el( 'button', 'sio-btn sio-btn--tertiary sio-panel__restore', __( 'Restore original', 'swift-image-optimizer' ) );
			restore.type = 'button';
			restore.addEventListener( 'click', () => onAction( 'restore', restore ) );
			panel.appendChild( restore );
		} else {
			// Images converted during upload never had a backup, and expired
			// backups have been deleted. Either way the original is gone, and
			// saying so beats silently omitting the button.
			panel.appendChild(
				el(
					'p',
					'sio-panel__note',
					__( 'No original stored, so this cannot be restored.', 'swift-image-optimizer' )
				)
			);
		}

		return panel;
	}

	// Not optimized: explain why if we know, and offer the action.
	if ( data.reason ) {
		panel.appendChild( el( 'p', 'sio-panel__reason', data.reason ) );
	}

	if ( data.canOptimize ) {
		if ( ! config.hasEngine ) {
			panel.appendChild(
				el(
					'p',
					'sio-panel__reason',
					__( 'No image conversion engine is available on this server.', 'swift-image-optimizer' )
				)
			);

			return panel;
		}

		const label = data.reason
			? __( 'Try again', 'swift-image-optimizer' )
			: __( 'Optimize', 'swift-image-optimizer' );

		const button = el( 'button', 'sio-btn sio-btn--secondary sio-panel__optimize', label );
		button.type = 'button';
		button.addEventListener( 'click', () => onAction( 'optimize', button ) );
		panel.appendChild( button );
	}

	return panel;
}

/**
 * Render (or re-render) the panel inside an attachment details view.
 *
 * @param {Backbone.Model} model     The attachment model.
 * @param {HTMLElement}    container The details container element.
 * @return {void}
 */
function renderPanel( model, container ) {
	if ( ! container || ! model ) {
		return;
	}

	// render() runs repeatedly; never stack panels.
	const existing = container.querySelector( '.sio-panel, .sio-panel-busy' );

	if ( existing ) {
		existing.remove();
	}

	const data = model.get( 'swiftImageOptimizer' );

	const onAction = async ( action, button ) => {
		if ( 'restore' === action ) {
			const ok = await sioConfirm( {
				title: __( 'Restore the original image?', 'swift-image-optimizer' ),
				message: __(
					'The optimized file will be removed and every reference to it updated back to the original.',
					'swift-image-optimizer'
				),
				confirm: __( 'Restore original', 'swift-image-optimizer' ),
			} );

			if ( ! ok ) {
				return;
			}
		}

		button.disabled = true;
		button.textContent = __( 'Working…', 'swift-image-optimizer' );

		request( action, { ids: [ model.get( 'id' ) ] } )
			.then( ( result ) => {
				const first = result && result.results && result.results[ 0 ];

				if ( first && ! first.ok ) {
					throw new Error( first.message );
				}

				// The filename changed, so the model's URLs are stale.
				return model.fetch();
			} )
			.then( () => renderPanel( model, container ) )
			.catch( ( error ) => {
				button.disabled = false;
				button.textContent = __( 'Optimize', 'swift-image-optimizer' );

				const message = el( 'p', 'sio-panel__error', error.message );
				button.parentNode.appendChild( message );
			} );
	};

	const panel = buildPanel( data, onAction );

	if ( panel ) {
		container.appendChild( panel );
	}
}

/**
 * Find the element the panel should be appended to.
 *
 * The two-column modal nests the file facts inside `.details`; the compact
 * variant does not.
 *
 * @param {HTMLElement} root View element.
 * @return {HTMLElement|null} Target container.
 */
const panelContainer = ( root ) =>
	root.querySelector( '.attachment-info .details' ) || root.querySelector( '.attachment-info' );

/**
 * Patch both attachment details views.
 *
 * The modal uses a different view depending on how it was opened. Patching
 * only one leaves the other blank.
 *
 * @return {void}
 */
function registerModalPanel() {
	const views = window.wp && window.wp.media && window.wp.media.view;

	if ( ! views || ! views.Attachment || ! views.Attachment.Details ) {
		return;
	}

	const patch = ( View ) =>
		View.extend( {
			render() {
				View.prototype.render.apply( this, arguments );
				renderPanel( this.model, panelContainer( this.el ) );
				return this;
			},
		} );

	const TwoColumn = views.Attachment.Details.TwoColumn;

	views.Attachment.Details = patch( views.Attachment.Details );

	if ( TwoColumn ) {
		views.Attachment.Details.TwoColumn = patch( TwoColumn );
	}
}

/**
 * Progress bar shown in place of the toolbar buttons during a run.
 *
 * Appended directly to the toolbar element rather than to its priority lists,
 * so core's select-mode show/hide logic — which targets
 * `.media-toolbar-primary > *, .media-toolbar-secondary > *` — never touches it.
 */
class OptimizeProgress {
	constructor( toolbarEl, total, onStop ) {
		this.total = total;
		this.done = 0;
		this.saved = 0;

		this.root = el( 'div', 'sio-bulk' );

		this.bar = el( 'div', 'sio-bulk__bar' );
		this.fill = el( 'div', 'sio-bulk__fill' );
		this.bar.appendChild( this.fill );

		this.label = el( 'span', 'sio-bulk__label' );
		this.savedLabel = el( 'span', 'sio-bulk__saved' );

		this.stop = el( 'button', 'sio-btn sio-btn--secondary is-destructive sio-bulk__stop', __( 'Stop', 'swift-image-optimizer' ) );
		this.stop.type = 'button';
		this.stop.addEventListener( 'click', onStop );

		this.root.appendChild( this.bar );
		this.root.appendChild( this.label );
		this.root.appendChild( this.savedLabel );
		this.root.appendChild( this.stop );

		toolbarEl.appendChild( this.root );
		this.update();
	}

	update() {
		const percent = this.total > 0 ? Math.min( 100, Math.round( ( this.done / this.total ) * 100 ) ) : 0;

		this.fill.style.width = `${ percent }%`;
		this.label.textContent = sprintf(
			/* translators: 1: images completed, 2: total images. */
			__( '%1$d of %2$d', 'swift-image-optimizer' ),
			this.done,
			this.total
		);
		this.savedLabel.textContent = this.saved > 0
			? sprintf(
				/* translators: %s: formatted file size. */
				__( '%s saved', 'swift-image-optimizer' ),
				formatBytes( this.saved )
			)
			: '';
	}

	advance( count, savedBytes ) {
		this.done += count;
		this.saved += savedBytes;
		this.update();
	}

	finish( summary ) {
		this.bar.remove();
		this.stop.remove();
		this.label.textContent = summary;
		this.savedLabel.textContent = '';

		window.setTimeout( () => this.destroy(), 6000 );
	}

	destroy() {
		if ( this.root && this.root.parentNode ) {
			this.root.remove();
		}
	}
}

/**
 * Optimize the current selection, in chunks, updating progress as it goes.
 *
 * @param {Object} controller The media frame controller.
 * @param {Object} toolbar    The toolbar view.
 * @return {void}
 */
async function runSelection( controller, toolbar ) {
	const selection = controller.state().get( 'selection' );
	const models = selection.models.slice();

	if ( ! models.length ) {
		return;
	}

	const ok = await sioConfirm( {
		title: sprintf(
			/* translators: %d: number of images. */
			_n(
				'Optimize %d image?',
				'Optimize %d images?',
				models.length,
				'swift-image-optimizer'
			),
			models.length
		),
		message: _n(
			'The original is backed up first, and every reference to it is updated.',
			'Originals are backed up first, and every reference to them is updated.',
			models.length,
			'swift-image-optimizer'
		),
		confirm: __( 'Optimize', 'swift-image-optimizer' ),
	} );

	if ( ! ok ) {
		return;
	}

	const size = Math.max( 1, parseInt( config.chunkSize, 10 ) || 3 );
	const chunks = [];

	for ( let i = 0; i < models.length; i += size ) {
		chunks.push( models.slice( i, i + size ) );
	}

	toolbar.$el.addClass( 'sio-running' );

	let stopped = false;
	const progress = new OptimizeProgress( toolbar.el, models.length, () => {
		stopped = true;
	} );

	let optimized = 0;
	let skipped = 0;
	let failed = 0;

	const runChunk = ( index ) => {
		if ( stopped || index >= chunks.length ) {
			return Promise.resolve();
		}

		const chunk = chunks[ index ];
		const ids = chunk.map( ( model ) => model.get( 'id' ) );

		return request( 'optimize', { ids } )
			.then( ( result ) => {
				let saved = 0;

				( result.results || [] ).forEach( ( item ) => {
					if ( item.ok ) {
						optimized++;
						saved += item.saved || 0;
					} else if ( 'failed' === item.code ) {
						failed++;
					} else {
						skipped++;
					}
				} );

				progress.advance( chunk.length, saved );

				// Filenames changed, so the thumbnails in the grid are now stale.
				return Promise.all( chunk.map( ( model ) => model.fetch() ) );
			} )
			.catch( () => {
				failed += chunk.length;
				progress.advance( chunk.length, 0 );
			} )
			.then( () => runChunk( index + 1 ) );
	};

	runChunk( 0 ).then( () => {
		toolbar.$el.removeClass( 'sio-running' );

		progress.finish(
			sprintf(
				/* translators: 1: optimized count, 2: skipped count, 3: failed count. */
				__( 'Done — %1$d optimized, %2$d skipped, %3$d failed.', 'swift-image-optimizer' ),
				optimized,
				skipped,
				failed
			)
		);

		controller.trigger( 'selection:action:done' );
	} );
}

/**
 * Add the two toolbar buttons to the grid-view attachments browser.
 *
 * @return {void}
 */
function registerToolbar() {
	const views = window.wp && window.wp.media && window.wp.media.view;

	if ( ! config.isGrid || ! views || ! views.AttachmentsBrowser || ! views.Button ) {
		return;
	}

	const Button = views.Button;

	/**
	 * Entry point. Always visible in normal mode; hides itself in select mode,
	 * where Cancel and "Optimize" take over.
	 */
	const OptimizeModeButton = Button.extend( {
		initialize() {
			Button.prototype.initialize.apply( this, arguments );
			this.controller.on( 'select:activate select:deactivate', this.toggleVisible, this );
		},

		toggleVisible() {
			this.$el.toggleClass( 'hidden', !! this.controller.isModeActive( 'select' ) );
		},

		click() {
			Button.prototype.click.apply( this, arguments );

			if ( ! this.controller.isModeActive( 'select' ) ) {
				this.controller.deactivateMode( 'edit' ).activateMode( 'select' );
			}
		},

		render() {
			Button.prototype.render.apply( this, arguments );
			this.$el.addClass( 'swift-optimize-mode-button sio-btn sio-btn--secondary' );
			this.toggleVisible();
			return this;
		},
	} );

	/**
	 * Executes the run. Mirrors core's DeleteSelectedButton.
	 */
	const OptimizeSelectedButton = Button.extend( {
		initialize() {
			Button.prototype.initialize.apply( this, arguments );
			this.controller.on( 'selection:toggle', this.updateState, this );
			this.controller.on( 'select:activate', this.updateState, this );
		},

		updateState() {
			const count = this.controller.state().get( 'selection' ).length;

			this.model.set( {
				disabled: ! count,
				text: count
					? sprintf(
						/* translators: %d: number of selected images. */
						__( 'Optimize (%d)', 'swift-image-optimizer' ),
						count
					)
					: __( 'Optimize', 'swift-image-optimizer' ),
			} );
		},

		click() {
			Button.prototype.click.apply( this, arguments );
			runSelection( this.controller, this.views.parent );
		},

		render() {
			Button.prototype.render.apply( this, arguments );

			this.$el.addClass( 'swift-optimize-selected-button sio-btn sio-btn--primary' );

			if ( ! this.controller.isModeActive( 'select' ) ) {
				this.$el.addClass( 'hidden' );
			}

			this.updateState();
			return this;
		},
	} );

	const original = views.AttachmentsBrowser.prototype.createToolbar;

	views.AttachmentsBrowser.prototype.createToolbar = function () {
		original.apply( this, arguments );

		// Same guard core uses for its own grid-only toolbar items.
		if ( ! this.controller.isModeActive || ! this.controller.isModeActive( 'grid' ) ) {
			return;
		}

		if ( ! config.hasEngine ) {
			return;
		}

		this.toolbar.set(
			'swiftOptimizeMode',
			new OptimizeModeButton( {
				text: __( 'Bulk Optimize', 'swift-image-optimizer' ),
				controller: this.controller,
				priority: -65,
			} ).render()
		);

		this.toolbar.set(
			'swiftOptimizeSelected',
			new OptimizeSelectedButton( {
				style: 'primary',
				disabled: true,
				text: __( 'Optimize', 'swift-image-optimizer' ),
				controller: this.controller,
				priority: -78,
			} ).render()
		);

		// Core only un-hides its own .delete-selected-button on select:activate,
		// so ours has to manage its own visibility.
		this.controller.on(
			'select:activate',
			function () {
				this.toolbar.$( '.swift-optimize-selected-button' ).removeClass( 'hidden' );
			},
			this
		);

		this.controller.on(
			'select:deactivate',
			function () {
				this.toolbar.$( '.swift-optimize-selected-button' ).addClass( 'hidden' );
			},
			this
		);
	};
}

if ( window.wp && window.wp.media ) {
	registerModalPanel();
	registerToolbar();
}
