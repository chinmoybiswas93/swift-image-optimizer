/**
 * Block editor bundle entry point.
 *
 * Adds the optimization panel to any selected block that carries an
 * attachment id — core/image, and by extension every item inside a
 * core/gallery block, since a gallery is built from individual core/image
 * blocks. Wraps BlockEdit via editor.BlockEdit rather than a document-level
 * sidebar panel, because the status is per-image, not per-post.
 *
 * React comes from WordPress via @wordpress/element; no @wordpress/components
 * import anywhere in this bundle, matching resources/admin's own rule.
 */

import { InspectorControls } from '@wordpress/block-editor';
import { createHigherOrderComponent } from '@wordpress/compose';
import { Fragment } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';

import Panel from './Panel';

import '../styles/editor.scss';

const TARGET_BLOCKS = [ 'core/image' ];

const withOptimizationPanel = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => {
		const { name, isSelected, attributes } = props;

		if (
			! TARGET_BLOCKS.includes( name ) ||
			! isSelected ||
			! attributes.id
		) {
			return <BlockEdit { ...props } />;
		}

		return (
			<Fragment>
				<BlockEdit { ...props } />
				<InspectorControls>
					<Panel attachmentId={ attributes.id } />
				</InspectorControls>
			</Fragment>
		);
	},
	'withOptimizationPanel'
);

addFilter(
	'editor.BlockEdit',
	'swift-image-optimizer/optimization-panel',
	withOptimizationPanel
);
