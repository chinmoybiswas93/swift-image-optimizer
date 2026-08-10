/** Settings tab. */

import { useState } from 'react';
import { __ } from '@wordpress/i18n';
import { Button, Notice, NumberInput, Range, Section, Select, Spinner, Toggle } from '../Components';
import { IconGear, IconLayers, IconSliders } from '../Icons';
import { saveSettings } from '../Services/http';

const SettingsPage = ( { values, setValues } ) => {
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ error, setError ] = useState( '' );

	const set = ( key, value ) => {
		setValues( ( prev ) => ( { ...prev, [ key ]: value } ) );
		setSaved( false );
	};

	const save = async () => {
		setSaving( true );
		setError( '' );
		try {
			await saveSettings( values );
			setSaved( true );
		} catch ( e ) {
			setError( e.message );
		}
		setSaving( false );
	};

	const bool = ( key ) => !! Number( values[ key ] );

	return (
		<>
			{ error && <Notice status="error" onRemove={ () => setError( '' ) }>{ error }</Notice> }
			{ saved && <Notice status="success" onRemove={ () => setSaved( false ) }>{ __( 'Settings saved.', 'swift-image-optimizer' ) }</Notice> }

			<Section
				icon={ <IconSliders /> }
				title={ __( 'Conversion', 'swift-image-optimizer' ) }
				description={ __(
					'How images are turned into WebP.',
					'swift-image-optimizer'
				) }
			>
				<Toggle
					label={ __( 'Optimize new uploads automatically', 'swift-image-optimizer' ) }
					help={ __( 'Convert images to WebP as they are uploaded.', 'swift-image-optimizer' ) }
					checked={ bool( 'auto_optimize' ) }
					onChange={ ( v ) => set( 'auto_optimize', v ? 1 : 0 ) }
				/>
				<Range
					label={ __( 'WebP quality', 'swift-image-optimizer' ) }
					help={ __( '82 is a good balance. Below 75 shows artefacts; above 88 gains little.', 'swift-image-optimizer' ) }
					value={ Number( values.quality ) || 82 }
					onChange={ ( v ) => set( 'quality', v ) }
					min={ 1 }
					max={ 100 }
				/>
				<NumberInput
					label={ __( 'Maximum image dimension (px)', 'swift-image-optimizer' ) }
					help={ __( 'Longest edge. Set to 0 to leave dimensions alone.', 'swift-image-optimizer' ) }
					value={ Number( values.max_dimension ) || 0 }
					onChange={ ( v ) => set( 'max_dimension', parseInt( v, 10 ) || 0 ) }
					min={ 0 }
					max={ 10000 }
				/>
				<Toggle
					label={ __( 'Convert PNG images', 'swift-image-optimizer' ) }
					checked={ bool( 'convert_png' ) }
					onChange={ ( v ) => set( 'convert_png', v ? 1 : 0 ) }
				/>
				<Toggle
					label={ __( 'Keep the original when WebP would be larger', 'swift-image-optimizer' ) }
					checked={ bool( 'skip_if_larger' ) }
					onChange={ ( v ) => set( 'skip_if_larger', v ? 1 : 0 ) }
				/>
				<Toggle
					label={ __( 'Keep a backup of uploaded originals', 'swift-image-optimizer' ) }
					help={ __( 'Uploads are converted and the original file is replaced. With this on, the original is kept so the image can be restored later. Turning it off means uploaded originals are gone for good.', 'swift-image-optimizer' ) }
					checked={ bool( 'backup_uploads' ) }
					onChange={ ( v ) => set( 'backup_uploads', v ? 1 : 0 ) }
				/>
				<Select
					label={ __( 'Conversion engine', 'swift-image-optimizer' ) }
					value={ values.engine || 'auto' }
					options={ [
						{ label: __( 'Automatic (recommended)', 'swift-image-optimizer' ), value: 'auto' },
						{ label: 'Imagick', value: 'imagick' },
						{ label: 'cwebp', value: 'cwebp' },
						{ label: 'GD', value: 'gd' },
					] }
					onChange={ ( v ) => set( 'engine', v ) }
				/>
			</Section>

			<Section
				icon={ <IconLayers /> }
				title={ __( 'Existing images', 'swift-image-optimizer' ) }
				description={ __(
					'What happens to images already published on your site.',
					'swift-image-optimizer'
				) }
			>
				<Toggle
					label={ __( 'Rewrite URLs in the database', 'swift-image-optimizer' ) }
					help={ __( 'Update references in posts, meta and options when an existing image is converted. Turning this off will leave broken images.', 'swift-image-optimizer' ) }
					checked={ bool( 'rewrite_urls' ) }
					onChange={ ( v ) => set( 'rewrite_urls', v ? 1 : 0 ) }
				/>
				<Toggle
					label={ __( 'Serve the WebP when an old URL is requested', 'swift-image-optimizer' ) }
					help={ __( 'Catches references the rewriter could not reach, such as hotlinks and cached pages.', 'swift-image-optimizer' ) }
					checked={ bool( 'enable_404_fallback' ) }
					onChange={ ( v ) => set( 'enable_404_fallback', v ? 1 : 0 ) }
				/>
				<Select
					label={ __( 'Keep original backups for', 'swift-image-optimizer' ) }
					help={ __( 'After this, originals are deleted and images can no longer be restored.', 'swift-image-optimizer' ) }
					value={ String( values.backup_retention ?? 30 ) }
					options={ [
						{ label: __( '7 days', 'swift-image-optimizer' ), value: '7' },
						{ label: __( '30 days', 'swift-image-optimizer' ), value: '30' },
						{ label: __( '90 days', 'swift-image-optimizer' ), value: '90' },
						{ label: __( 'Keep forever', 'swift-image-optimizer' ), value: '0' },
					] }
					onChange={ ( v ) => set( 'backup_retention', parseInt( v, 10 ) ) }
				/>
			</Section>

			<Section
				icon={ <IconGear /> }
				title={ __( 'WordPress behaviour', 'swift-image-optimizer' ) }
				description={ __(
					'Core defaults this plugin can override.',
					'swift-image-optimizer'
				) }
			>
				<Toggle
					label={ __( 'Disable WordPress automatic image scaling', 'swift-image-optimizer' ) }
					help={ __( 'WordPress scales images over 2560px by default.', 'swift-image-optimizer' ) }
					checked={ bool( 'disable_wp_scaling' ) }
					onChange={ ( v ) => set( 'disable_wp_scaling', v ? 1 : 0 ) }
				/>
			</Section>

			<div className="sio-savebar">
				<Button variant="primary" onClick={ save } disabled={ saving }>
					{ saving ? <Spinner /> : __( 'Save settings', 'swift-image-optimizer' ) }
				</Button>
			</div>
		</>
	);
};

export default SettingsPage;
