<template>
	<p class="k-agents-time-cell" :title="absolute">{{ relative }}</p>
</template>

<script>
const UNITS = [
	["year", 31536000],
	["month", 2592000],
	["week", 604800],
	["day", 86400],
	["hour", 3600],
	["minute", 60],
	["second", 1]
]

export default {
	props: {
		value: String
	},
	computed: {
		absolute() {
			return new Date(this.value).toLocaleString(this.$panel.translation.code, {
				dateStyle: "medium",
				timeStyle: "short"
			})
		},
		relative() {
			const seconds = (new Date(this.value).getTime() - Date.now()) / 1000
			const [unit, size] = UNITS.find(([, size]) => Math.abs(seconds) >= size) ?? UNITS.at(-1)
			const format = new Intl.RelativeTimeFormat(this.$panel.translation.code, { numeric: "auto" })

			return format.format(Math.round(seconds / size), unit)
		}
	}
}
</script>

<style>
.k-agents-time-cell {
	padding: 0.325rem var(--table-cell-padding);
	white-space: nowrap;
}
</style>
