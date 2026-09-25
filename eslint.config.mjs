import js from "@eslint/js"
import prettier from "eslint-config-prettier"
import vue from "eslint-plugin-vue"

export default [
	js.configs.recommended,
	...vue.configs["flat/vue2-recommended"],
	prettier,
	{
		files: ["src/**/*.{js,vue}"],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: "module",
			globals: {
				window: "readonly",
				document: "readonly",
				console: "readonly",
				panel: "readonly",
				AbortController: "readonly",
				ResizeObserver: "readonly",
				URL: "readonly",
				navigator: "readonly",
				setTimeout: "readonly"
			}
		},
		rules: {
			"vue/attributes-order": "error",
			"vue/component-definition-name-casing": "off",
			"vue/html-closing-bracket-newline": [
				"error",
				{
					singleline: "never",
					multiline: "always"
				}
			],
			"vue/multi-word-component-names": "off",
			"vue/require-default-prop": "off",
			"vue/require-prop-types": "error",
			"no-unused-vars": ["error", { argsIgnorePattern: "^_", varsIgnorePattern: "^props$" }]
		}
	}
]
