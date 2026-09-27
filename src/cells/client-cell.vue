<template>
	<div class="k-agents-client-cell">
		<span class="k-agents-client-name">{{ value.name }}</span>
		<k-button
			v-if="value.host"
			:text="value.host"
			:title="title"
			element="span"
			icon="check"
			size="xs"
			theme="positive"
			variant="filled"
		/>
		<span v-else :title="title" class="k-agents-client-unverified">
			{{ $t("agents.grants.unverified") }}
		</span>
	</div>
</template>

<script>
// a badge only for verified clients
export default {
	props: {
		value: Object
	},
	computed: {
		title() {
			return this.value.host
				? this.$t("agents.authorize.client.verified", { host: this.value.host })
				: this.$t("agents.authorize.client.unverified")
		}
	}
}
</script>

<style>
.k-agents-client-cell {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: var(--spacing-2);
	padding: 0.325rem var(--table-cell-padding);
}
.k-agents-client-name {
	font-weight: var(--font-bold);
}
.k-agents-client-unverified {
	color: var(--color-text-dimmed);
	font-size: var(--text-xs);
}
</style>
