/**
 * The @wordpress/scripts defaults, with parameters documented by TypeScript types
 * instead of JSDoc placeholders ("@param root0").
 */
const defaults = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaults,
	{
		files: [ '**/*.ts', '**/*.tsx' ],
		rules: {
			'jsdoc/require-param': 'off',
		},
	},
];
