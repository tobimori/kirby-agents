<template>
	<div class="k-agents-scopes-cell">
		<span class="k-agents-scopes-text">{{ text }}</span>
		<k-button
			v-if="hidden > 0"
			:dropdown="true"
			:text="$t('agents.grants.more', { count: hidden })"
			class="k-agents-scopes-toggle"
			size="xs"
			@click.stop="$refs.all.toggle()"
		/>
		<k-dropdown-content v-if="hidden > 0" ref="all" align-x="start">
			<ul class="k-agents-scopes-list">
				<li v-for="scope in value" :key="scope.value">
					<k-icon type="check" />
					<span>{{ scope.text }}</span>
				</li>
			</ul>
		</k-dropdown-content>

		<!-- measures what fits -->
		<div ref="measure" aria-hidden="true" class="k-agents-scopes-measure">
			<span v-for="(part, index) in parts" :key="index" data-label>{{ part }}</span>
			<span ref="more">{{ $t("agents.grants.more", { count: value.length }) }}</span>
		</div>
	</div>
</template>

<script>
// the labels that fit, the rest in a dropdown
export default {
	props: {
		value: Array
	},
	data() {
		return {
			shown: 1
		}
	},
	computed: {
		hidden() {
			return this.value.length - this.shown
		},
		labels() {
			return this.value.map((scope) => scope.short)
		},
		parts() {
			return this.labels.map((label, index) =>
				index < this.labels.length - 1 ? `${label}, ` : label
			)
		},
		text() {
			return this.labels.slice(0, this.shown).join(", ")
		}
	},
	watch: {
		value() {
			this.$nextTick(this.fit)
		}
	},
	mounted() {
		this.observer = new ResizeObserver(() => this.fit())
		this.observer.observe(this.$el)
	},
	beforeDestroy() {
		this.observer?.disconnect()
	},
	methods: {
		fit() {
			const style = window.getComputedStyle(this.$el)
			const width =
				this.$el.clientWidth -
				parseFloat(style.paddingInlineStart) -
				parseFloat(style.paddingInlineEnd)
			const labels = [...this.$refs.measure.querySelectorAll("[data-label]")].map(
				(el) => el.offsetWidth
			)
			// gap, padding, and caret
			const button = this.$refs.more.offsetWidth + 40
			let used = 0
			let shown = 0

			for (const [index, label] of labels.entries()) {
				const last = index === labels.length - 1

				if (used + label + (last ? 0 : button) > width) {
					break
				}

				used += label
				shown = index + 1
			}

			this.shown = Math.max(1, shown)
		}
	}
}
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
