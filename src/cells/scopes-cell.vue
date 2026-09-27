<template>
	<div ref="root" class="k-agents-scopes-cell">
		<span class="k-agents-scopes-text">{{ text }}</span>
		<template v-if="hidden > 0">
			<k-button
				:text="$t('agents.grants.more', { count: hidden })"
				class="k-agents-scopes-toggle"
				dropdown
				size="xs"
				@click.stop="all.toggle()"
			/>
			<k-dropdown-content ref="all" align-x="start">
				<ul class="k-agents-scopes-list">
					<li v-for="scope in value" :key="scope.value">
						<k-icon type="check" />
						<span>{{ scope.text }}</span>
					</li>
				</ul>
			</k-dropdown-content>
		</template>

		<div ref="measure" aria-hidden="true" class="k-agents-scopes-measure">
			<span v-for="(part, index) in parts" :key="index" data-label>{{ part }}</span>
			<span ref="more">{{ $t("agents.grants.more", { count: value.length }) }}</span>
		</div>
	</div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from "kirbyuse"

const props = defineProps({
	value: Array
})

const root = ref(null)
const all = ref(null)
const measure = ref(null)
const more = ref(null)
const shown = ref(1)

const hidden = computed(() => props.value.length - shown.value)
const labels = computed(() => props.value.map((scope) => scope.short))
const parts = computed(() =>
	labels.value.map((label, index) => (index < labels.value.length - 1 ? `${label}, ` : label))
)
const text = computed(() => labels.value.slice(0, shown.value).join(", "))

function fit() {
	const style = window.getComputedStyle(root.value)
	const width =
		root.value.clientWidth -
		parseFloat(style.paddingInlineStart) -
		parseFloat(style.paddingInlineEnd)
	const widths = [...measure.value.querySelectorAll("[data-label]")].map((el) => el.offsetWidth)
	const button = more.value.offsetWidth + 40
	let used = 0
	let count = 0

	for (const [index, label] of widths.entries()) {
		const last = index === widths.length - 1

		if (used + label + (last ? 0 : button) > width) {
			break
		}

		used += label
		count = index + 1
	}

	shown.value = Math.max(1, count)
}

const observer = new ResizeObserver(fit)

watch(() => props.value, fit, { flush: "post" })

onMounted(() => observer.observe(root.value))
onBeforeUnmount(() => observer.disconnect())
</script>

<style>
.k-agents-scopes-cell {
	position: relative;
	display: flex;
	align-items: center;
	gap: var(--spacing-1);
	padding: 0.325rem var(--table-cell-padding);
	white-space: nowrap;
}
.k-agents-scopes-text {
	overflow: hidden;
	text-overflow: ellipsis;
}
.k-agents-scopes-toggle {
	font-size: var(--text-sm);
	color: var(--color-text-dimmed);
}
.k-agents-scopes-measure {
	position: absolute;
	visibility: hidden;
	white-space: pre;
}
.k-agents-scopes-list {
	display: grid;
	gap: var(--spacing-2);
	padding: var(--spacing-2) var(--spacing-3);
	max-width: 22rem;
}
.k-agents-scopes-list li {
	display: flex;
	align-items: flex-start;
	gap: var(--spacing-2);
	line-height: var(--leading-normal);
	white-space: normal;
}
.k-agents-scopes-list .k-icon {
	flex-shrink: 0;
	color: var(--color-positive);
}
</style>
