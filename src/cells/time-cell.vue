<template>
	<p class="k-agents-time-cell" :title="absolute">{{ relative }}</p>
</template>

<script setup>
import { computed, usePanel } from "kirbyuse"

const UNITS = [
	["year", 31536000],
	["month", 2592000],
	["week", 604800],
	["day", 86400],
	["hour", 3600],
	["minute", 60],
	["second", 1]
]

const props = defineProps({
	value: String
})

const panel = usePanel()

const absolute = computed(() =>
	new Date(props.value).toLocaleString(panel.translation.code, {
		dateStyle: "medium",
		timeStyle: "short"
	})
)

const relative = computed(() => {
	const seconds = (new Date(props.value).getTime() - Date.now()) / 1000
	const [unit, size] = UNITS.find(([, size]) => Math.abs(seconds) >= size) ?? UNITS.at(-1)
	const format = new Intl.RelativeTimeFormat(panel.translation.code, { numeric: "auto" })

	return format.format(Math.round(seconds / size), unit)
})
</script>

<style>
.k-agents-time-cell {
	padding: 0.325rem var(--table-cell-padding);
	white-space: nowrap;
}
</style>
