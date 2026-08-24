/**
 * Inline icon set.
 *
 * Hand-rolled rather than pulled from a package: the plugin ships no icon
 * dependency, and these are the only glyphs the dashboard needs.
 */

const stroke = {
	fill: 'none',
	stroke: 'currentColor',
	strokeWidth: 1.7,
	strokeLinecap: 'round',
	strokeLinejoin: 'round',
};

const Svg = ( { size = 24, children } ) => (
	<svg
		width={ size }
		height={ size }
		viewBox="0 0 24 24"
		aria-hidden="true"
		focusable="false"
	>
		<g { ...stroke }>{ children }</g>
	</svg>
);

/** The plugin mark: a rounded tile with a bolt, used beside the wordmark. */
export const LogoMark = () => (
	<svg
		className="sio-masthead__mark"
		width="38"
		height="38"
		viewBox="0 0 38 38"
		aria-hidden="true"
		focusable="false"
	>
		<defs>
			<linearGradient id="sio-mark-gradient" x1="0" y1="0" x2="1" y2="1">
				<stop offset="0%" stopColor="#5b76f2" />
				<stop offset="100%" stopColor="#2c3fc0" />
			</linearGradient>
		</defs>
		<rect width="38" height="38" rx="11" fill="url(#sio-mark-gradient)" />
		<path d="M21.4 8.2 12.2 21.1h5.2l-1.1 8.7 9.4-13.3h-5.4z" fill="#fff" />
	</svg>
);

export const IconImage = () => (
	<Svg>
		<rect x="3" y="4.5" width="18" height="15" rx="2.5" />
		<circle cx="8.6" cy="10" r="1.5" />
		<path d="M3.4 17.2 8.9 12.4l3.6 3.2 3.3-2.8 4.8 4.4" />
	</Svg>
);

export const IconDisk = () => (
	<Svg>
		<rect x="3" y="5" width="18" height="14" rx="2.5" />
		<path d="M3 13h18" />
		<circle cx="17.2" cy="16" r="1" />
	</Svg>
);

export const IconBolt = () => (
	<Svg>
		<path d="M13.6 3 5.8 13.8h5.1L10.2 21l8-10.8h-5.2z" />
	</Svg>
);

export const IconSliders = () => (
	<Svg>
		<path d="M4 8.5h9M19 8.5h1M4 15.5h4M14 15.5h6" />
		<circle cx="16" cy="8.5" r="2.4" />
		<circle cx="11" cy="15.5" r="2.4" />
	</Svg>
);

export const IconLayers = () => (
	<Svg>
		<path d="M12 3.2 3.4 8 12 12.8 20.6 8z" />
		<path d="M3.4 12.9 12 17.7l8.6-4.8" />
	</Svg>
);

export const IconGear = () => (
	<Svg>
		<circle cx="12" cy="12" r="3.2" />
		<path d="M12 2.6v2.6M12 18.8v2.6M4.9 4.9l1.9 1.9M17.2 17.2l1.9 1.9M2.6 12h2.6M18.8 12h2.6M4.9 19.1l1.9-1.9M17.2 6.8l1.9-1.9" />
	</Svg>
);

export const IconArchive = () => (
	<Svg>
		<rect x="3" y="4.5" width="18" height="4.8" rx="1.5" />
		<path d="M5 9.3v9a1.7 1.7 0 0 0 1.7 1.7h10.6a1.7 1.7 0 0 0 1.7-1.7v-9" />
		<path d="M10 13.2h4" />
	</Svg>
);

/** Diagnostics: a stethoscope, for the server information section. */
export const IconStethoscope = () => (
	<Svg>
		<path d="M5 3.5v5.2a4.2 4.2 0 0 0 8.4 0V3.5" />
		<path d="M3.4 3.5h3.2M11.8 3.5H15" />
		<path d="M9.2 12.9v2.4a4.6 4.6 0 0 0 9.2 0v-1.6" />
		<circle cx="18.4" cy="11.4" r="2.3" />
	</Svg>
);

/** Logging: a document with lines of text. */
export const IconDocument = () => (
	<Svg>
		<path d="M13.4 3.2H6.8A1.8 1.8 0 0 0 5 5v14a1.8 1.8 0 0 0 1.8 1.8h10.4A1.8 1.8 0 0 0 19 19V8.8z" />
		<path d="M13.4 3.2V8.8H19" />
		<path d="M8.4 12.8h7.2M8.4 16.2h7.2" />
	</Svg>
);

export const IconTrendDown = () => (
	<svg
		width="14"
		height="14"
		viewBox="0 0 24 24"
		aria-hidden="true"
		focusable="false"
	>
		<g { ...stroke } strokeWidth={ 2.2 }>
			<path d="M12 4.5v14.2M6.4 13.1 12 18.9l5.6-5.8" />
		</g>
	</svg>
);
